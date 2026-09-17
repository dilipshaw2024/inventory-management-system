<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\BackorderAllocationService;
use Illuminate\Console\Command;

class AllocateBackorders extends Command
{
    protected $signature = 'erp:inventory:allocate-backorders {--company= : Limit allocation to a company ID}';
    protected $description = 'Allocate newly available stock to approved sales-order backorders.';

    public function handle(BackorderAllocationService $service): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))->pluck('id');
        foreach ($companies as $companyId) $this->info('Company '.$companyId.': allocated '.$service->allocateForCompany((int) $companyId).' units.');
        return self::SUCCESS;
    }
}
