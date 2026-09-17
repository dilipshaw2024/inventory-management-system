<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToProductCompany;
class ProductCostHistory extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['old_standard_cost' => 'decimal:6', 'new_standard_cost' => 'decimal:6', 'effective_at' => 'datetime'];
    public function product() { return $this->belongsTo(Product::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
