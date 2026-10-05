<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityInspectionResult extends Model
{
    protected $guarded = [];
    protected $casts = ['value_numeric' => 'decimal:8', 'value_boolean' => 'boolean'];

    public function inspection() { return $this->belongsTo(QualityInspection::class, 'inspection_id'); }
    public function planLine() { return $this->belongsTo(QualityInspectionPlanLine::class, 'plan_line_id'); }
}
