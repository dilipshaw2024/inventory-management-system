<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'statement_date' => 'date',
        'opening_balance' => 'decimal:6',
        'closing_balance' => 'decimal:6',
        'book_balance' => 'decimal:6',
        'difference' => 'decimal:6',
        'closed_at' => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
