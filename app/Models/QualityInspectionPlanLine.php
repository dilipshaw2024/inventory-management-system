<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityInspectionPlanLine extends Model
{
    protected $guarded = [];
    protected $casts = ['target_value' => 'decimal:8', 'minimum_value' => 'decimal:8', 'maximum_value' => 'decimal:8', 'is_required' => 'boolean'];

    public function plan() { return $this->belongsTo(QualityInspectionPlan::class, 'plan_id'); }
    public function results() { return $this->hasMany(QualityInspectionResult::class, 'plan_line_id'); }
}
