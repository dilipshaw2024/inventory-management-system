<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PasswordBreachScreeningService
{
    private const COMMON_COMPROMISED = [
        'password', 'password123', 'password1234', '12345678', '123456789',
        'qwerty123', 'letmein123', 'welcome123', 'admin123', 'iloveyou',
    ];

    public function isCompromised(string $password): bool
    {
        if (in_array(strtolower($password), self::COMMON_COMPROMISED, true)) return true;
        if (!config('erp.password_breach_screening.enabled', false)) return false;

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);
        try {
            $response = Http::timeout((int) config('erp.password_breach_screening.timeout', 3))
                ->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'InventoryERP-PasswordScreen/1.0'])
                ->get(rtrim((string) config('erp.password_breach_screening.endpoint', 'https://api.pwnedpasswords.com/range'), '/').'/'.$prefix);
            if (!$response->successful()) return (bool) config('erp.password_breach_screening.fail_closed', false);
            foreach (preg_split('/\r?\n/', $response->body()) as $line) {
                [$candidate] = array_pad(explode(':', trim($line), 2), 2, null);
                if (strtoupper((string) $candidate) === $suffix) return true;
            }
        } catch (\Throwable) {
            return (bool) config('erp.password_breach_screening.fail_closed', false);
        }
        return false;
    }
}
