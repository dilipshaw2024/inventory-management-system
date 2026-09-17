<?php

namespace App\Listeners;

use App\Models\UserActivityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class RecordUserActivity
{
    public function handle(Login|Logout|Failed $event): void
    {
        $user = property_exists($event, 'user') ? $event->user : null;
        $email = $user?->email ?? ($event instanceof Failed ? $event->credentials['email'] ?? null : null);
        $action = $event instanceof Login ? 'login' : ($event instanceof Logout ? 'logout' : 'login_failed');
        UserActivityLog::create([
            'company_id' => $user?->company_id,
            'user_id' => $user?->getKey(),
            'email' => $email,
            'action' => $action,
            'route' => app()->bound('request') ? request()->route()?->getName() : null,
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'user_agent' => app()->bound('request') ? request()->userAgent() : null,
            'metadata' => ['guard' => $event->guard ?? null],
        ]);
    }
}
