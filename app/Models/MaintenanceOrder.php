<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class MaintenanceOrder extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['scheduled_date' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'actual_hours' => 'decimal:4', 'labor_cost' => 'decimal:6'];
    public function asset() { return $this->belongsTo(ServiceAsset::class, 'asset_id'); }
    public function serviceRequest() { return $this->belongsTo(ServiceRequest::class); }
    public function parts() { return $this->hasMany(MaintenancePart::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function completer() { return $this->belongsTo(User::class, 'completed_by'); }
    public function laborJournal() { return $this->belongsTo(JournalEntry::class, 'labor_journal_entry_id'); }
    public function serviceInvoice() { return $this->hasOne(Invoice::class, 'maintenance_order_id'); }
}
