<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerOverdueNotification;
use App\Services\CustomerCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendCustomerOverdueAlerts extends Command
{
    protected $signature = 'erp:receivables:overdue-alerts {--min-days=1 : Minimum overdue age to alert} {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify authorized users about customers with overdue receivables';

    public function handle(CustomerCreditService $credit): int
    {
        $minimumDays = max(1, min(3650, (int) $this->option('min-days')));
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $customers = Customer::where('is_active', true)->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get();
        $users = User::where('is_active', true)->with('roles.permissions')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()->filter(
            fn (User $user): bool => $user->hasPermission('sales.manage') || $user->hasPermission('accounting.view') || $user->hasPermission('reports.view')
        );
        $sent = 0;
        foreach ($customers as $customer) {
            $assessment = $credit->assess($customer);
            if ($assessment['overdue_amount'] <= 0.000001 || $assessment['oldest_overdue_days'] < $minimumDays) continue;
            foreach ($users->where('company_id', $customer->company_id) as $user) {
                $duplicate = $user->notifications()->where('type', CustomerOverdueNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->customer_id', $customer->id)->exists();
                if ($duplicate) continue;
                $user->notify(new CustomerOverdueNotification([
                    'customer_id' => $customer->id,
                    'customer' => $customer->name,
                    'overdue_amount' => $assessment['overdue_amount'],
                    'oldest_overdue_days' => $assessment['oldest_overdue_days'],
                    'collection_status' => $assessment['collection_status'],
                ]));
                $sent++;
            }
        }
        $this->info("Created {$sent} customer overdue alert(s).");
        return self::SUCCESS;
    }
}
