<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CustomerOverdueNotification extends Notification
{
    public function __construct(private readonly array $customerData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->customerData + ['alert_type' => 'receivables.customer_overdue'];
    }
}
