<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCustomerCompany;
class CustomerContact extends Model
{
    use BelongsToCustomerCompany;

    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean'];
    public function customer() { return $this->belongsTo(Customer::class); }
}
