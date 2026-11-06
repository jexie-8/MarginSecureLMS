<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MfaController extends Controller
{
    public function setup(Request $request, TotpService $totp, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['platform_admin', 'university_admin', 'department_admin'], true), 403);
        $request->validate(['password' => ['required', 'current_password:web']]);
        abort_if($request->user()->mfa_confirmed_at !== null, 409, 'Disable the existing MFA configuration before creating a new one.');
        $secret = $totp->secret();
        $request->user()->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null])->save();
        $audit->record($request->user(), 'mfa.setup_started', $request->user(), [], $request);
        $issuer = rawurlencode('Margin LMS');
        $account = rawurlencode($request->user()->email);

        return response()->json(['secret' => $secret, 'otpauth_url' => "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&digits=6&period=30"]);
    }

    public function confirm(Request $request, TotpService $totp, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        abort_unless(in_array($user->role, ['platform_admin', 'university_admin', 'department_admin'], true), 403);
        abort_unless($user->mfa_secret && $totp->verify($user->mfa_secret, $data['code']), 422, 'The verification code is invalid.');
        $plain = collect(range(1, 8))->map(fn () => Str::upper(Str::random(10)))->all();
        $user->forceFill(['mfa_confirmed_at' => now(), 'mfa_recovery_codes' => array_map(fn ($code) => Hash::make($code), $plain)])->save();
        $audit->record($user, 'mfa.enabled', $user, [], $request);

        return response()->json(['message' => 'MFA enabled.', 'recovery_codes' => $plain]);
    }

    public function disable(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']]);
        $request->user()->forceFill(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_confirmed_at' => null])->save();
        $audit->record($request->user(), 'mfa.disabled', $request->user(), [], $request);

        return response()->json(['message' => 'MFA disabled.']);
    }

    public function challenge(Request $request, TotpService $totp, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $id = $request->session()->get('mfa_user_id');
        $user = $id ? User::query()->find($id) : null;
        abort_unless($user && $user->account_status === 'active' && $user->mfa_confirmed_at, 422, 'The MFA challenge has expired.');
        $submittedCode = Str::upper(trim($data['code']));
        $valid = $totp->verify($user->mfa_secret, $submittedCode);
        $usedRecoveryCode = false;
        if (! $valid) {
            $codes = $user->mfa_recovery_codes ?? [];
            foreach ($codes as $index => $hash) {
                if (Hash::check($submittedCode, $hash)) {
                    unset($codes[$index]);
                    $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                    $valid = true;
                    $usedRecoveryCode = true;
                    break;
                }
            }
        }
        if (! $valid) {
            $audit->record($user, 'mfa.challenge_failed', $user, [], $request);
        }
        abort_unless($valid, 422, 'The verification code is invalid.');
        Auth::guard('web')->login($user);
        $request->session()->forget('mfa_user_id');
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record($user, $usedRecoveryCode ? 'mfa.recovery_code_used' : 'mfa.challenge_succeeded', $user, [], $request);

        return response()->json(['message' => 'Login successful.', 'user' => [
            ...$user->only(['id', 'name', 'email', 'role', 'university_id', 'department_id']),
            'avatar_url' => $user->avatar_path ? url('/api/v1/profile/avatar/'.$user->id) : null,
            'mfa_enabled' => true,
        ]]);
    }
}
