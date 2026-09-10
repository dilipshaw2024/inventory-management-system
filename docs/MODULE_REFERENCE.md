# Module-wise Functional and Technical Reference

This document explains why each application module exists, what it owns, how it works, what it depends on, and how it participates in the complete inventory lifecycle.

## Module map

```text
Authentication / Admin
          |
          v
Supplier -> Product <- Unit
              ^
              |
          Category
              |
       +------+------+
       v             v
   Purchase       Invoice -> Payment -> Customer
       |             |
       +------> Stock <------+ 
                      |
                   Reports
```

The source for the relationship diagram is [`diagrams/module-relationships.mmd`](diagrams/module-relationships.mmd).

## 1. Authentication module

### Why it exists

Authentication protects operational screens and associates changes with the logged-in user. It provides the account lifecycle required before inventory or financial records can be managed.

### Main functionality

- Register and authenticate users.
- Verify email addresses.
- Request and complete password resets.
- Confirm the current password for sensitive flows.
- Log out and invalidate the session.

### How it works

Guest routes in `routes/auth.php` show login/register/reset forms and submit to the auth controllers. Authenticated routes use Laravel's `auth` middleware. The authenticated user ID is written to `created_by`, `updated_by`, or payment history fields where the controller implements auditing.

### Related code and data

- Controllers: `app/Http/Controllers/Auth/*`
- Model: `app/Models/User.php`
- Table: `users`
- Middleware: Laravel `auth`, `guest`, signed verification, and throttle middleware

### Important boundary

There is one authenticated user type in the code. There is no separate role, permission, or approval-authority module.

## 2. Admin/profile module

### Why it exists

It gives the signed-in operator a place to maintain identity information and credentials.

### Main functionality

- View and edit name, username, and email.
- Upload an admin profile image.
- Change the password after checking the old password.
- Sign out through the admin controller.

### How it works

`AdminController` loads the current `Auth::user()`, updates the `users` row, and stores profile images under `public/upload/admin_images`. Password changes use `Hash::check` and bcrypt.

### Related code and data

- Controller: `app/Http/Controllers/AdminController.php`
- Views: `resources/views/admin/admin_profile_*.blade.php`, `admin_change_password.blade.php`
- Table: `users`

## 3. Supplier module

### Why it exists

Suppliers identify where products are sourced. Supplier identity is needed for product master data, purchasing, supplier-wise stock reports, and traceability of incoming inventory.

### Main functionality

- List suppliers.
- Add, edit, and delete supplier records.
- Store name, mobile number, email, address, status, and audit IDs.
- Filter stock by supplier.

### How it works

`SupplierController` performs direct Eloquent reads/inserts/updates/deletes and redirects with flash notifications. Product forms use supplier IDs. The purchase form uses the selected supplier to request available categories through `/get-category`.

### Related code and data

- Controller: `app/Http/Controllers/Pos/SupplierController.php`
- Model/table: `Supplier` / `suppliers`
- Consumers: Product, Purchase, Stock report, purchase PDF report

## 4. Customer module

### Why it exists

Customers are the parties receiving invoices and owing or paying money. The module supports both reusable customer records and quick inline customers during invoice entry.

### Main functionality

- List, add, edit, and delete customers.
- Store contact information and a resized profile image.
- View credit customers and paid customers.
- Open an invoice's payment details.
- Record full or partial later payments.
- Generate customer-wise credit and paid reports.

### How it works

Customer CRUD is handled by `CustomerController`. During invoice creation, selecting customer ID `0` creates a new customer from inline form fields. Credit and paid lists are projections of the `payments` table. A later payment updates the payment summary and creates a `payment_details` history row.

### Related code and data

- Controller: `app/Http/Controllers/Pos/CustomerController.php`
- Model/table: `Customer` / `customers`
- Related tables: `payments`, `payment_details`, `invoices`, `invoice_details`
- Views: `resources/views/backend/customer/*`, `resources/views/backend/pdf/customer_*.blade.php`

### Payment classifications

- Credit view: `full_due` or `partial_paid`.
- Paid view: any status other than `full_due`, including `partial_paid` and `full_paid`.

## 5. Unit module

### Why it exists

Units describe how products are counted or sold, such as pieces, kilograms, or other business-specific units.

### Main functionality

List, add, edit, and delete unit names. A product stores `unit_id` so forms and reports can display the product's measurement context.

### Related code and data

- Controller/model: `UnitController` / `Unit`
- Table: `units`
- Consumer: Product module and purchase/inventory presentation

## 6. Category module

### Why it exists

Categories organize products and make product selection manageable in purchase, invoice, and product-wise report screens.

### Main functionality

List, add, edit, and delete category names. Category IDs are stored on products, purchases, and invoice details.

### How it works

The category selector is the second step in dependent dropdowns. In purchase entry, categories are limited to those represented by products belonging to the selected supplier. In invoice entry and stock reporting, products are loaded by category through `/get-product`.

### Related code and data

- Controller/model: `CategoryController` / `Category`
- Table: `categories`
- Consumers: Product, Purchase, Invoice, Stock, AJAX lookup module

## 7. Product module

### Why it exists

Products are the central stock items. They connect supplier, unit, and category master data to incoming purchases and outgoing invoice lines.

### Main functionality

- List, add, edit, and delete products.
- Assign supplier, unit, and category.
- Display current quantity.
- Serve product choices to purchase/invoice forms.

### How it works

New products start with `quantity = 0`. Product edit does not directly change quantity. Approved purchases increase quantity; approved invoice details decrease quantity. The product endpoint `/check-product` exposes current quantity to the invoice form and approval screens.

### Related code and data

- Controller/model: `ProductController` / `Product`
- Table: `products`
- Foreign-key-like IDs: `supplier_id`, `unit_id`, `category_id`
- Consumers: Purchase, Invoice, Stock, AJAX lookup, reports

## 8. Purchase module

### Why it exists

Purchases represent stock received from suppliers and provide the controlled entry point for increasing inventory.

### Main functionality

- Create multi-line purchase entries.
- Calculate line and estimated totals in the browser.
- Keep new purchases pending for review.
- Approve a purchase to add quantity to a product.
- Delete purchases and generate daily approved-purchase reports.

### How it works

1. User chooses supplier, category, and product.
2. JavaScript appends one or more line rows.
3. The form submits arrays of line values to `PurchaseController@PurchaseStore`.
4. Each line is saved with `status = 0`.
5. Approval adds `buying_qty` to `products.quantity` and changes status to `1`.

### Related code and data

- Controller: `app/Http/Controllers/Pos/PurchaseController.php`
- Model/table: `Purchase` / `purchases`
- Inputs: supplier, category, product, date, purchase number, quantity, price, description
- Consumers: Product quantity and Stock/Reports

### Consistency warning

Purchase approval is not transactional and does not check whether the purchase is already approved. This is a known risk documented for future hardening.

## 9. Invoice module

### Why it exists

Invoices represent outgoing sales and provide the controlled entry point for reducing inventory and creating a customer financial obligation.

### Main functionality

- Generate sequential-looking invoice numbers.
- Add multiple product lines.
- Calculate selling price and discount.
- Select an existing or inline customer.
- Record payment status at invoice creation.
- Hold invoices pending approval.
- Check stock and approve the sale.
- Print approved invoices and daily reports.

### How it works

1. The form loads categories and derives the next invoice number.
2. Category selection loads products; product selection retrieves current stock.
3. JavaScript calculates each `selling_price` and the discounted estimated amount.
4. Invoice submission runs in a database transaction and creates the invoice header, details, payment summary, and first payment-detail event.
5. The pending approval screen rechecks stock on the server.
6. Successful approval marks the invoice/details approved and subtracts quantities from products in a transaction.

### Related code and data

- Controller: `app/Http/Controllers/Pos/InvoiceController.php`
- Models/tables: `Invoice` / `invoices`, `InvoiceDetail` / `invoice_details`
- Related modules: Product, Category, Customer, Payment, Stock, Reports

### Consistency warning

Invoice number allocation is based on the latest row and can collide under concurrent requests. Submitted quantities and prices also need stronger server-side validation.

## 10. Payment module

### Why it exists

Payments separate the invoice sale from the amount collected and allow the system to track outstanding customer balances.

### Main functionality

- Record full-paid, full-due, or partial-paid status at invoice creation.
- Store the current total, paid amount, due amount, and discount.
- Accept subsequent full or partial payments.
- Preserve each later payment in `payment_details`.
- Support credit/paid customer reports.

### How it works

The invoice transaction creates one `payments` summary and one initial `payment_details` row. Later updates add to the paid amount, reduce due amount, update the status, and append another detail row. Payment changes do not change inventory.

### Related code and data

- Controllers: `InvoiceController`, `CustomerController`
- Models/tables: `Payment` / `payments`, `PaymentDetail` / `payment_details`
- Related modules: Invoice and Customer

## 11. Stock module

### Why it exists

Stock gives operators a current view of available product quantities and historical movement summaries.

### Main functionality

- List current stock by supplier/category order.
- Print stock reports.
- Filter stock by supplier.
- Filter stock by category and product.
- Show approved purchase totals and approved sales totals.

### How it works

The current balance is read from `products.quantity`. The stock view also sums approved `purchases.buying_qty` and approved `invoice_details.selling_qty` for display. Stock is mutated only by purchase approval and invoice approval.

### Related code and data

- Controller: `app/Http/Controllers/Pos/StockController.php`
- Model/table: `Product` / `products`
- Movement sources: `purchases`, `invoice_details`
- Views: `resources/views/backend/stock/*` and `resources/views/backend/pdf/stock_*.blade.php`

## 12. AJAX lookup module

### Why it exists

Dependent dropdowns reduce invalid combinations and make product selection faster without reloading the page.

### Endpoints

| Endpoint | Input | Output | Used by |
| --- | --- | --- | --- |
| `/get-category` | `supplier_id` | Distinct product categories with category relation | Purchase form |
| `/get-product` | `category_id` | Products in category | Purchase, invoice, stock report |
| `/check-product` | `product_id` | Current quantity | Invoice form |

### Boundary

These routes are session-authenticated and should also validate request IDs and return controlled errors if the selected records do not exist.

## 13. Report and printable-view module

### Why it exists

Operators need audit-friendly lists and printable outputs for stock, purchases, invoices, and customer balances.

### Main functionality

- Approved purchase and invoice date-range reports.
- Current stock, supplier-wise stock, and product-wise stock.
- Invoice and invoice-detail printing.
- Customer credit, paid, and customer-wise reports.

### How it works

Controllers query approved or payment-filtered records and render Blade templates in `resources/views/backend/pdf`. The browser opens many reports in a new tab; printing is handled by the browser rather than a PDF service.

## Complete business flow

```text
Admin signs in
  -> creates Supplier, Unit, Category
  -> creates Product linked to those masters
  -> records Purchase
  -> approves Purchase
  -> Product.quantity increases
  -> creates Invoice for Customer
  -> records Payment status
  -> approves Invoice after stock check
  -> Product.quantity decreases
  -> records later Customer payment if due remains
  -> reads Stock and financial Reports
```

See [`diagrams/module-flow.mmd`](diagrams/module-flow.mmd) for the complete flow and [`diagrams/module-dependencies.mmd`](diagrams/module-dependencies.mmd) for code-layer dependencies.
