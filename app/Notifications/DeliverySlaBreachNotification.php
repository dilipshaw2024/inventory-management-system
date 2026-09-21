<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DeliverySlaBreachNotification extends Notification
{
    public function __construct(private readonly array $deliveryData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->deliveryData + ['alert_type' => 'sales.delivery_sla_breached'];
    }
}
