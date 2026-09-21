<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryPackage;
use App\Models\DeliveryPackageLine;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryPackageIntegrationController extends Controller
{
    public function index(Request $request, int $deliveryId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $this->companyScope(Delivery::query(), $companyId)->findOrFail($deliveryId);
        $data = $request->validate([
            'status' => ['nullable', 'in:packed,dispatched,delivered,cancelled'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $packages = $this->companyScope(DeliveryPackage::with(['lines.product', 'lines.deliveryLine']), $companyId)
            ->where('delivery_id', $deliveryId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate($packages, $request, 'sales.delivery-packages', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request, int $deliveryId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            // Idempotent replay is handled below before the package transaction; the
            // database unique key still protects concurrent duplicate creation.
            'external_reference' => ['nullable', 'string', 'max:150'],
            'carrier' => ['nullable', 'string', 'max:255'], 'tracking_no' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'], 'weight_unit' => ['nullable', 'string', 'max:12'],
            'length' => ['nullable', 'numeric', 'gt:0'], 'width' => ['nullable', 'numeric', 'gt:0'], 'height' => ['nullable', 'numeric', 'gt:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.serial_numbers' => ['nullable', 'string', 'max:5000'],
        ]);

        $delivery = $this->companyScope(Delivery::query(), $companyId)->findOrFail($deliveryId);
        if (in_array($delivery->fulfillment_status, ['cancelled', 'delivered'], true)) {
            return response()->json(['message' => 'Packages cannot be added to a cancelled or delivered delivery.'], 422);
        }
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(DeliveryPackage::with(['lines.product', 'lines.deliveryLine']), $companyId)
                ->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }

        try {
            $package = DB::transaction(function () use ($data, $deliveryId, $companyId, $request): DeliveryPackage {
                $delivery = $this->companyScope(Delivery::with('lines.product'), $companyId)->lockForUpdate()->findOrFail($deliveryId);
                if (in_array($delivery->fulfillment_status, ['cancelled', 'delivered'], true)) throw new \RuntimeException('Packages cannot be added to a cancelled or delivered delivery.');

                $lineIds = collect($data['lines'])->pluck('delivery_line_id')->map(fn ($id): int => (int) $id);
                if ($lineIds->duplicates()->isNotEmpty()) throw new \RuntimeException('A delivery line may appear only once in a package.');
                $deliveryLines = $delivery->lines->keyBy('id');
                $package = DeliveryPackage::create([
                    'company_id' => $companyId, 'delivery_id' => $delivery->id,
                    'package_no' => app(NumberingSequenceService::class)->nextOrFallback('package', 'PKG-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                    'external_reference' => $data['external_reference'] ?? null,
                    'status' => $delivery->fulfillment_status === 'dispatched' ? 'dispatched' : 'packed',
                    'carrier' => $data['carrier'] ?? null, 'tracking_no' => $data['tracking_no'] ?? null,
                    'weight' => $data['weight'] ?? null, 'weight_unit' => $data['weight_unit'] ?? null,
                    'length' => $data['length'] ?? null, 'width' => $data['width'] ?? null, 'height' => $data['height'] ?? null,
                    'packed_at' => now(), 'dispatched_at' => $delivery->fulfillment_status === 'dispatched' ? now() : null, 'created_by' => $request->user()?->id,
                ]);

                foreach ($data['lines'] as $input) {
                    $line = $deliveryLines->get((int) $input['delivery_line_id']);
                    if (!$line) throw new \RuntimeException('A package line does not belong to the selected delivery.');
                    $quantity = (float) $input['quantity'];
                    $alreadyPacked = (float) DeliveryPackageLine::whereHas('package', fn ($query) => $query->where('delivery_id', $delivery->id)->where('status', '!=', 'cancelled'))->where('delivery_line_id', $line->id)->sum('quantity');
                    if ($alreadyPacked + $quantity > (float) $line->delivered_qty + 0.000001) throw new \RuntimeException('Package quantity exceeds the delivered quantity for '.$line->product->name.'.');

                    $serials = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) ($input['serial_numbers'] ?? '')))));
                    if ($serials && count($serials) !== (int) round($quantity)) throw new \RuntimeException('Serial count must equal package quantity for '.$line->product->name.'.');
                    if ($delivery->status === 'approved' && $line->product->tracking_type === 'serial') {
                        if (count($serials) !== (int) round($quantity)) throw new \RuntimeException('Serial numbers are required when packing approved serial-tracked product '.$line->product->name.'.');
                        $issued = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) $line->issued_serial_numbers))));
                        if (array_diff($serials, $issued)) throw new \RuntimeException('One or more package serials were not issued on the delivery.');
                        $used = DeliveryPackageLine::whereHas('package', fn ($query) => $query->where('delivery_id', $delivery->id)->where('status', '!=', 'cancelled'))->where('delivery_line_id', $line->id)->pluck('serial_numbers')->flatMap(fn ($value) => array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) $value))))->all();
                        if (array_intersect($serials, $used)) throw new \RuntimeException('A serial number has already been assigned to another package.');
                    }
                    DeliveryPackageLine::create(['package_id' => $package->id, 'delivery_line_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => $quantity, 'serial_numbers' => $serials ? implode(',', $serials) : null]);
                }
                app(AuditService::class)->record('delivery.package.created', $package, null, $package->load('lines')->toArray());
                return $package->fresh(['lines.product', 'lines.deliveryLine']);
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $package, 'status' => 'packed'], 201);
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
