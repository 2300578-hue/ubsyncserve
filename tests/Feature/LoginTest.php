<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('root loads the login page without redirecting', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertViewIs('login.login');
});

test('csrf endpoint returns a fresh token without cacheable headers', function () {
    $this->getJson(route('csrf-token'))
        ->assertSuccessful()
        ->assertJsonStructure(['token'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('invalid login credentials return a generic email and password message', function () {
    $this->postJson(route('login.post'), [
        'email' => 'unknown@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid email or password.');
});

test('only the configured admin email can log in as a manager', function () {
    config(['admin.email' => 'manager@example.com']);

    $manager = User::factory()->create([
        'email' => 'another-manager@example.com',
        'password' => 'correct-password',
        'role' => 'manager',
    ]);

    $response = $this->postJson(route('login.post'), [
        'email' => $manager->email,
        'password' => 'correct-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid email or password.');

    $this->assertGuest();
});

test('waiter accounts can still log in when the admin email is configured', function () {
    config(['admin.email' => 'manager@example.com']);

    $waiter = User::factory()->create([
        'email' => 'staff@example.com',
        'password' => 'correct-password',
        'role' => 'waiter',
    ]);

    $this->postJson(route('login.post'), [
        'email' => $waiter->email,
        'password' => 'correct-password',
    ])
        ->assertSuccessful()
        ->assertJsonPath('redirect', route('waiter.dashboard'));
});

test('manager registration is limited to the configured admin email', function () {
    config(['admin.email' => 'manager@example.com']);

    $response = $this->postJson(route('create-account.send-code'), [
        'first_name' => 'Another',
        'last_name' => 'Manager',
        'email' => 'another-manager@example.com',
        'role' => 'manager',
        'password' => 'valid-password',
        'password_confirmation' => 'valid-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Manager registration is restricted to the configured admin email.');

    $this->assertDatabaseMissing('pending_account_verifications', [
        'email' => 'another-manager@example.com',
    ]);
});
