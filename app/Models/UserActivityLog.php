<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
