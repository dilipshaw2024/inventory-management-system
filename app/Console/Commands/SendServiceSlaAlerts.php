<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\ServiceSlaBreachNotification;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendServiceSlaAlerts extends Command
{
    protected $signature = 'erp:service:sla-alerts {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify service users about unresolved requests that breached their response SLA.';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $now = now();
        $requests = ServiceRequest::with(['asset', 'customer', 'contract', 'assignee'])
            ->whereNotNull('response_due_at')->whereNotIn('status', ['resolved', 'cancelled'])
            ->where(function ($query) use ($now): void { $query->where(function ($nested) use ($now): void { $nested->whereNull('assigned_at')->where('response_due_at', '<', $now); })->orWhereColumn('assigned_at', '>', 'response_due_at'); })
            ->where(fn ($query) => $query->whereNull('last_sla_escalated_at')->orWhere('last_sla_escalated_at', '<', $now->copy()->startOfDay()))
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get();
        $users = User::where('is_active', true)->with('roles.permissions')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()->filter(fn (User $user): bool => $user->hasPermission('service.manage') || $user->hasPermission('reports.view'));
        $sent = 0;
        foreach ($requests as $request) {
            $escalation = DB::transaction(function () use ($request, $now): array {
                $locked = ServiceRequest::lockForUpdate()->findOrFail($request->id);
                if (in_array($locked->status, ['resolved', 'cancelled'], true) || !$locked->response_due_at || $locked->response_due_at->greaterThanOrEqualTo($now)) return [];
                if ($locked->last_sla_escalated_at && $locked->last_sla_escalated_at->greaterThanOrEqualTo($now->copy()->startOfDay())) return [];
                $before = $locked->only(['sla_breached_at', 'sla_escalation_level', 'last_sla_escalated_at']);
                $level = min(3, (int) $locked->sla_escalation_level + 1);
                $locked->update(['sla_breached_at' => $locked->sla_breached_at ?: $now, 'sla_escalation_level' => $level, 'last_sla_escalated_at' => $now]);
                app(AuditService::class)->record('service_request.sla_escalated', $locked, $before, $locked->fresh()->only(['sla_breached_at', 'sla_escalation_level', 'last_sla_escalated_at']) + ['escalation_level' => $level, 'automated' => true]);
                return [$level, $locked->fresh(['asset', 'customer', 'assignee'])];
            });
            if (!$escalation) continue;
            [$level, $escalated] = $escalation;
            $recipients = $users->where('company_id', $escalated->company_id);
            if ($level === 1 && $escalated->assignee?->is_active) $recipients = $recipients->push($escalated->assignee);
            foreach ($recipients->unique('id') as $user) {
                $duplicate = $user->notifications()->where('type', ServiceSlaBreachNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->request_id', $escalated->id)->exists();
                if ($duplicate) continue;
                $user->notify(new ServiceSlaBreachNotification(['request_id' => $escalated->id, 'request_no' => $escalated->request_no, 'asset' => $escalated->asset?->name, 'customer' => $escalated->customer?->name, 'response_due_at' => $escalated->response_due_at?->toISOString(), 'priority' => $escalated->priority, 'escalation_level' => $level]));
                $sent++;
            }
        }
        $this->info("Created {$sent} service SLA alert(s).");
        return self::SUCCESS;
    }
}
