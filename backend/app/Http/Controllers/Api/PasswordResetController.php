<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $activeUserExists = User::query()->where('email', $data['email'])->where('account_status', 'active')->exists();
        if ($activeUserExists) {
            Password::broker()->sendResetLink(['email' => $data['email']]);
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
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $status = Password::broker()->reset(
            $data,
            function (User $user, string $password): void {
                abort_unless($user->account_status === 'active', 422, 'The password reset request is invalid.');
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'The password reset request is invalid or expired.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
