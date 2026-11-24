<?php

namespace App\Http\Controllers;

use App\Mail\AccountVerificationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AccountRegistrationController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['manager', 'waiter'])],
            'password' => ['required', 'string', 'min:4', 'same:password_confirmation'],
            'password_confirmation' => ['required', 'string'],
        ]);

        if (User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'An account with this email already exists.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinute();

        DB::table('pending_account_verifications')->updateOrInsert(
            ['email' => $validated['email']],
            [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'role' => $validated['role'],
                'password' => Hash::make($validated['password']),
                'otp_hash' => Hash::make($code),
                'otp_expires_at' => $expiresAt,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Mail::to($validated['email'])->send(new AccountVerificationCode(
            $code,
            $validated['first_name'],
        ));

        return response()->json([
            'message' => 'A 6-digit verification code has been sent to your email address.',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function resendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $pending = DB::table('pending_account_verifications')
            ->where('email', $validated['email'])
            ->first();

        if (! $pending) {
            return response()->json([
                'message' => 'Your verification request could not be found. Please create your account again.',
            ], 422);
        }

        if (now()->lessThan($pending->otp_expires_at)) {
            return response()->json([
                'message' => 'You can request a new code after the current code expires.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinute();

        DB::table('pending_account_verifications')
            ->where('id', $pending->id)
            ->update([
                'otp_hash' => Hash::make($code),
                'otp_expires_at' => $expiresAt,
                'updated_at' => now(),
            ]);

        Mail::to($pending->email)->send(new AccountVerificationCode(
            $code,
            $pending->first_name,
        ));

        return response()->json([
            'message' => 'A new 6-digit verification code has been sent to your email address.',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $pending = DB::table('pending_account_verifications')
            ->where('email', $validated['email'])
            ->first();

        if (! $pending) {
            return response()->json([
                'message' => 'Your verification request could not be found. Please create your account again.',
            ], 422);
        }

        if (now()->greaterThanOrEqualTo($pending->otp_expires_at)) {
            return response()->json([
                'message' => 'This verification code has expired. Please request a new code.',
            ], 422);
        }

        if (! Hash::check($validated['code'], $pending->otp_hash)) {
            return response()->json([
                'message' => 'The verification code is incorrect.',
            ], 422);
        }

        DB::transaction(function () use ($pending): void {
            User::create([
                'name' => trim($pending->first_name.' '.$pending->last_name),
                'email' => $pending->email,
                'password' => $pending->password,
                'role' => $pending->role,
                'email_verified_at' => now(),
            ]);

            DB::table('pending_account_verifications')->where('id', $pending->id)->delete();
        });

        return response()->json([
            'message' => 'Account created successfully. You can now log in.',
        ]);
    }
}
