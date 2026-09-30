<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosRegister extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
    public function store() { return $this->belongsTo(Store::class); }
    public function sessions() { return $this->hasMany(PosSession::class, 'register_id'); }
    public function openSession() { return $this->hasOne(PosSession::class, 'register_id')->where('status', 'open'); }
}
