<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class RoutingOperation extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['setup_minutes' => 'decimal:2', 'run_minutes' => 'decimal:2'];
    public function routing() { return $this->belongsTo(Routing::class); }
    public function workCenter() { return $this->belongsTo(WorkCenter::class); }
}
