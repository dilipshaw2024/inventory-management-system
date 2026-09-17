<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductionOperation extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['planned_quantity' => 'decimal:6', 'completed_quantity' => 'decimal:6', 'actual_setup_minutes' => 'decimal:2', 'actual_run_minutes' => 'decimal:2', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'scheduled_start_at' => 'datetime', 'scheduled_end_at' => 'datetime'];
    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function routingOperation() { return $this->belongsTo(RoutingOperation::class); }
    public function workCenter() { return $this->belongsTo(WorkCenter::class); }
    public function starter() { return $this->belongsTo(User::class, 'started_by'); }
    public function completer() { return $this->belongsTo(User::class, 'completed_by'); }
}
