<?php

namespace App\Services;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class PasswordPolicyService
{
    public function rules(bool $confirmed = true): array
    {
        $rules = ['required', 'string'];
        if ($confirmed) $rules[] = 'confirmed';

        $settings = function_exists('app') && app()->bound('config')
            ? app('config')->get('erp.password_policy', [])
            : ['min_length' => 12, 'mixed_case' => true, 'numbers' => true, 'symbols' => true];
        $password = Password::min((int) ($settings['min_length'] ?? 12));
        if ($settings['mixed_case'] ?? true) $password->mixedCase();
        if ($settings['numbers'] ?? true) $password->numbers();
        if ($settings['symbols'] ?? true) $password->symbols();

        $rules[] = $password;
        return $rules;
    }

    public function assertNotReused(User $user, string $plainText, string $attribute = 'password'): void
    {
        if (Hash::check($plainText, (string) $user->password)) {
            throw ValidationException::withMessages([$attribute => 'You cannot reuse your current password.']);
        }
        foreach (PasswordHistory::where('user_id', $user->getKey())->latest()->limit(10)->get(['password_hash']) as $history) {
            if (Hash::check($plainText, (string) $history->password_hash)) {
                throw ValidationException::withMessages([$attribute => 'You cannot reuse a recent password.']);
            }
        }
    }

    public function rememberCurrent(User $user): void
    {
        if (!$user->password) return;
        PasswordHistory::create(['user_id' => $user->getKey(), 'company_id' => $user->company_id, 'password_hash' => $user->password]);
        $history = PasswordHistory::where('user_id', $user->getKey())->latest()->get();
        if ($history->count() > 10) PasswordHistory::where('user_id', $user->getKey())->whereNotIn('id', $history->take(10)->pluck('id'))->delete();
    }
}
