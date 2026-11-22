<?php

use App\Models\InventoryIngredient;
use App\Models\Product;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $manager = User::factory()->create(['role' => 'manager']);

    DB::table('restaurant_tables')->insert(
        collect(range(1, 15))
            ->map(fn (int $tableNumber): array => [
                'table_number' => $tableNumber,
                'is_active' => true,
                'status' => 'available',
                'orders' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all()
    );

    SalesShift::query()->create([
        'started_by' => $manager->id,
        'business_date' => now('Asia/Manila')->toDateString(),
        'started_at' => now(),
        'status' => 'open',
    ]);
});

test('waiter orders are saved and decrement product stock', function () {
    $product = Product::create([
        'name' => 'Burger Steak',
        'category' => 'Lunch',
        'selling_price' => 100,
        'stock_quantity' => 5,
        'add_ons' => [['name' => 'Cheese', 'price' => 15]],
    ]);

    $response = $this->postJson(route('orders.complete'), [
        'table_number' => '4',
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'add_ons' => [['name' => 'Cheese', 'price' => 0]],
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('total_amount', 257.6)
        ->assertJsonPath('subtotal_amount', 230)
        ->assertJsonPath('discount_amount', 0)
        ->assertJsonPath('vat_amount', 27.6)
        ->assertJsonPath('grand_total_amount', 257.6);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'stock_quantity' => 3,
    ]);
    $this->assertDatabaseHas('orders', [
        'order_number' => $response->json('order_number'),
        'table_number' => '4',
        'total_amount' => 257.6,
    ]);
    $this->assertDatabaseHas('order_items', [
        'product_id' => $product->id,
        'product_name' => 'Burger Steak',
        'quantity' => 2,
        'unit_price' => 115,
    ]);
    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 4,
        'status' => 'occupied',
        'bill' => 230,
    ]);

    $tableState = DB::table('restaurant_tables')->where('table_number', 4)->first();
    expect(json_decode($tableState->orders, true))
        ->toBe([[
            'name' => 'Burger Steak',
            'qty' => 2,
            'price' => 115,
            'addonName' => 'Cheese',
            'discountType' => null,
            'discountAmount' => 0,
            'vatAmount' => 27.6,
        ]]);
});

test('regular orders add twelve percent VAT to the subtotal', function () {
    $product = Product::create([
        'name' => 'Family Meal',
        'category' => 'Lunch',
        'selling_price' => 250,
        'stock_quantity' => 2,
    ]);

    $response = $this->postJson(route('orders.complete'), [
        'table_number' => 5,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('subtotal_amount', 250)
        ->assertJsonPath('vat_amount', 30)
        ->assertJsonPath('total_amount', 280);
});

test('a manager-added table above the old limit accepts orders', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $product = Product::create([
        'name' => 'Chicken Meal',
        'selling_price' => 150,
        'stock_quantity' => 2,
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.api.tables.store'))
        ->assertCreated()
        ->assertJsonPath('id', 16);

    $this->postJson(route('orders.complete'), [
        'table_number' => 16,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 16,
        'status' => 'occupied',
    ]);
});

test('orders cannot be placed at a manager-removed table', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $product = Product::create([
        'name' => 'Chicken Meal',
        'selling_price' => 150,
        'stock_quantity' => 2,
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.api.tables.store'))
        ->assertCreated();

    $this->deleteJson(route('manager.api.tables.destroy', ['tableNumber' => 16]))
        ->assertSuccessful();

    $this->postJson(route('orders.complete'), [
        'table_number' => 16,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('table_number');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'stock_quantity' => 2,
    ]);
});

test('orders deduct shared ingredient stock across every linked product', function () {
    $egg = InventoryIngredient::query()->create([
        'name' => 'Egg',
        'normalized_name' => 'egg',
        'stock_quantity' => 10,
    ]);
    $patty = InventoryIngredient::query()->create([
        'name' => 'Patty',
        'normalized_name' => 'patty',
        'stock_quantity' => 8,
    ]);
    $cucumber = InventoryIngredient::query()->create([
        'name' => 'Cucumber',
        'normalized_name' => 'cucumber',
        'stock_quantity' => 10,
    ]);

    $tapsilog = Product::create([
        'name' => 'Tapsilog',
        'selling_price' => 100,
        'stock_quantity' => 8,
    ]);
    $tapsilog->ingredients()->attach([$egg->id, $patty->id]);
    $tapsilog->refreshStockQuantity();

    $hatsilog = Product::create([
        'name' => 'Hatsilog',
        'selling_price' => 90,
        'stock_quantity' => 10,
    ]);
    $hatsilog->ingredients()->attach([$egg->id, $cucumber->id]);
    $hatsilog->refreshStockQuantity();

    $this->postJson(route('orders.complete'), [
        'items' => [['product_id' => $tapsilog->id, 'quantity' => 3]],
    ])->assertCreated();

    $this->assertDatabaseHas('products', [
        'id' => $tapsilog->id,
        'stock_quantity' => 5,
    ]);
    $this->assertDatabaseHas('products', [
        'id' => $hatsilog->id,
        'stock_quantity' => 7,
    ]);
    expect($egg->fresh()->stock_quantity)->toBe(7)
        ->and($patty->fresh()->stock_quantity)->toBe(5)
        ->and($cucumber->fresh()->stock_quantity)->toBe(10);
});

test('an order exceeding ingredient-based product stock is not saved or deducted', function () {
    $cucumber = InventoryIngredient::query()->create([
        'name' => 'Cucumber',
        'normalized_name' => 'cucumber',
        'stock_quantity' => 10,
    ]);
    $egg = InventoryIngredient::query()->create([
        'name' => 'Egg',
        'normalized_name' => 'egg',
        'stock_quantity' => 8,
    ]);
    $product = Product::create([
        'name' => 'Cucumber Egg Salad',
        'selling_price' => 100,
        'stock_quantity' => 8,
    ]);
    $product->ingredients()->attach([$cucumber->id, $egg->id]);
    $product->refreshStockQuantity();

    $this->postJson(route('orders.complete'), [
        'items' => [['product_id' => $product->id, 'quantity' => 9]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('items');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'stock_quantity' => 8,
    ]);
    expect($cucumber->fresh()->stock_quantity)->toBe(10)
        ->and($egg->fresh()->stock_quantity)->toBe(8);
    $this->assertDatabaseCount('orders', 0);
});

test('orders can still be submitted before the optional booking type migration is deployed', function () {
    Schema::table('orders', function (Blueprint $table): void {
        $table->dropColumn('booking_type');
    });

    $product = Product::create([
        'name' => 'Advance Meal',
        'selling_price' => 100,
        'stock_quantity' => 2,
    ]);

    $this->postJson(route('orders.complete'), [
        'table_number' => 1,
        'booking_type' => 'advance-order',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    expect(Schema::hasColumn('orders', 'booking_type'))->toBeFalse();
});

test('advance order reserves its table only when the order is submitted and guests are known', function () {
    $product = Product::create([
        'name' => 'Advance Meal',
        'selling_price' => 100,
        'stock_quantity' => 3,
    ]);

    $response = $this->postJson(route('orders.complete'), [
        'table_number' => 2,
        'booking_type' => 'advance-order',
        'adults' => 2,
        'children' => 1,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('payment_status', 'pending');

    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 2,
        'status' => 'reserved-advance',
        'adults' => 2,
        'children' => 1,
        'guests' => 3,
    ]);

    $this->postJson(route('orders.complete'), [
        'table_number' => 2,
        'booking_type' => 'advance-order',
        'adults' => 2,
        'children' => 1,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('table_number');

    $this->assertDatabaseHas('orders', [
        'order_number' => $response->json('order_number'),
        'status' => 'pending',
    ]);
    $this->assertDatabaseCount('orders', 1);
});

test('advance orders are marked paid automatically in the local environment', function () {
    config(['app.env' => 'local']);

    $product = Product::create([
        'name' => 'Local Test Meal',
        'selling_price' => 100,
        'stock_quantity' => 2,
    ]);

    $response = $this->postJson(route('orders.complete'), [
        'table_number' => 5,
        'booking_type' => 'advance-order',
        'payment_method' => 'gcash',
        'adults' => 2,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('payment_status', 'paid');

    $order = DB::table('orders')->where('order_number', $response->json('order_number'))->first();

    expect($order->status)->toBe('completed')
        ->and($order->paid_at)->not->toBeNull();

    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 5,
        'status' => 'paid',
        'is_paid' => true,
    ]);
});

test('advance order without guest details does not create an empty advance reservation', function () {
    $product = Product::create([
        'name' => 'Advance Meal',
        'selling_price' => 100,
        'stock_quantity' => 2,
    ]);

    $this->postJson(route('orders.complete'), [
        'table_number' => 3,
        'booking_type' => 'advance-order',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 3,
        'status' => 'occupied',
        'guests' => 0,
    ]);
});

test('checkout payment choice stays pending and is preserved when staff confirms payment', function () {
    $product = Product::create([
        'name' => 'PayMaya Meal',
        'selling_price' => 100,
        'stock_quantity' => 2,
    ]);

    $response = $this->postJson(route('orders.complete'), [
        'table_number' => 1,
        'payment_method' => 'paymaya',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    $this->assertDatabaseHas('orders', [
        'order_number' => $response->json('order_number'),
        'payment_method' => 'paymaya',
        'status' => 'pending',
        'paid_at' => null,
    ]);

    $manager = User::query()->where('role', 'manager')->firstOrFail();
    $this->actingAs($manager)
        ->postJson(route('orders.settle-table', 1))
        ->assertSuccessful();

    $this->assertDatabaseHas('orders', [
        'order_number' => $response->json('order_number'),
        'payment_method' => 'paymaya',
        'status' => 'completed',
    ]);
});

test('senior and pwd discounts apply immediately without an ID number', function (string $discountType) {
    $product = Product::create([
        'name' => 'Rice Bowl',
        'selling_price' => 100,
        'stock_quantity' => 5,
    ]);

    $response = $this->postJson(route('orders.complete'), [
        'table_number' => '4',
        'discount_type' => $discountType,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('subtotal_amount', 100)
        ->assertJsonPath('discount_amount', 20)
        ->assertJsonPath('vat_amount', 0)
        ->assertJsonPath('grand_total_amount', 80)
        ->assertJsonPath('total_amount', 80);

    $this->assertDatabaseHas('orders', [
        'order_number' => $response->json('order_number'),
        'total_amount' => 80,
    ]);

    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 4,
        'bill' => 80,
    ]);

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('3.bill', 80)
        ->assertJsonPath('3.orders.0.price', 100)
        ->assertJsonPath('3.orders.0.discountType', $discountType)
        ->assertJsonPath('3.orders.0.discountAmount', 20);
})->with(['senior', 'pwd']);

test('orders store no separate discount or ID fields', function () {
    foreach ([
        'subtotal_amount',
        'discount_type',
        'discount_id_image_path',
        'discount_amount',
        'vat_amount',
        'grand_total_amount',
    ] as $column) {
        expect(Schema::hasColumn('orders', $column))->toBeFalse();
    }
});

test('an order that exceeds available stock is not saved', function () {
    $product = Product::create([
        'name' => 'Beef Tapa',
        'selling_price' => 120,
        'stock_quantity' => 3,
    ]);

    $this->postJson(route('orders.complete'), [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ])->assertUnprocessable();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'stock_quantity' => 3,
    ]);
    $this->assertDatabaseCount('orders', 0);
});

test('advance orders keep their reserved status and guest count when sent to stations', function () {
    $product = Product::create([
        'name' => 'Chicken Meal',
        'selling_price' => 150,
        'stock_quantity' => 3,
    ]);

    DB::table('restaurant_tables')
        ->where('table_number', 6)
        ->update([
            'status' => 'reserved-advance',
            'adults' => 2,
            'children' => 2,
            'guests' => 4,
            'bill' => 300,
            'orders' => json_encode([]),
        ]);

    $this->postJson(route('orders.complete'), [
        'table_number' => '6',
        'adults' => 0,
        'children' => 0,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    $this->assertDatabaseHas('restaurant_tables', [
        'table_number' => 6,
        'status' => 'reserved-advance',
        'guests' => 4,
        'bill' => 450,
    ]);
});

test('the first catalog request creates products for every food image when inventory is empty', function () {
    $this->getJson(route('products.index'))
        ->assertSuccessful()
        ->assertJsonCount(9);

    $this->assertDatabaseCount('products', 9);
});
