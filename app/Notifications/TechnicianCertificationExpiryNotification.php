<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class TechnicianCertificationExpiryNotification extends Notification
{
    public function __construct(private readonly array $certificationData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->certificationData + ['alert_type' => 'service.technician_certification_expiry'];
    }
}
