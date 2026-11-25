<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Support\PasswordPolicy;

class PasswordResetController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $activeUserExists = User::query()->where('email', $data['email'])->where('account_status', 'active')->exists();
        if ($activeUserExists) {
            try {
                Password::broker()->sendResetLink(['email' => $data['email']]);
            } catch (\Throwable $e) {
                // A mail outage must not change the answer: a different response
                // would tell an attacker which addresses have accounts.
                report($e);
            }
        }

        return response()->json([
            'message' => 'If an active account matches that address, a reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);
        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password): void {
                abort_unless($user->account_status === 'active', 422, 'The password reset request is invalid.');
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                // A reset means the old credential may be compromised: end every
                // existing session so a stolen session cannot outlive the reset.
                DB::table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'The password reset request is invalid or expired.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
