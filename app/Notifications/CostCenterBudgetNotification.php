<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CostCenterBudgetNotification extends Notification
{
    public function __construct(private readonly array $budgetData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->budgetData + ['alert_type' => 'accounting.cost_center_budget'];
    }
}
