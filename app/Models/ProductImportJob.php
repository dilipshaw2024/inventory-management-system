<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductImportJob extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['dry_run' => 'boolean', 'errors' => 'array', 'attempts' => 'integer', 'max_attempts' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
