<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryTrackingEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['event_at' => 'datetime', 'raw_payload' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Delivery tracking events are immutable; record a correcting carrier event instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('Delivery tracking events cannot be deleted.');
        });
    }

    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
