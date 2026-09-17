<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RecurringJournalTemplate extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'lines' => 'array',
        'starts_on' => 'date',
        'next_run_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function runs() { return $this->hasMany(RecurringJournalRun::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
