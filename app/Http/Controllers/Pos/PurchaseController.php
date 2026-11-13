<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Category;
use Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Requests\Pos\PurchaseRequest;
use App\Services\InventoryLedgerService;
use App\Services\AuditService;
use App\Services\ProductLifecycleService;
use App\Services\ProductSpreadsheetService;

class PurchaseController extends Controller
{
    private function companyId(): int
    {
        return (int) Auth::user()->company_id;
    }

    private function visibleProducts()
    {
        return Product::where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'));
    }

    private function companyPurchase(int $id): Purchase
    {
        return Purchase::where('company_id', $this->companyId())->findOrFail($id);
    }

    public function PurchaseAll(){

        $allData = Purchase::where('company_id', $this->companyId())->orderBy('date','desc')->orderBy('id','desc')->get();
        return view('backend.purchase.purchase_all',compact('allData'));

    } // End Method 


    public function PurchaseAdd(){

        $companyId = $this->companyId();
        $supplier = Supplier::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('id','desc')->get();
        $unit = Unit::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('id','desc')->get();
        $category = Category::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('id','desc')->get();
        return view('backend.purchase.purchase_add',compact('supplier','unit','category'));

    } // End Method 


    public function PurchaseStore(PurchaseRequest $request){

    if ($request->category_id == null) {

       $notification = array(
        'message' => 'Sorry you do not select any item', 
        'alert-type' => 'error'
    );
    return redirect()->back( )->with($notification);
    } else {

        $count_category = count($request->category_id);
        for ($i=0; $i < $count_category; $i++) {
            $product = $this->visibleProducts()->find($request->product_id[$i]);
            if (!$product || (int) $product->supplier_id !== (int) $request->supplier_id[$i] || (int) $product->category_id !== (int) $request->category_id[$i]) {
                return redirect()->back()->withInput()->with(['message' => 'One or more purchase products do not match the selected supplier/category.', 'alert-type' => 'error']);
            }
            try { app(ProductLifecycleService::class)->assertPurchasable($product); }
            catch (\RuntimeException $exception) { return redirect()->back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        }

        DB::transaction(function () use ($request, $count_category) {
          for ($i=0; $i < $count_category; $i++) {
            $purchase = new Purchase();
            $purchase->company_id = $this->companyId();
            $purchase->date = date('Y-m-d', strtotime($request->date[$i]));
            $purchase->purchase_no = $request->purchase_no[$i];
            $purchase->supplier_id = $request->supplier_id[$i];
            $purchase->category_id = $request->category_id[$i];

            $purchase->product_id = $request->product_id[$i];
            $purchase->buying_qty = $request->buying_qty[$i];
            $purchase->unit_price = $request->unit_price[$i];
            $purchase->buying_price = (float) $request->buying_qty[$i] * (float) $request->unit_price[$i];
            $purchase->description = $request->description[$i];

            $purchase->created_by = Auth::user()->id;
            $purchase->status = '0';
            $purchase->save();
          }
        });
    } // end else 

    $notification = array(
        'message' => 'Data Save Successfully', 
        'alert-type' => 'success'
    );
    return redirect()->route('purchase.all')->with($notification); 
    } // End Method 


    public function PurchaseDelete($id){

        $purchase = $this->companyPurchase((int) $id);
        if ((int) $purchase->status === 1) {
            return redirect()->back()->with(['message' => 'Approved purchases cannot be deleted because they affect stock history.', 'alert-type' => 'error']);
        }
        $purchase->delete();

         $notification = array(
        'message' => 'Purchase Iteam Deleted Successfully', 
        'alert-type' => 'success'
    );
    return redirect()->back()->with($notification); 

    } // End Method 


    public function PurchasePending(){

        $allData = Purchase::where('company_id', $this->companyId())->orderBy('date','desc')->orderBy('id','desc')->where('status','0')->get();
        return view('backend.purchase.purchase_pending',compact('allData'));
    }// End Method 


    public function PurchaseApprove($id){

        $approved = DB::transaction(function () use ($id) {
            $purchase = Purchase::where('company_id', $this->companyId())->lockForUpdate()->findOrFail($id);
            if ((int) $purchase->status === 1) {
                return false;
            }

            $product = $this->visibleProducts()->lockForUpdate()->findOrFail($purchase->product_id);
            app(ProductLifecycleService::class)->assertPurchasable($product);
            $product->quantity = (float) $product->quantity + (float) $purchase->buying_qty;
            $product->save();
            app(InventoryLedgerService::class)->post(
                $product->id,
                'receipt',
                (float) $purchase->buying_qty,
                (float) $purchase->unit_price,
                null,
                $purchase,
                'Approved purchase receipt'
            );
            app(AuditService::class)->record('purchase.approved', $purchase, ['status' => 0], ['status' => 1]);
            $purchase->status = 1;
            $purchase->updated_by = Auth::user()->id;
            $purchase->save();
            return true;
        });

        if($approved){

             $notification = array(
        'message' => 'Status Approved Successfully', 
        'alert-type' => 'success'
          );
    return redirect()->route('purchase.all')->with($notification); 

        }

        return redirect()->route('purchase.all')->with(['message' => 'This purchase is already approved.', 'alert-type' => 'info']);

    }// End Method 


    public function DailyPurchaseReport(){
        return view('backend.purchase.daily_purchase_report');
    }// End Method 


    public function DailyPurchasePdf(Request $request){
        abort_unless(auth()->user()?->company_id, 403, 'A company is required for purchase reporting.');
        $dates = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'compare_start_date' => ['nullable', 'date', 'required_with:compare_end_date'],
            'compare_end_date' => ['nullable', 'date', 'required_with:compare_start_date', 'after_or_equal:compare_start_date'],
        ]);
        $sdate = $dates['start_date'];
        $edate = $dates['end_date'];
        $allData = Purchase::where('company_id', $this->companyId())->whereBetween('date',[$sdate,$edate])->where('status','1')->orderBy('date','desc')->orderBy('id','desc')->get();
        $summary = static fn ($rows): array => [
            'documents' => $rows->count(),
            'quantity' => (float) $rows->sum('buying_qty'),
            'amount' => (float) $rows->sum('buying_price'),
            'average_unit_price' => (float) $rows->sum('buying_qty') > 0 ? (float) $rows->sum('buying_price') / (float) $rows->sum('buying_qty') : 0,
        ];
        $currentSummary = $summary($allData);
        $comparison = null;
        if (!empty($dates['compare_start_date']) && !empty($dates['compare_end_date'])) {
            $compareData = Purchase::where('company_id', $this->companyId())->whereBetween('date', [$dates['compare_start_date'], $dates['compare_end_date']])->where('status', '1')->get();
            $comparison = $summary($compareData);
            foreach (['documents', 'quantity', 'amount', 'average_unit_price'] as $metric) {
                $comparison[$metric.'_variance'] = $currentSummary[$metric] - $comparison[$metric];
                $comparison[$metric.'_variance_percent'] = $comparison[$metric] != 0 ? (($currentSummary[$metric] - $comparison[$metric]) / abs($comparison[$metric])) * 100 : null;
            }
            $comparison['start_date'] = $dates['compare_start_date'];
            $comparison['end_date'] = $dates['compare_end_date'];
        }


        $start_date = date('Y-m-d',strtotime($request->start_date));
        $end_date = date('Y-m-d',strtotime($request->end_date));
        return view('backend.pdf.daily_purchase_report_pdf', compact('allData', 'start_date', 'end_date', 'currentSummary', 'comparison'));

    }// End Method 

    public function DailyPurchaseExport(Request $request, ProductSpreadsheetService $spreadsheet): Response
    {
        abort_unless(auth()->user()?->company_id, 403, 'A company is required for purchase reporting.');
        $dates = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);
        $rows = Purchase::with(['product', 'supplier', 'unit'])
            ->where('company_id', $this->companyId())
            ->whereBetween('date', [$dates['start_date'], $dates['end_date']])
            ->where('status', '1')
            ->orderByDesc('date')->orderByDesc('id')->get();
        $headers = ['Purchase no', 'Date', 'Supplier', 'Product', 'SKU', 'Unit', 'Quantity', 'Unit price', 'Amount'];
        $values = $rows->map(fn (Purchase $purchase): array => [
            $purchase->purchase_no,
            $purchase->date,
            $purchase->supplier?->name ?: 'N/A',
            $purchase->product?->name ?: 'N/A',
            $purchase->product?->sku ?: '',
            $purchase->unit?->name ?: '',
            number_format((float) $purchase->buying_qty, 6, '.', ''),
            number_format((float) $purchase->unit_price, 6, '.', ''),
            number_format((float) $purchase->buying_price, 6, '.', ''),
        ])->all();
        if (($dates['format'] ?? 'csv') === 'xlsx') {
            return response($spreadsheet->write($headers, $values), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="purchase-report-'.now()->toDateString().'.xlsx"']);
        }
        return response()->streamDownload(function () use ($headers, $values): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);
            foreach ($values as $value) fputcsv($output, $value);
            fclose($output);
        }, 'purchase-report-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }




}
  
