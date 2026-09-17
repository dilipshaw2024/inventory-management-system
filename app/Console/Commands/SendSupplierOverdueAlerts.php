<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SupplierOverdueNotification;
use App\Services\SupplierPayablesService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendSupplierOverdueAlerts extends Command
{
    protected $signature = 'erp:payables:overdue-alerts {--min-days=1 : Minimum overdue age to alert} {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify authorized users about suppliers with overdue payables';

    public function handle(SupplierPayablesService $payables): int
    {
        $minimumDays = max(1, min(3650, (int) $this->option('min-days')));
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $suppliers = Supplier::where('is_active', true)->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get();
        $users = User::where('is_active', true)->with('roles.permissions')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()->filter(
            fn (User $user): bool => $user->hasPermission('purchasing.manage') || $user->hasPermission('accounting.view') || $user->hasPermission('reports.view')
        );
        $sent = 0;
        foreach ($suppliers as $supplier) {
            $assessment = $payables->assess($supplier);
            if ($assessment['overdue_amount'] <= 0.000001 || $assessment['oldest_overdue_days'] < $minimumDays) continue;
            foreach ($users->where('company_id', $supplier->company_id) as $user) {
                $duplicate = $user->notifications()->where('type', SupplierOverdueNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->supplier_id', $supplier->id)->exists();
                if ($duplicate) continue;
                $user->notify(new SupplierOverdueNotification([
                    'supplier_id' => $supplier->id,
                    'supplier' => $supplier->name,
                    'overdue_amount' => $assessment['overdue_amount'],
                    'oldest_overdue_days' => $assessment['oldest_overdue_days'],
                    'collection_status' => $assessment['collection_status'],
                ]));
                $sent++;
            }
        }
        $this->info("Created {$sent} supplier overdue alert(s).");
        return self::SUCCESS;
    }
}
