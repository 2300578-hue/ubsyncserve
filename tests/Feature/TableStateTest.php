<?php

use App\Models\Order;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('csrf token can be refreshed for an expired browser token', function () {
    $this->getJson(route('csrf-token'))
        ->assertSuccessful()
        ->assertJsonStructure(['token'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('table statuses are stored centrally and returned in the floorplan format', function () {
    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonCount(15)
        ->assertJsonPath('0.status', 'available');

    $this->putJson(route('tables.update'), [
        'tables' => [
            [
                'id' => 2,
                'status' => 'occupied',
                'isPaid' => false,
                'adults' => 2,
                'children' => 1,
                'guests' => 3,
                'bill' => 450,
                'orders' => [['name' => 'Dinner', 'qty' => 1, 'price' => 450]],
                'startTime' => now()->toISOString(),
            ],
        ],
    ])->assertSuccessful();

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('1.status', 'occupied')
        ->assertJsonPath('1.guests', 3)
        ->assertJsonPath('1.orders.0.name', 'Dinner');
});

test('table status updates reject invalid statuses and duplicate table numbers', function () {
    $this->putJson(route('tables.update'), [
        'tables' => [
            ['id' => 1, 'status' => 'closed'],
        ],
    ])->assertUnprocessable();

    $this->putJson(route('tables.update'), [
        'tables' => [
            ['id' => 1, 'status' => 'occupied'],
            ['id' => 1, 'status' => 'available'],
        ],
    ])->assertUnprocessable();
});

test('empty advance selections are reset instead of showing zero guests', function () {
    $this->getJson(route('tables.index'))->assertSuccessful();

    DB::table('restaurant_tables')
        ->where('table_number', 1)
        ->update([
            'status' => 'reserved-advance',
            'adults' => 0,
            'children' => 0,
            'guests' => 0,
            'bill' => 0,
            'orders' => json_encode([]),
        ]);

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('0.status', 'available')
        ->assertJsonPath('0.guests', 0);
});

test('an empty advance selection cannot be saved as reserved', function () {
    $this->putJson(route('tables.update'), [
        'tables' => [
            [
                'id' => 1,
                'status' => 'reserved-advance',
                'adults' => 0,
                'children' => 0,
                'guests' => 0,
                'orders' => [],
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('tables');
});

test('paid table can be cleared with a single table-specific request', function () {
    $this->actingAs(User::factory()->create(['role' => 'manager']));
    $this->getJson(route('tables.index'))->assertSuccessful();
    $this->putJson(route('tables.update'), [
        'tables' => [
            [
                'id' => 1,
                'status' => 'paid',
                'isPaid' => true,
                'adults' => 2,
                'children' => 1,
                'guests' => 3,
                'bill' => 450,
                'orders' => [['name' => 'Dinner', 'qty' => 1, 'price' => 450]],
                'startTime' => now()->toISOString(),
            ],
        ],
    ])->assertSuccessful();

    $this->postJson(route('tables.clear', ['tableNumber' => 1]))
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('0.status', 'available')
        ->assertJsonPath('0.isPaid', false)
        ->assertJsonPath('0.guests', 0)
        ->assertJsonPath('0.bill', 0)
        ->assertJsonPath('0.orders', [])
        ->assertJsonPath('0.startTime', null);
});

test('manager can clear an occupied table when it has no pending database orders', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $this->actingAs($manager)->getJson(route('tables.index'))->assertSuccessful();

    $this->putJson(route('tables.update'), [
        'tables' => [
            [
                'id' => 1,
                'status' => 'occupied',
                'guests' => 1,
                'orders' => [['name' => 'Stale order', 'qty' => 1, 'price' => 150]],
            ],
        ],
    ])->assertSuccessful();

    $this->postJson(route('tables.clear', ['tableNumber' => 1]))
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('0.status', 'available')
        ->assertJsonPath('0.guests', 0)
        ->assertJsonPath('0.orders', []);
});

test('occupied table cannot be cleared while a database order is pending', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $shift = SalesShift::query()->create([
        'started_by' => $manager->id,
        'business_date' => now()->toDateString(),
        'started_at' => now(),
        'status' => 'open',
    ]);

    Order::query()->create([
        'order_number' => 'ORD-TABLE-1-PENDING',
        'table_number' => '1',
        'sales_shift_id' => $shift->id,
        'status' => 'pending',
        'total_amount' => 150,
        'subtotal_amount' => 150,
        'discount_amount' => 0,
        'vat_amount' => 0,
    ]);

    $this->actingAs($manager)->getJson(route('tables.index'))->assertSuccessful();
    $this->putJson(route('tables.update'), [
        'tables' => [
            [
                'id' => 1,
                'status' => 'occupied',
                'guests' => 1,
                'orders' => [['name' => 'Dinner', 'qty' => 1, 'price' => 150]],
            ],
        ],
    ])->assertSuccessful();

    $this->postJson(route('tables.clear', ['tableNumber' => 1]))
        ->assertConflict()
        ->assertJsonPath('message', 'Cannot clear this table while pending orders remain.');

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('0.status', 'occupied');
});

test('waiters cannot clear occupied tables', function () {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $this->actingAs($waiter)->getJson(route('tables.index'))->assertSuccessful();
    $this->putJson(route('tables.update'), [
        'tables' => [
            ['id' => 1, 'status' => 'occupied', 'guests' => 1],
        ],
    ])->assertSuccessful();

    $this->postJson(route('tables.clear', ['tableNumber' => 1]))
        ->assertForbidden();

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('0.status', 'occupied');
});

test('only managers can clear a paid table', function () {
    $this->getJson(route('tables.index'))->assertSuccessful();
    $this->putJson(route('tables.update'), [
        'tables' => [
            [
                'id' => 1,
                'status' => 'paid',
                'isPaid' => true,
                'orders' => [['name' => 'Dinner', 'qty' => 1, 'price' => 450]],
            ],
        ],
    ])->assertSuccessful();

    $waiter = User::factory()->create(['role' => 'waiter']);

    $this->actingAs($waiter)
        ->postJson(route('tables.clear', ['tableNumber' => 1]))
        ->assertForbidden();

    $this->actingAs($waiter)
        ->putJson(route('tables.update'), [
            'tables' => [
                ['id' => 1, 'status' => 'available', 'isPaid' => false],
            ],
        ])
        ->assertForbidden();

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('0.status', 'paid')
        ->assertJsonPath('0.isPaid', true);
});

test('empty tables can still be cleared from customer and waiter flows', function () {
    $this->getJson(route('tables.index'))->assertSuccessful();

    $this->postJson(route('tables.clear', ['tableNumber' => 1]))
        ->assertSuccessful()
        ->assertJsonPath('success', true);
});

test('all floorplan statuses can be synchronized', function (string $status, array $attributes) {
    $this->putJson(route('tables.update'), [
        'tables' => [
            ['id' => 3, 'status' => $status, ...$attributes],
        ],
    ])->assertSuccessful();

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('2.status', $status);
})->with([
    'occupied' => ['occupied', []],
    'advance order' => ['reserved-advance', ['guests' => 1]],
    'reserved table' => ['reserved-booking', []],
    'paid' => ['paid', []],
]);
