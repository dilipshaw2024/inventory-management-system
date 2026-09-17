<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DemandForecastOverride extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'forecast_quantity' => 'decimal:6'];
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
