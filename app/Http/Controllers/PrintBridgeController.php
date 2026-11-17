<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignQzMessageRequest;
use App\Models\PrintJob;
use App\Models\SalesShift;
use App\Services\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PrintBridgeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeStaff($request);

        return response()->view('print-bridge');
    }

    public function queueTableReceipt(Request $request, int $tableNumber, PrintJobService $printJobs): JsonResponse
    {
        $this->authorizeStaff($request);

        $job = $printJobs->queueTableReceipt($tableNumber);
        abort_if($job === null, 422, 'This table has no pending orders to print.');

        return response()->json([
            'job_id' => $job->id,
            'status' => $job->status,
            'created' => $job->wasRecentlyCreated,
        ], $job->wasRecentlyCreated ? 201 : 200);
    }

    public function claimNext(Request $request): Response
    {
        $this->authorizeStaff($request);

        $job = DB::transaction(function (): ?PrintJob {
            PrintJob::query()
                ->where('status', 'pending')
                ->where('job_type', 'order_receipt')
                ->update([
                    'status' => 'failed',
                    'error_message' => 'Individual order receipts were superseded by Confirm Print table receipts.',
                    'updated_at' => now(),
                ]);

            $currentShiftId = SalesShift::query()
                ->where('status', 'open')
                ->value('id');

            while (true) {
                $candidate = PrintJob::query()
                    ->where('status', 'pending')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($candidate === null) {
                    return null;
                }

                if (
                    $candidate->job_type === 'table_receipt'
                    && (
                        $currentShiftId === null
                        || (int) ($candidate->payload['sales_shift_id'] ?? 0) !== (int) $currentShiftId
                    )
                ) {
                    $candidate->update([
                        'status' => 'failed',
                        'error_message' => 'This receipt belongs to a different or expired sales shift. Confirm Print again to create a current receipt.',
                    ]);

                    continue;
                }

                $job = $candidate;
                break;
            }

            $job->update([
                'status' => 'processing',
                'claim_token' => Str::random(64),
                'claimed_at' => now(),
            ]);

            return $job->fresh();
        });

        if ($job === null) {
            return response()->noContent();
        }

        return response()->json([
            'id' => $job->id,
            'claim_token' => $job->claim_token,
            'payload' => $job->payload,
        ]);
    }

    public function markPrinted(Request $request, PrintJob $printJob): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'claim_token' => ['required', 'string', 'size:64'],
        ]);

        $this->transitionClaimedJob($printJob, $data['claim_token'], function (PrintJob $job): void {
            $job->update([
                'status' => 'printed',
                'printed_at' => now(),
                'claim_token' => null,
                'error_message' => null,
            ]);
        });

        return response()->json(['status' => 'printed']);
    }

    public function markFailed(Request $request, PrintJob $printJob): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'claim_token' => ['required', 'string', 'size:64'],
            'error_message' => ['required', 'string', 'max:1000'],
        ]);

        $this->transitionClaimedJob($printJob, $data['claim_token'], function (PrintJob $job) use ($data): void {
            $job->update([
                'status' => 'failed',
                'claim_token' => null,
                'error_message' => $data['error_message'],
            ]);
        });

        return response()->json(['status' => 'failed']);
    }

    public function show(Request $request, PrintJob $printJob): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json([
            'status' => $printJob->status,
            'error_message' => $printJob->error_message,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function certificate(Request $request): Response
    {
        $this->authorizeStaff($request);
        $certificatePath = $this->resolveConfiguredPath(config('services.qz.certificate_path'));

        abort_unless($certificatePath !== null && is_file($certificatePath), 503, 'QZ Tray certificate is not configured.');

        $certificate = file_get_contents($certificatePath);
        if ($certificate === false) {
            abort(503, 'Unable to read the configured QZ Tray certificate.');
        }

        return response($certificate, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function sign(SignQzMessageRequest $request): Response
    {
        $privateKeyPath = $this->resolveConfiguredPath(config('services.qz.private_key_path'));

        abort_unless($privateKeyPath !== null && is_file($privateKeyPath), 503, 'QZ Tray private key is not configured.');

        $privateKeyContents = file_get_contents($privateKeyPath);
        if ($privateKeyContents === false) {
            abort(503, 'Unable to read the configured QZ Tray private key.');
        }

        $privateKey = openssl_pkey_get_private($privateKeyContents);
        if ($privateKey === false) {
            abort(503, 'The configured QZ Tray private key is invalid.');
        }

        $signature = '';
        if (! openssl_sign($request->validated('request'), $signature, $privateKey, OPENSSL_ALGO_SHA512)) {
            abort(500, 'Unable to sign the QZ Tray message.');
        }

        return response(base64_encode($signature), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function resolveConfiguredPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(in_array($request->user()?->role, ['manager', 'waiter'], true), 403);
    }

    private function transitionClaimedJob(PrintJob $printJob, string $claimToken, callable $transition): void
    {
        DB::transaction(function () use ($printJob, $claimToken, $transition): void {
            $job = PrintJob::query()->lockForUpdate()->findOrFail($printJob->id);

            abort_unless(
                $job->status === 'processing'
                    && is_string($job->claim_token)
                    && hash_equals($job->claim_token, $claimToken),
                409,
                'This print job is no longer claimed by this bridge.'
            );

            $transition($job);
        });
    }
}
