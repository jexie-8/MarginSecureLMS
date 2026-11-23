<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MfaVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in for the Flutter mobile app. Browsers keep using cookie sessions;
 * the mobile app receives a short-lived bearer token, stored in the device's
 * secure keystore. The same throttling, account checks, MFA and audit logging
 * apply as for the browser flow.
 */
class MobileAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    private const MFA_CHALLENGE_MINUTES = 5;

    private const MFA_MAX_ATTEMPTS = 5;

    private const MFA_ROLES = ['platform_admin', 'university_admin', 'department_admin'];

    public function login(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $credentials = $request->validated();
        $device = $this->deviceName($request);
        $key = $this->throttleKey($credentials['email'], (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $audit->record(null, 'auth.login_rate_limited', null, [
                'identifier_hash' => $this->identifierHash($credentials['email']),
                'client' => 'mobile',
            ], $request);
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Try again later.'],
            ])->status(429);
        }

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $credentials['email']]);
        $passwordOk = $user !== null && $provider->validateCredentials($user, ['password' => $credentials['password']]);

        if (! $passwordOk || $user->account_status !== 'active') {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $audit->record($passwordOk ? $user : null, 'auth.login_failed', $passwordOk ? $user : null, [
                'identifier_hash' => $this->identifierHash($credentials['email']),
                'reason' => $passwordOk ? 'account_unavailable' : 'invalid_credentials',
                'client' => 'mobile',
            ], $request);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials or account unavailable.'],
            ]);
        }

        RateLimiter::clear($key);

        if ($user->mfa_confirmed_at !== null && in_array($user->role, self::MFA_ROLES, true)) {
            $challengeId = Str::random(64);
            Cache::put($this->challengeKey($challengeId), $user->id, now()->addMinutes(self::MFA_CHALLENGE_MINUTES));
            $audit->record($user, 'auth.mfa_required', $user, ['client' => 'mobile'], $request);

            return response()->json([
                'message' => 'Multi-factor authentication is required.',
                'mfa_required' => true,
                'challenge_id' => $challengeId,
                'device_name' => $device,
            ], 202);
        }

        return $this->issueToken($user, $device, $request, $audit, 'auth.login_succeeded');
    }

    public function challenge(Request $request, MfaVerifier $verifier, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $userId = Cache::get($this->challengeKey($data['challenge_id']));
        $user = $userId ? User::query()->find($userId) : null;
        abort_unless($user && $user->account_status === 'active' && $user->mfa_confirmed_at, 422, 'The MFA challenge has expired.');

        $matched = $verifier->verify($user, $data['code']);
        if ($matched === null) {
            $audit->record($user, 'mfa.challenge_failed', $user, ['client' => 'mobile'], $request);

            // A challenge allows only a few guesses, then must be restarted with the password.
            $attemptsKey = $this->challengeKey($data['challenge_id']).':attempts';
            Cache::add($attemptsKey, 0, now()->addMinutes(self::MFA_CHALLENGE_MINUTES));
            if (Cache::increment($attemptsKey) >= self::MFA_MAX_ATTEMPTS) {
                Cache::forget($this->challengeKey($data['challenge_id']));
            }

            abort(422, 'The verification code is invalid.');
        }

        Cache::forget($this->challengeKey($data['challenge_id']));

        return $this->issueToken(
            $user,
            $this->deviceName($request),
            $request,
            $audit,
            $matched === 'recovery' ? 'mfa.recovery_code_used' : 'mfa.challenge_succeeded',
        );
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        $audit->record($request->user(), 'auth.logout', $request->user(), ['client' => 'mobile'], $request);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function issueToken(User $user, string $device, Request $request, AuditLogger $audit, string $event): JsonResponse
    {
        $expiresAt = now()->addHours(max(1, (int) config('margin.mobile_token_hours', 8)));
        $token = $user->createToken('mobile:'.$device, ['*'], $expiresAt);

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record($user, $event, $user, ['client' => 'mobile'], $request);

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                ...$user->only(['id', 'name', 'email', 'role', 'university_id', 'department_id']),
                'avatar_url' => $user->avatar_path ? url('/api/v1/profile/avatar/'.$user->id) : null,
                'mfa_enabled' => $user->mfa_confirmed_at !== null,
            ],
        ]);
    }

    private function deviceName(Request $request): string
    {
        $name = trim((string) $request->input('device_name', 'mobile'));

        return mb_substr($name !== '' ? $name : 'mobile', 0, 80);
    }

    private function challengeKey(string $id): string
    {
        return 'mobile-mfa:'.hash('sha256', $id);
    }

    // Same key as the browser login so both entry points share one attempt budget.
    private function throttleKey(string $email, string $ip): string
    {
        return 'margin-login:'.hash('sha256', mb_strtolower($email).'|'.$ip);
    }

    private function identifierHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
