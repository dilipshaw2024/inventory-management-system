<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QualityInspectionPlan extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['sampling_percent' => 'decimal:4', 'is_active' => 'boolean'];

    public function product() { return $this->belongsTo(Product::class); }
    public function lines() { return $this->hasMany(QualityInspectionPlanLine::class, 'plan_id')->orderBy('sequence'); }
    public function inspections() { return $this->hasMany(QualityInspection::class, 'plan_id'); }
}
