<?php

use App\Models\Product;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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
});

test('a manager can start a sales shift and sees empty shift totals', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    $this->actingAs($manager)
        ->putJson(route('manager.api.sales-settings.update'), ['business_day_cutoff' => '08:07'])
        ->assertSuccessful();

    $this->actingAs($manager)
        ->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 1000])
        ->assertCreated()
        ->assertJsonPath('shift.opening_float', '1000.00');

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('current_shift.status', 'open')
        ->assertJsonPath('current_shift.opening_float', '1000.00');

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('daily_reports.0.cutoff_time', '08:07');

    $this->assertDatabaseCount('sales_shifts', 1);
});

test('sales stop when no shift is open and a manager cannot start a duplicate shift', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $product = Product::create([
        'name' => 'Chicken Meal',
        'selling_price' => 100,
        'stock_quantity' => 5,
    ]);

    $this->actingAs($manager)
        ->postJson(route('orders.complete'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
        ->assertUnprocessable();

    $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 0])->assertCreated();
    $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 0])->assertUnprocessable();
});

test('closing sales saves exact earnings and starting again resets only the live totals', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $this->actingAs($manager);
    $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 1000])->assertCreated();

    $product = Product::create([
        'name' => 'Beef Tapa',
        'cost_price' => 60,
        'selling_price' => 100,
        'stock_quantity' => 5,
    ]);

    $order = $this->postJson(route('orders.complete'), [
        'table_number' => 1,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertCreated();

    $this->assertDatabaseHas('orders', [
        'order_number' => $order->json('order_number'),
        'sales_shift_id' => SalesShift::query()->value('id'),
    ]);

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('summary.order_count', 0)
        ->assertJsonPath('summary.total_sales', 0);

    $this->postJson(route('orders.settle-table', 1))
        ->assertSuccessful();

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('summary.order_count', 1)
        ->assertJsonPath('summary.items_sold', 2)
        ->assertJsonPath('summary.total_sales', 224)
        ->assertJsonPath('summary.gross_sales', 200)
        ->assertJsonPath('summary.vat_total', 24)
        ->assertJsonPath('summary.cash_sales', 224)
        ->assertJsonPath('products.0.name', 'Beef Tapa')
        ->assertJsonPath('products.0.qtySold', 2)
        ->assertJsonPath('products.0.totalRevenue', 200);

    $this->postJson(route('manager.api.sales-shift.close'))
        ->assertSuccessful()
        ->assertJsonPath('shift.status', 'closed')
        ->assertJsonPath('summary.total_sales', 224)
        ->assertJsonPath('summary.expected_cash_drawer', 1224);

    $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 0])->assertCreated();

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('current_shift.status', 'open')
        ->assertJsonPath('current_shift.opening_float', '0.00')
        ->assertJsonPath('summary.order_count', 1)
        ->assertJsonPath('summary.items_sold', 2)
        ->assertJsonPath('summary.total_sales', 224)
        ->assertJsonPath('daily_reports.1.summary.expected_cash_drawer', 1224)
        ->assertJsonPath('products.0.name', 'Beef Tapa')
        ->assertJsonCount(1, 'products');

    $this->assertDatabaseCount('sales_shifts', 2);
    $this->assertDatabaseHas('sales_shifts', [
        'status' => 'closed',
        'total_sales' => 224,
        'cash_sales' => 224,
        'order_count' => 1,
        'items_sold' => 2,
    ]);
});

test('paid customer advance orders appear in performance analytics', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $this->actingAs($manager);
    $shift = $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 0])
        ->assertCreated()
        ->json('shift');

    $product = Product::create([
        'name' => 'Advance Order Meal',
        'selling_price' => 100,
        'stock_quantity' => 3,
    ]);

    $order = $this->postJson(route('orders.complete'), [
        'table_number' => 2,
        'booking_type' => 'advance-order',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertCreated();

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('summary.order_count', 0)
        ->assertJsonCount(0, 'transactions');

    $this->postJson(route('orders.settle-table', 2))
        ->assertSuccessful();

    $this->assertDatabaseHas('orders', [
        'order_number' => $order->json('order_number'),
        'sales_shift_id' => $shift['id'],
        'status' => 'completed',
    ]);

    $analytics = $this->getJson(route('manager.api.sales-shift.show'))->assertSuccessful();
    expect($analytics->json('business_date'))->toBe(substr($shift['business_date'], 0, 10));
    expect($analytics->json('from'))->toBe(substr($shift['business_date'], 0, 10));

    $analytics
        ->assertSuccessful()
        ->assertJsonPath('current_shift.id', $shift['id'])
        ->assertJsonCount(1, 'daily_reports')
        ->assertJsonPath('daily_reports.0.summary.order_count', 1)
        ->assertJsonPath('summary.order_count', 1)
        ->assertJsonPath('summary.total_sales', 224)
        ->assertJsonPath('products.0.name', 'Advance Order Meal')
        ->assertJsonPath('products.0.qtySold', 2)
        ->assertJsonPath('transactions.0.orderId', $order->json('order_number'))
        ->assertJsonPath('transactions.0.bookingType', 'advance-order')
        ->assertJsonPath('transactions.0.status', 'completed');
});

test('a manager cannot close a shift that still has unpaid table orders', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $this->actingAs($manager);
    $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 100])->assertCreated();

    $product = Product::create([
        'name' => 'Waffle',
        'selling_price' => 50,
        'stock_quantity' => 2,
    ]);

    $this->postJson(route('orders.complete'), [
        'table_number' => 3,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    $this->postJson(route('manager.api.sales-shift.close'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift')
        ->assertJsonPath('errors.shift.0', 'Settle all unpaid database orders before closing the sales day. Pending orders: Table 3.');

    $this->postJson(route('orders.settle-table', 3))->assertSuccessful();

    $this->postJson(route('manager.api.sales-shift.close'))
        ->assertSuccessful()
        ->assertJsonPath('shift.status', 'closed');
});

test('a table can be settled without selecting a payment method', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $this->actingAs($manager);
    $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 0])->assertCreated();

    $product = Product::create([
        'name' => 'Iced Tea',
        'selling_price' => 40,
        'stock_quantity' => 2,
    ]);

    $this->postJson(route('orders.complete'), [
        'table_number' => 4,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated();

    $this->postJson(route('orders.settle-table', 4))
        ->assertSuccessful();

    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertSuccessful()
        ->assertJsonPath('summary.cash_sales', 44.8)
        ->assertJsonPath('summary.gcash_sales', 0)
        ->assertJsonPath('summary.total_sales', 44.8);
});

test('only managers can use sales shift controls', function () {
    $waiter = User::factory()->create(['role' => 'waiter']);

    $this->actingAs($waiter)
        ->getJson(route('manager.api.sales-shift.show'))
        ->assertForbidden();
});

test('a manager can filter sales by business date and update the daily cutoff', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    $this->actingAs($manager)
        ->putJson(route('manager.api.sales-settings.update'), ['business_day_cutoff' => '03:00'])
        ->assertSuccessful()
        ->assertJsonPath('cutoff_time', '03:00');

    $today = now('Asia/Manila')->format('H:i') < '03:00'
        ? now('Asia/Manila')->subDay()->toDateString()
        : now('Asia/Manila')->toDateString();
    $yesterday = Carbon::parse($today)->subDay()->toDateString();

    SalesShift::query()->create([
        'started_by' => $manager->id,
        'business_date' => $yesterday,
        'started_at' => now()->subDay(),
        'status' => 'closed',
    ]);

    $this->getJson(route('manager.api.sales-shift.show', ['preset' => 'yesterday']))
        ->assertSuccessful()
        ->assertJsonPath('cutoff_time', '03:00')
        ->assertJsonPath('from', $yesterday)
        ->assertJsonPath('to', $yesterday)
        ->assertJsonCount(1, 'daily_reports');
});

test('sales day and daily shift report use the same business date before the cutoff', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    Carbon::setTestNow(Carbon::parse('2026-11-21 17:04:00', 'UTC'));

    try {
        $this->actingAs($manager)
            ->putJson(route('manager.api.sales-settings.update'), ['business_day_cutoff' => '03:00'])
            ->assertSuccessful();

        $shift = $this->postJson(route('manager.api.sales-shift.start'), ['opening_float' => 0])
            ->assertCreated()
            ->json('shift');

        $this->getJson(route('manager.api.sales-shift.show'))
            ->assertSuccessful()
            ->assertJsonPath('business_date', '2026-11-21')
            ->assertJsonPath('philippines_date', '2026-11-22')
            ->assertJsonPath('current_shift.id', $shift['id'])
            ->assertJsonPath('daily_reports.0.business_date', '2026-11-21');
    } finally {
        Carbon::setTestNow();
    }
});

test('guest users cannot use sales shift controls', function () {
    $this->getJson(route('manager.api.sales-shift.show'))
        ->assertUnauthorized();
});
