<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\StorePosSettingsService;
use InvalidArgumentException;
use Tests\TestCase;

class StorePosSettingsServiceTest extends TestCase
{
    public function test_default_tenders_are_available_for_legacy_stores(): void
    {
        $service = new StorePosSettingsService();
        $this->assertSame(StorePosSettingsService::TENDERS, $service->allowedTenders(new Store()));
    }

    public function test_settings_normalize_known_pos_controls_and_preserve_extensions(): void
    {
        $service = new StorePosSettingsService();
        $settings = $service->normalize([
            'allowed_tenders' => ['card', 'card', 'cash'],
            'receipt_footer' => 'Thank you',
            'require_customer' => 1,
            'auto_print_receipt' => 0,
            'cash_variance_tolerance' => '1.25',
            'custom_terminal_key' => 'terminal-1',
        ]);

        $this->assertSame(['card', 'cash'], $settings['allowed_tenders']);
        $this->assertTrue($settings['require_customer']);
        $this->assertFalse($settings['auto_print_receipt']);
        $this->assertSame(1.25, $settings['cash_variance_tolerance']);
        $this->assertSame('terminal-1', $settings['custom_terminal_key']);
    }

    public function test_invalid_tenders_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StorePosSettingsService())->normalize(['allowed_tenders' => ['crypto']]);
    }

    public function test_allowed_tenders_are_read_from_store_settings(): void
    {
        $store = new Store();
        $store->pos_settings = ['allowed_tenders' => ['card']];
        $this->assertSame(['card'], (new StorePosSettingsService())->allowedTenders($store));
    }
}
