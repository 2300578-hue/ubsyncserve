<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('all floorplan statuses can be synchronized', function (string $status) {
    $this->putJson(route('tables.update'), [
        'tables' => [
            ['id' => 3, 'status' => $status],
        ],
    ])->assertSuccessful();

    $this->getJson(route('tables.index'))
        ->assertSuccessful()
        ->assertJsonPath('2.status', $status);
})->with([
    'occupied' => 'occupied',
    'advance order' => 'reserved-advance',
    'reserved table' => 'reserved-booking',
    'paid' => 'paid',
]);
