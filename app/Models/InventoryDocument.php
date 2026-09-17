<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryDocument extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'inspected_at' => 'datetime', 'approved_at' => 'datetime'];

    public function lines() { return $this->hasMany(InventoryDocumentLine::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function costCenter() { return $this->belongsTo(CostCenter::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function inspector() { return $this->belongsTo(User::class, 'inspected_by'); }
}
