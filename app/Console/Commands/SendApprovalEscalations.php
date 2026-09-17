<?php

namespace App\Console\Commands;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalAction;
use App\Models\User;
use App\Notifications\ApprovalEscalationNotification;
use App\Services\ApprovalGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SendApprovalEscalations extends Command
{
    protected $signature = 'erp:approvals:escalate {--company= : Limit notifications to one company ID}';
    protected $description = 'Notify authorized users about approval steps that exceeded their configured SLA';

    private const PENDING_STATUS = [
        'App\\Models\\Invoice' => 0, 'App\\Models\\Purchase' => 0,
        'App\\Models\\PurchaseOrder' => 'submitted', 'App\\Models\\PurchaseRequisition' => 'submitted',
        'App\\Models\\PurchaseQuotation' => 'submitted', 'App\\Models\\PurchaseRfq' => 'submitted',
        'App\\Models\\GoodsReceipt' => 'pending', 'App\\Models\\PurchaseInvoice' => 'pending',
        'App\\Models\\SalesOrder' => 'submitted', 'App\\Models\\SalesQuotation' => 'submitted',
        'App\\Models\\Delivery' => 'pending', 'App\\Models\\InventoryReturn' => 'pending',
        'App\\Models\\InventoryDocument' => 'pending', 'App\\Models\\InventoryAdjustment' => 'pending',
        'App\\Models\\InventoryTransfer' => 'pending', 'App\\Models\\InventoryStatusTransfer' => 'pending',
        'App\\Models\\StockCount' => 'submitted', 'App\\Models\\SupplierPayment' => 'pending',
        'App\\Models\\LandedCost' => 'pending', 'App\\Models\\CustomerRefund' => 'pending',
        'App\\Models\\JournalEntry' => 'draft',
    ];

    public function handle(ApprovalGuard $approvalGuard): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $sent = 0;
        $policies = ApprovalPolicy::query()->where('is_active', true)->whereNotNull('escalation_after_hours')
            ->where('escalation_after_hours', '>', 0)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))->get();

        foreach ($policies->groupBy('document_type') as $documentType => $documentPolicies) {
            $pendingStatus = self::PENDING_STATUS[$documentType] ?? null;
            if ($pendingStatus === null || !class_exists($documentType)) continue;
            $query = $documentType::query()->where('status', $pendingStatus)
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));
            foreach ($query->get() as $document) {
                if (!$document instanceof Model || !$document->getAttribute('created_at')) continue;
                $policy = $approvalGuard->pendingPolicy($document);
                if (!$policy || !$policy->escalation_after_hours) continue;
                $lastStepAt = $document->created_at;
                $latestAction = ApprovalAction::query()->where('company_id', $document->company_id)
                    ->where('document_type', $document->getMorphClass())->where('document_id', $document->getKey())
                    ->latest('approved_at')->first();
                if ($latestAction?->approved_at) $lastStepAt = $latestAction->approved_at;
                if ($lastStepAt->copy()->addHours((int) $policy->escalation_after_hours)->isFuture()) continue;
                $users = User::query()->where('is_active', true)->where('company_id', $document->company_id)
                    ->with('roles.permissions')->get()
                    ->filter(fn (User $user): bool => $user->hasPermission($policy->required_permission));
                foreach ($users as $user) {
                    $duplicate = $user->notifications()->where('type', ApprovalEscalationNotification::class)
                        ->whereDate('created_at', Carbon::today())
                        ->whereJsonContains('data->document_type', $document->getMorphClass())
                        ->whereJsonContains('data->document_id', $document->getKey())->exists();
                    if ($duplicate) continue;
                    $user->notify(new ApprovalEscalationNotification([
                        'document_type' => $document->getMorphClass(), 'document_id' => $document->getKey(),
                        'document_no' => $document->getAttribute('order_no') ?: $document->getAttribute('invoice_no') ?: $document->getAttribute('document_no') ?: (string) $document->getKey(),
                        'approval_step' => (int) $policy->approval_step, 'required_permission' => $policy->required_permission,
                        'overdue_since' => $lastStepAt->copy()->addHours((int) $policy->escalation_after_hours)->toISOString(),
                    ]));
                    $sent++;
                }
            }
        }
        $this->info("Created {$sent} approval escalation alert(s).");
        return self::SUCCESS;
    }
}
