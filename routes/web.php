<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Demo\DemoController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Pos\SupplierController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\UnitController;
use App\Http\Controllers\Pos\CategoryController;
use App\Http\Controllers\Pos\ProductController;
use App\Http\Controllers\Pos\PurchaseController;
use App\Http\Controllers\Pos\DefaultController;
use App\Http\Controllers\Pos\InvoiceController;
use App\Http\Controllers\Pos\StockController;
use App\Http\Controllers\Pos\InventoryAdjustmentController;
use App\Http\Controllers\Pos\InventoryTransferController;
use App\Http\Controllers\ErpOrganizationController;
use App\Http\Controllers\Pos\ProcurementController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\PurchaseRfqController;
use App\Http\Controllers\SupplierProductPriceController;
use App\Http\Controllers\CustomerProductPriceController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\SupplierContactController;
use App\Http\Controllers\SupplierPerformanceController;
use App\Http\Controllers\InventoryAnalyticsController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SalesQuotationController;
use App\Http\Controllers\Pos\SalesFulfillmentController;
use App\Http\Controllers\Pos\InventoryReturnController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ManufacturingController;
use App\Http\Controllers\Pos\StockCountController;
use App\Http\Controllers\Pos\InventoryStatusController;
use App\Http\Controllers\Pos\ProductUomController;
use App\Http\Controllers\Pos\PurchaseInvoiceController;
use App\Http\Controllers\Pos\SupplierPaymentController;
use App\Http\Controllers\Pos\CustomerCreditController;
use App\Http\Controllers\Pos\ReceivablesController;
use App\Http\Controllers\Pos\ProductVariantController;
use App\Http\Controllers\Pos\ProductCostingController;
use App\Http\Controllers\Pos\LandedCostController;
use App\Http\Controllers\SecurityRoleController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\FinanceConfigurationController;
use App\Http\Controllers\ApprovalPolicyController;
use App\Http\Controllers\ApprovalDelegationController;
use App\Http\Controllers\ServiceMaintenanceController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\PaymentReversalController;
use App\Http\Controllers\CustomerRefundController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReplenishmentPolicyController;
use App\Http\Controllers\DocumentAttachmentController;
use App\Http\Controllers\DemandForecastController;
use App\Http\Controllers\PutAwayController;
use App\Http\Controllers\DataRetentionController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\UserSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\InventoryDocumentController;
use App\Http\Controllers\Pos\StockReservationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CostCenterController;

Route::get('/', function () {
    return view('welcome');
});
 

Route::controller(DemoController::class)->group(function () {
    Route::get('/about', 'Index')->name('about.page')->middleware('check');
    Route::get('/contact', 'ContactMethod')->name('cotact.page');
});


Route::middleware('auth')->group(function(){

Route::middleware('auth')->controller(NotificationController::class)->prefix('erp/notifications')->name('notifications.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{id}/read', 'read')->name('read');
});



 // Admin All Route 
Route::controller(AdminController::class)->group(function () {
    Route::get('/admin/logout', 'destroy')->name('admin.logout');
    Route::get('/admin/profile', 'Profile')->name('admin.profile');
    Route::get('/edit/profile', 'EditProfile')->name('edit.profile');
    Route::post('/store/profile', 'StoreProfile')->name('store.profile');

    Route::get('/change/password', 'ChangePassword')->name('change.password');
    Route::post('/update/password', 'UpdatePassword')->name('update.password');
     
});

Route::middleware('auth')->controller(UserSessionController::class)->prefix('security/sessions')->name('security.sessions.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{id}/revoke', 'revoke')->name('revoke');
});


 // Supplier All Route 
Route::controller(SupplierController::class)->middleware('permission:purchasing.manage')->group(function () {
    Route::get('/supplier/all', 'SupplierAll')->name('supplier.all'); 
    Route::get('/supplier/add', 'SupplierAdd')->name('supplier.add'); 
    Route::post('/supplier/store', 'SupplierStore')->name('supplier.store');
    Route::get('/supplier/edit/{id}', 'SupplierEdit')->name('supplier.edit'); 
    Route::post('/supplier/update', 'SupplierUpdate')->name('supplier.update');
    Route::post('/supplier/delete/{id}', 'SupplierDelete')->name('supplier.delete');
});


// Customer All Route 
Route::controller(CustomerController::class)->middleware('permission:sales.manage')->group(function () {
    Route::get('/customer/all', 'CustomerAll')->name('customer.all'); 
    Route::get('/customer/add', 'CustomerAdd')->name('customer.add');
    Route::post('/customer/store', 'CustomerStore')->name('customer.store');
    Route::get('/customer/edit/{id}', 'CustomerEdit')->name('customer.edit');
    Route::post('/customer/update', 'CustomerUpdate')->name('customer.update');
    Route::post('/customer/delete/{id}', 'CustomerDelete')->name('customer.delete');

    Route::get('/credit/customer', 'CreditCustomer')->name('credit.customer');
    Route::get('/credit/customer/print/pdf', 'CreditCustomerPrintPdf')->name('credit.customer.print.pdf');

    Route::get('/customer/edit/invoice/{invoice_id}', 'CustomerEditInvoice')->name('customer.edit.invoice');
     Route::post('/customer/update/invoice/{invoice_id}', 'CustomerUpdateInvoice')->name('customer.update.invoice');

     Route::get('/customer/invoice/details/{invoice_id}', 'CustomerInvoiceDetails')->name('customer.invoice.details.pdf');

      Route::get('/paid/customer', 'PaidCustomer')->name('paid.customer');
      Route::get('/paid/customer/print/pdf', 'PaidCustomerPrintPdf')->name('paid.customer.print.pdf');

       Route::get('/customer/wise/report', 'CustomerWiseReport')->name('customer.wise.report');
       Route::get('/customer/wise/credit/report', 'CustomerWiseCreditReport')->name('customer.wise.credit.report');
       Route::get('/customer/wise/paid/report', 'CustomerWisePaidReport')->name('customer.wise.paid.report');
     
});


// Unit All Route 
Route::controller(UnitController::class)->middleware('permission:inventory.post')->group(function () {
    Route::get('/unit/all', 'UnitAll')->name('unit.all'); 
    Route::get('/unit/add', 'UnitAdd')->name('unit.add');
    Route::post('/unit/store', 'UnitStore')->name('unit.store');
    Route::get('/unit/edit/{id}', 'UnitEdit')->name('unit.edit');
    Route::post('/unit/update', 'UnitUpdate')->name('unit.update');
    Route::post('/unit/delete/{id}', 'UnitDelete')->name('unit.delete');
     
});


// Category All Route 
Route::controller(CategoryController::class)->middleware('permission:inventory.post')->group(function () {
    Route::get('/category/all', 'CategoryAll')->name('category.all'); 
    Route::get('/category/add', 'CategoryAdd')->name('category.add');
    Route::post('/category/store', 'CategoryStore')->name('category.store');
    Route::get('/category/edit/{id}', 'CategoryEdit')->name('category.edit');
    Route::post('/category/update', 'CategoryUpdate')->name('category.update');
    Route::post('/category/delete/{id}', 'CategoryDelete')->name('category.delete');
     
});


// Product All Route 
Route::controller(ProductController::class)->middleware('permission:inventory.post')->group(function () {
    Route::get('/product/all', 'ProductAll')->name('product.all'); 
    Route::get('/product/add', 'ProductAdd')->name('product.add');
    Route::post('/product/store', 'ProductStore')->name('product.store');
    Route::get('/product/edit/{id}', 'ProductEdit')->name('product.edit');
    Route::post('/product/update', 'ProductUpdate')->name('product.update');
    Route::post('/product/delete/{id}', 'ProductDelete')->name('product.delete');
    Route::get('/product/export', 'ProductExport')->name('product.export')->middleware('permission:reports.export');
    Route::post('/product/import', 'ProductImport')->name('product.import')->middleware('permission:inventory.post');
    Route::get('/product/import/errors', 'ProductImportErrors')->name('product.import.errors')->middleware('permission:inventory.view');
     
});


  
// Purchase All Route 
Route::controller(PurchaseController::class)->middleware('permission:purchasing.manage')->group(function () {
    Route::get('/purchase/all', 'PurchaseAll')->name('purchase.all'); 
    Route::get('/purchase/add', 'PurchaseAdd')->name('purchase.add');
    Route::post('/purchase/store', 'PurchaseStore')->name('purchase.store');
    Route::post('/purchase/delete/{id}', 'PurchaseDelete')->name('purchase.delete');
    Route::get('/purchase/pending', 'PurchasePending')->name('purchase.pending');
    Route::post('/purchase/approve/{id}', 'PurchaseApprove')->name('purchase.approve')->middleware('permission:inventory.approve');

    Route::get('/daily/purchase/report', 'DailyPurchaseReport')->name('daily.purchase.report');
    Route::get('/daily/purchase/pdf', 'DailyPurchasePdf')->name('daily.purchase.pdf');
     
});


// Invoice All Route 
Route::controller(InvoiceController::class)->middleware('permission:sales.manage')->group(function () {
    Route::get('/invoice/all', 'InvoiceAll')->name('invoice.all'); 
    Route::get('/invoice/add', 'invoiceAdd')->name('invoice.add');
    Route::post('/invoice/store', 'InvoiceStore')->name('invoice.store');

    Route::get('/invoice/pending/list', 'PendingList')->name('invoice.pending.list');
    Route::post('/invoice/delete/{id}', 'InvoiceDelete')->name('invoice.delete');
    Route::get('/invoice/approve/{id}', 'InvoiceApprove')->name('invoice.approve');
    Route::post('/invoice/reject/{id}', 'Reject')->name('invoice.reject')->middleware('permission:inventory.approve');

    Route::post('/approval/store/{id}', 'ApprovalStore')->name('approval.store')->middleware('permission:inventory.approve');
    Route::get('/print/invoice/list', 'PrintInvoiceList')->name('print.invoice.list');
    Route::get('/print/invoice/{id}', 'PrintInvoice')->name('print.invoice');

    Route::get('/daily/invoice/report', 'DailyInvoiceReport')->name('daily.invoice.report');
    Route::get('/daily/invoice/pdf', 'DailyInvoicePdf')->name('daily.invoice.pdf');
    
     
});





// Stock All Route 
Route::controller(StockController::class)->middleware('permission:reports.view')->group(function () {
    Route::get('/stock/report', 'StockReport')->name('stock.report');
    Route::get('/stock/movements', 'MovementReport')->name('stock.movements');
    Route::get('/stock/movements/export', 'MovementExport')->name('stock.movements.export')->middleware('permission:reports.export');
    Route::get('/stock/valuation', 'ValuationReport')->name('stock.valuation');
    Route::get('/stock/location-stock', 'LocationStockReport')->name('stock.location.stock');
    Route::get('/stock/expiry', 'ExpiryReport')->name('stock.expiry');
    Route::get('/stock/batch-stock', 'BatchStockReport')->name('stock.batch.stock');
    Route::get('/stock/serial-stock', 'SerialStockReport')->name('stock.serial.stock');
    Route::get('/stock/traceability', 'TraceabilityReport')->name('stock.traceability');
    Route::get('/stock/report/pdf', 'StockReportPdf')->name('stock.report.pdf'); 

    Route::get('/stock/supplier/wise', 'StockSupplierWise')->name('stock.supplier.wise'); 
    Route::get('/supplier/wise/pdf', 'SupplierWisePdf')->name('supplier.wise.pdf');
    Route::get('/product/wise/pdf', 'ProductWisePdf')->name('product.wise.pdf');

 
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(InventoryAdjustmentController::class)->group(function () {
    Route::get('/inventory/adjustments', 'index')->name('inventory.adjustments');
    Route::get('/inventory/adjustments/add', 'create')->name('inventory.adjustments.create');
    Route::post('/inventory/adjustments', 'store')->name('inventory.adjustments.store')->middleware('permission:inventory.post');
    Route::get('/inventory/opening-stock/add', 'openingCreate')->name('inventory.opening.create');
    Route::post('/inventory/opening-stock', 'openingStore')->name('inventory.opening.store')->middleware('permission:inventory.post');
    Route::post('/inventory/adjustments/{id}/approve', 'approve')->name('inventory.adjustments.approve')->middleware('permission:inventory.approve');
    Route::post('/inventory/adjustments/{id}/reject', 'reject')->name('inventory.adjustments.reject')->middleware('permission:inventory.approve');
});

Route::middleware('auth')->controller(InventoryDocumentController::class)->prefix('inventory/documents')->name('inventory.documents.')->group(function () {
    Route::get('/', 'index')->name('index')->middleware('permission:inventory.view');
    Route::get('/add', 'create')->name('create')->middleware('permission:inventory.post');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:inventory.approve');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:inventory.approve');
    Route::post('/{id}/inspect', 'inspect')->name('inspect')->middleware('permission:inventory.approve');
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(InventoryTransferController::class)->group(function () {
    Route::get('/inventory/transfers', 'index')->name('inventory.transfers');
    Route::get('/inventory/transfers/add', 'create')->name('inventory.transfers.create');
    Route::post('/inventory/transfers', 'store')->name('inventory.transfers.store')->middleware('permission:inventory.post');
    Route::post('/inventory/transfers/{id}/approve', 'approve')->name('inventory.transfers.approve')->middleware('permission:inventory.approve');
    Route::post('/inventory/transfers/{id}/dispatch', 'dispatchTransfer')->name('inventory.transfers.dispatch')->middleware('permission:warehouse.manage');
    Route::post('/inventory/transfers/{id}/receive', 'receive')->name('inventory.transfers.receive')->middleware('permission:warehouse.manage');
    Route::post('/inventory/transfers/{id}/close-shortage', 'closeShortage')->name('inventory.transfers.close-shortage')->middleware('permission:warehouse.manage');
    Route::post('/inventory/transfers/{id}/resolve-variance', 'resolveVariance')->name('inventory.transfers.resolve-variance')->middleware('permission:warehouse.manage');
    Route::post('/inventory/transfers/{id}/reject', 'reject')->name('inventory.transfers.reject')->middleware('permission:inventory.approve');
});

Route::middleware(['auth', 'permission:organization.manage'])->controller(ErpOrganizationController::class)->prefix('erp/organization')->name('erp.organization.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/company', 'company')->name('company')->middleware('permission:organization.manage');
    Route::patch('/company/{id}', 'updateCompany')->name('company.update')->middleware('permission:organization.manage');
    Route::post('/company/{id}/deactivate', 'deactivateCompany')->name('company.deactivate')->middleware('permission:organization.manage');
    Route::post('/branch', 'branch')->name('branch')->middleware('permission:organization.manage');
    Route::patch('/branch/{id}', 'updateBranch')->name('branch.update')->middleware('permission:organization.manage');
    Route::post('/branch/{id}/deactivate', 'deactivateBranch')->name('branch.deactivate')->middleware('permission:organization.manage');
    Route::post('/warehouse', 'warehouse')->name('warehouse')->middleware('permission:organization.manage');
    Route::patch('/warehouse/{id}', 'updateWarehouse')->name('warehouse.update')->middleware('permission:organization.manage');
    Route::post('/warehouse/{id}/deactivate', 'deactivateWarehouse')->name('warehouse.deactivate')->middleware('permission:organization.manage');
    Route::post('/department', 'department')->name('department')->middleware('permission:organization.manage');
    Route::patch('/department/{id}', 'updateDepartment')->name('department.update')->middleware('permission:organization.manage');
    Route::post('/department/{id}/deactivate', 'deactivateDepartment')->name('department.deactivate')->middleware('permission:organization.manage');
    Route::post('/store', 'store')->name('store')->middleware('permission:organization.manage');
    Route::patch('/store/{id}', 'updateStore')->name('store.update')->middleware('permission:organization.manage');
    Route::post('/store/{id}/deactivate', 'deactivateStore')->name('store.deactivate')->middleware('permission:organization.manage');
    Route::post('/location', 'location')->name('location')->middleware('permission:organization.manage');
    Route::patch('/location/{id}', 'updateLocation')->name('location.update')->middleware('permission:organization.manage');
    Route::post('/location/{id}/deactivate', 'deactivateLocation')->name('location.deactivate')->middleware('permission:organization.manage');
    Route::post('/location-rule', 'locationRule')->name('location-rule')->middleware('permission:organization.manage');
    Route::post('/location-rule/{id}/deactivate', 'deactivateLocationRule')->name('location-rule.deactivate')->middleware('permission:organization.manage');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(ProcurementController::class)->prefix('procurement')->name('procurement.')->group(function () {
    Route::get('/orders', 'orders')->name('orders');
    Route::get('/orders/add', 'createOrder')->name('orders.create');
    Route::post('/orders', 'storeOrder')->name('orders.store')->middleware('permission:purchasing.manage');
    Route::post('/orders/{id}/approve', 'approveOrder')->name('orders.approve')->middleware('permission:purchasing.manage');
    Route::post('/orders/{id}/reject', 'rejectOrder')->name('orders.reject')->middleware('permission:purchasing.manage');
    Route::post('/orders/{id}/cancel', 'cancelOrder')->name('orders.cancel')->middleware('permission:purchasing.manage');
    Route::get('/receipts', 'receipts')->name('receipts');
    Route::get('/receipts/add', 'createReceipt')->name('receipts.create');
    Route::post('/receipts', 'storeReceipt')->name('receipts.store')->middleware('permission:purchasing.manage');
    Route::post('/receipts/{id}/approve', 'approveReceipt')->name('receipts.approve')->middleware('permission:inventory.approve');
    Route::post('/receipts/{id}/reject', 'rejectReceipt')->name('receipts.reject')->middleware('permission:inventory.approve');
    Route::post('/receipts/{id}/inspect', 'inspectReceipt')->name('receipts.inspect')->middleware('permission:inventory.approve');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(PurchaseRequisitionController::class)->prefix('procurement/requisitions')->name('procurement.requisitions.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:purchasing.manage');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:purchasing.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:purchasing.manage');
    Route::post('/{id}/convert', 'convert')->name('convert')->middleware('permission:purchasing.manage');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(PurchaseRfqController::class)->prefix('procurement/rfqs')->name('procurement.rfqs.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:purchasing.manage');
    Route::get('/{id}/quote/{supplierId}', 'quoteForm')->name('quote.create');
    Route::get('/{id}/compare', 'compare')->name('compare');
    Route::post('/{id}/award', 'award')->name('award')->middleware('permission:purchasing.manage');
    Route::post('/{id}/quote', 'quote')->name('quote')->middleware('permission:purchasing.manage');
    Route::post('/{id}/close', 'close')->name('close')->middleware('permission:purchasing.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:purchasing.manage');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(SupplierProductPriceController::class)->prefix('procurement/supplier-prices')->name('procurement.supplier.prices.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store')->middleware('permission:purchasing.manage');
    Route::post('/{id}/deactivate', 'deactivate')->name('deactivate')->middleware('permission:purchasing.manage');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:purchasing.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:purchasing.manage');
});

Route::middleware(['auth', 'permission:reports.view'])->get('/procurement/supplier-performance', [SupplierPerformanceController::class, 'index'])->name('procurement.supplier.performance');
Route::middleware(['auth', 'permission:reports.export'])->get('/procurement/supplier-performance/export', [SupplierPerformanceController::class, 'export'])->name('procurement.supplier.performance.export');
Route::middleware(['auth', 'permission:purchasing.manage'])->controller(\App\Http\Controllers\SupplierCorrectiveActionController::class)->prefix('procurement/supplier-corrective-actions')->name('procurement.supplier.corrective.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::patch('/{id}', 'update')->name('update');
});
Route::middleware(['auth', 'permission:reports.view'])->get('/reports/inventory-analytics', [InventoryAnalyticsController::class, 'index'])->name('reports.inventory.analytics');
Route::middleware(['auth', 'permission:reports.view'])->get('/reports/sales', [SalesReportController::class, 'index'])->name('reports.sales');
Route::middleware(['auth', 'permission:reports.export'])->get('/reports/sales/export', [SalesReportController::class, 'export'])->name('reports.sales.export');
Route::middleware(['auth', 'permission:reports.view'])->get('/erp/accounting/reconciliation', [ReconciliationController::class, 'index'])->name('erp.accounting.reconciliation');
Route::middleware(['auth', 'permission:accounting.manage'])->post('/erp/accounting/reconciliation/reviews', [ReconciliationController::class, 'storeReview'])->name('erp.accounting.reconciliation.review');
Route::middleware(['auth', 'permission:accounting.manage'])->post('/erp/accounting/reconciliation/snapshots', [ReconciliationController::class, 'captureSnapshot'])->name('erp.accounting.reconciliation.snapshot');
Route::middleware(['auth', 'permission:accounting.manage'])->controller(BankReconciliationController::class)->prefix('erp/accounting/bank-reconciliation')->name('erp.accounting.bank.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/accounts', 'storeAccount')->name('accounts.store');
    Route::post('/lines', 'storeLine')->name('lines.store');
    Route::post('/lines/import', 'importCsv')->name('lines.import');
    Route::post('/lines/{id}/match', 'match')->name('lines.match');
    Route::post('/lines/{id}/match/reverse', 'reverseMatch')->name('lines.match.reverse');
    Route::get('/lines/{id}/suggestions', 'suggestions')->name('lines.suggestions');
    Route::patch('/lines/{id}/status', 'setStatus')->name('lines.status');
});
Route::middleware(['auth', 'permission:accounting.manage'])->post('/customer/payments/{id}/reverse', [PaymentReversalController::class, 'customer'])->name('customer.payments.reverse');
Route::middleware(['auth', 'permission:accounting.manage'])->post('/customer/refunds', [CustomerRefundController::class, 'store'])->name('customer.refunds.store');
Route::middleware(['auth', 'permission:accounting.manage'])->post('/customer/refunds/{id}/approve', [CustomerRefundController::class, 'approve'])->name('customer.refunds.approve');
Route::middleware(['auth', 'permission:accounting.manage'])->post('/customer/refunds/{id}/reject', [CustomerRefundController::class, 'reject'])->name('customer.refunds.reject');
Route::middleware(['auth', 'permission:accounting.manage'])->post('/procurement/payments/{id}/reverse', [PaymentReversalController::class, 'supplier'])->name('procurement.payments.reverse');
Route::middleware(['auth', 'permission:reports.export'])->get('/reports/inventory-analytics/export', [InventoryAnalyticsController::class, 'export'])->name('reports.inventory.analytics.export');

Route::middleware(['auth', 'permission:sales.manage'])->controller(CustomerProductPriceController::class)->prefix('sales/customer-prices')->name('sales.customer.prices.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store')->middleware('permission:sales.manage');
    Route::post('/{id}/deactivate', 'deactivate')->name('deactivate')->middleware('permission:sales.manage');
});

Route::middleware(['auth', 'permission:sales.manage'])->controller(PromotionController::class)->prefix('sales/promotions')->name('sales.promotions.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:sales.manage');
});

Route::middleware(['auth', 'permission:sales.manage'])->controller(CustomerContactController::class)->prefix('customer/contacts')->name('customer.contacts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store')->middleware('permission:sales.manage');
    Route::patch('/{id}', 'update')->name('update');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(SupplierContactController::class)->prefix('supplier/contacts')->name('supplier.contacts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::patch('/{id}', 'update')->name('update');
});

Route::middleware(['auth', 'permission:sales.manage'])->controller(SalesFulfillmentController::class)->prefix('fulfillment')->name('fulfillment.')->group(function () {
    Route::get('/orders', 'orders')->name('orders');
    Route::get('/orders/add', 'createOrder')->name('orders.create');
    Route::post('/orders', 'storeOrder')->name('orders.store')->middleware('permission:sales.manage');
    Route::post('/orders/{id}/approve', 'approveOrder')->name('orders.approve')->middleware('permission:sales.manage');
    Route::post('/orders/{id}/reject', 'rejectOrder')->name('orders.reject')->middleware('permission:sales.manage');
    Route::post('/orders/{id}/cancel', 'cancelOrder')->name('orders.cancel')->middleware('permission:sales.manage');
    Route::get('/deliveries', 'deliveries')->name('deliveries');
    Route::get('/deliveries/add', 'createDelivery')->name('deliveries.create');
    Route::post('/deliveries', 'storeDelivery')->name('deliveries.store')->middleware('permission:sales.manage');
    Route::post('/deliveries/{id}/approve', 'approveDelivery')->name('deliveries.approve')->middleware('permission:inventory.approve');
    Route::post('/deliveries/{id}/reject', 'rejectDelivery')->name('deliveries.reject')->middleware('permission:inventory.approve');
    Route::post('/deliveries/{id}/delivered', 'confirmDelivered')->name('deliveries.delivered')->middleware('permission:sales.manage');
    Route::post('/deliveries/{id}/cancel', 'cancelDelivery')->name('deliveries.cancel')->middleware('permission:sales.manage');
    Route::post('/deliveries/{id}/operation/{type}', 'completeOperation')->where('type', 'pick|pack')->name('deliveries.operation')->middleware('permission:warehouse.manage');
});

Route::middleware(['auth', 'permission:sales.manage'])->controller(SalesQuotationController::class)->prefix('sales/quotations')->name('sales.quotations.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:sales.manage');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:sales.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:sales.manage');
    Route::post('/{id}/convert', 'convert')->name('convert')->middleware('permission:sales.manage');
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(InventoryReturnController::class)->prefix('inventory/returns')->name('inventory.returns.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:inventory.approve');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:inventory.approve');
    Route::post('/{id}/inspect', 'inspect')->name('inspect')->middleware('permission:inventory.approve');
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(StockReservationController::class)->prefix('inventory/reservations')->name('inventory.reservations.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::patch('/{id}/location', 'reassign')->name('reassign')->middleware('permission:inventory.post');
    Route::post('/{id}/release', 'release')->name('release')->middleware('permission:inventory.post');
});

Route::middleware(['auth', 'permission:accounting.manage'])->controller(AccountingController::class)->prefix('erp/accounting')->name('erp.accounting.')->group(function () {
    Route::get('/accounts', 'accounts')->name('accounts');
    Route::post('/accounts', 'storeAccount')->name('accounts.store')->middleware('permission:accounting.manage');
    Route::put('/accounts/{id}', 'updateAccount')->name('accounts.update')->middleware('permission:accounting.manage');
    Route::post('/accounts/{id}/deactivate', 'deactivateAccount')->name('accounts.deactivate')->middleware('permission:accounting.manage');
    Route::get('/journals', 'journals')->name('journals');
    Route::get('/trial-balance', 'trialBalance')->name('trial-balance');
    Route::get('/financial-statements', 'financialStatements')->name('financial-statements');
    Route::get('/cash-flow', 'cashFlow')->name('cash-flow');
    Route::get('/recurring-journals', 'recurringJournals')->name('recurring-journals');
    Route::get('/tax-report', 'taxReport')->name('tax-report');
    Route::get('/tax-report/export', 'taxReportExport')->name('tax-report.export')->middleware('permission:reports.export');
    Route::get('/fx-revaluation', 'fxRevaluation')->name('fx-revaluation');
    Route::post('/fx-revaluation', 'postFxRevaluation')->name('fx-revaluation.post');
    Route::post('/journals', 'storeJournal')->name('journals.store')->middleware('permission:accounting.manage');
    Route::post('/journals/{id}/approve', 'approveJournal')->name('journals.approve')->middleware('permission:accounting.manage');
    Route::post('/journals/{id}/reverse', 'reverseJournal')->name('journals.reverse')->middleware('permission:accounting.manage');
    Route::post('/recurring-journals', 'storeRecurringJournal')->name('recurring-journals.store')->middleware('permission:accounting.manage');
    Route::post('/recurring-journals/{id}/deactivate', 'deactivateRecurringJournal')->name('recurring-journals.deactivate')->middleware('permission:accounting.manage');
    Route::get('/mappings', 'mappings')->name('mappings');
    Route::get('/cost-centers/report', 'costCenterReport')->name('cost-centers.report');
    Route::post('/mappings', 'storeMapping')->name('mappings.store')->middleware('permission:accounting.manage');
});
Route::middleware(['auth', 'permission:accounting.manage'])->controller(\App\Http\Controllers\PriceListController::class)->prefix('erp/pricing/lists')->name('erp.pricing.lists.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::post('/items', 'storeItem')->name('item');
    Route::post('/assign-customer', 'assignCustomer')->name('assign-customer');
    Route::post('/assign-supplier', 'assignSupplier')->name('assign-supplier');
    Route::post('/{id}/deactivate', 'deactivate')->name('deactivate');
});
Route::middleware(['auth', 'permission:accounting.manage'])->controller(CostCenterController::class)->prefix('erp/accounting/cost-centers')->name('erp.accounting.cost-centers.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('/{id}', 'update')->name('update');
    Route::get('/budgets', 'budgets')->name('budgets');
    Route::post('/budgets', 'storeBudget')->name('budgets.store');
});

Route::middleware(['auth', 'permission:reports.view'])->get('/planning/report', [PlanningController::class, 'index'])->name('planning.report');
Route::middleware(['auth', 'permission:reports.view'])->get('/planning/purchase-suggestions', [PlanningController::class, 'purchaseSuggestions'])->name('planning.purchase.suggestions');
Route::middleware(['auth', 'permission:purchasing.manage'])->post('/planning/purchase-suggestions/create-orders', [PlanningController::class, 'createPurchaseOrders'])->name('planning.purchase.suggestions.create-orders');
Route::middleware(['auth', 'permission:inventory.view'])->controller(ReplenishmentPolicyController::class)->prefix('planning/policies')->name('planning.policies.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::patch('/{id}', 'update')->name('update')->middleware('permission:inventory.post');
    Route::post('/{id}/deactivate', 'deactivate')->name('deactivate')->middleware('permission:inventory.post');
});
Route::middleware(['auth'])->controller(DocumentAttachmentController::class)->prefix('erp/attachments')->name('erp.attachments.')->group(function () {
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{id}/download', 'download')->name('download');
    Route::get('/{id}/preview', 'preview')->name('preview');
});
Route::middleware(['auth', 'permission:reports.view'])->get('/planning/production-suggestions', [PlanningController::class, 'productionSuggestions'])->name('planning.production.suggestions');
Route::middleware('auth')->controller(DataRetentionController::class)->prefix('erp/security/retention')->name('erp.security.retention.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/policies', 'storePolicy')->name('policies.store')->middleware('permission:users.manage');
    Route::post('/holds', 'storeHold')->name('holds.store')->middleware('permission:users.manage');
    Route::post('/holds/{id}/release', 'releaseHold')->name('holds.release')->middleware('permission:users.manage');
    Route::get('/archives/export', 'exportArchives')->name('archives.export')->middleware('permission:users.manage');
    Route::post('/preview', 'preview')->name('preview')->middleware('permission:users.manage');
    Route::post('/purge-requests', 'requestPurge')->name('purge-requests.store')->middleware('permission:users.manage');
    Route::post('/purge-requests/{id}/approve', 'decidePurge')->defaults('decision', 'approve')->name('purge-requests.approve')->middleware('permission:users.manage');
    Route::post('/purge-requests/{id}/reject', 'decidePurge')->defaults('decision', 'reject')->name('purge-requests.reject')->middleware('permission:users.manage');
});
Route::middleware('auth')->controller(ProductController::class)->prefix('product/barcodes')->name('product.barcodes.')->group(function () {
    Route::get('/', 'barcodes')->name('index');
    Route::post('/', 'storeBarcode')->name('store')->middleware('permission:inventory.post');
});
Route::middleware('auth')->get('/pos/barcode-lookup', [ProductController::class, 'barcodeLookup'])->name('pos.barcode.lookup');
Route::middleware(['auth', 'permission:inventory.view'])->get('/product/barcodes/print', [ProductController::class, 'barcodePrint'])->name('product.barcodes.print');
Route::middleware('auth')->controller(BrandController::class)->prefix('product/brands')->name('product.brands.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::put('/{id}', 'update')->name('update')->middleware('permission:inventory.post');
    Route::delete('/{id}', 'destroy')->name('destroy')->middleware('permission:inventory.post');
});

// Backward-compatible aliases retained for legacy ERP navigation links.
// Legacy route names retained for external bookmarks; canonical navigation uses *.index names.
Route::middleware(['auth', 'permission:sales.manage'])->get('/legacy/customer/credit-control', [CustomerCreditController::class, 'index'])->name('customer.credit');
Route::middleware(['auth', 'permission:accounting.manage'])->get('/legacy/erp/finance', [FinanceConfigurationController::class, 'index'])->name('erp.finance');
Route::middleware(['auth', 'permission:users.manage'])->get('/legacy/erp/security/audit', [AuditLogController::class, 'index'])->name('erp.security.audit');
Route::middleware(['auth', 'permission:users.manage'])->get('/legacy/erp/security/roles', [SecurityRoleController::class, 'index'])->name('erp.security.roles');
Route::middleware(['auth', 'permission:users.manage'])->get('/legacy/erp/security/users', [UserManagementController::class, 'index'])->name('erp.security.users');
Route::middleware(['auth', 'permission:service.manage'])->get('/legacy/erp/service', [ServiceMaintenanceController::class, 'index'])->name('erp.service');
Route::middleware(['auth', 'permission:inventory.view'])->get('/legacy/inventory/counts', [StockCountController::class, 'index'])->name('inventory.counts');
Route::middleware(['auth', 'permission:inventory.view'])->get('/legacy/inventory/status', [InventoryStatusController::class, 'index'])->name('inventory.status');
Route::middleware(['auth', 'permission:purchasing.manage'])->get('/legacy/procurement/invoices', [PurchaseInvoiceController::class, 'index'])->name('procurement.invoices');
Route::middleware(['auth', 'permission:purchasing.manage'])->get('/legacy/procurement/landed-costs', [LandedCostController::class, 'index'])->name('procurement.landed.costs');
Route::middleware(['auth', 'permission:purchasing.manage'])->get('/legacy/procurement/payments', [SupplierPaymentController::class, 'index'])->name('procurement.payments');
Route::middleware(['auth', 'permission:accounting.manage'])->get('/legacy/product/costing', [ProductCostingController::class, 'index'])->name('product.costing');
Route::middleware(['auth', 'permission:inventory.view'])->get('/legacy/product/uoms', [ProductUomController::class, 'index'])->name('product.uoms');
Route::middleware(['auth', 'permission:users.manage'])->controller(ApiTokenController::class)->prefix('erp/security/api-tokens')->name('erp.security.api-tokens.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::delete('/{id}', 'destroy')->name('destroy');
});
Route::middleware(['auth', 'permission:users.manage'])->controller(ApprovalPolicyController::class)->prefix('erp/security/approval-policies')->name('erp.security.approval-policies.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('/{id}', 'update')->name('update');
});
Route::middleware(['auth', 'permission:users.manage'])->controller(ApprovalDelegationController::class)->prefix('erp/security/approval-delegations')->name('erp.security.approval-delegations.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::post('/{id}/deactivate', 'deactivate')->name('deactivate');
});
Route::middleware(['auth', 'permission:reports.view'])->get('/planning/demand-forecast', [DemandForecastController::class, 'index'])->name('planning.demand.forecast');
Route::middleware(['auth', 'permission:reports.view'])->get('/planning/replenishment-scenario', [DemandForecastController::class, 'scenario'])->name('planning.replenishment.scenario');
Route::middleware(['auth', 'permission:inventory.post'])->post('/planning/replenishment-scenario/save', [DemandForecastController::class, 'saveScenario'])->name('planning.replenishment.scenario.save');
Route::middleware(['auth', 'permission:inventory.post'])->post('/planning/demand-forecast/override', [DemandForecastController::class, 'storeOverride'])->name('planning.demand.forecast.override');
Route::middleware(['auth', 'permission:inventory.view'])->get('/warehouse/put-away', [PutAwayController::class, 'index'])->name('warehouse.put.away');
Route::middleware(['auth', 'permission:inventory.post'])->post('/warehouse/put-away', [PutAwayController::class, 'confirm'])->name('warehouse.put.away.confirm');
Route::middleware(['auth', 'permission:inventory.view'])->get('/planning/mrp', [PlanningController::class, 'mrp'])->name('planning.mrp');
Route::middleware(['auth', 'permission:reports.view'])->get('/planning/dashboard', [PlanningController::class, 'dashboard'])->name('planning.dashboard');

Route::middleware(['auth', 'permission:inventory.view'])->controller(StockCountController::class)->prefix('inventory/counts')->name('inventory.counts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::get('/{id}/edit', 'edit')->name('edit');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::post('/schedules', 'storeSchedule')->name('schedules.store')->middleware('permission:inventory.post');
    Route::put('/{id}', 'update')->name('update')->middleware('permission:inventory.post');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:inventory.approve');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:inventory.approve');
    Route::post('/{id}/request-recount', 'requestRecount')->name('request-recount')->middleware('permission:inventory.approve');
    Route::post('/{id}/assign-counters', 'assignCounters')->name('assign-counters')->middleware('permission:inventory.approve');
    Route::post('/{id}/assignments/{assignmentId}/complete', 'completeCounter')->name('assignments.complete')->middleware('permission:inventory.post');
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(InventoryStatusController::class)->prefix('inventory/status')->name('inventory.status.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:inventory.approve');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:inventory.approve');
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(ProductUomController::class)->prefix('product/uoms')->name('product.uoms.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store')->middleware('permission:inventory.post');
    Route::delete('/{id}', 'destroy')->name('destroy')->middleware('permission:inventory.post');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(PurchaseInvoiceController::class)->prefix('procurement/invoices')->name('procurement.invoices.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/price-variance', 'priceVariance')->name('price-variance');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:purchasing.manage');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:accounting.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:accounting.manage');
    Route::post('/{id}/reverse', 'reverse')->name('reverse')->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(SupplierPaymentController::class)->prefix('procurement/payments')->name('procurement.payments.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/aging', 'aging')->name('aging');
    Route::get('/aging/export', 'agingExport')->name('aging.export')->middleware('permission:reports.export');
    Route::post('/', 'store')->name('store')->middleware('permission:purchasing.manage');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:accounting.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:accounting.manage');
    Route::post('/allocate', 'allocate')->name('allocate')->middleware('permission:accounting.manage');
    Route::post('/allocations/{id}/void', 'voidAllocation')->name('allocations.void')->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'permission:sales.manage'])->controller(CustomerCreditController::class)->prefix('customer/credit-control')->name('customer.credit.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/{id}', 'update')->name('update')->middleware('permission:sales.manage');
});

Route::middleware(['auth', 'permission:accounting.manage'])->controller(CustomerCreditController::class)->prefix('customer/payment-allocations')->name('customer.payment.allocations.')->group(function () {
    Route::get('/', 'allocations')->name('index');
    Route::post('/', 'allocate')->name('store');
    Route::post('/{id}/void', 'voidAllocation')->name('void')->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'permission:reports.view'])->controller(ReceivablesController::class)->prefix('customer/receivables')->name('customer.receivables.')->group(function () {
    Route::get('/aging', 'aging')->name('aging');
    Route::get('/aging/export', 'export')->name('aging.export')->middleware('permission:reports.export');
});

Route::middleware(['auth', 'permission:inventory.view'])->controller(ProductVariantController::class)->prefix('product')->name('product.')->group(function () {
    Route::get('/variants', 'index')->name('variants');
    Route::post('/variants', 'storeVariant')->name('variants.store')->middleware('permission:inventory.post');
    Route::patch('/variants/{id}', 'updateVariant')->name('variants.update')->middleware('permission:inventory.post');
    Route::post('/attributes', 'storeAttribute')->name('attributes.store')->middleware('permission:inventory.post');
    Route::post('/attributes/values', 'storeValue')->name('attributes.values.store')->middleware('permission:inventory.post');
});

Route::middleware(['auth', 'permission:accounting.manage'])->controller(ProductCostingController::class)->prefix('product/costing')->name('product.costing.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/{id}', 'update')->name('update')->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'permission:purchasing.manage'])->controller(LandedCostController::class)->prefix('procurement/landed-costs')->name('procurement.landed.costs.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/', 'store')->name('store')->middleware('permission:purchasing.manage');
    Route::post('/{id}/approve', 'approve')->name('approve')->middleware('permission:accounting.manage');
    Route::post('/{id}/reject', 'reject')->name('reject')->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'permission:users.manage'])->controller(SecurityRoleController::class)->prefix('erp/security/roles')->name('erp.security.roles.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'storeRole')->name('store')->middleware('permission:users.manage');
    Route::post('/conflicts', 'storeConflict')->name('conflicts.store')->middleware('permission:users.manage');
    Route::post('/assign', 'assignUser')->name('assign')->middleware('permission:users.manage');
});

Route::middleware(['auth', 'permission:users.manage'])->controller(UserManagementController::class)->prefix('erp/security/users')->name('erp.security.users.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/{id}', 'update')->name('update')->middleware('permission:users.manage');
    Route::post('/{id}/reset-mfa', 'resetMfa')->name('reset-mfa')->middleware('permission:users.manage');
});

Route::middleware(['auth', 'permission:users.manage'])->controller(AuditLogController::class)->prefix('erp/security/audit')->name('erp.security.audit.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/activity', 'activity')->name('activity');
    Route::get('/activity/export', 'activityExport')->name('activity.export')->middleware('permission:reports.export');
    Route::get('/status-history', 'statusHistory')->name('status.history');
    Route::get('/revisions', 'revisions')->name('revisions');
    Route::get('/revisions/{id}/diff', 'revisionDiff')->name('revisions.diff');
    Route::get('/export', 'export')->name('export')->middleware('permission:reports.export');
});
Route::middleware(['auth', 'permission:users.manage'])->post('/erp/security/restore/{type}/{id}', [\App\Http\Controllers\SoftDeleteRestoreController::class, 'restore'])->name('erp.security.restore');

Route::middleware(['auth', 'permission:accounting.manage'])->controller(FinanceConfigurationController::class)->prefix('erp/finance')->name('erp.finance.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/currency', 'currency')->name('currency')->middleware('permission:accounting.manage');
    Route::post('/rate', 'rate')->name('rate')->middleware('permission:accounting.manage');
    Route::post('/tax', 'tax')->name('tax')->middleware('permission:accounting.manage');
    Route::post('/fiscal-year', 'fiscalYear')->name('fiscal')->middleware('permission:accounting.manage');
    Route::post('/fiscal-year/{id}/close', 'closeFiscalYear')->name('fiscal.close')->middleware('permission:accounting.manage');
    Route::post('/fiscal-year/{id}/reopen', 'reopenFiscalYear')->name('fiscal.reopen')->middleware('permission:accounting.manage');
    Route::post('/sequence', 'sequence')->name('sequence')->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'permission:accounting.manage'])->controller(\App\Http\Controllers\SystemSettingsController::class)->prefix('erp/settings')->name('erp.settings.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/', 'update')->name('update');
    Route::put('/expired-batch-policy', 'updateExpiredBatchPolicy')->name('expired-batch-policy');
});

Route::middleware(['auth', 'permission:service.manage'])->controller(ServiceMaintenanceController::class)->prefix('erp/service')->name('erp.service.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/add', 'create')->name('create');
    Route::post('/asset', 'asset')->name('asset')->middleware('permission:service.manage');
    Route::post('/technician', 'technician')->name('technician')->middleware('permission:service.manage');
    Route::post('/schedule', 'schedule')->name('schedule')->middleware('permission:service.manage');
    Route::post('/schedule/{id}/generate', 'generateSchedule')->name('schedule.generate')->middleware('permission:service.manage');
    Route::post('/request', 'request')->name('request')->middleware('permission:service.manage');
    Route::post('/request/{id}/assign', 'assignRequest')->name('request.assign')->middleware('permission:service.manage');
    Route::post('/order', 'order')->name('order')->middleware('permission:service.manage');
    Route::post('/order/{id}/status', 'updateOrderStatus')->name('order.status')->middleware('permission:service.manage');
    Route::get('/order/{id}/parts', 'parts')->name('parts')->middleware('permission:service.manage');
    Route::post('/order/{id}/parts/reserve', 'reservePart')->name('parts.reserve')->middleware('permission:service.manage');
    Route::post('/order/{id}/parts', 'consumePart')->name('parts.consume')->middleware('permission:service.manage');
    Route::post('/order/{id}/parts/{partId}/return', 'returnPart')->name('parts.return')->middleware('permission:service.manage');
    Route::get('/warranty-claims', 'warrantyClaims')->name('warranty.claims');
    Route::post('/warranty-claims', 'warrantyClaim')->name('warranty.claims.store')->middleware('permission:service.manage');
    Route::post('/warranty-claims/{id}/decision', 'warrantyClaimDecision')->name('warranty.claims.decision')->middleware('permission:service.manage');
    Route::post('/warranty-claims/{id}/settle', 'settleWarrantyClaim')->name('warranty.claims.settle')->middleware('permission:service.manage');
    Route::get('/asset/{id}/spare-parts', 'spareParts')->name('asset.spare-parts');
    Route::post('/asset/{id}/spare-parts', 'storeSparePart')->name('asset.spare-parts.store')->middleware('permission:service.manage');
});

Route::middleware(['auth', 'permission:hr.view'])->controller(\App\Http\Controllers\HrEmployeeController::class)->prefix('erp/hr')->name('erp.hr.')->group(function () {
    Route::get('/employees', 'index')->name('employees');
    Route::post('/employees', 'store')->name('employees.store')->middleware('permission:hr.manage');
    Route::post('/employees/{id}/terminate', 'deactivate')->name('employees.terminate')->middleware('permission:hr.manage');
    Route::post('/employees/{id}/benefits', 'storeBenefit')->name('employees.benefits.store')->middleware('permission:hr.manage');
    Route::post('/employees/{id}/benefits/{benefitId}/deactivate', 'deactivateBenefit')->name('employees.benefits.deactivate')->middleware('permission:hr.manage');
    Route::get('/pay-runs', 'payRuns')->name('pay-runs');
    Route::post('/pay-runs', 'storePayRun')->name('pay-runs.store')->middleware('permission:hr.manage');
    Route::post('/pay-runs/{id}/approve', 'approvePayRun')->name('pay-runs.approve')->middleware('permission:hr.manage');
    Route::post('/pay-runs/{id}/pay', 'payPayRun')->name('pay-runs.pay')->middleware('permission:hr.manage');
    Route::post('/pay-runs/{id}/reverse-payment', 'reversePayRun')->name('pay-runs.reverse-payment')->middleware('permission:hr.manage');
    Route::post('/payroll-rules', 'storePayrollRule')->name('payroll-rules.store')->middleware('permission:hr.manage');
    Route::post('/payroll-rules/{id}/deactivate', 'deactivatePayrollRule')->name('payroll-rules.deactivate')->middleware('permission:hr.manage');
});

Route::middleware(['auth', 'permission:hr.view'])->controller(\App\Http\Controllers\HrLeaveController::class)->prefix('erp/hr')->name('erp.hr.')->group(function () {
    Route::get('/leave', 'index')->name('leave');
    Route::post('/leave-types', 'storeType')->name('leave-types.store')->middleware('permission:hr.manage');
    Route::post('/leave-requests', 'store')->name('leave-requests.store')->middleware('permission:hr.manage');
    Route::post('/leave-requests/{id}/approve', 'approve')->name('leave-requests.approve')->middleware('permission:hr.manage');
    Route::post('/leave-requests/{id}/reject', 'reject')->name('leave-requests.reject')->middleware('permission:hr.manage');
});

Route::middleware(['auth', 'permission:hr.view'])->controller(\App\Http\Controllers\HrAttendanceController::class)->prefix('erp/hr')->name('erp.hr.')->group(function () {
    Route::get('/attendance', 'index')->name('attendance');
    Route::post('/attendance', 'store')->name('attendance.store')->middleware('permission:hr.manage');
});

Route::middleware(['auth', 'permission:manufacturing.manage'])->controller(ManufacturingController::class)->prefix('manufacturing')->name('manufacturing.')->group(function () {
    Route::get('/boms', 'boms')->name('boms');
    Route::get('/routings', 'routing')->name('routings');
    Route::post('/work-centers', 'storeWorkCenter')->name('work-centers.store')->middleware('permission:manufacturing.manage');
    Route::post('/routings', 'storeRouting')->name('routings.store')->middleware('permission:manufacturing.manage');
    Route::post('/boms', 'storeBom')->name('boms.store')->middleware('permission:manufacturing.manage');
    Route::post('/boms/{id}/approve', 'approveBom')->name('boms.approve')->middleware('permission:manufacturing.manage');
    Route::post('/boms/{id}/reject', 'rejectBom')->name('boms.reject')->middleware('permission:manufacturing.manage');
    Route::get('/orders', 'orders')->name('orders');
    Route::get('/production-variance', 'productionVariance')->name('production-variance');
    Route::get('/scrap', 'scrap')->name('scrap');
    Route::get('/scrap/add', 'createScrap')->name('scrap.create');
    Route::post('/scrap', 'storeScrap')->name('scrap.store')->middleware('permission:manufacturing.manage');
    Route::post('/scrap/{id}/approve', 'approveScrap')->name('scrap.approve')->middleware('permission:manufacturing.manage');
    Route::post('/scrap/{id}/reject', 'rejectScrap')->name('scrap.reject')->middleware('permission:manufacturing.manage');
    Route::get('/orders/add', 'createOrder')->name('orders.create');
    Route::post('/orders', 'storeOrder')->name('orders.store')->middleware('permission:manufacturing.manage');
    Route::post('/orders/{id}/release', 'release')->name('orders.release')->middleware('permission:inventory.approve');
    Route::post('/orders/{id}/schedule', 'schedule')->name('orders.schedule')->middleware('permission:manufacturing.manage');
    Route::post('/orders/{id}/complete', 'complete')->name('orders.complete')->middleware('permission:inventory.approve');
    Route::post('/orders/{id}/cancel', 'cancel')->name('orders.cancel')->middleware('permission:manufacturing.manage');
    Route::post('/orders/{id}/pause', 'pause')->name('orders.pause')->middleware('permission:manufacturing.manage');
    Route::post('/orders/{id}/resume', 'resume')->name('orders.resume')->middleware('permission:manufacturing.manage');
    Route::post('/orders/{id}/close', 'close')->name('orders.close')->middleware('permission:manufacturing.manage');
    Route::get('/orders/{id}/operations', 'operations')->name('orders.operations');
    Route::post('/operations/{id}/start', 'startOperation')->name('operations.start')->middleware('permission:manufacturing.manage');
    Route::post('/operations/{id}/complete', 'completeOperation')->name('operations.complete')->middleware('permission:manufacturing.manage');
});



 }); // End Group Middleware




// Default All Route 
Route::middleware('auth')->controller(DefaultController::class)->group(function () {
    Route::get('/get-category', 'GetCategory')->name('get-category'); 
    Route::get('/get-product', 'GetProduct')->name('get-product'); 
    Route::get('/check-product', 'GetStock')->name('check-product-stock'); 
     
});


 


Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth'])->name('dashboard');

require __DIR__.'/auth.php';


// Route::get('/contact', function () {
//     return view('contact');
// });
