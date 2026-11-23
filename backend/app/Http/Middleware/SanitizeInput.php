<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cleans every text value in an API request before validation.
 *
 *  - Every string: invalid UTF-8 is repaired, control characters and NUL bytes
 *    are removed, and surrounding whitespace is trimmed.
 *  - Short "plain text" fields (names, titles, codes): HTML tags and invisible
 *    or direction-changing characters are also removed, so a name can never
 *    carry markup or spoof its own appearance.
 *  - Long free text (submissions, announcements, feedback) keeps its content,
 *    including characters like "<" that students legitimately write in code or
 *    maths. The API only ever returns it as JSON and the apps show it as plain
 *    text, never as HTML.
 *  - Secrets (passwords, tokens) are never touched, so they are not altered.
 *
 * This is one layer of defence alongside validation and output handling.
 */
class SanitizeInput
{
    /** Left exactly as sent. */
    private const UNTOUCHED = [
        'password', 'current_password', 'password_confirmation', 'token', 'challenge_id',
    ];

    /** Short single-line fields that must never contain markup. */
    private const PLAIN_TEXT = [
        'name', 'title', 'code', 'criterion', 'device_name', 'label', 'subject', 'location',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->input();

        if ($input !== []) {
            $request->merge($this->clean($input));
        }

        return $next($request);
    }

    /** @param array<int|string, mixed> $values */
    private function clean(array $values, ?string $parentKey = null): array
    {
        foreach ($values as $key => $value) {
            $name = is_string($key) ? $key : $parentKey;

            if (is_string($key) && in_array($key, self::UNTOUCHED, true)) {
                continue;
            }

            $values[$key] = match (true) {
                is_array($value) => $this->clean($value, $name),
                is_string($value) => $this->cleanString($value, in_array($name, self::PLAIN_TEXT, true)),
                default => $value,
            };
        }

        return $values;
    }

    private function cleanString(string $value, bool $plainText): string
    {
        // Repair invalid UTF-8 (the database would otherwise reject the request).
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Remove NUL and control characters, keeping tab, newline and carriage return.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        if ($plainText) {
            $value = strip_tags($value);
            // Zero-width characters and bidirectional overrides used for spoofing.
            $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        }

        return trim($value);
    }
}
