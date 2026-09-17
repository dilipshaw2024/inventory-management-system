<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryReconciliationSnapshot extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['as_of_date' => 'date', 'rows' => 'array', 'total_quantity' => 'decimal:6'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Inventory reconciliation snapshots are immutable; create a new dated snapshot.');
        });
        static::deleting(function (): void {
            throw new LogicException('Inventory reconciliation snapshots cannot be deleted.');
        });
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
