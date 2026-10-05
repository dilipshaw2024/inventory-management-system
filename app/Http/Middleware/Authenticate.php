<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            $user = $request->user();
            $exempt = $request->routeIs('change.password', 'update.password', 'admin.logout', 'mfa.*');
            if ($user && !$exempt && app(PasswordPolicyService::class)->isExpired($user)) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Password expired. Change your password before continuing.'], 403);
                }
                return redirect()->route('change.password')->withErrors(['password' => 'Your password has expired. Please change it to continue.']);
            }
            return $next($request);
        }, ...$guards);
    }
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}
