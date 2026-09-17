<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PasswordHistory extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $hidden = ['password_hash'];
    public function user() { return $this->belongsTo(User::class); }
}
