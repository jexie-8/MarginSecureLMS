<?php

namespace App\Services;

use App\Models\User;
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

        if ($user->mfa_secret && $this->totp->verify($user->mfa_secret, $code)) {
            return 'totp';
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
