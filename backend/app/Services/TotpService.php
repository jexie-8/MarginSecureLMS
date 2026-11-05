<?php

namespace App\Services;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $bytes = random_bytes(20); $buffer = 0; $bits = 0; $result = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $buffer = ($buffer << 8) | $byte; $bits += 8;
            while ($bits >= 5) { $bits -= 5; $result .= self::ALPHABET[($buffer >> $bits) & 31]; }
        }
        return $result;
    }

    public function verify(string $secret, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) return false;
        $counter = intdiv(time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) if (hash_equals($this->code($secret, $counter + $offset), $code)) return true;
        return false;
    }

    private function code(string $secret, int $counter): string
    {
        $key = $this->decode($secret);
        $binary = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binary, $key, true); $offset = ord($hash[19]) & 15;
        $value = ((ord($hash[$offset]) & 127) << 24) | ((ord($hash[$offset + 1]) & 255) << 16) | ((ord($hash[$offset + 2]) & 255) << 8) | (ord($hash[$offset + 3]) & 255);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decode(string $value): string
    {
        $buffer = 0; $bits = 0; $result = '';
        foreach (str_split(strtoupper($value)) as $char) {
            $position = strpos(self::ALPHABET, $char); if ($position === false) continue;
            $buffer = ($buffer << 5) | $position; $bits += 5;
            if ($bits >= 8) { $bits -= 8; $result .= chr(($buffer >> $bits) & 255); }
        }
        return $result;
    }
}
