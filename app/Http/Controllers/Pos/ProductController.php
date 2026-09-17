<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Brand;
use App\Models\TaxRate;
use App\Models\ProductBarcode;
use App\Http\Requests\Pos\ProductRequest;
use Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Services\QrCodeService;
use App\Services\ProductSpreadsheetService;
use App\Services\NumberingSequenceService;
use Illuminate\Support\Str;
  
class ProductController extends Controller
{
    public function barcodes()
    {
        $products = Product::where('status', 1)->orderBy('name')->get();
        $barcodes = ProductBarcode::with('product')->latest()->paginate(50);
        return view('backend.product.barcodes', compact('products', 'barcodes'));
    }

    public function storeBarcode(Request $request)
    {
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'));
        $data = $request->validate(['product_id' => ['required', 'integer', $productScope], 'code' => ['required', 'string', 'max:120', 'unique:product_barcodes,code'], 'type' => ['required', 'in:barcode,qrcode'], 'is_primary' => ['nullable', 'boolean'], 'description' => ['nullable', 'string', 'max:255']]);
        $barcode = DB::transaction(function () use ($data): ProductBarcode {
            if (!empty($data['is_primary'])) ProductBarcode::where('product_id', $data['product_id'])->update(['is_primary' => false]);
            $barcode = ProductBarcode::create($data + ['is_primary' => (bool) ($data['is_primary'] ?? false)]);
            $product = Product::findOrFail($data['product_id']);
            if ($barcode->is_primary || !$product->barcode) $product->update(['barcode' => $barcode->code]);
            return $barcode;
        });
        return back()->with(['message' => 'Product barcode saved.', 'alert-type' => 'success']);
    }

    public function barcodeLookup(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:120']]);
        $barcode = ProductBarcode::with('product')->where('code', $data['code'])->first();
        $product = $barcode?->product ?: Product::where('barcode', $data['code'])->first();
        if (!$product) return response()->json(['message' => 'Barcode not found.'], 404);
        return response()->json(['product' => $product->only(['id', 'name', 'sku', 'barcode', 'quantity', 'sales_price', 'tax_rate']), 'barcode_type' => $barcode?->type ?? 'barcode']);
    }

    public function barcodePrint(Request $request)
    {
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'));
        $data = $request->validate(['product_id' => ['required', 'integer', $productScope]]);
        $product = Product::with('barcodes')->findOrFail($data['product_id']);
        $qrImages = $product->barcodes->where('type', 'qrcode')->mapWithKeys(fn ($barcode): array => [$barcode->id => app(QrCodeService::class)->pngDataUri($barcode->code)])->all();
        return view('backend.product.barcode_print', compact('product', 'qrImages'));
    }
    public function ProductAll(){

        $product = Product::with(['attachments' => fn ($query) => $query->where('attachment_type', 'image')->where('is_primary', true)->latest('id')])->latest()->get();
        return view('backend.product.product_all',compact('product'));

    } // End Method 


    public function ProductAdd(){

        $supplier = Supplier::orderBy('id','desc')->get();
        $category = Category::orderBy('id','desc')->get();
        $unit = Unit::orderBy('id','desc')->get();
        $brands = Brand::orderBy('name')->get();
        $taxRates = TaxRate::where('is_active', true)->orderBy('name')->get();
        return view('backend.product.product_add',compact('supplier','category','unit','brands','taxRates'));
    } // End Method 


    public function ProductStore(ProductRequest $request){

        $companyId = Auth::user()->company_id;
        $sku = $request->filled('sku') ? $request->sku : $this->nextSku($companyId);

        Product::create([

            'name' => $request->name,
            'company_id' => $companyId,
            'supplier_id' => $request->supplier_id,
            'unit_id' => $request->unit_id,
            'category_id' => $request->category_id,
            'brand_id' => $request->brand_id,
            'sku' => $sku,
            'barcode' => $request->barcode,
            'hsn_sac_code' => $request->hsn_sac_code,
            'purchase_price' => $request->purchase_price,
            'sales_price' => $request->sales_price,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock,
            'reorder_level' => $request->reorder_level ?? 0,
            'tax_rate' => $request->filled('tax_rate') ? $request->tax_rate : (float) (Category::whereKey($request->category_id)->value('tax_rate') ?? 0),
            'tax_rate_id' => $request->tax_rate_id,
            'tracking_type' => $request->tracking_type,
            'product_type' => $request->input('product_type', 'stock'),
            'lifecycle_status' => $request->input('lifecycle_status', 'active'),
            'can_purchase' => $request->boolean('can_purchase', true),
            'can_sell' => $request->boolean('can_sell', true),
            'is_stock_item' => $request->boolean('is_stock_item', true),
            'weight_kg' => $request->weight_kg,
            'length_m' => $request->length_m,
            'width_m' => $request->width_m,
            'height_m' => $request->height_m,
            'quantity' => '0',
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(), 
        ]);

        $notification = array(
            'message' => 'Product Inserted Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('product.all')->with($notification); 

    } // End Method 



    public function ProductEdit($id){

        $supplier = Supplier::orderBy('id','desc')->get();
        $category = Category::orderBy('id','desc')->get();
        $unit = Unit::orderBy('id','desc')->get();
        $brands = Brand::orderBy('name')->get();
        $taxRates = TaxRate::where('is_active', true)->orderBy('name')->get();
        $product = Product::findOrFail($id);
        $attachments = $product->attachments()->latest()->get();
        return view('backend.product.product_edit',compact('product','supplier','category','unit','brands','attachments','taxRates'));
    } // End Method 



    public function ProductUpdate(Request $request){

        $request->validate([
            'id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($request->id)->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($request->id)->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'hsn_sac_code' => ['nullable', 'string', 'max:30'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'gte:min_stock'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where(fn ($query) => $query->where('company_id', Auth::user()?->company_id)->orWhereNull('company_id'))],
            'tracking_type' => ['required', 'in:none,batch,serial'],
            'product_type' => ['nullable', 'in:stock,service,consumable,asset,bundle'],
            'lifecycle_status' => ['nullable', 'in:draft,active,discontinued,blocked,archived'],
            'can_purchase' => ['nullable', 'boolean'], 'can_sell' => ['nullable', 'boolean'], 'is_stock_item' => ['nullable', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'], 'length_m' => ['nullable', 'numeric', 'min:0'], 'width_m' => ['nullable', 'numeric', 'min:0'], 'height_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product_id = $request->id;
        $product = Product::findOrFail($product_id);
        try { app(\App\Services\ProductLifecycleService::class)->assertTransitionAllowed($product, $request->only(['is_stock_item', 'lifecycle_status'])); }
        catch (\RuntimeException $exception) { return redirect()->back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }

         $product->update([

            'name' => $request->name,
            'supplier_id' => $request->supplier_id,
            'unit_id' => $request->unit_id,
            'category_id' => $request->category_id, 
            'brand_id' => $request->brand_id,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'hsn_sac_code' => $request->hsn_sac_code,
            'purchase_price' => $request->purchase_price,
            'sales_price' => $request->sales_price,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock,
            'reorder_level' => $request->reorder_level ?? 0,
            'tax_rate' => $request->filled('tax_rate') ? $request->tax_rate : (float) (Category::whereKey($request->category_id)->value('tax_rate') ?? 0),
            'tax_rate_id' => $request->tax_rate_id,
            'tracking_type' => $request->tracking_type,
            'product_type' => $request->input('product_type', 'stock'),
            'lifecycle_status' => $request->input('lifecycle_status', 'active'),
            'can_purchase' => $request->boolean('can_purchase', true),
            'can_sell' => $request->boolean('can_sell', true),
            'is_stock_item' => $request->boolean('is_stock_item', true),
            'weight_kg' => $request->weight_kg,
            'length_m' => $request->length_m,
            'width_m' => $request->width_m,
            'height_m' => $request->height_m,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(), 
        ]);

        $notification = array(
            'message' => 'Product Updated Successfully', 
            'alert-type' => 'success'
        );

        return redirect()->route('product.all')->with($notification); 


    } // End Method 


    public function ProductDelete($id){
       $product = Product::findOrFail($id);
       if ($product->purchases()->exists() || $product->invoiceDetails()->exists() || $product->quantity > 0) {
            return redirect()->back()->with(['message' => 'This product cannot be deleted because it has stock or transaction history.', 'alert-type' => 'error']);
       }
       $product->delete();
       return redirect()->back()->with(['message' => 'Product Deleted Successfully', 'alert-type' => 'success']);
    }

    public function ProductExport(Request $request, ProductSpreadsheetService $spreadsheet): Response
    {
        $rows = Product::orderBy('id')->get()->map(fn ($product) => [$product->id, $product->name, $product->sku, $product->barcode, $product->supplier_id, $product->unit_id, $product->category_id, $product->brand_id, $product->hsn_sac_code, $product->purchase_price, $product->sales_price, $product->min_stock, $product->max_stock, $product->reorder_level, $product->tax_rate, $product->tax_rate_id, $product->weight_kg, $product->length_m, $product->width_m, $product->height_m, $product->tracking_type, $product->product_type, $product->lifecycle_status, $product->can_purchase ? 1 : 0, $product->can_sell ? 1 : 0, $product->is_stock_item ? 1 : 0, $product->status]);
        if ($request->query('format') === 'csv') return response()->streamDownload(function () use ($rows): void { $handle = fopen('php://output', 'w'); fputcsv($handle, ProductSpreadsheetService::HEADERS); foreach ($rows as $row) fputcsv($handle, $row); fclose($handle); }, 'products-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
        return response($spreadsheet->write(ProductSpreadsheetService::HEADERS, $rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="products-'.now()->format('Ymd-His').'.xlsx"']);
    }

    public function ProductImport(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'], 'dry_run' => ['nullable', 'boolean']]);
        $dryRun = $request->boolean('dry_run');
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        try { $importRows = app(ProductSpreadsheetService::class)->read($request->file('file')->getRealPath(), $extension); }
        catch (\Throwable $exception) { return back()->withInput()->with(['message' => 'Import rejected: '.$exception->getMessage(), 'alert-type' => 'error']); }
        $headers = array_keys($importRows[0] ?? []);
        $required = ['name', 'unit_id', 'category_id'];
        $errors = [];
        $rows = [];
        foreach ($required as $field) if (!in_array($field, $headers, true)) $errors[] = "Header {$field} is required.";
        if (count($headers) !== count(array_unique($headers))) $errors[] = 'CSV headers must be unique.';
        $seenSkus = [];
        $seenBarcodes = [];
        $line = 1;
        foreach ($importRows as $row) {
            $line++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            foreach ($required as $field) if (blank($row[$field] ?? null)) $errors[] = "Row {$line}: {$field} is required.";
            if (!empty($row['tracking_type']) && !in_array($row['tracking_type'], ['none', 'batch', 'serial'], true)) $errors[] = "Row {$line}: tracking_type must be none, batch, or serial.";
            if (!empty($row['product_type']) && !in_array($row['product_type'], ['stock', 'service', 'consumable', 'asset', 'bundle'], true)) $errors[] = "Row {$line}: product_type is invalid.";
            if (!empty($row['lifecycle_status']) && !in_array($row['lifecycle_status'], ['draft', 'active', 'discontinued', 'blocked', 'archived'], true)) $errors[] = "Row {$line}: lifecycle_status is invalid.";
            foreach (['can_purchase', 'can_sell', 'is_stock_item'] as $flag) if ($row[$flag] !== null && $row[$flag] !== '' && !in_array((string) $row[$flag], ['0', '1'], true)) $errors[] = "Row {$line}: {$flag} must be 0 or 1.";
            foreach (['purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'weight_kg', 'length_m', 'width_m', 'height_m'] as $field) {
                if ($row[$field] !== null && $row[$field] !== '' && !is_numeric($row[$field])) $errors[] = "Row {$line}: {$field} must be numeric.";
                if ($row[$field] !== null && $row[$field] !== '' && is_numeric($row[$field]) && (float) $row[$field] < 0) $errors[] = "Row {$line}: {$field} cannot be negative.";
            }
            if (is_numeric($row['max_stock'] ?? null) && is_numeric($row['min_stock'] ?? null) && (float) $row['max_stock'] < (float) $row['min_stock']) $errors[] = "Row {$line}: max_stock cannot be below min_stock.";
            if (is_numeric($row['tax_rate'] ?? null) && ((float) $row['tax_rate'] < 0 || (float) $row['tax_rate'] > 100)) $errors[] = "Row {$line}: tax_rate must be between 0 and 100.";
            if (!empty($row['status']) && !in_array((string) $row['status'], ['0', '1'], true)) $errors[] = "Row {$line}: status must be 0 or 1.";
            foreach (['supplier_id' => Supplier::class, 'unit_id' => Unit::class, 'category_id' => Category::class, 'brand_id' => Brand::class, 'tax_rate_id' => TaxRate::class] as $field => $model) {
                if (!empty($row[$field]) && !$model::whereKey($row[$field])->exists()) $errors[] = "Row {$line}: {$field} does not exist.";
            }
            if (!empty($row['id']) && !Product::whereKey($row['id'])->exists()) $errors[] = "Row {$line}: product id does not exist.";
            if (!empty($row['sku']) && Product::where('sku', $row['sku'])->when($row['id'] ?? null, fn ($query) => $query->where('id', '!=', $row['id']))->exists()) $errors[] = "Row {$line}: SKU already exists.";
            if (!empty($row['barcode']) && Product::where('barcode', $row['barcode'])->when($row['id'] ?? null, fn ($query) => $query->where('id', '!=', $row['id']))->exists()) $errors[] = "Row {$line}: barcode already exists.";
            if (!empty($row['sku']) && in_array($row['sku'], $seenSkus, true)) $errors[] = "Row {$line}: SKU is duplicated in this file.";
            if (!empty($row['barcode']) && in_array($row['barcode'], $seenBarcodes, true)) $errors[] = "Row {$line}: barcode is duplicated in this file.";
            if (!empty($row['sku'])) $seenSkus[] = $row['sku'];
            if (!empty($row['barcode'])) $seenBarcodes[] = $row['barcode'];
            $rows[] = $row;
        }
        if ($dryRun) {
            session(['product_import.errors' => $errors]);
            return view('backend.product.import_preview', compact('rows', 'errors'));
        }
        if ($errors) return back()->withInput()->with(['message' => 'Import rejected: '.implode(' ', $errors), 'alert-type' => 'error']);
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $payload = collect($row)->only(['name', 'sku', 'barcode', 'supplier_id', 'unit_id', 'category_id', 'brand_id', 'hsn_sac_code', 'purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'tax_rate_id', 'weight_kg', 'length_m', 'width_m', 'height_m', 'tracking_type', 'product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item', 'status'])->filter(fn ($value) => $value !== null && $value !== '')->all();
                $payload['status'] = $payload['status'] ?? 1;
                $payload['product_type'] = $payload['product_type'] ?? 'stock'; $payload['lifecycle_status'] = $payload['lifecycle_status'] ?? 'active'; $payload['can_purchase'] = (int) ($payload['can_purchase'] ?? 1); $payload['can_sell'] = (int) ($payload['can_sell'] ?? 1); $payload['is_stock_item'] = (int) ($payload['is_stock_item'] ?? 1);
                if (!empty($row['id'])) {
                    $product = Product::findOrFail($row['id']);
                    app(\App\Services\ProductLifecycleService::class)->assertTransitionAllowed($product, $payload);
                    $product->update($payload + ['updated_by' => auth()->id()]);
                } else {
                    if (empty($payload['sku'])) $payload['sku'] = $this->nextSku(auth()->user()?->company_id);
                    Product::create($payload + ['company_id' => auth()->user()?->company_id, 'quantity' => 0, 'created_by' => auth()->id()]);
                }
            }
        });
        return redirect()->route('product.all')->with(['message' => count($rows).' products imported successfully.', 'alert-type' => 'success']);
    }

    public function ProductImportErrors(): StreamedResponse
    {
        $errors = (array) session('product_import.errors', []);
        return response()->streamDownload(function () use ($errors): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['error']);
            foreach ($errors as $error) fputcsv($handle, [$error]);
            fclose($handle);
        }, 'product-import-errors-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function nextSku(?int $companyId): string
    {
        do {
            $sku = app(NumberingSequenceService::class)->nextOrFallback('product', 'SKU-'.Str::upper(Str::random(10)), $companyId);
        } while (Product::where('sku', $sku)->exists());
        return $sku;
    }
}
 
