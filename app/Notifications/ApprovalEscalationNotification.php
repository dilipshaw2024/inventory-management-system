<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ApprovalEscalationNotification extends Notification
{
    public function __construct(private readonly array $approvalData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->approvalData + ['alert_type' => 'approval.escalation'];
    }
}
