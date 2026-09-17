<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentStatusHistory extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'changed_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class, 'changed_by'); }
}
