<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Brand extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function products() { return $this->hasMany(Product::class); }
    public function attachments() { return $this->morphMany(DocumentAttachment::class, 'attachable'); }
}
