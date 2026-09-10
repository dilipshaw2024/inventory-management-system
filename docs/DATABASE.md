# Database Reference

## Business tables

All business tables have an auto-incrementing `id` and Laravel timestamps. Business ID columns are integers without database foreign keys.

| Table | Important columns | Meaning |
| --- | --- | --- |
| `users` | `name`, `username`, `email`, `password`, `email_verified_at` | Authenticated accounts |
| `suppliers` | `name`, `mobile_no`, `email`, `address`, `status`, audit IDs | Supplier master data |
| `customers` | `name`, `customer_image`, contact fields, `status`, audit IDs | Customer master data |
| `units` | `name`, `status`, audit IDs | Product units |
| `categories` | `name`, `status`, audit IDs | Product categories |
| `products` | `supplier_id`, `unit_id`, `category_id`, `name`, `quantity`, `status`, audit IDs | Current stock master |
| `purchases` | supplier/category/product IDs, `purchase_no`, `date`, `buying_qty`, `unit_price`, `buying_price`, `status` | Incoming stock lines |
| `invoices` | `invoice_no`, `date`, `description`, `status`, audit IDs | Sale headers |
| `invoice_details` | invoice/category/product IDs, `selling_qty`, `unit_price`, `selling_price`, `status` | Sale lines |
| `payments` | invoice/customer IDs, `paid_status`, `paid_amount`, `due_amount`, `total_amount`, `discount_amount` | Current payment summary |
| `payment_details` | invoice ID, `current_paid_amount`, `date`, `updated_by` | Payment event history |

## Eloquent relationships

- `Product` belongs to `Supplier`, `Unit`, and `Category`.
- `Purchase` belongs to `Product`, `Supplier`, `Unit`, and `Category`.
- `Invoice` has many `InvoiceDetail` rows and maps to `Payment` by invoice ID.
- `InvoiceDetail` belongs to `Product` and `Category`.
- `Payment` belongs to `Customer` and `Invoice`.

Supplier and customer relationships are primarily queried through IDs in controllers/views rather than declared model methods.

## Status values

| Column | Values used |
| --- | --- |
| `purchases.status` | `0` pending, `1` approved |
| `invoices.status` | `0` pending, `1` approved |
| `invoice_details.status` | `0` pending, `1` approved |
| master-data status columns | Default `1`; not consistently filtered |
| `payments.paid_status` | `full_paid`, `full_due`, `partial_paid` |

## Stock accounting

The authoritative current stock is `products.quantity`.

```text
Approved purchase: Product.quantity += Purchase.buying_qty
Approved invoice:  Product.quantity -= InvoiceDetail.selling_qty
```

The stock report also calculates approved purchased and sold totals for display. Those totals do not recompute the stored quantity.

## Payment accounting

At invoice creation:

```text
total_amount = estimated_amount
full_paid:    paid_amount = total_amount; due_amount = 0
full_due:     paid_amount = 0;           due_amount = total_amount
partial_paid: paid_amount = entered amount; due_amount = total_amount - entered amount
```

Later payments update the `payments` summary and append a `payment_details` event. They do not alter invoice status or stock.

## Schema considerations

Prices and quantities use MySQL `double`, which can create monetary precision issues. IDs lack foreign keys and indexes beyond primary keys. Migrations permit many nullable values. These should be addressed before strict accounting or high-volume concurrent operations.
