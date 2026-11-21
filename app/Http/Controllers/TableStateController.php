<?php

namespace App\Http\Controllers;

use App\Models\SalesShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TableStateController extends Controller
{
    public function index(): JsonResponse
    {
        $this->clearEmptyAdvanceSelections();

        $tables = DB::table('restaurant_tables')
            ->where('is_active', true)
            ->orderBy('table_number')
            ->get()
            ->map(fn (object $table): array => [
                'id' => (int) $table->table_number,
                'status' => $table->status,
                'isPaid' => (bool) $table->is_paid,
                'adults' => (int) $table->adults,
                'children' => (int) $table->children,
                'guests' => (int) $table->guests,
                'bill' => (float) $table->bill,
                'orders' => json_decode($table->orders, true, flags: JSON_THROW_ON_ERROR),
                'startTime' => $table->start_time,
            ]);

        return response()->json($tables);
    }

    private function clearEmptyAdvanceSelections(): void
    {
        $emptyAdvanceSelections = DB::table('restaurant_tables')
            ->where('is_active', true)
            ->where('status', 'reserved-advance')
            ->where('is_paid', false)
            ->where('adults', 0)
            ->where('children', 0)
            ->where('guests', 0)
            ->where('bill', 0)
            ->get(['table_number', 'orders']);

        foreach ($emptyAdvanceSelections as $table) {
            $orders = json_decode($table->orders, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($orders) && $orders !== []) {
                continue;
            }

            DB::table('restaurant_tables')
                ->where('table_number', $table->table_number)
                ->where('is_active', true)
                ->where('status', 'reserved-advance')
                ->where('is_paid', false)
                ->where('adults', 0)
                ->where('children', 0)
                ->where('guests', 0)
                ->where('bill', 0)
                ->where('orders', $table->orders)
                ->update([
                    'status' => 'available',
                    'start_time' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tables' => ['required', 'array', 'min:1'],
            'tables.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'tables.*.status' => ['required', Rule::in([
                'available',
                'occupied',
                'paid',
                'reserved-advance',
                'reserved-booking',
            ])],
            'tables.*.isPaid' => ['sometimes', 'boolean'],
            'tables.*.adults' => ['sometimes', 'integer', 'min:0'],
            'tables.*.children' => ['sometimes', 'integer', 'min:0'],
            'tables.*.guests' => ['sometimes', 'integer', 'min:0'],
            'tables.*.bill' => ['sometimes', 'numeric', 'min:0'],
            'tables.*.orders' => ['sometimes', 'array'],
            'tables.*.startTime' => ['sometimes', 'nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            foreach ($validated['tables'] as $table) {
                $currentTable = DB::table('restaurant_tables')
                    ->where('table_number', $table['id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($currentTable === null) {
                    throw ValidationException::withMessages([
                        'tables' => ["Table {$table['id']} is no longer active."],
                    ]);
                }

                $resultingGuests = $table['guests'] ?? (int) $currentTable->guests;
                $resultingOrders = $table['orders']
                    ?? json_decode($currentTable->orders, true, flags: JSON_THROW_ON_ERROR);
                if ($table['status'] === 'reserved-advance' && $resultingGuests < 1 && $resultingOrders === []) {
                    throw ValidationException::withMessages([
                        'tables' => ["Table {$table['id']} cannot be marked as an advance order without guests or order items."],
                    ]);
                }

                if (
                    ($currentTable->status === 'paid' || (bool) $currentTable->is_paid)
                    && ($table['status'] === 'available' || ($table['isPaid'] ?? true) === false)
                ) {
                    abort_unless($request->user()?->role === 'manager', 403);
                }

                $attributes = [
                    'status' => $table['status'],
                    'updated_at' => now(),
                ];

                foreach ([
                    'isPaid' => 'is_paid',
                    'adults' => 'adults',
                    'children' => 'children',
                    'guests' => 'guests',
                    'bill' => 'bill',
                ] as $input => $column) {
                    if (array_key_exists($input, $table)) {
                        $attributes[$column] = $input === 'isPaid'
                            ? (bool) $table[$input]
                            : $table[$input];
                    }
                }

                if (array_key_exists('orders', $table)) {
                    $attributes['orders'] = json_encode($table['orders'], JSON_THROW_ON_ERROR);
                }

                if (array_key_exists('startTime', $table)) {
                    $attributes['start_time'] = $table['startTime'] === null
                        ? null
                        : Carbon::parse($table['startTime'])->toDateTimeString();
                }

                DB::table('restaurant_tables')
                    ->where('table_number', $table['id'])
                    ->update($attributes);
            }
        });

        return response()->json(['success' => true]);
    }

    public function clear(Request $request, int $tableNumber): JsonResponse
    {
        return DB::transaction(function () use ($request, $tableNumber): JsonResponse {
            $table = DB::table('restaurant_tables')
                ->where('table_number', $tableNumber)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($table === null) {
                return response()->json(['message' => 'Table not found.'], 404);
            }

            $isOccupied = $table->status === 'occupied';
            $isPaid = $table->status === 'paid' || (bool) $table->is_paid;

            if ($isOccupied || $isPaid) {
                abort_unless($request->user()?->role === 'manager', 403);
            }

            $openSalesShiftId = $isOccupied
                ? SalesShift::query()->where('status', 'open')->value('id')
                : null;

            if (
                $openSalesShiftId !== null
                && DB::table('orders')
                    ->where('table_number', (string) $tableNumber)
                    ->where('sales_shift_id', $openSalesShiftId)
                    ->where('status', 'pending')
                    ->exists()
            ) {
                abort(409, 'Cannot clear this table while pending orders remain.');
            }

            DB::table('restaurant_tables')
                ->where('table_number', $tableNumber)
                ->where('is_active', true)
                ->update([
                    'status' => 'available',
                    'is_paid' => false,
                    'adults' => 0,
                    'children' => 0,
                    'guests' => 0,
                    'bill' => 0,
                    'orders' => json_encode([], JSON_THROW_ON_ERROR),
                    'start_time' => null,
                    'updated_at' => now(),
                ]);

            return response()->json(['success' => true]);
        });
    }

    public function store(): JsonResponse
    {
        $tableNumber = DB::transaction(function (): int {
            $lastTable = DB::table('restaurant_tables')
                ->orderByDesc('table_number')
                ->lockForUpdate()
                ->first(['table_number']);
            $tableNumber = ((int) ($lastTable->table_number ?? 0)) + 1;

            DB::table('restaurant_tables')->insert([
                'table_number' => $tableNumber,
                'is_active' => true,
                'status' => 'available',
                'orders' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $tableNumber;
        });

        return response()->json([
            'id' => $tableNumber,
            'tableNumber' => $tableNumber,
        ], 201);
    }

    public function updateNumber(Request $request, int $tableNumber): JsonResponse
    {
        $table = DB::table('restaurant_tables')
            ->where('table_number', $tableNumber)
            ->where('is_active', true)
            ->first();

        if ($table === null) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $validated = $request->validate([
            'table_number' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('restaurant_tables', 'table_number')->ignore($table->id),
            ],
        ]);

        return DB::transaction(function () use ($table, $validated): JsonResponse {
            $lockedTable = DB::table('restaurant_tables')
                ->where('id', $table->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($lockedTable === null) {
                return response()->json(['message' => 'Table not found.'], 404);
            }

            $this->ensureTableCanBeManaged($lockedTable);

            DB::table('restaurant_tables')
                ->where('id', $lockedTable->id)
                ->update([
                    'table_number' => $validated['table_number'],
                    'updated_at' => now(),
                ]);

            return response()->json([
                'id' => (int) $validated['table_number'],
                'tableNumber' => (int) $validated['table_number'],
            ]);
        });
    }

    public function destroy(int $tableNumber): JsonResponse
    {
        return DB::transaction(function () use ($tableNumber): JsonResponse {
            $table = DB::table('restaurant_tables')
                ->where('table_number', $tableNumber)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($table === null) {
                return response()->json(['message' => 'Table not found.'], 404);
            }

            $this->ensureTableCanBeManaged($table);

            DB::table('restaurant_tables')
                ->where('id', $table->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            return response()->json(['success' => true]);
        });
    }

    private function ensureTableCanBeManaged(object $table): void
    {
        $orders = json_decode($table->orders, true, flags: JSON_THROW_ON_ERROR);
        $hasCurrentOrders = is_array($orders) && $orders !== [];
        $hasCurrentGuests = (int) $table->adults > 0
            || (int) $table->children > 0
            || (int) $table->guests > 0;
        $hasPendingOrders = $this->hasPendingOrders((int) $table->table_number);

        if (
            $table->status !== 'available'
            || (bool) $table->is_paid
            || $hasCurrentOrders
            || $hasCurrentGuests
            || (float) $table->bill > 0
            || $hasPendingOrders
        ) {
            abort(409, 'Only an available table with no active orders or reservations can be edited or removed.');
        }
    }

    private function hasPendingOrders(int $tableNumber): bool
    {
        $openSalesShiftId = SalesShift::query()
            ->where('status', 'open')
            ->value('id');

        return $openSalesShiftId !== null
            && DB::table('orders')
                ->where('table_number', (string) $tableNumber)
                ->where('sales_shift_id', $openSalesShiftId)
                ->where('status', 'pending')
                ->exists();
    }
}
