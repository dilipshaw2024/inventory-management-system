<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ServiceSlaBreachNotification extends Notification
{
    public function __construct(private readonly array $requestData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->requestData + ['alert_type' => 'service.sla_breached'];
    }
}
