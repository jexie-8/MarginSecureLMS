<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $key = $this->throttleKey($credentials['email'], (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Try again later.'],
            ])->status(429);
        }

        // A login request always starts a new authentication state. This avoids
        // leaving a previous user authenticated after a failed account switch.
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if (! Auth::guard('web')->attempt($credentials)) {
            $this->recordFailure($key);
        }

        $user = Auth::guard('web')->user();

        if ($user === null || $user->account_status !== 'active') {
            if ($user !== null) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $this->recordFailure($key);
        }

        $request->session()->regenerate();
        RateLimiter::clear($key);

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->publicUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->publicUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function recordFailure(string $key): never
    {
        RateLimiter::hit($key, self::DECAY_SECONDS);

        throw ValidationException::withMessages([
            'email' => ['Invalid credentials or account unavailable.'],
        ]);
    }

    private function throttleKey(string $email, string $ip): string
    {
        return 'margin-login:'.hash('sha256', mb_strtolower($email).'|'.$ip);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUser(object $user): array
    {
        return $user->only([
            'id',
            'name',
            'email',
            'role',
            'university_id',
            'department_id',
        ]);
    }
}
