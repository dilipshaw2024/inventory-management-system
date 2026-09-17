<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\ProductSpreadsheetService;

class InventoryAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'abc_basis' => ['nullable', 'in:revenue,movement,value'], 'category_id' => ['nullable', 'integer']]);
        $from = $data['from'] ?? now()->subYear()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $abcBasis = $data['abc_basis'] ?? 'revenue';
        $categoryId = $data['category_id'] ?? null;
        $rows = $this->analyticsRows($from, $to, $abcBasis, $categoryId);
        return view('backend.stock.inventory_analytics', compact('rows', 'from', 'to', 'abcBasis', 'categoryId'));
    }

    public function export(Request $request, ProductSpreadsheetService $spreadsheet): Response
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:csv,xlsx'],
            'abc_basis' => ['nullable', 'in:revenue,movement,value'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $from = $validated['from'];
        $to = $validated['to'];
        $abcBasis = $validated['abc_basis'] ?? 'revenue';
        $categoryId = $validated['category_id'] ?? null;
        $rows = $this->analyticsRows($from, $to, $abcBasis, $categoryId);
        $format = $validated['format'] ?? 'csv';
        $headers = ['Product', 'Category', 'Revenue', 'COGS', 'Gross profit', 'Margin %', 'Quantity sold', 'Opening stock', 'Average stock', 'Inventory value', 'Turnover', 'Days of inventory', 'ABC basis', 'ABC'];
        $values = $rows->map(fn (array $row): array => [$row['product']->name, $row['product']->category?->name ?: 'Uncategorized', number_format($row['revenue'], 2, '.', ''), number_format($row['cogs'], 2, '.', ''), number_format($row['gross_profit'], 2, '.', ''), number_format($row['margin_percent'], 2, '.', ''), number_format($row['quantity_sold'], 3, '.', ''), number_format($row['opening_stock'], 3, '.', ''), number_format($row['average_stock'], 3, '.', ''), number_format($row['inventory_value'], 2, '.', ''), number_format($row['turnover'], 2, '.', ''), $row['days_of_inventory'] === null ? '' : number_format($row['days_of_inventory'], 2, '.', ''), $row['abc_basis'] ?? 'revenue', $row['abc']])->all();

        if ($format === 'xlsx') {
            return response($spreadsheet->write($headers, $values), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="inventory-analytics-'.$from.'-to-'.$to.'.xlsx"',
            ]);
        }

        return response()->streamDownload(function () use ($headers, $values): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);
            foreach ($values as $value) fputcsv($output, $value);
            fclose($output);
        }, 'inventory-analytics-'.$from.'-to-'.$to.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function analyticsRows(string $from, string $to, string $abcBasis = 'revenue', ?int $categoryId = null)
    {
        return app(\App\Services\InventoryAnalyticsService::class)->rowsForCompany((int) auth()->user()?->company_id, $from, $to, null, $abcBasis, $categoryId);
    }
}
