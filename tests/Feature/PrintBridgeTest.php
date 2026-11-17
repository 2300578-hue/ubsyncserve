<?php

use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $manager = User::factory()->create(['role' => 'manager']);

    SalesShift::query()->create([
        'started_by' => $manager->id,
        'business_date' => now('Asia/Manila')->toDateString(),
        'started_at' => now(),
        'status' => 'open',
    ]);
});

test('staff table order submission waits for confirm print before queuing a receipt', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $product = Product::create([
        'name' => 'Rice Bowl',
        'selling_price' => 100,
        'stock_quantity' => 3,
    ]);

    $this->actingAs($waiter)->postJson(route('orders.complete'), [
        'table_number' => 4,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('print_job_queued', false);

    $this->assertDatabaseCount('print_jobs', 0);
});

test('retrying the same staff order request does not create another order or consume stock twice', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $product = Product::create([
        'name' => 'Rice Bowl',
        'selling_price' => 100,
        'stock_quantity' => 3,
    ]);
    $request = [
        'idempotency_key' => 'a73f1b1f-e2d8-4877-9c2c-7f6a9c1efb24',
        'table_number' => 4,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ];

    $first = $this->actingAs($waiter)->postJson(route('orders.complete'), $request)->assertCreated();
    $second = $this->postJson(route('orders.complete'), $request)->assertSuccessful();

    expect($second->json('order_id'))->toBe($first->json('order_id'));
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'stock_quantity' => 2,
    ]);
});

test('guest checkout orders do not enqueue staff receipts', function (): void {
    $product = Product::create([
        'name' => 'Rice Bowl',
        'selling_price' => 100,
        'stock_quantity' => 3,
    ]);

    $this->postJson(route('orders.complete'), [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('print_job_queued', false);

    $this->assertDatabaseCount('print_jobs', 0);
});

test('one bridge claims and acknowledges each job only once', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $currentShiftId = SalesShift::query()->where('status', 'open')->value('id');
    $job = PrintJob::query()->create([
        'job_type' => 'table_receipt',
        'dedupe_key' => 'test-order-receipt',
        'payload' => ['title' => 'TABLE RECEIPT', 'sales_shift_id' => $currentShiftId, 'items' => []],
        'status' => 'pending',
    ]);

    $claim = $this->actingAs($waiter)
        ->getJson(route('print-jobs.next'))
        ->assertSuccessful()
        ->assertJsonPath('id', $job->id);

    $this->getJson(route('print-jobs.next'))->assertNoContent();

    $this->postJson(route('print-jobs.printed', $job), [
        'claim_token' => $claim->json('claim_token'),
    ])->assertSuccessful()
        ->assertJsonPath('status', 'printed');

    $this->assertDatabaseHas('print_jobs', [
        'id' => $job->id,
        'status' => 'printed',
        'claim_token' => null,
    ]);
});

test('the bridge skips pending individual order receipts from the old workflow', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $job = PrintJob::query()->create([
        'job_type' => 'order_receipt',
        'dedupe_key' => 'legacy-order-receipt',
        'payload' => ['title' => 'ORDER RECEIPT', 'items' => []],
        'status' => 'pending',
    ]);

    $this->actingAs($waiter)->getJson(route('print-jobs.next'))->assertNoContent();

    $this->assertDatabaseHas('print_jobs', [
        'id' => $job->id,
        'status' => 'failed',
    ]);
});

test('staff can check a print job status after confirmation', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $job = PrintJob::query()->create([
        'job_type' => 'table_receipt',
        'dedupe_key' => 'status-order-receipt',
        'payload' => ['title' => 'TABLE RECEIPT', 'items' => []],
        'status' => 'processing',
    ]);

    $this->actingAs($waiter)
        ->getJson(route('print-jobs.show', $job))
        ->assertSuccessful()
        ->assertJsonPath('status', 'processing');
});

test('a different bridge cannot acknowledge a claimed job', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $currentShiftId = SalesShift::query()->where('status', 'open')->value('id');
    $job = PrintJob::query()->create([
        'job_type' => 'table_receipt',
        'dedupe_key' => 'claimed-order-receipt',
        'payload' => ['title' => 'TABLE RECEIPT', 'sales_shift_id' => $currentShiftId, 'items' => []],
        'status' => 'pending',
    ]);

    $this->actingAs($waiter)->getJson(route('print-jobs.next'))->assertSuccessful();

    $this->postJson(route('print-jobs.printed', $job), [
        'claim_token' => str_repeat('0', 64),
    ])->assertConflict();

    $this->assertDatabaseHas('print_jobs', [
        'id' => $job->id,
        'status' => 'processing',
    ]);
});

test('table receipt requests deduplicate the same pending order set', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $currentShift = SalesShift::query()->where('status', 'open')->firstOrFail();
    $order = Order::query()->create([
        'order_number' => 'ORD-PRINT-1',
        'table_number' => '5',
        'sales_shift_id' => $currentShift->id,
        'status' => 'pending',
        'total_amount' => 105,
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'vat_amount' => 5,
    ]);
    $order->items()->create([
        'product_name' => 'Rice Bowl',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $first = $this->actingAs($waiter)
        ->postJson(route('print-jobs.tables', 5))
        ->assertCreated();
    $second = $this->postJson(route('print-jobs.tables', 5))
        ->assertSuccessful()
        ->assertJsonPath('created', false);

    expect($second->json('job_id'))->toBe($first->json('job_id'));
    $this->assertDatabaseCount('print_jobs', 1);

    PrintJob::query()->findOrFail($first->json('job_id'))->update(['status' => 'failed']);
    $retry = $this->postJson(route('print-jobs.tables', 5))
        ->assertSuccessful()
        ->assertJsonPath('created', false);

    expect($retry->json('job_id'))->toBe($first->json('job_id'));
    $this->assertDatabaseHas('print_jobs', [
        'id' => $first->json('job_id'),
        'status' => 'pending',
    ]);
});

test('table receipts include only pending orders from the currently open sales shift', function (): void {
    $waiter = User::factory()->create(['role' => 'waiter']);
    $manager = User::factory()->create(['role' => 'manager']);
    $currentShift = SalesShift::query()->where('status', 'open')->firstOrFail();
    $previousShift = SalesShift::query()->create([
        'started_by' => $manager->id,
        'business_date' => now('Asia/Manila')->subDay()->toDateString(),
        'started_at' => now()->subDay(),
        'ended_at' => now()->subDay()->addHours(8),
        'status' => 'closed',
    ]);

    $previousOrder = Order::query()->create([
        'order_number' => 'ORD-OLD-SHIFT',
        'table_number' => '6',
        'sales_shift_id' => $previousShift->id,
        'status' => 'pending',
        'total_amount' => 105,
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'vat_amount' => 5,
    ]);
    $previousOrder->items()->create([
        'product_name' => 'Old Rice Bowl',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $currentOrder = Order::query()->create([
        'order_number' => 'ORD-CURRENT-SHIFT',
        'table_number' => '6',
        'sales_shift_id' => $currentShift->id,
        'status' => 'pending',
        'total_amount' => 105,
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'vat_amount' => 5,
    ]);
    $currentOrder->items()->create([
        'product_name' => 'Current Rice Bowl',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $response = $this->actingAs($waiter)
        ->postJson(route('print-jobs.tables', 6))
        ->assertCreated();
    $job = PrintJob::query()->findOrFail($response->json('job_id'));

    expect($job->payload['order_numbers'])->toBe(['ORD-CURRENT-SHIFT'])
        ->and($job->payload['sales_shift_id'])->toBe($currentShift->id)
        ->and($job->payload['items'])->toHaveCount(1)
        ->and($job->payload['items'][0]['name'])->toBe('Current Rice Bowl');
});
