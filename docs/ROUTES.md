# Route Reference

All routes in the authenticated admin group require `auth`. The dependent-dropdown lookup routes also require `auth`.

## Public and dashboard

| Method | URI | Name | Handler |
| --- | --- | --- | --- |
| GET | `/` | — | welcome view |
| GET | `/about` | `about.page` | `DemoController@Index` (`check` middleware) |
| GET | `/contact` | `cotact.page` | `DemoController@ContactMethod` |
| GET | `/dashboard` | `dashboard` | admin dashboard view |

## Admin/profile

| Method | URI | Name | Handler |
| --- | --- | --- | --- |
| GET | `/admin/logout` | `admin.logout` | `AdminController@destroy` |
| GET | `/admin/profile` | `admin.profile` | `AdminController@Profile` |
| GET | `/edit/profile` | `edit.profile` | `AdminController@EditProfile` |
| POST | `/store/profile` | `store.profile` | `AdminController@StoreProfile` |
| GET | `/change/password` | `change.password` | `AdminController@ChangePassword` |
| POST | `/update/password` | `update.password` | `AdminController@UpdatePassword` |

## Master data

Every module has list/add/store/edit/update/delete routes:

| Module | Named route families |
| --- | --- |
| Supplier | `supplier.all`, `supplier.add`, `supplier.store`, `supplier.edit`, `supplier.update`, `supplier.delete` |
| Customer | `customer.all`, `customer.add`, `customer.store`, `customer.edit`, `customer.update`, `customer.delete` |
| Unit | `unit.all`, `unit.add`, `unit.store`, `unit.edit`, `unit.update`, `unit.delete` |
| Category | `category.all`, `category.add`, `category.store`, `category.edit`, `category.update`, `category.delete` |
| Product | `product.all`, `product.add`, `product.store`, `product.edit`, `product.update`, `product.delete` |

Edit/delete routes use `{id}`; update routes receive the ID from the form.

## Purchases

| Method | URI | Name | Purpose |
| --- | --- | --- | --- |
| GET | `/purchase/all` | `purchase.all` | List purchases |
| GET | `/purchase/add` | `purchase.add` | Purchase form |
| POST | `/purchase/store` | `purchase.store` | Save pending purchase lines |
| GET | `/purchase/delete/{id}` | `purchase.delete` | Delete purchase |
| GET | `/purchase/pending` | `purchase.pending` | List pending purchases |
| GET | `/purchase/approve/{id}` | `purchase.approve` | Add quantity and approve |
| GET | `/daily/purchase/report` | `daily.purchase.report` | Date filter form |
| GET | `/daily/purchase/pdf` | `daily.purchase.pdf` | Approved date-range report |

## Invoices

| Method | URI | Name | Purpose |
| --- | --- | --- | --- |
| GET | `/invoice/all` | `invoice.all` | Approved invoices |
| GET | `/invoice/add` | `invoice.add` | Invoice form |
| POST | `/invoice/store` | `invoice.store` | Save pending invoice, details, payment |
| GET | `/invoice/pending/list` | `invoice.pending.list` | Pending invoices |
| GET | `/invoice/delete/{id}` | `invoice.delete` | Delete invoice and children |
| GET | `/invoice/approve/{id}` | `invoice.approve` | Approval form |
| POST | `/approval/store/{id}` | `approval.store` | Validate stock and approve invoice |
| GET | `/print/invoice/list` | `print.invoice.list` | Printable invoice list |
| GET | `/print/invoice/{id}` | `print.invoice` | Printable invoice |
| GET | `/daily/invoice/report` | `daily.invoice.report` | Date filter form |
| GET | `/daily/invoice/pdf` | `daily.invoice.pdf` | Approved date-range report |

## Customer, stock, and lookup routes

Customer routes are `credit.customer`, `credit.customer.print.pdf`, `customer.edit.invoice/{invoice_id}`, `customer.update.invoice/{invoice_id}`, `customer.invoice.details/{invoice_id}`, `paid.customer`, `paid.customer.print.pdf`, `customer.wise.report`, `customer.wise.credit.report`, and `customer.wise.paid.report`.

Stock routes are `stock.report`, `stock.report.pdf`, `stock.supplier.wise`, `supplier.wise.pdf`, and `product.wise.pdf`. The supplier/product report accepts `supplier_id`, or `category_id` plus `product_id`.

| Method | URI | Name | Response |
| --- | --- | --- | --- |
| GET | `/get-category` | `get-category` | JSON categories for `supplier_id` |
| GET | `/get-product` | `get-product` | JSON products for `category_id` |
| GET | `/check-product` | `check-product-stock` | JSON quantity for `product_id` |

## Authentication routes

`routes/auth.php` provides register, login, forgot/reset password, email verification, confirm password, and logout routes. Their exact named definitions remain in that source file.
