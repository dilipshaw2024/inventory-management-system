<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class MfaController extends Controller
{
    public function setup(MfaService $mfa)
    {
        $user = Auth::user();
        if ($user->mfa_enabled) return view('auth.mfa_setup', ['secret' => null, 'uri' => null, 'recoveryCodes' => session('mfa.recovery_codes')]);
        $secret = session('mfa.setup_secret');
        if (!$secret) { $secret = $mfa->generateSecret(); session(['mfa.setup_secret' => $secret]); }
        $uri = $mfa->provisioningUri($user, $secret);
        return view('auth.mfa_setup', compact('secret', 'uri'));
    }

    public function enable(Request $request, MfaService $mfa)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = session('mfa.setup_secret');
        if (!$secret || !$mfa->verify($secret, $data['code'])) return back()->withErrors(['code' => 'The authenticator code is invalid or expired.']);
        $user = Auth::user();
        $recovery = $mfa->generateRecoveryCodes();
        $user->update(['mfa_secret' => $secret, 'mfa_enabled' => true, 'mfa_recovery_codes' => $recovery['hashed'], 'mfa_enabled_at' => now()]);
        session()->forget('mfa.setup_secret');
        session(['mfa.verified_user_id' => $user->id]);
        app(AuditService::class)->record('user.mfa_enabled', $user, null, ['mfa_enabled' => true]);
        session(['mfa.recovery_codes' => $recovery['plain']]);
        return redirect()->route('mfa.setup')->with(['message' => 'Multi-factor authentication enabled. Save your recovery codes.', 'alert-type' => 'success']);
    }

    public function challenge()
    {
        return view('auth.mfa_challenge');
    }

    public function verifyChallenge(Request $request, MfaService $mfa)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = Auth::user();
        $valid = $mfa->verify((string) $user->mfa_secret, $data['code']);
        if (!$valid) {
            $codes = (array) ($user->mfa_recovery_codes ?? []);
            foreach ($codes as $index => $hash) if (Hash::check(strtoupper($data['code']), $hash)) { unset($codes[$index]); $user->update(['mfa_recovery_codes' => array_values($codes)]); $valid = true; break; }
        }
        if (!$valid) return back()->withErrors(['code' => 'The authenticator or recovery code is invalid or expired.']);
        session(['mfa.verified_user_id' => Auth::id()]);
        app(AuditService::class)->record('user.mfa_verified', Auth::user(), null, ['verified' => true]);
        return redirect()->intended(route('dashboard'));
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = Auth::user();
        $user->update(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_enabled_at' => null]);
        session()->forget('mfa.verified_user_id');
        app(AuditService::class)->record('user.mfa_disabled', $user, ['mfa_enabled' => true], ['mfa_enabled' => false]);
        return back()->with(['message' => 'Multi-factor authentication disabled.', 'alert-type' => 'info']);
    }
}
