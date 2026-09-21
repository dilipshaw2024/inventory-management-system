<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierCorrectiveAction extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['due_date' => 'date', 'closed_at' => 'datetime'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function closer() { return $this->belongsTo(User::class, 'closed_by'); }
}
