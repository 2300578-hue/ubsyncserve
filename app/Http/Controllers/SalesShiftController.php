<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesSetting;
use App\Models\SalesShift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesShiftController extends Controller
{
    private const BUSINESS_TIMEZONE = 'Asia/Manila';

    public function show(Request $request): JsonResponse
    {
        $this->ensureReportingSchema();
        DB::transaction(function (): void {
            $this->rolloverExpiredShift();
        });

        $setting = SalesSetting::current();
        $today = $this->businessDate($setting->business_day_cutoff);
        $dates = $this->validatedDates($request, $today);
        $shifts = SalesShift::query()
            ->whereBetween('business_date', [$dates['from'], $dates['to']])
            ->with('startedBy:id,name')
            ->orderByDesc('business_date')
            ->orderByDesc('started_at')
            ->get();
        $orders = Order::query()
            ->with('items.product')
            ->whereIn('sales_shift_id', $shifts->modelKeys())
            ->where('status', 'completed')
            ->orderByDesc('paid_at')
            ->get();

        $dailyReports = $shifts->map(fn (SalesShift $shift): array => $this->dailyReport(
            $shift,
            $orders->where('sales_shift_id', $shift->id)
        ))->values();
        $summary = $this->summarize($orders);
        $openShift = SalesShift::query()
            ->where('status', 'open')
            ->latest('started_at')
            ->first();
        $currentShift = $openShift ?? SalesShift::query()
            ->whereDate('business_date', $today)
            ->latest('started_at')
            ->first();

        return response()->json([
            'business_date' => $today,
            'cutoff_time' => substr($setting->business_day_cutoff, 0, 5),
            'from' => $dates['from'],
            'to' => $dates['to'],
            'preset' => $dates['preset'],
            'current_shift' => $currentShift,
            'summary' => $summary,
            'products' => $this->productBreakdown($orders),
            'transactions' => $orders->map(fn (Order $order): array => $this->transaction($order))->values(),
            'daily_reports' => $dailyReports,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->ensureReportingSchema();
        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $shift = DB::transaction(function () use ($request, $data): SalesShift {
            $settings = SalesSetting::query()->lockForUpdate()->first();
            if ($settings === null) {
                $settings = SalesSetting::current();
            }

            $this->rolloverExpiredShift($settings);

            if (SalesShift::query()->where('status', 'open')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'shift' => ['A sales shift is already open. Close it before starting another shift.'],
                ]);
            }

            return SalesShift::query()->create([
                'started_by' => $request->user()->id,
                'business_date' => $this->businessDate($settings->business_day_cutoff),
                'cutoff_time' => $settings->business_day_cutoff,
                'started_at' => CarbonImmutable::now('UTC'),
                'opening_float' => $data['opening_float'],
                'status' => 'open',
            ]);
        });

        return response()->json(['shift' => $shift], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $this->ensureReportingSchema();

        $shift = DB::transaction(function () use ($request): SalesShift {
            SalesSetting::query()->lockForUpdate()->first();
            $shift = SalesShift::query()
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($shift === null) {
                throw ValidationException::withMessages([
                    'shift' => ['There is no open sales shift to close.'],
                ]);
            }

            if ($shift->orders()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([
                    'shift' => ['Settle all unpaid database orders before closing the sales day.'],
                ]);
            }

            return $this->closeShift($shift, $request->user()->id);
        });

        return response()->json([
            'shift' => $shift,
            'summary' => $shift->z_read,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->ensureReportingSchema();
        $data = $request->validate([
            'business_day_cutoff' => ['required', 'date_format:H:i'],
        ]);

        $setting = DB::transaction(function () use ($data): SalesSetting {
            $settings = SalesSetting::current();
            $settings = SalesSetting::query()->lockForUpdate()->findOrFail($settings->id);

            if (SalesShift::query()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages([
                    'business_day_cutoff' => ['Close the current shift before changing the business-day cutoff.'],
                ]);
            }

            $settings->update(['business_day_cutoff' => $data['business_day_cutoff']]);

            return $settings->fresh();
        });

        return response()->json([
            'business_date' => $this->businessDate($setting->business_day_cutoff),
            'cutoff_time' => substr($setting->business_day_cutoff, 0, 5),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $report = $this->show($request)->getData(true);
        $filename = "sales-reports-{$report['from']}-to-{$report['to']}.csv";

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open the sales report export stream.');
            }

            fputcsv($stream, [
                'Business Date', 'Started At', 'Business Cut-off', 'Ended At', 'Status', 'Opening Float',
                'Order Count', 'Items Sold', 'Gross Sales', 'Discounts', 'Net Sales',
                'VAT', 'Total Sales', 'Expected Cash Drawer',
            ]);

            foreach ($report['daily_reports'] as $day) {
                fputcsv($stream, [
                    $day['business_date'],
                    $day['started_at'],
                    $day['cutoff_time'],
                    $day['ended_at'],
                    $day['status'],
                    $day['opening_float'],
                    $day['summary']['order_count'],
                    $day['summary']['items_sold'],
                    $day['summary']['gross_sales'],
                    $day['summary']['discount_total'],
                    $day['summary']['net_sales'],
                    $day['summary']['vat_total'],
                    $day['summary']['total_sales'],
                    $day['summary']['expected_cash_drawer'],
                ]);
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settleTable(Request $request, int $tableNumber): JsonResponse
    {
        abort_unless(in_array($request->user()?->role, ['manager', 'waiter'], true), 403);
        $data = $request->validate([
            'payment_method' => ['sometimes', 'nullable', 'in:cash,gcash'],
        ]);
        $paymentMethod = $data['payment_method'] ?? 'cash';
        $this->ensureReportingSchema();

        $paidOrders = DB::transaction(function () use ($request, $tableNumber, $paymentMethod): EloquentCollection {
            $shift = SalesShift::query()
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($shift === null) {
                throw ValidationException::withMessages([
                    'shift' => ['Sales are closed. Ask the manager to start today’s sales before accepting payment.'],
                ]);
            }

            $orders = Order::query()
                ->where('table_number', (string) $tableNumber)
                ->where('sales_shift_id', $shift->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                throw ValidationException::withMessages([
                    'table' => ['No unpaid database order was found for this table.'],
                ]);
            }

            foreach ($orders as $order) {
                $order->update([
                    'status' => 'completed',
                    'payment_method' => $paymentMethod,
                    'paid_at' => now(),
                ]);
            }

            return $orders;
        });

        return response()->json([
            'order_numbers' => $paidOrders->pluck('order_number')->values(),
        ]);
    }

    private function ensureReportingSchema(): void
    {
        if (
            ! Schema::hasTable('sales_shifts')
            || ! Schema::hasTable('sales_settings')
            || ! Schema::hasColumn('sales_shifts', 'cutoff_time')
            || ! Schema::hasTable('orders')
            || ! Schema::hasColumn('orders', 'sales_shift_id')
        ) {
            abort(503, 'Sales reporting database is not set up. Run php artisan migrate, then reload this page.');
        }
    }

    private function rolloverExpiredShift(?SalesSetting $settings = null): void
    {
        $settings ??= SalesSetting::current();
        $today = $this->businessDate($settings->business_day_cutoff);
        $expiredShifts = SalesShift::query()
            ->where('status', 'open')
            ->whereDate('business_date', '<', $today)
            ->lockForUpdate()
            ->get();

        foreach ($expiredShifts as $shift) {
            if ($shift->orders()->where('status', 'pending')->exists()) {
                continue;
            }

            $this->closeShift($shift, null);
        }
    }

    private function closeShift(SalesShift $shift, ?int $managerId): SalesShift
    {
        $orders = $shift->orders()
            ->where('status', 'completed')
            ->with('items')
            ->get();
        $summary = $this->summarize($orders);

        $shift->update([
            'ended_by' => $managerId,
            'ended_at' => CarbonImmutable::now('UTC'),
            'status' => 'closed',
            'order_count' => $summary['order_count'],
            'items_sold' => $summary['items_sold'],
            'gross_sales' => $summary['gross_sales'],
            'discount_total' => $summary['discount_total'],
            'net_sales' => $summary['net_sales'],
            'vat_total' => $summary['vat_total'],
            'total_sales' => $summary['total_sales'],
            'cash_sales' => $summary['cash_sales'],
            'gcash_sales' => $summary['gcash_sales'],
            'z_read' => [
                ...$summary,
                'opening_float' => (float) $shift->opening_float,
                'expected_cash_drawer' => round((float) $shift->opening_float + $summary['cash_sales'], 2),
            ],
        ]);

        return $shift->fresh();
    }

    /**
     * @return array{from: string, to: string, preset: string}
     */
    private function validatedDates(Request $request, string $businessDate): array
    {
        $validated = $request->validate([
            'preset' => ['nullable', Rule::in(['today', 'yesterday', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $preset = $validated['preset'] ?? 'today';
        if ($preset === 'yesterday') {
            $yesterday = CarbonImmutable::parse($businessDate, self::BUSINESS_TIMEZONE)->subDay()->toDateString();

            return ['from' => $yesterday, 'to' => $yesterday, 'preset' => $preset];
        }

        $from = $preset === 'custom' ? ($validated['from'] ?? $businessDate) : $businessDate;
        $to = $preset === 'custom' ? ($validated['to'] ?? $from) : $from;

        if ($to < $from) {
            throw ValidationException::withMessages([
                'to' => ['The end date must be the same as or later than the start date.'],
            ]);
        }

        return ['from' => $from, 'to' => $to, 'preset' => $preset];
    }

    private function businessDate(string $cutoffTime): string
    {
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        if ($now->format('H:i') < substr($cutoffTime, 0, 5)) {
            $now = $now->subDay();
        }

        return $now->toDateString();
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @return array{order_count: int, items_sold: int, gross_sales: float, discount_total: float, net_sales: float, vat_total: float, total_sales: float, cash_sales: float, gcash_sales: float}
     */
    private function summarize(EloquentCollection $orders): array
    {
        $gross = $discounts = $net = $vat = $total = $cash = $gcash = 0.0;
        $itemsSold = 0;

        foreach ($orders as $order) {
            $gross += (float) $order->subtotal_amount;
            $discounts += (float) $order->discount_amount;
            $net += (float) $order->subtotal_amount - (float) $order->discount_amount;
            $vat += (float) $order->vat_amount;
            $total += (float) $order->total_amount;
            $itemsSold += (int) $order->items->sum('quantity');

            if ($order->payment_method === 'cash') {
                $cash += (float) $order->total_amount;
            } elseif ($order->payment_method === 'gcash') {
                $gcash += (float) $order->total_amount;
            }
        }

        return [
            'order_count' => $orders->count(),
            'items_sold' => $itemsSold,
            'gross_sales' => round($gross, 2),
            'discount_total' => round($discounts, 2),
            'net_sales' => round($net, 2),
            'vat_total' => round($vat, 2),
            'total_sales' => round($total, 2),
            'cash_sales' => round($cash, 2),
            'gcash_sales' => round($gcash, 2),
        ];
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @return array<string, mixed>
     */
    private function dailyReport(SalesShift $shift, EloquentCollection $orders): array
    {
        $summary = $shift->status === 'closed' && $shift->z_read !== null
            ? $shift->z_read
            : $this->summarize($orders);
        $summary['opening_float'] = (float) $shift->opening_float;
        $summary['expected_cash_drawer'] = round(
            (float) $shift->opening_float + (float) $summary['cash_sales'],
            2
        );

        return [
            'id' => $shift->id,
            'business_date' => $shift->business_date->toDateString(),
            'cutoff_time' => $shift->cutoff_time === null ? null : substr((string) $shift->cutoff_time, 0, 5),
            'started_at' => $shift->started_at?->toIso8601String(),
            'ended_at' => $shift->ended_at?->toIso8601String(),
            'started_by' => $shift->startedBy?->name,
            'status' => $shift->status,
            'opening_float' => (float) $shift->opening_float,
            'summary' => $summary,
            'transactions' => $orders->map(fn (Order $order): array => $this->transaction($order))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(Order $order): array
    {
        return [
            'orderId' => $order->order_number,
            'timestamp' => $order->paid_at?->toIso8601String(),
            'totalAmount' => (float) $order->total_amount,
            'tableId' => $order->table_number,
            'paymentMethod' => $order->payment_method,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'name' => $item->product_name,
                'qty' => $item->quantity,
                'price' => (float) $item->unit_price,
            ])->all(),
            'status' => $order->status,
        ];
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @return list<array<string, int|float|string>>
     */
    private function productBreakdown(EloquentCollection $orders): array
    {
        $products = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $key = (string) ($item->product_id ?? $item->product_name);
                if (! isset($products[$key])) {
                    $products[$key] = [
                        'id' => $item->product_id ?? $key,
                        'name' => $item->product_name,
                        'qtySold' => 0,
                        'unitCost' => (float) ($item->product?->cost_price ?? 0),
                        'totalRevenue' => 0.0,
                        'imgPath' => $item->product?->image
                            ? (str_starts_with($item->product->image, 'data:') || str_starts_with($item->product->image, 'http')
                                ? $item->product->image
                                : '/img/'.$item->product->image)
                            : '/img/placeholder.png',
                    ];
                }

                $products[$key]['qtySold'] += $item->quantity;
                $products[$key]['totalRevenue'] += (float) $item->unit_price * $item->quantity;
            }
        }

        $totalRevenue = array_sum(array_column($products, 'totalRevenue'));
        foreach ($products as &$product) {
            $product['totalRevenue'] = round($product['totalRevenue'], 2);
            $product['percentageOfSales'] = $totalRevenue > 0
                ? round(($product['totalRevenue'] / $totalRevenue) * 100, 2)
                : 0;
        }
        unset($product);

        usort($products, fn (array $first, array $second): int => $second['totalRevenue'] <=> $first['totalRevenue']);

        return array_values($products);
    }
}
