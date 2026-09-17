<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InventoryExpiryNotification extends Notification
{
    public function __construct(private readonly array $batchData) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return $this->batchData + ['alert_type' => 'inventory.expiry'];
    }
}
