<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Services\InventoryAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendLowStockAlerts extends Command
{
    protected $signature = 'erp:inventory:low-stock-alerts {--company= : Limit alerts to a company ID}';
    protected $description = 'Notify authorized users about products at or below their reorder level';

    public function handle(): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $products = Product::where('status', 1)->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get();
        $availability = app(InventoryAvailabilityService::class)->availableMany($products, true, null, $companyId);
        $users = User::where('is_active', true)->with('roles.permissions')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->get()->filter(fn (User $user): bool => $user->hasPermission('reports.view') || $user->hasPermission('inventory.view'));
        $sent = 0;
        foreach ($products as $product) {
            $available = (float) ($availability[$product->id] ?? 0);
            if ($available > (float) ($product->reorder_level ?? 0)) continue;
            foreach ($users->where('company_id', $product->company_id) as $user) {
                $duplicate = $user->notifications()->where('type', LowStockNotification::class)->whereDate('created_at', Carbon::today())->whereJsonContains('data->product_id', $product->id)->exists();
                if ($duplicate) continue;
                $user->notify(new LowStockNotification(['product_id' => $product->id, 'product' => $product->name, 'sku' => $product->sku, 'available' => $available, 'reorder_level' => (float) ($product->reorder_level ?? 0), 'min_stock' => $product->min_stock === null ? null : (float) $product->min_stock]));
                $sent++;
            }
        }
        $this->info("Created {$sent} low-stock alert(s).");
        return self::SUCCESS;
    }
}
