<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('database seeder creates an idempotent verified main admin who can log in', function () {
    $password = 'a-secure-test-password-2026';
    config([
        'admin.email' => '2300578@ub.edu.ph',
        'admin.name' => 'UB Sync Admin',
        'admin.password' => $password,
    ]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', '2300578@ub.edu.ph')->firstOrFail();

    expect(User::query()->count())->toBe(1)
        ->and($admin->role)->toBe('manager')
        ->and($admin->email_verified_at)->not->toBeNull();

    $this->postJson(route('login.post'), [
        'email' => $admin->email,
        'password' => $password,
    ])
        ->assertSuccessful()
        ->assertJsonPath('redirect', route('manager.dashboard'));
});

test('database seeder refuses to create the admin without a strong configured password', function () {
    config(['admin.password' => null]);

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_PASSWORD');

    expect(User::query()->count())->toBe(0);
});
