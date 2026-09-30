<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DispatchManifest extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = [
        'manifest_date' => 'date',
        'handed_off_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function deliveries() { return $this->belongsToMany(Delivery::class, 'dispatch_manifest_deliveries'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function handoffActor() { return $this->belongsTo(User::class, 'handed_off_by'); }
}

