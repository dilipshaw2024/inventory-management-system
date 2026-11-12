<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryProof extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['delivered_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];

    protected static function booted(): void
    {
        static::updating(function (): void { throw new LogicException('Delivery proofs are immutable; record a correcting proof instead.'); });
        static::deleting(function (): void { throw new LogicException('Delivery proofs cannot be deleted.'); });
    }

    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function attachment() { return $this->belongsTo(DocumentAttachment::class); }
    public function capturedBy() { return $this->belongsTo(User::class, 'captured_by'); }
}
