<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class MfaService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $secret .= self::ALPHABET[ord($byte) % strlen(self::ALPHABET)];
        }
        return $secret;
    }

    /** @return array{plain: array<int, string>, hashed: array<int, string>} */
    public function generateRecoveryCodes(): array
    {
        $plain = collect(range(1, 8))->map(fn (): string => strtoupper(Str::random(4).'-'.Str::random(4)))->all();
        return ['plain' => $plain, 'hashed' => collect($plain)->map(fn (string $code): string => password_hash($code, PASSWORD_DEFAULT))->all()];
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = rawurlencode(config('app.name', 'ERP'));
        $account = rawurlencode($user->email ?: $user->username);
        return "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $normalized = preg_replace('/\s+/', '', $code);
        if (!is_string($normalized) || !preg_match('/^\d{6}$/', $normalized)) return false;
        $counter = intdiv($timestamp ?? time(), 30);
        foreach ([-1, 0, 1] as $window) {
            $binaryCounter = pack('N*', 0).pack('N*', $counter + $window);
            $hash = hash_hmac('sha1', $binaryCounter, $this->decodeBase32($secret), true);
            $offset = ord($hash[19]) & 0x0f;
            $binary = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
            if (hash_equals(str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT), $normalized)) return true;
        }
        return false;
    }

    private function decodeBase32(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $character) $bits .= str_pad(decbin(strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        $binary = '';
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $binary .= chr(bindec($chunk));
        return $binary;
    }
}
