<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\ServiceSlaBreachNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendServiceSlaAlerts extends Command
{
    protected $signature = 'erp:service:sla-alerts {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify service users about unresolved requests that breached their response SLA.';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $requests = ServiceRequest::with(['asset', 'customer', 'contract'])
            ->whereNotNull('response_due_at')->whereNotIn('status', ['resolved', 'cancelled'])
            ->where(function ($query): void { $query->where(function ($nested): void { $nested->whereNull('assigned_at')->where('response_due_at', '<', now()); })->orWhereColumn('assigned_at', '>', 'response_due_at'); })
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get();
        $users = User::where('is_active', true)->with('roles.permissions')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()->filter(fn (User $user): bool => $user->hasPermission('service.manage') || $user->hasPermission('reports.view'));
        $sent = 0;
        foreach ($requests as $request) {
            foreach ($users->where('company_id', $request->company_id) as $user) {
                $duplicate = $user->notifications()->where('type', ServiceSlaBreachNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->request_id', $request->id)->exists();
                if ($duplicate) continue;
                $user->notify(new ServiceSlaBreachNotification(['request_id' => $request->id, 'request_no' => $request->request_no, 'asset' => $request->asset?->name, 'customer' => $request->customer?->name, 'response_due_at' => $request->response_due_at?->toISOString(), 'priority' => $request->priority]));
                $sent++;
            }
        }
        $this->info("Created {$sent} service SLA alert(s).");
        return self::SUCCESS;
    }
}
