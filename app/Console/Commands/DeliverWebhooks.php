<?php

namespace App\Console\Commands;

use App\Models\IntegrationWebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Console\Command;

class DeliverWebhooks extends Command
{
    protected $signature = 'erp:integration:deliver-webhooks {--limit=100}';
    protected $description = 'Deliver pending ERP webhook outbox records with signed retries';

    public function handle(WebhookService $webhooks): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $deliveries = IntegrationWebhookDelivery::with('subscription')->whereHas('subscription', fn ($query) => $query->where('is_active', true))->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit($limit)->get();
        foreach ($deliveries as $delivery) $webhooks->deliver($delivery);
        $this->info('Processed '.$deliveries->count().' webhook deliveries.');
        return self::SUCCESS;
    }
}
