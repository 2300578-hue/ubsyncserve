<?php

use App\Mail\AccountVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('root loads the login page without redirecting', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertViewIs('login.login');
});

test('authenticated manager can log out and is redirected to login', function () {
    $manager = User::factory()->create(['role' => 'manager']);

    $this->actingAs($manager)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
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

test('an expired signup code can be resent and the new code is valid for one minute', function () {
    Mail::fake();

    $email = 'new-user@example.com';
    $initialResponse = $this->postJson(route('create-account.send-code'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'role' => 'waiter',
        'password' => 'correct-password',
        'password_confirmation' => 'correct-password',
    ])->assertSuccessful();

    expect(now()->diffInSeconds(Carbon::parse($initialResponse->json('expires_at'))))->toBeGreaterThanOrEqual(59);

    $codes = [];
    Mail::assertSent(AccountVerificationCode::class, function (AccountVerificationCode $mail) use (&$codes, $email): bool {
        $codes[] = $mail->code;

        return $mail->hasTo($email);
    });

    $this->travel(61)->seconds();

    $this->postJson(route('create-account.verify-code'), [
        'email' => $email,
        'code' => $codes[0],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This verification code has expired. Please request a new code.');

    $resendResponse = $this->postJson(route('create-account.resend-code'), [
        'email' => $email,
    ])->assertSuccessful();

    Mail::assertSent(AccountVerificationCode::class, function (AccountVerificationCode $mail) use (&$codes, $email): bool {
        if ($mail->hasTo($email) && ! in_array($mail->code, $codes, true)) {
            $codes[] = $mail->code;
        }

        return $mail->hasTo($email);
    }, 2);

    $pending = DB::table('pending_account_verifications')->where('email', $email)->first();

    expect(count($codes))->toBe(2)
        ->and(Hash::check($codes[1], $pending->otp_hash))->toBeTrue()
        ->and(Carbon::parse($resendResponse->json('expires_at'))->timestamp)->toBe(Carbon::parse($pending->otp_expires_at)->timestamp)
        ->and(Carbon::parse($pending->updated_at)->diffInSeconds(Carbon::parse($pending->otp_expires_at)))->toEqual(60);

    $this->postJson(route('create-account.verify-code'), [
        'email' => $email,
        'code' => $codes[0],
    ])->assertUnprocessable();

    $this->postJson(route('create-account.verify-code'), [
        'email' => $email,
        'code' => $codes[1],
    ])->assertSuccessful();
});

test('a signup code cannot be resent before it expires', function () {
    Mail::fake();

    $email = 'new-user@example.com';
    $this->postJson(route('create-account.send-code'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'role' => 'waiter',
        'password' => 'correct-password',
        'password_confirmation' => 'correct-password',
    ])->assertSuccessful();

    $this->postJson(route('create-account.resend-code'), [
        'email' => $email,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You can request a new code after the current code expires.');

    Mail::assertSent(AccountVerificationCode::class, 1);
});
