<?php

namespace App\Http\Controllers;

use App\Models\SalesSetting;
use App\Models\SalesShift;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerBookingController extends Controller
{
    public function create(): View
    {
        return view('customer.book', [
            'salesOpen' => $this->hasOpenSalesShift(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $bookings = DB::table('customer_bookings')
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNull('archived_at')
            ->latest()
            ->get()
            ->map(fn (object $booking): array => [
                'id' => $booking->booking_reference,
                'name' => $booking->name,
                'email' => $booking->email,
                'phone' => $booking->phone,
                'type' => $booking->type,
                'adults' => $booking->adults,
                'children' => $booking->children,
                'guests' => $booking->guests,
                'date' => $booking->booking_date,
                'time' => substr($booking->booking_time, 0, 5),
                'requests' => $booking->requests,
                'status' => $booking->status,
                'createdAt' => $booking->created_at,
            ]);

        return response()->json($bookings);
    }

    public function tableDetails(string $bookingReference): JsonResponse
    {
        $booking = DB::table('customer_bookings')
            ->where('booking_reference', $bookingReference)
            ->where('status', 'confirmed')
            ->whereNull('archived_at')
            ->first(['booking_reference', 'type', 'adults', 'children', 'guests', 'status']);

        if ($booking === null) {
            return response()->json([
                'message' => 'This reservation link is invalid or no longer active. Please contact staff for a new confirmed link.',
            ], 404);
        }

        return response()->json([
            'id' => $booking->booking_reference,
            'type' => $booking->type,
            'adults' => (int) $booking->adults,
            'children' => (int) $booking->children,
            'guests' => (int) $booking->guests,
            'status' => $booking->status,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['advance-order', 'table-reservation'])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:filter', 'max:255', 'regex:/\A[^@\s]+@gmail\.com\z/i'],
            'phone' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'adults' => ['required', 'integer', 'min:0'],
            'children' => ['required', 'integer', 'min:0'],
            'requests' => ['nullable', 'string', 'max:2000'],
        ]);

        $guests = $validated['adults'] + $validated['children'];

        if ($guests < 1) {
            return response()->json([
                'message' => 'At least one guest is required.',
            ], 422);
        }

        $booking = DB::transaction(function () use ($validated, $guests): int {
            $settings = SalesSetting::current();
            $settings = SalesSetting::query()->lockForUpdate()->findOrFail($settings->id);
            $businessDate = CarbonImmutable::now('Asia/Manila');

            if ($businessDate->format('H:i') < substr($settings->business_day_cutoff, 0, 5)) {
                $businessDate = $businessDate->subDay();
            }

            $openShift = SalesShift::query()
                ->where('status', 'open')
                ->whereDate('business_date', $businessDate->toDateString())
                ->lockForUpdate()
                ->first();

            if ($openShift === null) {
                abort(409, 'Sales are currently closed. Ask the manager to start a sales shift before submitting a reservation.');
            }

            $normalizedEmail = $this->normalizeGmailAddress($validated['email']);
            if ($normalizedEmail !== null && $this->hasConfirmedReservationEmail($normalizedEmail)) {
                throw ValidationException::withMessages([
                    'email' => ['This Gmail account already has a confirmed reservation.'],
                ]);
            }

            return DB::table('customer_bookings')->insertGetId([
                'booking_reference' => 'RES-'.strtoupper(Str::random(8)),
                'type' => $validated['type'],
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'adults' => $validated['adults'],
                'children' => $validated['children'],
                'guests' => $guests,
                'booking_date' => $validated['date'],
                'booking_time' => $validated['time'],
                'requests' => $validated['requests'] ?? null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Reservation request submitted successfully. Please wait for confirmation.',
            'booking_id' => $booking,
        ], 201);
    }

    public function updateStatus(Request $request, string $bookingReference): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'cancelled'])],
        ]);

        $result = DB::transaction(function () use ($bookingReference, $validated): array {
            $booking = DB::table('customer_bookings')
                ->where('booking_reference', $bookingReference)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                return ['message' => 'Reservation not found.', 'http_status' => 404];
            }

            if ($booking->status !== 'pending') {
                return [
                    'message' => 'Reservation has already been processed.',
                    'status' => $booking->status,
                    'http_status' => 409,
                ];
            }

            if ($validated['status'] === 'confirmed') {
                $normalizedEmail = $this->normalizeGmailAddress($booking->email);
                if ($normalizedEmail !== null) {
                    if (Schema::hasTable('confirmed_reservation_emails')) {
                        $now = now();

                        $emailClaim = DB::table('confirmed_reservation_emails')
                            ->where('normalized_email', $normalizedEmail)
                            ->lockForUpdate()
                            ->first();

                        if ($emailClaim !== null && $emailClaim->booking_reference !== $bookingReference) {
                            $claimIsActive = DB::table('customer_bookings')
                                ->where('booking_reference', $emailClaim->booking_reference)
                                ->whereNull('archived_at')
                                ->exists();

                            if ($claimIsActive) {
                                return [
                                    'message' => 'This Gmail account already has a confirmed reservation.',
                                    'http_status' => 409,
                                ];
                            }

                            DB::table('confirmed_reservation_emails')
                                ->where('normalized_email', $normalizedEmail)
                                ->where('booking_reference', $emailClaim->booking_reference)
                                ->delete();
                        }

                        DB::table('confirmed_reservation_emails')->insertOrIgnore([
                            'normalized_email' => $normalizedEmail,
                            'booking_reference' => $bookingReference,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $emailClaim = DB::table('confirmed_reservation_emails')
                            ->where('normalized_email', $normalizedEmail)
                            ->first();

                        $emailAlreadyClaimed = $emailClaim === null
                            || $emailClaim->booking_reference !== $bookingReference;
                    } else {
                        $emailAlreadyClaimed = DB::table('customer_bookings')
                            ->where('status', 'confirmed')
                            ->whereNull('archived_at')
                            ->where('id', '!=', $booking->id)
                            ->pluck('email')
                            ->contains(fn (string $email): bool => $this->normalizeGmailAddress($email) === $normalizedEmail);
                    }

                    if ($emailAlreadyClaimed) {
                        return [
                            'message' => 'This Gmail account already has a confirmed reservation.',
                            'http_status' => 409,
                        ];
                    }
                }
            }

            DB::table('customer_bookings')
                ->where('id', $booking->id)
                ->update([
                    'status' => $validated['status'],
                    'updated_at' => now(),
                ]);

            return ['success' => true, 'status' => $validated['status'], 'http_status' => 200];
        });

        $httpStatus = $result['http_status'];
        unset($result['http_status']);

        return response()->json($result, $httpStatus);
    }

    public function archive(Request $request, string $bookingReference): JsonResponse
    {
        $this->authorizeStaff($request);

        $archived = DB::transaction(function () use ($bookingReference): bool {
            $booking = DB::table('customer_bookings')
                ->where('booking_reference', $bookingReference)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first(['id', 'booking_reference']);

            if ($booking === null) {
                return false;
            }

            DB::table('customer_bookings')
                ->where('id', $booking->id)
                ->update([
                    'archived_at' => now(),
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('confirmed_reservation_emails')) {
                DB::table('confirmed_reservation_emails')
                    ->where('booking_reference', $booking->booking_reference)
                    ->delete();
            }

            return true;
        });

        return $archived
            ? response()->json(['success' => true])
            : response()->json(['message' => 'Reservation not found.'], 404);
    }

    public function archiveAll(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        DB::transaction(function (): void {
            $bookingReferences = DB::table('customer_bookings')
                ->whereNull('archived_at')
                ->whereIn('status', ['pending', 'confirmed', 'cancelled'])
                ->lockForUpdate()
                ->pluck('booking_reference');

            if ($bookingReferences->isEmpty()) {
                return;
            }

            DB::table('customer_bookings')
                ->whereIn('booking_reference', $bookingReferences)
                ->whereNull('archived_at')
                ->update([
                    'archived_at' => now(),
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('confirmed_reservation_emails')) {
                DB::table('confirmed_reservation_emails')
                    ->whereIn('booking_reference', $bookingReferences)
                    ->delete();
            }
        });

        return response()->json(['success' => true]);
    }

    private function normalizeGmailAddress(string $email): ?string
    {
        $email = Str::lower(trim($email));
        if (! str_ends_with($email, '@gmail.com')) {
            return null;
        }

        [$localPart] = explode('@', $email, 2);
        $localPart = explode('+', $localPart, 2)[0];

        return str_replace('.', '', $localPart).'@gmail.com';
    }

    private function hasConfirmedReservationEmail(string $normalizedEmail): bool
    {
        if (Schema::hasTable('confirmed_reservation_emails')) {
            return DB::table('confirmed_reservation_emails as claims')
                ->join('customer_bookings as bookings', 'bookings.booking_reference', '=', 'claims.booking_reference')
                ->where('claims.normalized_email', $normalizedEmail)
                ->whereNull('bookings.archived_at')
                ->exists();
        }

        return DB::table('customer_bookings')
            ->where('status', 'confirmed')
            ->whereNull('archived_at')
            ->pluck('email')
            ->contains(fn (string $email): bool => $this->normalizeGmailAddress($email) === $normalizedEmail);
    }

    private function hasOpenSalesShift(): bool
    {
        $settings = SalesSetting::current();
        $businessDate = CarbonImmutable::now('Asia/Manila');

        if ($businessDate->format('H:i') < substr($settings->business_day_cutoff, 0, 5)) {
            $businessDate = $businessDate->subDay();
        }

        return SalesShift::query()
            ->where('status', 'open')
            ->whereDate('business_date', $businessDate->toDateString())
            ->exists();
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(in_array($request->user()?->role, ['manager', 'waiter'], true), 403);
    }
}
