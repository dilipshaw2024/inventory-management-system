<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\QualityInspection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QualityInspectionReportController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'inspection_type' => ['nullable', 'in:receiving,in_process,final,return'],
            'status' => ['nullable', 'in:pending,in_progress,passed,failed,conditionally_accepted'],
        ]);
        $from = $data['from'] ?? now()->subYear()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $query = $this->query($request, $from, $to, $data);
        $inspections = (clone $query)->latest('id')->paginate(50)->withQueryString();
        $summary = $this->summary((clone $query)->get());
        $companyId = (int) $request->user()->company_id;
        $products = Product::where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderBy('name')->get(['id', 'name', 'sku']);
        $locations = InventoryLocation::withoutGlobalScopes()
            ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScopes()
                ->when($request->user()->warehouse_id, fn ($warehouse, $warehouseId) => $warehouse->whereKey($warehouseId))
                ->whereHas('branch', fn ($branch) => $branch->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->when($request->user()->branch_id, fn ($q, $branchId) => $q->whereKey($branchId))))
            ->orderBy('code')->get(['id', 'code', 'warehouse_id']);

        return view('backend.quality.inspection_report', compact('inspections', 'summary', 'from', 'to', 'products', 'locations'));
    }

    public function export(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'inspection_type' => ['nullable', 'in:receiving,in_process,final,return'],
            'status' => ['nullable', 'in:pending,in_progress,passed,failed,conditionally_accepted'],
        ]);
        $rows = $this->query($request, $data['from'], $data['to'], $data)->get();
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Inspection', 'Product', 'SKU', 'Inspection type', 'Location', 'Warehouse', 'Status', 'Disposition', 'Quantity', 'Sample quantity', 'Created at', 'Completed at']);
            foreach ($rows as $inspection) {
                fputcsv($output, [
                    $inspection->inspection_no,
                    $inspection->product?->name,
                    $inspection->product?->sku,
                    $inspection->plan?->inspection_type,
                    $inspection->location?->code,
                    $inspection->location?->warehouse?->name,
                    $inspection->status,
                    $inspection->disposition,
                    $inspection->quantity,
                    $inspection->sample_quantity,
                    optional($inspection->created_at)->toDateTimeString(),
                    optional($inspection->completed_at)->toDateTimeString(),
                ]);
            }
            fclose($output);
        }, 'quality-inspections-'.$data['from'].'-to-'.$data['to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    private function query(Request $request, string $from, string $to, array $data)
    {
        $companyId = (int) $request->user()->company_id;
        return QualityInspection::with(['plan:id,inspection_type', 'product:id,name,sku', 'location:id,code,warehouse_id', 'location.warehouse:id,name'])
            ->where('company_id', $companyId)
            ->when($request->user()->warehouse_id || $request->user()->branch_id, function ($query) use ($request): void {
                $query->whereHas('location', function ($location) use ($request): void {
                    $location->withoutGlobalScopes()
                        ->when($request->user()->warehouse_id, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
                        ->whereHas('warehouse', fn ($warehouse) => $warehouse->withoutGlobalScopes()->whereHas('branch', fn ($branch) => $branch->withoutGlobalScopes()->when($request->user()->branch_id, fn ($q, $branchId) => $q->whereKey($branchId))));
                });
            })
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['inspection_type'] ?? null, fn ($q, $type) => $q->whereHas('plan', fn ($plan) => $plan->where('inspection_type', $type)));
    }

    private function summary($inspections): array
    {
        $completed = $inspections->whereIn('status', ['passed', 'failed', 'conditionally_accepted']);
        return [
            'inspection_count' => $inspections->count(),
            'pending_count' => $inspections->whereIn('status', ['pending', 'in_progress'])->count(),
            'completed_count' => $completed->count(),
            'passed_count' => $inspections->where('status', 'passed')->count(),
            'failed_count' => $inspections->where('status', 'failed')->count(),
            'conditionally_accepted_count' => $inspections->where('status', 'conditionally_accepted')->count(),
            'pass_rate_percent' => $completed->count() ? round(($inspections->where('status', 'passed')->count() / $completed->count()) * 100, 2) : null,
            'sample_quantity' => (float) $inspections->sum('sample_quantity'),
            'inspected_quantity' => (float) $inspections->sum('quantity'),
        ];
    }
}
