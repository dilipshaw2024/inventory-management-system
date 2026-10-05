<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InventoryExceptionNotification extends Notification
{
    public function __construct(private readonly array $exceptionData) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->exceptionData + ['alert_type' => 'inventory.exception'];
    }
}
