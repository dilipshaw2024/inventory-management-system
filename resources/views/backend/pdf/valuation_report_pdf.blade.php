<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Inventory Valuation</title><style>body{font-family:Arial,sans-serif;font-size:12px;color:#111}h1{font-size:20px;margin-bottom:4px}.muted{color:#666}.filters{margin:12px 0}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #bbb;padding:6px;text-align:left}th{background:#eee}td.number,th.number{text-align:right}@media print{.print-button{display:none}}</style></head>
<body>
<button class="print-button" onclick="window.print()">Print</button>
<h1>Inventory Valuation</h1>
<div class="muted">Generated {{ now()->format('Y-m-d H:i:s') }}</div>
<div class="filters">Historical cost-layer valuation filtered by the selected report criteria.</div>
<table><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Category</th><th class="number">Quantity</th><th class="number">Value</th></tr></thead><tbody>
@forelse($valuation as $product)
<tr><td>{{ $loop->iteration }}</td><td>{{ $product->name }}</td><td>{{ $product->sku }}</td><td>{{ $product->category?->name ?: 'N/A' }}</td><td class="number">{{ $product->filtered_quantity ?? $product->valuation_quantity ?? $product->quantity }}</td><td class="number">{{ number_format((float) $product->ledger_value, 2) }}</td></tr>
@empty
<tr><td colspan="6">No products found.</td></tr>
@endforelse
</tbody></table>
</body></html>
