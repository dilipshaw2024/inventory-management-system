<?php

namespace Tests\Unit;

use App\Services\ProductSpreadsheetService;
use PHPUnit\Framework\TestCase;

class ProductSpreadsheetTest extends TestCase
{
    public function test_xlsx_export_can_be_read_back(): void
    {
        $service = new ProductSpreadsheetService();
        $path = tempnam(sys_get_temp_dir(), 'product-xlsx-test-');
        file_put_contents($path, $service->write(ProductSpreadsheetService::HEADERS, [[7, 'Widget & cable', 'W-7', '890123', '', 2, 3, '', 'HSN-1', '10.50', '20.00', 1, 100, 5, 18, '', 1.2, 0.4, 0.2, 0.1, 'batch', 'stock', 'active', 1, 1, 1, 1]]));

        try {
            $rows = $service->read($path, 'xlsx');
            self::assertSame('Widget & cable', $rows[0]['name']);
            self::assertSame('W-7', $rows[0]['sku']);
            self::assertSame('batch', $rows[0]['tracking_type']);
        } finally {
            unlink($path);
        }
    }

    public function test_export_schema_contains_lifecycle_controls(): void
    {
        self::assertContains('product_type', ProductSpreadsheetService::HEADERS);
        self::assertContains('lifecycle_status', ProductSpreadsheetService::HEADERS);
        self::assertContains('can_sell', ProductSpreadsheetService::HEADERS);
    }
}
