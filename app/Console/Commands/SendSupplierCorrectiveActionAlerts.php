<?php

namespace App\Console\Commands;

use App\Models\SupplierCorrectiveAction;
use App\Models\User;
use App\Notifications\SupplierCorrectiveActionNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendSupplierCorrectiveActionAlerts extends Command
{
    protected $signature = 'erp:procurement:corrective-action-alerts {--company= : Limit alerts to one company ID}';
    protected $description = 'Notify procurement users about supplier corrective actions due today or overdue';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $today = Carbon::today();
        $actions = SupplierCorrectiveAction::with(['supplier', 'owner'])
            ->whereIn('status', ['open', 'in_progress', 'resolved'])
            ->whereNotNull('due_date')->whereDate('due_date', '<=', $today)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))->get();
        $sent = 0;
        foreach ($actions as $action) {
            $alertType = $action->due_date->isBefore($today) ? 'overdue' : 'due_today';
            $users = User::where('company_id', $action->company_id)->where('is_active', true)->with('roles.permissions')->get()
                ->filter(fn (User $user): bool => $user->hasPermission('reports.view') || $user->hasPermission('purchasing.manage'));
            if ($action->owner && $action->owner->is_active) $users = $users->push($action->owner);
            foreach ($users->unique('id') as $user) {
                $duplicate = $user->notifications()->where('type', SupplierCorrectiveActionNotification::class)->whereDate('created_at', $today)->whereJsonContains('data->action_id', $action->id)->whereJsonContains('data->alert_type', 'procurement.supplier_corrective_action')->exists();
                if ($duplicate) continue;
                $user->notify(new SupplierCorrectiveActionNotification([
                    'action_id' => $action->id, 'supplier_id' => $action->supplier_id, 'supplier' => $action->supplier?->name,
                    'title' => $action->title, 'severity' => $action->severity, 'status' => $action->status,
                    'due_date' => $action->due_date->toDateString(), 'alert_status' => $alertType,
                    'endpoint' => '/api/integration/supplier-corrective-actions/'.$action->id,
                ]));
                $sent++;
            }
        }
        $this->info("Created {$sent} supplier corrective-action alert(s).");
        return self::SUCCESS;
    }
}
