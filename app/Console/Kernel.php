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
        $schedule->command('erp:retention:process')->monthlyOn(1, '01:00');
        $schedule->command('erp:sessions:prune')->dailyAt('03:00');
        $schedule->command('erp:inventory:expiry-alerts')->dailyAt('06:00');
        $schedule->command('erp:inventory:low-stock-alerts')->dailyAt('06:15');
        $schedule->command('erp:receivables:overdue-alerts')->dailyAt('06:30');
        $schedule->command('erp:payables:overdue-alerts')->dailyAt('06:45');
        $schedule->command('erp:service:generate-due')->dailyAt('05:00');
        $schedule->command('erp:service:expire-contracts')->dailyAt('04:45');
        $schedule->command('erp:service:sla-alerts')->dailyAt('06:35');
        $schedule->command('erp:inventory:generate-counts')->dailyAt('04:00');
        $schedule->command('erp:inventory:allocate-backorders')->hourly();
        $schedule->command('erp:planning:generate-purchase-orders')->dailyAt('02:00');
        $schedule->command('erp:planning:generate-production-orders')->dailyAt('02:15');
        $schedule->command('erp:integration:deliver-webhooks')->everyFiveMinutes();
        $schedule->command('erp:accounting:generate-recurring-journals')->dailyAt('00:30');
        $schedule->command('erp:approvals:escalate')->hourly();
        $schedule->command('erp:inventory:capture-snapshot')->dailyAt('23:55');
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
