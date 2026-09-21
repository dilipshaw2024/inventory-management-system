<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function parentCompany()
    {
        return $this->belongsTo(self::class, 'parent_company_id');
    }

    public function subsidiaries()
    {
        return $this->hasMany(self::class, 'parent_company_id');
    }
}
