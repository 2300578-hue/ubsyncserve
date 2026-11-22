<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->recalculateSales(0.12, true);
    }

    public function down(): void
    {
        $this->recalculateSales(0.05, false);
    }

    private function recalculateSales(float $vatRate, bool $discountsAreExempt): void
    {
        DB::table('orders')->orderBy('id')->chunkById(500, function ($orders) use ($vatRate, $discountsAreExempt): void {
            foreach ($orders as $order) {
                $subtotal = (float) $order->subtotal_amount;
                $discount = min($subtotal, max(0, (float) $order->discount_amount));
                $taxableAmount = max(0, round($subtotal - $discount, 2));
                $vat = $discountsAreExempt && $discount > 0
                    ? 0.00
                    : round($taxableAmount * $vatRate, 2);

                DB::table('orders')
                    ->where('id', $order->id)
                    ->update([
                        'vat_amount' => $vat,
                        'total_amount' => round($taxableAmount + $vat, 2),
                    ]);
            }
        });

        foreach (DB::table('sales_shifts')->get() as $shift) {
            $orders = DB::table('orders')
                ->where('sales_shift_id', $shift->id)
                ->where('status', 'completed')
                ->get(['subtotal_amount', 'discount_amount', 'vat_amount', 'total_amount', 'payment_method']);

            $summary = [
                'order_count' => $orders->count(),
                'items_sold' => (int) DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.sales_shift_id', $shift->id)
                    ->where('orders.status', 'completed')
                    ->sum('order_items.quantity'),
                'gross_sales' => round((float) $orders->sum('subtotal_amount'), 2),
                'discount_total' => round((float) $orders->sum('discount_amount'), 2),
                'net_sales' => round((float) $orders->sum(
                    fn (object $order): float => (float) $order->subtotal_amount - (float) $order->discount_amount
                ), 2),
                'vat_total' => round((float) $orders->sum('vat_amount'), 2),
                'total_sales' => round((float) $orders->sum('total_amount'), 2),
                'cash_sales' => round((float) $orders
                    ->where('payment_method', 'cash')
                    ->sum('total_amount'), 2),
                'gcash_sales' => round((float) $orders
                    ->where('payment_method', 'gcash')
                    ->sum('total_amount'), 2),
            ];

            $attributes = [
                'order_count' => $summary['order_count'],
                'items_sold' => $summary['items_sold'],
                'gross_sales' => $summary['gross_sales'],
                'discount_total' => $summary['discount_total'],
                'net_sales' => $summary['net_sales'],
                'vat_total' => $summary['vat_total'],
                'total_sales' => $summary['total_sales'],
                'cash_sales' => $summary['cash_sales'],
                'gcash_sales' => $summary['gcash_sales'],
                'updated_at' => now(),
            ];

            if ($shift->status === 'closed') {
                $attributes['z_read'] = json_encode([
                    ...$summary,
                    'opening_float' => (float) $shift->opening_float,
                    'expected_cash_drawer' => round((float) $shift->opening_float + $summary['cash_sales'], 2),
                ], JSON_THROW_ON_ERROR);
            }

            DB::table('sales_shifts')
                ->where('id', $shift->id)
                ->update($attributes);
        }
    }
};
