<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('erp:retention:process')->monthlyOn(1, '01:00')->withoutOverlapping(1440)->onOneServer();
        $schedule->command('erp:sessions:prune')->dailyAt('03:00')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:inventory:expiry-alerts')->dailyAt('06:00')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:inventory:low-stock-alerts')->dailyAt('06:15')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:inventory:exception-alerts excess')->dailyAt('06:20')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:inventory:exception-alerts slow')->dailyAt('06:25')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:inventory:exception-alerts dead')->dailyAt('06:28')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:receivables:overdue-alerts')->dailyAt('06:30')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:payables:overdue-alerts')->dailyAt('06:45')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:service:generate-due')->dailyAt('05:00')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:service:expire-contracts')->dailyAt('04:45')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:service:sla-alerts')->dailyAt('06:35')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:sales:delivery-sla-alerts')->dailyAt('06:40')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:logistics:sync-carrier-tracking')->hourly()->withoutOverlapping(55)->onOneServer();
        $schedule->command('erp:service:generate-spare-part-purchase-orders')->dailyAt('02:45')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:sales:expire-quotations')->dailyAt('00:45')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:procurement:close-overdue-rfqs')->dailyAt('00:50')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:inventory:generate-counts')->dailyAt('04:00')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:inventory:allocate-backorders')->hourly()->withoutOverlapping(55)->onOneServer();
        $schedule->command('erp:inventory:expire-reservations')->hourly()->withoutOverlapping(55)->onOneServer();
        $schedule->command('erp:planning:generate-purchase-orders')->dailyAt('02:00')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:planning:generate-production-orders')->dailyAt('02:15')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:planning:auto-release-production-orders')->dailyAt('02:20')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:planning:generate-transfer-orders')->dailyAt('02:30')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:integration:deliver-webhooks')->everyFiveMinutes()->withoutOverlapping(4)->onOneServer();
        $schedule->command('erp:accounting:generate-recurring-journals')->dailyAt('00:30')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:accounting:sync-bank-statements')->dailyAt('01:00')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:accounting:auto-match-bank-statements')->dailyAt('01:05')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:accounting:retry-tax-filings')->dailyAt('01:20')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:approvals:escalate')->hourly()->withoutOverlapping(55)->onOneServer();
        $schedule->command('erp:accounting:budget-alerts')->dailyAt('07:00')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:procurement:corrective-action-alerts')->dailyAt('07:05')->withoutOverlapping(60)->onOneServer();
        $schedule->command('erp:inventory:capture-snapshot')->dailyAt('23:55')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:warehouse:capture-utilization')->dailyAt('23:50')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:security:verify-audit-chain')->dailyAt('00:10')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:reconcile-inventory-ledger --fail-on-mismatch')->dailyAt('00:20')->withoutOverlapping(120)->onOneServer();
        $schedule->command('erp:products:process-import-jobs')->hourly()->withoutOverlapping(55)->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
