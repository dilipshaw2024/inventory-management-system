<?php

namespace App\Services;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalAction;
use Illuminate\Database\Eloquent\Model;
use App\Models\ApprovalDelegation;
use App\Models\User;

class ApprovalGuard
{
    /**
     * Records only an intermediate step before a caller opens its posting
     * transaction. This keeps the step durable when the caller intentionally
     * aborts with a "next approver required" exception.
     */
    public function assertBeforeTransaction(string $modelClass, int $id): void
    {
        $document = $this->companyScope($modelClass::query())->findOrFail($id);
        $amount = $this->amountOf($document);
        $policies = $this->matchingPolicies($document, $amount);
        if ($policies->isEmpty()) return;
        $steps = $policies->groupBy('approval_step')->sortKeys();
        if ($steps->count() < 2) return;
        $completed = $this->companyScope(ApprovalAction::query(), $document->getAttribute('company_id'))
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->pluck('approval_step')->map(fn ($step): int => (int) $step)->all();
        $currentStep = $steps->keys()->first(fn ($step): bool => !in_array((int) $step, $completed, true));
        if ($currentStep !== null && (int) $currentStep !== (int) $steps->keys()->last()) {
            $this->assertDifferent($document);
        }
    }

    public function assertDifferent(Model $document): void
    {
        $creatorId = $document->getAttribute('created_by');
        $approverId = request()->user()?->id ?? auth()->id();
        if ($creatorId && $approverId && (int) $creatorId === (int) $approverId) throw new \RuntimeException('Maker-checker control: the creator cannot approve this transaction.');

        $this->assertPolicy($document);
    }

    /** Return the active policy for the next approval step, if any. */
    public function pendingPolicy(Model $document): ?ApprovalPolicy
    {
        $policies = $this->matchingPolicies($document, $this->amountOf($document));
        if ($policies->isEmpty()) return null;
        $steps = $policies->groupBy('approval_step')->sortKeys();
        $completed = $this->companyScope(ApprovalAction::query(), $document->getAttribute('company_id'))
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->pluck('approval_step')->map(fn ($step): int => (int) $step)->all();
        $currentStep = $steps->keys()->first(fn ($step): bool => !in_array((int) $step, $completed, true));
        return $currentStep === null ? null : $steps->get($currentStep)->first();
    }

    private function assertPolicy(Model $document): void
    {
        $amount = $this->amountOf($document);
        $policies = $this->matchingPolicies($document, $amount);

        if ($policies->isEmpty()) return;

        $steps = $policies->groupBy('approval_step')->sortKeys();
        $completed = $this->companyScope(ApprovalAction::query(), $document->getAttribute('company_id'))
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->pluck('approval_step')->map(fn ($step): int => (int) $step)->all();
        $currentStep = $steps->keys()->first(fn ($step): bool => !in_array((int) $step, $completed, true));
        // A document can pass through more than one lifecycle action (for
        // example approve, dispatch, and receive). Once its configured
        // approval steps are complete, later lifecycle actions must not be
        // mistaken for a second approval cycle.
        if ($currentStep === null) return;
        $policy = $steps->get($currentStep)->first();

        if ($policy && $policy->required_permission && !$this->hasPermissionOrDelegation($policy->required_permission, $document->getMorphClass(), $document->getAttribute('company_id'))) {
            throw new \RuntimeException('Approval policy requires permission: '.$policy->required_permission.'.');
        }

        ApprovalAction::firstOrCreate([
            'company_id' => $document->getAttribute('company_id') ?: auth()->user()?->company_id,
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
            'approval_step' => (int) $currentStep,
        ], ['acted_by' => auth()->id(), 'approved_at' => now()]);

        if ($steps->count() > 1 && (int) $currentStep !== (int) $steps->keys()->last()) {
            throw new \RuntimeException('Approval step '.$currentStep.' recorded. The next approval step is required before posting.');
        }
    }

    private function hasPermissionOrDelegation(string $permission, string $documentType, ?int $companyId = null): bool
    {
        if (auth()->user()?->hasPermission($permission)) return true;
        $now = now();
        return $this->companyScope(ApprovalDelegation::query(), $companyId)
            ->where('delegate_id', auth()->id())
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->get()
            ->contains(function (ApprovalDelegation $delegation) use ($permission, $documentType, $companyId): bool {
                $types = $delegation->document_types ?: [];
                if ($types && !in_array('*', $types, true) && !in_array($documentType, $types, true)) return false;
                $delegator = User::whereKey($delegation->delegator_id)
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                    ->first();
                return $delegator?->hasPermission($permission) === true;
            });
    }

    private function amountOf(Model $document): float
    {
        foreach (['grand_total', 'total_amount', 'total', 'amount', 'net_total'] as $field) {
            if ($document->getAttribute($field) !== null) return (float) $document->getAttribute($field);
        }
        return 0.0;
    }

    private function matchingPolicies(Model $document, float $amount)
    {
        $branchId = $document->getAttribute('branch_id');
        $categoryIds = [];
        if ($document->getAttribute('category_id')) $categoryIds[] = (int) $document->getAttribute('category_id');
        if (method_exists($document, 'lines')) {
            foreach ($document->lines as $line) {
                $categoryId = method_exists($line, 'product') ? $line->product?->category_id : null;
                if ($categoryId) $categoryIds[] = (int) $categoryId;
            }
        }
        $categoryIds = array_values(array_unique($categoryIds));
        return $this->companyScope(ApprovalPolicy::query(), $document->getAttribute('company_id'))
            ->where('document_type', $document->getMorphClass())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('min_amount')->orWhere('min_amount', '<=', $amount))
            ->where(fn ($query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->where(fn ($query) => $branchId
                ? $query->whereNull('branch_id')->orWhere('branch_id', $branchId)
                : $query->whereNull('branch_id'))
            ->where(fn ($query) => $categoryIds
                ? $query->whereNull('category_id')->orWhereIn('category_id', $categoryIds)
                : $query->whereNull('category_id'))
            ->orderBy('approval_step')->orderByDesc('branch_id')->orderByDesc('category_id')->orderByDesc('min_amount')->get();
    }

    private function companyScope($query, ?int $companyId = null)
    {
        $companyId ??= auth()->user()?->company_id;
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
