<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->mfa_enabled && !$request->routeIs('mfa.*') && $request->session()->get('mfa.verified_user_id') !== Auth::id()) {
            return redirect()->route('mfa.challenge');
        }
        return $next($request);
    }
}
