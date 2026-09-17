<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SupplierOverdueNotification extends Notification
{
    public function __construct(private readonly array $supplierData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->supplierData + ['alert_type' => 'payables.supplier_overdue'];
    }
}
