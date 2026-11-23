<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one password rule used everywhere a password is chosen (change, reset,
 * bulk import): at least 12 characters with mixed case, numbers and symbols,
 * and not found in public breach lists.
 *
 * The breach check sends only the first five characters of a hash of the
 * password to the Have I Been Pwned range API, never the password itself. If
 * that service cannot be reached the check is skipped rather than blocking
 * sign-ups. Switch it off with CHECK_BREACHED_PASSWORDS=false.
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min(12)->mixedCase()->numbers()->symbols();

        return config('margin.check_breached_passwords', true) ? $rule->uncompromised() : $rule;
    }
}
