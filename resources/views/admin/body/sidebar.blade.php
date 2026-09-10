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
