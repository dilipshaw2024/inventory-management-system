<?php

namespace App\Console\Commands;

use App\Models\CostCenterBudget;
use App\Models\JournalLine;
use App\Models\User;
use App\Notifications\CostCenterBudgetNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendCostCenterBudgetAlerts extends Command
{
    protected $signature = 'erp:accounting:budget-alerts {--threshold=0.8 : Alert when actual spend reaches this fraction of budget} {--company= : Limit alerts to one company ID}';
    protected $description = 'Notify authorized users about cost centers approaching or exceeding budget';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        if ($threshold < 0 || $threshold > 1) $threshold = 0.8;
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $today = Carbon::today();
        $budgets = CostCenterBudget::with('costCenter')
            ->where('is_active', true)->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get();
        $users = User::where('is_active', true)->with('roles.permissions')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()
            ->filter(fn (User $user): bool => $user->hasPermission('accounting.manage') || $user->hasPermission('accounting.view') || $user->hasPermission('reports.view'));
        $sent = 0;
        foreach ($budgets as $budget) {
            $actual = (float) JournalLine::where('cost_center_id', $budget->cost_center_id)->whereHas('entry', function ($query) use ($budget, $today): void {
                $query->where('status', 'posted')->where('company_id', $budget->company_id)
                    ->whereDate('date', '>=', $budget->period_start)->whereDate('date', '<=', min($budget->period_end->toDateString(), $today->toDateString()));
            })->selectRaw('COALESCE(SUM(debit - credit), 0) AS actual')->value('actual');
            $amount = (float) $budget->budget_amount;
            $utilization = $amount > 0 ? $actual / $amount : null;
            if ($amount > 0 ? $utilization < $threshold : $actual <= 0.000001) continue;
            $status = $amount > 0 && $actual > $amount ? 'over_budget' : ($amount > 0 ? 'warning' : 'unbudgeted');
            foreach ($users->where('company_id', $budget->company_id) as $user) {
                $duplicate = $user->notifications()->where('type', CostCenterBudgetNotification::class)->whereDate('created_at', $today)->whereJsonContains('data->budget_id', $budget->id)->exists();
                if ($duplicate) continue;
                $user->notify(new CostCenterBudgetNotification([
                    'budget_id' => $budget->id, 'cost_center_id' => $budget->cost_center_id,
                    'cost_center' => $budget->costCenter?->name, 'period_start' => $budget->period_start->toDateString(),
                    'period_end' => $budget->period_end->toDateString(), 'budget' => $amount,
                    'actual' => $actual, 'variance' => $amount - $actual, 'utilization' => $utilization,
                    'status' => $status,
                ]));
                $sent++;
            }
        }
        $this->info("Created {$sent} cost-center budget alert(s).");
        return self::SUCCESS;
    }
}
