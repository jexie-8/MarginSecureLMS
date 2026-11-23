<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Checks a submitted second-factor code (authenticator code or one-time
 * recovery code). Shared by the browser and mobile sign-in flows so both
 * apply exactly the same rules.
 */
class MfaVerifier
{
    public function __construct(private readonly TotpService $totp) {}

    /** @return 'totp'|'recovery'|null which kind of code matched, or null if none did */
    public function verify(User $user, string $submitted): ?string
    {
        $code = Str::upper(trim($submitted));

        $step = $user->mfa_secret ? $this->totp->matchingStep($user->mfa_secret, $code) : null;
        if ($step !== null) {
            // A code works once. Remember each accepted step for longer than its
            // validity window so a captured code cannot be replayed.
            if (Cache::add("totp-used:{$user->id}:{$step}", true, now()->addMinutes(3))) {
                return 'totp';
            }

            return null;
        }

        $codes = $user->mfa_recovery_codes ?? [];
        foreach ($codes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return 'recovery';
            }
        }

        return null;
    }
}
