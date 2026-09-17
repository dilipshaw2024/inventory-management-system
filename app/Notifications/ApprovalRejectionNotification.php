<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ApprovalRejectionNotification extends Notification
{
    public function __construct(private readonly array $rejectionData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->rejectionData + ['alert_type' => 'approval.rejected'];
    }
}
