<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentRevision extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'changed_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class, 'changed_by'); }
    public function auditLog() { return $this->belongsTo(AuditLog::class); }
}
