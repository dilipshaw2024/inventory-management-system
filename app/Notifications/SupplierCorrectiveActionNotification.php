<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SupplierCorrectiveActionNotification extends Notification
{
    public function __construct(private readonly array $actionData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->actionData + ['alert_type' => 'procurement.supplier_corrective_action'];
    }
}
