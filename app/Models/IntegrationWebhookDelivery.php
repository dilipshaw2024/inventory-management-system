<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class IntegrationWebhookDelivery extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'dead_lettered_at' => 'datetime'];
    public function subscription() { return $this->belongsTo(IntegrationWebhookSubscription::class, 'subscription_id'); }

    protected static function booted(): void
    {
        static::saving(function (IntegrationWebhookDelivery $delivery): void {
            if (!$delivery->subscription_id) throw new \LogicException('A webhook delivery requires a subscription.');
            $subscription = (new IntegrationWebhookSubscription())->newQueryWithoutScopes()->find($delivery->subscription_id);
            if (!$subscription || (int) $subscription->company_id !== (int) $delivery->company_id) {
                throw new \LogicException('Webhook delivery and subscription must belong to the same company.');
            }
        });
    }
}
