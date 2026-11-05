<?php

namespace App\Services\Applicants;

/**
 * Generates and hashes applicant invitation codes.
 *
 * Codes carry 160 bits of entropy, encoded as 32 Crockford base32 characters
 * (no I, L, O, U) and grouped for manual entry. Only a keyed HMAC-SHA256 of
 * the normalized code is stored, so the database alone cannot reveal or
 * verify codes, yet lookups stay indexable.
 */
class InvitationCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $code = '';
        foreach (str_split($bits, 5) as $chunk) {
            $code .= self::ALPHABET[bindec($chunk)];
        }

        return implode('-', str_split($code, 4));
    }

    /**
     * Uppercases, removes separators/whitespace and maps commonly confused
     * characters (I/L -> 1, O -> 0) as Crockford base32 specifies.
     */
    public function normalize(string $input): string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');

        return strtr($value, ['I' => '1', 'L' => '1', 'O' => '0']);
    }

    public function looksValid(string $input): bool
    {
        $normalized = $this->normalize($input);

        return strlen($normalized) === 32 && strspn($normalized, self::ALPHABET) === 32;
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), $this->key());
    }

    private function key(): string
    {
        // Domain-separated key derived from APP_KEY.
        return hash_hmac('sha256', 'applicant-invitation-codes', (string) config('app.key'), true);
    }
}
