<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankStatementImportBatch extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    public function lines() { return $this->hasMany(BankStatementLine::class, 'import_batch_id'); }
    public function importer() { return $this->belongsTo(User::class, 'imported_by'); }
}
