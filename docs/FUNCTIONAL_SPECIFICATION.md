# Functional Specification

## Authentication and account management

Guests can register, log in, request a password reset, reset a password, and complete email verification. Authenticated users can log out, view/edit their profile, upload a profile image, and change their password.

Profile images are stored in `public/upload/admin_images`. Customer images are resized to 200x200 and stored in `public/upload/customer`.

## Master data

Each master-data module follows a list/add/store/edit/update/delete pattern:

- Suppliers: name, phone, email, address.
- Customers: name, image, phone, email, address.
- Units: unit name.
- Categories: product grouping.
- Products: name, supplier, unit, category, and quantity.

Create and update operations record `created_by`/`updated_by` where implemented. Products start with `quantity = 0`; quantity is changed by approved purchases and invoices, not by the product edit form.

Deletion uses GET routes and `findOrFail`. Customer deletion also attempts to unlink the stored image path.

## Purchase workflow

The purchase form selects a supplier, then loads categories associated with that supplier, then loads products for the selected category. Multiple rows can be added in the browser. Each row contains date, purchase number, supplier, category, product, quantity, unit price, description, and calculated buying price.

`buying_price = buying_qty × unit_price`; the client calculates the estimated total as the sum of line buying prices.

`POST /purchase/store` creates one `purchases` row per submitted line with `status = 0` (Pending). Saving does not change product stock. Approval adds `buying_qty` to the selected product's `quantity`, then changes the purchase status to `1` (Approved). Daily purchase reports include approved records in an inclusive date range.

## Invoice and sales workflow

The invoice form generates the next invoice number from the latest invoice number, loads products by category, and displays current product stock through AJAX. Multiple line items can be added.

`selling_price = selling_qty × unit_price`; the estimated amount is the line total minus discount.

The customer selector supports an existing customer or an inline customer record (`customer_id = 0`). Payment status values are:

| Value | Meaning | Initial payment values |
| --- | --- | --- |
| `full_paid` | Paid in full | paid = total, due = 0 |
| `full_due` | No payment yet | paid = 0, due = total |
| `partial_paid` | Partially paid | paid = entered amount, due = total - entered amount |

`POST /invoice/store` creates an invoice, detail rows, a payment snapshot, and an initial payment-detail row inside a database transaction. The invoice and details start pending (`status = 0`) and stock is unchanged.

Approval checks every requested quantity against current product quantity. If all lines pass, details become approved, product quantities are reduced, and the invoice becomes approved (`status = 1`) inside a transaction.

Deleting an invoice removes the invoice, its details, payment, and payment-detail rows. Approved invoices can be listed and printed. Daily invoice reports include approved invoices in a date range.

## Customer credit and payment workflow

The credit list selects payments with `paid_status` equal to `full_due` or `partial_paid`. An invoice can be opened to add a payment. Full settlement adds the current due amount and sets due to zero; partial settlement adds the submitted amount and subtracts it from due. Each update creates a `payment_details` history row.

The paid list selects payments whose status is not `full_due`, so it includes both `full_paid` and `partial_paid`. Customer-wise credit and paid report endpoints filter by customer ID.

## AJAX dependencies

- Supplier change -> `/get-category`: distinct categories represented by products for the supplier.
- Category change -> `/get-product`: products for the category.
- Product change -> `/check-product`: current product quantity.

These JSON endpoints are used by purchase, invoice, and stock report forms.

## Reports and printable views

Report routes return Blade templates under `resources/views/backend/pdf`. They are intended to open in a new browser tab and be printed or saved using browser print functionality. Families include stock, supplier-wise stock, product-wise stock, daily purchase, daily invoice, invoice detail, invoice, customer credit, customer paid, and customer-wise variants.

## Current implementation caveats

- Quantity, price, and status calculations are trusted from submitted form values in several paths; server-side validation should be added.
- Purchase approval is not transactional and has no duplicate-approval guard; approving the same row twice can add stock twice.
- Invoice number generation reads the latest row, so concurrent invoice creation can collide.
- Delete routes use GET; POST/DELETE routes would be safer.
- Customer image deletion assumes the stored path exists and is valid.
