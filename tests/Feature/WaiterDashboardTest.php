<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to login when opening the waiter dashboard', function () {
    $this->get(route('waiter.dashboard'))
        ->assertRedirect(route('login'));
});

test('authenticated waiters can open the waiter dashboard', function () {
    $waiter = User::factory()->create(['role' => 'waiter']);

    $this->actingAs($waiter)
        ->get(route('waiter.dashboard'))
        ->assertSuccessful();
});
