<?php

use App\Mail\AccountVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('root loads the login page without redirecting', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertViewIs('login.login');
});

test('invalid login credentials return a generic email and password message', function () {
    $this->postJson(route('login.post'), [
        'email' => 'unknown@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid email or password.');
});

test('verified staff and manager accounts can log in to their role dashboard', function (string $role, string $dashboard) {
    Mail::fake();

    $email = "{$role}@example.com";
    $password = 'correct-password';

    $this->postJson(route('create-account.send-code'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'role' => $role,
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertSuccessful();

    $code = null;
    Mail::assertSent(AccountVerificationCode::class, function (AccountVerificationCode $mail) use ($email, &$code): bool {
        $code = $mail->code;

        return $mail->hasTo($email);
    });

    $this->postJson(route('create-account.verify-code'), [
        'email' => $email,
        'code' => $code,
    ])->assertSuccessful();

    $user = User::query()->where('email', $email)->firstOrFail();
    expect($user->role)->toBe($role);

    $this->postJson(route('login.post'), [
        'email' => $email,
        'password' => $password,
    ])
        ->assertSuccessful()
        ->assertJsonPath('redirect', route($dashboard));
})->with([
    'staff' => ['waiter', 'waiter.dashboard'],
    'manager' => ['manager', 'manager.dashboard'],
]);
