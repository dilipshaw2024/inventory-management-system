<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApplyCompanyLocalization
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user && config('sanctum')) {
            $user = auth()->guard('sanctum')->user();
        }

        $company = $user?->company;
        $previousLocale = app()->getLocale();
        $previousTimezone = config('app.timezone', 'UTC');
        $timezone = $company?->timezone;
        $locale = $company?->locale;

        if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }
        if (is_string($locale) && preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z]{2,4})?(?:@[A-Za-z0-9_-]+)?$/', $locale) === 1) {
            app()->setLocale(str_replace('_', '-', $locale));
        }

        try {
            return $next($request);
        } finally {
            config(['app.timezone' => $previousTimezone]);
            date_default_timezone_set($previousTimezone);
            app()->setLocale($previousLocale);
        }
    }
}
