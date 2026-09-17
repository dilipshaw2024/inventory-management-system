<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class TrackUserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->hasSession() && Schema::hasTable('user_sessions')) {
            $hash = hash('sha256', $request->session()->getId());
            $session = UserSession::firstOrNew(['user_id' => Auth::id(), 'session_hash' => $hash]);
            if ($session->revoked_at) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->route('login')->withErrors(['email' => 'This session has been revoked.']);
            }
            $session->fill([
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 65000),
                'last_activity' => now(),
            ])->save();
        }

        return $next($request);
    }
}
