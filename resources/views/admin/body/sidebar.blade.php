 <div class="vertical-menu">

                <div data-simplebar class="h-100">

                    <div class="inventory-sidebar-context">
                        <div class="inventory-sidebar-context-icon"><i class="ri-store-2-line"></i></div>
                        <div><span>WORKSPACE</span><strong>Inventory operations</strong></div>
                        <i class="ri-more-2-fill ms-auto"></i>
                    </div>

                    <!--- Sidemenu -->
                    <div id="sidebar-menu">
                        <!-- Left Menu Start -->
                        <ul class="metismenu list-unstyled" id="side-menu">
                            <li class="menu-title">Workspace</li>

                            <li><a href="{{ route('erp.organization.index') }}" class="waves-effect"><i class="ri-building-4-line"></i><span>Organization Setup</span></a></li>
                            <li><a href="{{ route('erp.accounting.accounts') }}" class="waves-effect"><i class="ri-bank-line"></i><span>Chart of Accounts</span></a></li>
                            <li><a href="{{ route('erp.pricing.lists.index') }}" class="waves-effect"><i class="ri-price-tag-3-line"></i><span>Named Price Lists</span></a></li>
                            <li><a href="{{ route('erp.accounting.recurring-journals') }}" class="waves-effect"><i class="ri-repeat-line"></i><span>Recurring Journals</span></a></li>
                            <li><a href="{{ route('erp.accounting.mappings') }}" class="waves-effect"><i class="ri-links-line"></i><span>Accounting Mappings</span></a></li>
                            <li><a href="{{ route('erp.accounting.cost-centers.index') }}" class="waves-effect"><i class="ri-focus-3-line"></i><span>Cost Centers</span></a></li>
                            <li><a href="{{ route('erp.accounting.cost-centers.budgets') }}" class="waves-effect"><i class="ri-funds-line"></i><span>Cost Center Budgets</span></a></li>
                            <li><a href="{{ route('erp.accounting.cost-centers.report') }}" class="waves-effect"><i class="ri-pie-chart-line"></i><span>Cost Center Report</span></a></li>
                            <li><a href="{{ route('erp.accounting.reconciliation') }}" class="waves-effect"><i class="ri-scales-3-line"></i><span>Reconciliation</span></a></li>
                            <li><a href="{{ route('erp.accounting.tax-report') }}" class="waves-effect"><i class="ri-file-list-3-line"></i><span>Tax Report</span></a></li>
                            <li><a href="{{ route('erp.accounting.trial-balance') }}" class="waves-effect"><i class="ri-scales-2-line"></i><span>Trial Balance</span></a></li>
                            <li><a href="{{ route('erp.accounting.financial-statements') }}" class="waves-effect"><i class="ri-bar-chart-grouped-line"></i><span>Financial Statements</span></a></li>
                            <li><a href="{{ route('erp.accounting.cash-flow') }}" class="waves-effect"><i class="ri-exchange-dollar-line"></i><span>Cash Flow</span></a></li>
                            <li><a href="{{ route('erp.accounting.bank.index') }}" class="waves-effect"><i class="ri-bank-card-line"></i><span>Bank Reconciliation</span></a></li>
                            <li><a href="{{ route('customer.payment.allocations.index') }}" class="waves-effect"><i class="ri-wallet-3-line"></i><span>Payment Allocations</span></a></li>
                            <li><a href="{{ route('erp.finance.index') }}" class="waves-effect"><i class="ri-exchange-dollar-line"></i><span>Finance Configuration</span></a></li>
                            <li><a href="{{ route('erp.settings.index') }}" class="waves-effect"><i class="ri-settings-4-line"></i><span>ERP System Settings</span></a></li>
                            <li><a href="{{ route('erp.service.index') }}" class="waves-effect"><i class="ri-customer-service-2-line"></i><span>Service & Maintenance</span></a></li>
                            <li><a href="{{ route('erp.hr.employees') }}" class="waves-effect"><i class="ri-team-line"></i><span>Employees</span></a></li>
                            <li><a href="{{ route('erp.hr.pay-runs') }}" class="waves-effect"><i class="ri-money-dollar-circle-line"></i><span>Payroll Runs</span></a></li>
                            <li><a href="{{ route('erp.hr.leave') }}" class="waves-effect"><i class="ri-calendar-check-line"></i><span>Leave Management</span></a></li>
                            <li><a href="{{ route('erp.hr.attendance') }}" class="waves-effect"><i class="ri-time-line"></i><span>Attendance</span></a></li>
                            <li><a href="{{ route('sales.promotions.index') }}" class="waves-effect"><i class="ri-price-tag-3-line"></i><span>Sales Promotions</span></a></li>
                            <li><a href="{{ route('planning.report') }}" class="waves-effect"><i class="ri-line-chart-line"></i><span>Planning Exceptions</span></a></li>
                            <li><a href="{{ route('planning.dashboard') }}" class="waves-effect"><i class="ri-dashboard-2-line"></i><span>Planning Dashboard</span></a></li>
                            <li><a href="{{ route('planning.purchase.suggestions') }}" class="waves-effect"><i class="ri-shopping-cart-2-line"></i><span>Purchase Suggestions</span></a></li>
                            <li><a href="{{ route('planning.policies.index') }}" class="waves-effect"><i class="ri-settings-3-line"></i><span>Replenishment Policies</span></a></li>
                            <li><a href="{{ route('planning.production.suggestions') }}" class="waves-effect"><i class="ri-hammer-line"></i><span>Production Suggestions</span></a></li>
                            <li><a href="{{ route('planning.demand.forecast') }}" class="waves-effect"><i class="ri-bar-chart-2-line"></i><span>Demand Forecast</span></a></li>
                            <li><a href="{{ route('warehouse.put.away') }}" class="waves-effect"><i class="ri-inbox-unarchive-line"></i><span>Put-away Planner</span></a></li>
                            <li><a href="{{ route('inventory.documents.index') }}" class="waves-effect"><i class="ri-file-transfer-line"></i><span>Stock Receipts & Issues</span></a></li>
                            <li><a href="{{ route('inventory.reservations.index') }}" class="waves-effect"><i class="ri-lock-line"></i><span>Stock Reservations</span></a></li>
                            <li><a href="{{ route('planning.mrp') }}" class="waves-effect"><i class="ri-flow-chart"></i><span>MRP Net Requirements</span></a></li>
                            <li><a href="{{ route('stock.traceability') }}" class="waves-effect"><i class="ri-route-line"></i><span>Traceability</span></a></li>
                            <li><a href="{{ route('procurement.requisitions.index') }}" class="waves-effect"><i class="ri-file-list-3-line"></i><span>Purchase Requisitions</span></a></li>
                            <li><a href="{{ route('procurement.rfqs.index') }}" class="waves-effect"><i class="ri-questionnaire-line"></i><span>Purchase RFQs</span></a></li>
                            <li><a href="{{ route('procurement.supplier.prices.index') }}" class="waves-effect"><i class="ri-price-tag-3-line"></i><span>Supplier Pricing</span></a></li>
                            <li><a href="{{ route('procurement.supplier.performance') }}" class="waves-effect"><i class="ri-bar-chart-grouped-line"></i><span>Supplier Performance</span></a></li>
                            <li><a href="{{ route('reports.inventory.analytics') }}" class="waves-effect"><i class="ri-pie-chart-2-line"></i><span>Inventory Analytics</span></a></li>
                            <li><a href="{{ route('reports.sales') }}" class="waves-effect"><i class="ri-bar-chart-grouped-line"></i><span>Sales Report</span></a></li>
                            <li><a href="{{ route('sales.quotations.index') }}" class="waves-effect"><i class="ri-file-paper-2-line"></i><span>Sales Quotations</span></a></li>
                            <li><a href="{{ route('sales.customer.prices.index') }}" class="waves-effect"><i class="ri-price-tag-2-line"></i><span>Customer Pricing</span></a></li>
                            <li><a href="{{ route('customer.contacts.index') }}" class="waves-effect"><i class="ri-contacts-line"></i><span>Customer Contacts</span></a></li>
                            <li><a href="{{ route('manufacturing.boms') }}" class="waves-effect"><i class="ri-tools-line"></i><span>Manufacturing BOMs</span></a></li>
                            <li><a href="{{ route('manufacturing.orders') }}" class="waves-effect"><i class="ri-settings-5-line"></i><span>Production Orders</span></a></li>
                            <li><a href="{{ route('product.uoms.index') }}" class="waves-effect"><i class="ri-ruler-line"></i><span>Product UOMs</span></a></li>
                            <li><a href="{{ route('product.variants') }}" class="waves-effect"><i class="ri-layout-grid-line"></i><span>Product Variants</span></a></li>
                            <li><a href="{{ route('product.brands.index') }}" class="waves-effect"><i class="ri-price-tag-3-line"></i><span>Product Brands</span></a></li>
                            <li><a href="{{ route('product.barcodes.index') }}" class="waves-effect"><i class="ri-barcode-line"></i><span>Product Barcodes</span></a></li>
                            <li><a href="{{ route('product.costing.index') }}" class="waves-effect"><i class="ri-money-dollar-circle-line"></i><span>Costing Methods</span></a></li>
                            <li><a href="{{ route('erp.security.roles.index') }}" class="waves-effect"><i class="ri-shield-keyhole-line"></i><span>Roles & Permissions</span></a></li>
                            <li><a href="{{ route('erp.security.approval-policies.index') }}" class="waves-effect"><i class="ri-checkbox-multiple-line"></i><span>Approval Policies</span></a></li>
                            <li><a href="{{ route('erp.security.approval-delegations.index') }}" class="waves-effect"><i class="ri-user-shared-line"></i><span>Approval Delegations</span></a></li>
                            <li><a href="{{ route('erp.security.users.index') }}" class="waves-effect"><i class="ri-user-settings-line"></i><span>User Administration</span></a></li>
                            <li><a href="{{ route('erp.security.audit.index') }}" class="waves-effect"><i class="ri-history-line"></i><span>Audit Trail</span></a></li>
                            <li><a href="{{ route('erp.security.audit.activity') }}" class="waves-effect"><i class="ri-login-circle-line"></i><span>User Activity</span></a></li>
                            <li><a href="{{ route('erp.security.audit.status.history') }}" class="waves-effect"><i class="ri-timeline-view"></i><span>Status History</span></a></li>
                            <li><a href="{{ route('erp.security.audit.revisions') }}" class="waves-effect"><i class="ri-git-commit-line"></i><span>Document Revisions</span></a></li>
                            <li><a href="{{ route('erp.security.retention.index') }}" class="waves-effect"><i class="ri-archive-line"></i><span>Data Retention</span></a></li>
                            <li><a href="{{ route('erp.security.api-tokens.index') }}" class="waves-effect"><i class="ri-key-2-line"></i><span>API Tokens</span></a></li>
                            <li><a href="{{ route('security.sessions.index') }}" class="waves-effect"><i class="ri-device-line"></i><span>Active Sessions</span></a></li>
                            <li><a href="{{ route('notifications.index') }}" class="waves-effect"><i class="ri-notification-3-line"></i><span>Notifications</span></a></li>
                            <li><a href="{{ route('mfa.setup') }}" class="waves-effect"><i class="ri-shield-check-line"></i><span>Multi-factor Auth</span></a></li>
                            <li><a href="{{ route('erp.attachments.create') }}" class="waves-effect"><i class="ri-attachment-2"></i><span>Attachments</span></a></li>

                            <li>
                                <a href="{{ route('dashboard') }}" class="waves-effect {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                                    <i class="ri-home-fill"></i> 
                                    <span>Dashboard</span>
                                </a>
                            </li>
 
                
        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('supplier.*') ? 'active' : '' }}">
                <i class="ri-hotel-fill"></i>
                <span>Manage Suppliers</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('supplier.all') }}">All Supplier</a></li>
                <li><a href="{{ route('supplier.contacts.index') }}">Supplier Contacts</a></li>
               
            </ul>
        </li>


        <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('customer.*') || request()->routeIs('credit.customer') || request()->routeIs('paid.customer') ? 'active' : '' }}">
                <i class="ri-shield-user-fill"></i>
                <span>Manage Customers</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('customer.all') }}">All Customers</a></li>
                 <li><a href="{{ route('credit.customer') }}">Credit Customers</a></li>

                 <li><a href="{{ route('paid.customer') }}">Paid Customers</a></li>
                 <li><a href="{{ route('customer.wise.report') }}">Customer Wise Report</a></li>
                 <li><a href="{{ route('customer.credit.index') }}">Credit Control</a></li>
                 <li><a href="{{ route('customer.receivables.aging') }}">Receivables Aging</a></li>
               
            </ul>
        </li>


         <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('unit.*') ? 'active' : '' }}">
                <i class="ri-delete-back-fill"></i>
                <span>Manage Units</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('unit.all') }}">All Unit</a></li>
               
            </ul>
        </li>

         <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('category.*') ? 'active' : '' }}">
                <i class="ri-apps-2-fill"></i>
                <span>Manage Category</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('category.all') }}">All Category</a></li>
               
            </ul>
        </li>


          <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('product.*') ? 'active' : '' }}">
                <i class="ri-reddit-fill"></i>
                <span>Manage Product</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('product.all') }}">All Product</a></li>
               
            </ul>
        </li>


          <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('purchase.*') || request()->routeIs('daily.purchase.*') ? 'active' : '' }}">
                <i class="ri-oil-fill"></i>
                <span>Manage Purchase</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('purchase.all') }}">All Purchase</a></li>
                <li><a href="{{ route('procurement.orders') }}">Purchase Orders</a></li>
                <li><a href="{{ route('procurement.receipts') }}">Goods Receipts</a></li>
                <li><a href="{{ route('procurement.invoices.index') }}">Supplier Invoices</a></li>
                <li><a href="{{ route('procurement.invoices.price-variance') }}">Purchase Price Variance</a></li>
                <li><a href="{{ route('procurement.payments.index') }}">Accounts Payable</a></li>
                <li><a href="{{ route('procurement.payments.aging') }}">Supplier Aging</a></li>
                <li><a href="{{ route('procurement.landed.costs.index') }}">Landed Costs</a></li>
                <li><a href="{{ route('purchase.pending') }}">Approval Purchase</a></li>
                <li><a href="{{ route('daily.purchase.report') }}">Daily Purchase Report</a></li>
               
            </ul>
        </li>


          <li>
            <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('invoice.*') || request()->routeIs('print.invoice.*') || request()->routeIs('daily.invoice.*') ? 'active' : '' }}">
                <i class="ri-compass-2-fill"></i>
                <span>Manage Invoice</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="{{ route('invoice.all') }}">All Invoice</a></li>
                <li><a href="{{ route('invoice.pending.list') }}">Approval Invoice</a></li>
                <li><a href="{{ route('print.invoice.list') }}">Print Invoice List</a></li>
                <li><a href="{{ route('daily.invoice.report') }}">Daily Invoice Report</a></li>
                <li><a href="{{ route('fulfillment.orders') }}">Sales Orders</a></li>
                <li><a href="{{ route('fulfillment.deliveries') }}">Deliveries</a></li>
               
            </ul>
        </li>

                             





                            <li class="menu-title">Inventory &amp; reports</li>

    <li>
        <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('stock.*') || request()->routeIs('supplier.wise.*') || request()->routeIs('product.wise.*') ? 'active' : '' }}">
            <i class="ri-gift-fill"></i>
            <span>Manage Stock</span>
        </a>
        <ul class="sub-menu" aria-expanded="false">
            <li><a href="{{ route('stock.report') }}">Stock Report</a></li>
            <li><a href="{{ route('stock.movements') }}">Stock Movement Ledger</a></li>
            <li><a href="{{ route('stock.valuation') }}">Stock Valuation</a></li>
            <li><a href="{{ route('stock.location.stock') }}">Location Stock</a></li>
            <li><a href="{{ route('stock.expiry') }}">Batch Expiry Alerts</a></li>
            <li><a href="{{ route('stock.batch.stock') }}">Batch-wise Stock</a></li>
            <li><a href="{{ route('stock.serial.stock') }}">Serial-wise Stock</a></li>
            <li><a href="{{ route('inventory.adjustments') }}">Stock Adjustments</a></li>
            <li><a href="{{ route('inventory.opening.create') }}">Opening Stock</a></li>
            <li><a href="{{ route('inventory.transfers') }}">Stock Transfers</a></li>
            <li><a href="{{ route('inventory.returns.index') }}">Stock Returns</a></li>
            <li><a href="{{ route('inventory.counts.index') }}">Physical Stock Counts</a></li>
            <li><a href="{{ route('inventory.status.index') }}">Quarantine / Damaged</a></li>
            <li><a href="{{ route('stock.supplier.wise') }}">Supplier / Product Wise </a></li>
            
        </ul>
    </li>

                            <li>
                                <a href="javascript: void(0);" class="has-arrow waves-effect">
                                    <i class="ri-profile-line"></i>
                                    <span>Support</span>
                                </a>
                                <ul class="sub-menu" aria-expanded="false">
                                    <li><a href="{{ route('admin.profile') }}">My profile</a></li>
                                    <li><a href="{{ route('change.password') }}">Security</a></li>
                                </ul>
                            </li>

                           

                            
                         

                        </ul>
                    </div>
                    <!-- Sidebar -->
                </div>
            </div>
