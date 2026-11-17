<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PrintJob;
use App\Models\SalesShift;
use Illuminate\Support\Collection;

class PrintJobService
{
    public function queueTableReceipt(int $tableNumber): ?PrintJob
    {
        $salesShiftId = SalesShift::query()
            ->where('status', 'open')
            ->value('id');

        if ($salesShiftId === null) {
            return null;
        }

        $orders = Order::query()
            ->where('table_number', (string) $tableNumber)
            ->where('sales_shift_id', $salesShiftId)
            ->where('status', 'pending')
            ->with('items')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $orderIds = $orders->modelKeys();
        $dedupeKey = 'table-receipt:'.$salesShiftId.':'.$tableNumber.':'.hash('sha256', implode(',', $orderIds));
        $title = $orders->contains(
            fn (Order $order): bool => $order->booking_type === 'advance-order'
        ) ? 'ADVANCE ORDER' : 'TABLE RECEIPT';
        $payload = $this->receiptPayload($orders, $title);

        $job = PrintJob::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'job_type' => 'table_receipt',
                'payload' => $payload,
                'status' => 'pending',
            ]
        );

        if ($job->status === 'failed') {
            $job->update([
                'payload' => $payload,
                'status' => 'pending',
                'claim_token' => null,
                'claimed_at' => null,
                'printed_at' => null,
                'error_message' => null,
            ]);
        }

        return $job;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<string, mixed>
     */
    private function receiptPayload(Collection $orders, string $title): array
    {
        $firstOrder = $orders->first();
        $receiptTime = now('Asia/Manila');

        return [
            'title' => $title,
            'sales_shift_id' => $firstOrder?->sales_shift_id,
            'table_number' => $firstOrder?->table_number,
            'order_numbers' => $orders->pluck('order_number')->all(),
            'date' => $receiptTime->format('m/d/Y'),
            'time' => $receiptTime->format('h:i A'),
            'items' => $orders->flatMap(fn (Order $order): array => $order->items
                ->map(fn ($item): array => [
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'add_ons' => collect($item->add_ons ?? [])->pluck('name')->all(),
                ])
                ->all())
                ->all(),
            'subtotal_amount' => (float) $orders->sum('subtotal_amount'),
            'discount_amount' => (float) $orders->sum('discount_amount'),
            'vat_amount' => (float) $orders->sum('vat_amount'),
            'total_amount' => (float) $orders->sum('total_amount'),
        ];
    }
}
