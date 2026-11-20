<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentAttachment extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['is_primary' => 'boolean', 'is_published' => 'boolean', 'publication_channels' => 'array', 'sort_order' => 'integer', 'size_bytes' => 'integer', 'version' => 'integer'];
    public function attachable() { return $this->morphTo(); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
