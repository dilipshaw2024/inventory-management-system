<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $hidden = ['connection_config'];
    protected $casts = ['is_active' => 'boolean', 'connection_config' => 'encrypted:array', 'last_synced_at' => 'datetime'];
    public function glAccount() { return $this->belongsTo(ChartOfAccount::class, 'gl_account_id'); }
    public function statementLines() { return $this->hasMany(BankStatementLine::class); }
}
