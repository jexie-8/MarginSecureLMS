<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'margin-login|'
            .Str::lower($credentials['email'])
            .'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Try again later.'],
            ])->status(429);
        }

        if (!Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user = Auth::guard('web')->user();

        if ($user->account_status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials or account unavailable.'],
            ]);
        }

        $request->session()->regenerate();
        RateLimiter::clear($key);

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Login successful.',
            'user' => $user->only([
                'id',
                'name',
                'email',
                'role',
                'university_id',
                'department_id',
            ]),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->only([
                'id',
                'name',
                'email',
                'role',
                'university_id',
                'department_id',
            ]),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
