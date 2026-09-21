<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\CostCenterBudget;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CostCenterBudgetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostCenterBudgetAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_alert_command_is_repeat_safe_and_notifies_authorized_users(): void
    {
        $company = Company::create(['name' => 'Budget Alert Co', 'code' => 'BUDGET-ALERT']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::create(['code' => 'budget-reader', 'name' => 'Budget reader', 'is_active' => true]);
        $permission = Permission::create(['code' => 'accounting.view', 'name' => 'Accounting view', 'module' => 'accounting']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $center = CostCenter::create(['company_id' => $company->id, 'code' => 'OPS-ALERT', 'name' => 'Operations', 'is_active' => true]);
        $expense = ChartOfAccount::create(['company_id' => $company->id, 'code' => '6200', 'name' => 'Budget expense', 'account_type' => 'expense', 'is_active' => true]);
        $cash = ChartOfAccount::create(['company_id' => $company->id, 'code' => '1200', 'name' => 'Budget cash', 'account_type' => 'asset', 'is_active' => true]);
        $budget = CostCenterBudget::create(['company_id' => $company->id, 'cost_center_id' => $center->id, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'budget_amount' => 100]);
        $journal = JournalEntry::create(['company_id' => $company->id, 'entry_no' => 'BUDGET-ALERT-1', 'date' => now()->toDateString(), 'status' => 'draft']);
        $journal->lines()->createMany([['account_id' => $expense->id, 'cost_center_id' => $center->id, 'debit' => 90, 'credit' => 0], ['account_id' => $cash->id, 'debit' => 0, 'credit' => 90]]);
        $journal->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);

        $this->artisan('erp:accounting:budget-alerts', ['--company' => $company->id])->assertExitCode(0);
        $this->assertSame(1, $user->notifications()->where('type', CostCenterBudgetNotification::class)->count());
        $this->assertSame('warning', $user->notifications()->first()->data['status']);
        $this->assertSame($budget->id, $user->notifications()->first()->data['budget_id']);
        $this->artisan('erp:accounting:budget-alerts', ['--company' => $company->id])->assertExitCode(0);
        $this->assertSame(1, $user->notifications()->where('type', CostCenterBudgetNotification::class)->count());
    }
}
