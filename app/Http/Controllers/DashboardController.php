<?php

namespace App\Http\Controllers;

use App\Models\InventoryAdjustment;

class DashboardController extends Controller
{
    public function index()
    {
        $metrics = app(\App\Services\DashboardMetricsService::class)->forUser(auth()->user());
        $monthSales = $metrics['month_sales']; $totalProducts = $metrics['total_products']; $lowStock = $metrics['low_stock']; $pendingApprovals = $metrics['pending_approvals']; $receivables = $metrics['receivables'];
        $openServiceRequests = $metrics['open_service_requests']; $breachedServiceRequests = $metrics['breached_service_requests']; $activeMaintenanceOrders = $metrics['active_maintenance_orders']; $agingStockValue = $metrics['aging_stock_value'] ?? null; $agedStockValue = $metrics['aged_stock_value'] ?? null; $warehouseUtilization = $metrics['warehouse_utilization'] ?? null;

        $cards = [
                ['Sales this month', number_format($monthSales, 2), 'Approved invoice value', 'ri-line-chart-line', 'primary', $monthSales > 0 ? '100%' : '8%'],
                ['Inventory health', $lowStock === 0 ? 'Healthy' : $lowStock.' low', $lowStock === 0 ? 'No items below reorder level' : $lowStock.' of '.$totalProducts.' products need attention', 'ri-archive-stack-line', 'success', max(8, round(100 * ($totalProducts - min($lowStock, $totalProducts)) / $totalProducts).'%')],
                ['Pending approvals', (string) $pendingApprovals, 'Purchases, invoices, and adjustments', 'ri-time-line', 'warning', $pendingApprovals > 0 ? '100%' : '8%'],
                ['Receivables', number_format($receivables, 2), 'Outstanding customer balance', 'ri-group-line', 'info', $receivables > 0 ? '100%' : '8%'],
                ['Service requests', (string) $openServiceRequests, $breachedServiceRequests.' SLA breached', 'ri-customer-service-2-line', $breachedServiceRequests > 0 ? 'danger' : 'success', $breachedServiceRequests > 0 ? '100%' : '8%'],
                ['Maintenance workload', (string) $activeMaintenanceOrders, 'Planned or in progress', 'ri-tools-line', 'primary', $activeMaintenanceOrders > 0 ? '100%' : '8%'],
                ['Inventory value', $agingStockValue === null ? '—' : number_format($agingStockValue, 2), 'Cost-layer stock on hand', 'ri-stack-line', 'success', $agingStockValue > 0 ? '100%' : '8%'],
                ['Aged inventory', $agedStockValue === null ? '—' : number_format($agedStockValue, 2), 'Stock older than 365 days', 'ri-time-line', $agedStockValue > 0 ? 'warning' : 'success', $agedStockValue > 0 ? '100%' : '8%'],
                ['Warehouse capacity', $warehouseUtilization === null ? '—' : number_format($warehouseUtilization, 1).'%', 'Occupied quantity capacity', 'ri-building-2-line', $warehouseUtilization !== null && $warehouseUtilization >= 80 ? 'warning' : 'info', $warehouseUtilization === null ? '8%' : max(8, round($warehouseUtilization)).'%'],
        ];
        if (!($metrics['visibility']['finance'] ?? true)) $cards = collect($cards)->reject(fn (array $metric): bool => in_array($metric[0], ['Sales this month', 'Receivables'], true))->values()->all();
        if (!($metrics['visibility']['service'] ?? true)) $cards = collect($cards)->reject(fn (array $metric): bool => in_array($metric[0], ['Service requests', 'Maintenance workload'], true))->values()->all();
        if (!($metrics['visibility']['inventory'] ?? true)) $cards = collect($cards)->reject(fn (array $metric): bool => in_array($metric[0], ['Inventory health', 'Inventory value', 'Aged inventory', 'Warehouse capacity'], true))->values()->all();
        return view('admin.index', [
            'metrics' => $cards,
            'salesTrend' => $metrics['sales_trend'] ?? [], 'purchaseTrend' => $metrics['purchase_trend'] ?? [], 'showFinance' => (bool) ($metrics['visibility']['finance'] ?? true),
            'exceptionDrilldowns' => $metrics['exception_drilldowns'] ?? ['low_stock' => [], 'excess_stock' => []],
        ]);
    }
}
