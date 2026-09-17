<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class IntegrationWebhookSubscription extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $hidden = ['secret'];
    protected $casts = ['event_types' => 'array', 'secret' => 'encrypted', 'is_active' => 'boolean', 'last_delivered_at' => 'datetime'];
    public function deliveries() { return $this->hasMany(IntegrationWebhookDelivery::class, 'subscription_id'); }
}
