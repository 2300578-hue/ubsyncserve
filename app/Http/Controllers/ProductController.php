<?php

namespace App\Http\Controllers;

use App\Models\InventoryIngredient;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\SalesSetting;
use App\Models\SalesShift;
use App\Services\PrintJobService;
use Carbon\CarbonImmutable;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(ProductSeeder $productSeeder): JsonResponse
    {
        if (! Product::query()->exists()) {
            $productSeeder->run();
        }

        return response()->json(Product::query()->with('ingredients')->latest('id')->get()->map->toCatalogArray());
    }

    public function store(Request $request): JsonResponse
    {
        $product = $this->saveProduct($this->validatedData($request));

        return response()->json($product->load('ingredients')->toCatalogArray(), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product = $this->saveProduct($this->validatedData($request), $product);

        return response()->json($product->load('ingredients')->toCatalogArray());
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    public function completeOrder(Request $request, PrintJobService $printJobs): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.add_ons' => ['sometimes', 'array'],
            'items.*.add_ons.*.name' => ['required', 'string', 'max:255'],
            'table_number' => ['nullable', 'integer', 'min:1'],
            'booking_type' => ['nullable', Rule::in(['advance-order'])],
            'payment_method' => ['sometimes', 'nullable', Rule::in(['gcash', 'paymaya'])],
            'adults' => ['sometimes', 'integer', 'min:0'],
            'children' => ['sometimes', 'integer', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['senior', 'pwd'])],
        ]);
        if (
            ! Schema::hasTable('sales_shifts')
            || ! Schema::hasTable('sales_settings')
            || ! Schema::hasColumn('orders', 'sales_shift_id')
        ) {
            abort(503, 'Sales reporting database is not set up. Run php artisan migrate, then reload this page.');
        }

        $autoPayLocalAdvanceOrder = config('app.env') === 'local'
            && ($data['booking_type'] ?? null) === 'advance-order';
        $idempotencyKey = $data['idempotency_key'] ?? null;
        $shouldQueueOrderSlip = in_array(Auth::user()?->role, ['manager', 'waiter'], true);
        if ($idempotencyKey !== null) {
            $existingOrder = Order::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existingOrder !== null) {
                $printJob = $shouldQueueOrderSlip ? $printJobs->queueOrderSlip($existingOrder) : null;

                return response()->json($this->orderResponse($existingOrder, $printJob), 200);
            }
        }

        $hasBookingTypeColumn = Schema::hasColumn('orders', 'booking_type');

        $result = DB::transaction(function () use (
            $data,
            $autoPayLocalAdvanceOrder,
            $hasBookingTypeColumn,
            $idempotencyKey,
            $printJobs,
            $shouldQueueOrderSlip
        ): array {
            $salesShift = SalesShift::query()
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($idempotencyKey !== null) {
                $existingOrder = Order::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existingOrder !== null) {
                    return [
                        'order' => $existingOrder,
                        'duplicate' => true,
                        'print_job' => $shouldQueueOrderSlip
                            ? $printJobs->queueOrderSlip($existingOrder)
                            : null,
                    ];
                }
            }

            $settings = SalesSetting::current();
            $businessDate = CarbonImmutable::now('Asia/Manila');
            if ($businessDate->format('H:i') < substr($settings->business_day_cutoff, 0, 5)) {
                $businessDate = $businessDate->subDay();
            }

            if (
                $salesShift === null
                || $salesShift->business_date->toDateString() !== $businessDate->toDateString()
            ) {
                throw ValidationException::withMessages([
                    'sales' => ['Sales are currently closed. Ask the manager to start a sales shift.'],
                ]);
            }

            $itemsByProduct = collect($data['items'])->groupBy('product_id')->sortKeys();
            $products = [];

            foreach ($itemsByProduct as $productId => $items) {
                $product = Product::query()->lockForUpdate()->findOrFail($productId);
                $quantity = $items->sum('quantity');
                $ingredientIds = $product->ingredients()
                    ->orderBy('ingredients.id')
                    ->pluck('ingredients.id')
                    ->all();
                $ingredients = $ingredientIds === []
                    ? collect()
                    : InventoryIngredient::query()
                        ->whereKey($ingredientIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                $availableStock = $ingredients->isNotEmpty()
                    ? $ingredients->min('stock_quantity')
                    : $product->stock_quantity;

                if ($availableStock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Not enough stock for {$product->name}."],
                    ]);
                }

                $products[$productId] = $product;
                if ($ingredients->isNotEmpty()) {
                    $ingredients->each(fn (InventoryIngredient $ingredient) => $ingredient->decrement('stock_quantity', $quantity));
                    Product::query()
                        ->whereHas('ingredients', fn (Builder $query) => $query->whereIn('ingredients.id', $ingredientIds))
                        ->with('ingredients')
                        ->get()
                        ->each(fn (Product $relatedProduct) => $relatedProduct->refreshStockQuantity());
                } else {
                    $product->decrement('stock_quantity', $quantity);
                }
            }

            $orderItems = collect($data['items'])->map(function (array $item) use ($products): array {
                $product = $products[$item['product_id']];
                $addOns = collect($item['add_ons'] ?? [])->map(function (array $requestedAddOn) use ($product): array {
                    $addOn = collect($product->add_ons ?? [])
                        ->firstWhere('name', $requestedAddOn['name']);

                    if ($addOn === null) {
                        throw ValidationException::withMessages([
                            'items' => ["Invalid add-on for {$product->name}."],
                        ]);
                    }

                    return [
                        'name' => $addOn['name'],
                        'price' => (float) ($addOn['price'] ?? 0),
                    ];
                })->values()->all();
                $unitPrice = (float) $product->selling_price
                    + collect($addOns)->sum('price');

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'add_ons' => $addOns,
                    'line_total' => $unitPrice * $item['quantity'],
                ];
            });

            $subtotalAmount = round((float) $orderItems->sum('line_total'), 2);
            $discountAmount = isset($data['discount_type'])
                ? round($subtotalAmount * 0.20, 2)
                : 0.00;
            $discountedAmount = $subtotalAmount - $discountAmount;
            $vatAmount = round($discountedAmount * 0.05, 2);
            $grandTotalAmount = round($discountedAmount + $vatAmount, 2);

            $tableNumber = $data['table_number'] ?? null;
            if ($tableNumber !== null) {
                $tableNumber = (int) $tableNumber;
                $tableState = DB::table('restaurant_tables')
                    ->where('table_number', $tableNumber)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($tableState === null) {
                    throw ValidationException::withMessages([
                        'table_number' => ['This table is no longer available. Please select an active table.'],
                    ]);
                }

                if (
                    ($data['booking_type'] ?? null) === 'advance-order'
                    && ! in_array($tableState->status, ['available', 'reserved-booking'], true)
                ) {
                    throw ValidationException::withMessages([
                        'table_number' => ['This table is no longer available for an advance order. Please select another table.'],
                    ]);
                }

                $existingOrders = json_decode($tableState->orders, true, flags: JSON_THROW_ON_ERROR);
                $allocatedDiscount = 0.00;
                $lastOrderItemIndex = $orderItems->count() - 1;
                $newTableOrders = $orderItems->values()->map(function (array $item, int $index) use (
                    $data,
                    $discountAmount,
                    $subtotalAmount,
                    $lastOrderItemIndex,
                    &$allocatedDiscount
                ): array {
                    $itemDiscount = 0.00;
                    if (isset($data['discount_type'])) {
                        $remainingDiscount = max(0, round($discountAmount - $allocatedDiscount, 2));
                        $itemDiscount = $index === $lastOrderItemIndex
                            ? $remainingDiscount
                            : min(
                                $remainingDiscount,
                                $subtotalAmount > 0
                                    ? round($item['line_total'] * $discountAmount / $subtotalAmount, 2)
                                    : 0.00
                            );
                        $allocatedDiscount += $itemDiscount;
                    }

                    return [
                        'name' => $item['product_name'],
                        'qty' => $item['quantity'],
                        'price' => $item['unit_price'],
                        'addonName' => collect($item['add_ons'])->pluck('name')->implode(', ') ?: 'default',
                        'discountType' => $data['discount_type'] ?? null,
                        'discountAmount' => $itemDiscount,
                    ];
                });
                $adults = $data['adults'] ?? (int) $tableState->adults;
                $children = $data['children'] ?? (int) $tableState->children;
                if ($adults + $children === 0 && (int) $tableState->guests > 0) {
                    $adults = (int) $tableState->adults;
                    $children = (int) $tableState->children;
                }
                $tableStatus = 'occupied';
                if ($tableState->status === 'reserved-booking') {
                    $tableStatus = 'reserved-booking';
                } elseif (
                    $adults + $children > 0
                    && (($data['booking_type'] ?? null) === 'advance-order' || $tableState->status === 'reserved-advance')
                ) {
                    $tableStatus = 'reserved-advance';
                }

                DB::table('restaurant_tables')
                    ->where('table_number', $tableNumber)
                    ->update([
                        'status' => $autoPayLocalAdvanceOrder ? 'paid' : $tableStatus,
                        'is_paid' => $autoPayLocalAdvanceOrder,
                        'adults' => $adults,
                        'children' => $children,
                        'guests' => $adults + $children,
                        'bill' => (float) $tableState->bill + $discountedAmount,
                        'orders' => json_encode(array_merge($existingOrders, $newTableOrders->all()), JSON_THROW_ON_ERROR),
                        'start_time' => $tableState->start_time ?? now(),
                        'updated_at' => now(),
                    ]);
            }

            $order = Order::create([
                'order_number' => 'ORD-'.Str::upper((string) Str::uuid()),
                'idempotency_key' => $idempotencyKey,
                'table_number' => $tableNumber,
                ...($hasBookingTypeColumn ? ['booking_type' => $data['booking_type'] ?? null] : []),
                'waiter_id' => Auth::id(),
                'sales_shift_id' => $salesShift->id,
                'status' => $autoPayLocalAdvanceOrder ? 'completed' : 'pending',
                'payment_method' => $data['payment_method'] ?? null,
                'paid_at' => $autoPayLocalAdvanceOrder ? now() : null,
                'total_amount' => $grandTotalAmount,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'vat_amount' => $vatAmount,
            ]);

            foreach ($orderItems as $orderItem) {
                unset($orderItem['line_total']);
                $order->items()->create($orderItem);
            }

            $printJob = $shouldQueueOrderSlip ? $printJobs->queueOrderSlip($order) : null;

            return [
                'order' => $order,
                'duplicate' => false,
                'print_job' => $printJob,
            ];
        });

        return response()->json(
            $this->orderResponse($result['order'], $result['print_job']),
            $result['duplicate'] ? 200 : 201
        );
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function orderResponse(Order $order, ?PrintJob $printJob = null): array
    {
        return [
            'message' => 'Order saved and inventory updated.',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'print_job_queued' => $printJob !== null && $printJob->status !== 'printed',
            'print_job_id' => $printJob?->status === 'printed' ? null : $printJob?->id,
            'total_amount' => (float) $order->total_amount,
            'subtotal_amount' => (float) $order->subtotal_amount,
            'discount_amount' => (float) $order->discount_amount,
            'vat_amount' => (float) $order->vat_amount,
            'grand_total_amount' => (float) $order->total_amount,
            'payment_status' => $order->paid_at !== null ? 'paid' : 'pending',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'cat' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
            'img' => ['nullable', 'string'],
            'addOns' => ['nullable', 'array'],
            'addOns.*.name' => ['nullable', 'string', 'max:255'],
            'addOns.*.price' => ['nullable', 'numeric', 'min:0'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*.id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.stock' => ['nullable', 'integer', 'min:0'],
        ]);

        return [
            'name' => $validated['name'],
            'category' => $validated['category'] ?? $validated['cat'] ?? null,
            'cost_price' => $validated['cost'] ?? 0,
            'selling_price' => $validated['sellingPrice'],
            'image' => $validated['img'] ?? null,
            'add_ons' => $validated['addOns'] ?? [],
            'ingredients' => $validated['ingredients'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveProduct(array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $product): Product {
            $ingredientInputs = $data['ingredients'];
            unset($data['ingredients']);

            if ($product === null) {
                $product = Product::create($data);
            } else {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                $product->update($data);
            }

            $this->syncIngredients($product, $ingredientInputs);
            $product->refreshStockQuantity();

            return $product;
        });
    }

    /**
     * @param  array<int, array{id?: int|null, name: string, stock?: int|null}>  $ingredientInputs
     */
    private function syncIngredients(Product $product, array $ingredientInputs): void
    {
        $ingredientIds = [];
        $ingredientInputs = collect($ingredientInputs)
            ->sortBy(static fn (array $ingredient): string => Str::lower(trim($ingredient['name'])))
            ->values()
            ->all();

        foreach ($ingredientInputs as $index => $ingredientInput) {
            $name = trim($ingredientInput['name']);
            if ($name === '') {
                throw ValidationException::withMessages([
                    "ingredients.{$index}.name" => ['Ingredient name is required.'],
                ]);
            }

            $normalizedName = Str::lower($name);
            $ingredient = isset($ingredientInput['id'])
                ? InventoryIngredient::query()->lockForUpdate()->findOrFail($ingredientInput['id'])
                : InventoryIngredient::query()->where('normalized_name', $normalizedName)->lockForUpdate()->first();

            if ($ingredient !== null) {
                if ($ingredient->normalized_name !== $normalizedName) {
                    throw ValidationException::withMessages([
                        "ingredients.{$index}.name" => ['Select the matching shared ingredient.'],
                    ]);
                }

                if (in_array($ingredient->id, $ingredientIds, true)) {
                    throw ValidationException::withMessages([
                        "ingredients.{$index}.name" => ['An ingredient can only be added once to a product.'],
                    ]);
                }

                $ingredientIds[] = $ingredient->id;

                continue;
            }

            if (! array_key_exists('stock', $ingredientInput) || $ingredientInput['stock'] === null) {
                throw ValidationException::withMessages([
                    "ingredients.{$index}.stock" => ['Enter the initial stock for this new shared ingredient.'],
                ]);
            }

            $ingredient = InventoryIngredient::query()->create([
                'name' => $name,
                'normalized_name' => $normalizedName,
                'stock_quantity' => $ingredientInput['stock'],
            ]);
            $ingredientIds[] = $ingredient->id;
        }

        $product->ingredients()->sync($ingredientIds);
    }
}
