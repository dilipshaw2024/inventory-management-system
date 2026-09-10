# System Overview

## Purpose

The Inventory Management System is a server-rendered Laravel application for maintaining product master data, receiving stock, issuing customer invoices, recording payments, approving transactions, and producing operational reports.

## Main capabilities

- Email/password registration, login, logout, email verification, password reset, and password confirmation.
- Admin profile, profile image, and password management.
- Supplier, customer, unit, category, and product CRUD screens.
- Purchase entry with multiple line items and pending/approved workflow.
- Invoice entry with multiple line items, discount, full/partial/due payment status, and pending/approved workflow.
- Automatic stock increase on an approved purchase and stock decrease on an approved invoice.
- Credit customer list, paid customer list, customer-specific reports, payment updates, and payment history.
- Stock, supplier-wise, product-wise, daily purchase, daily invoice, invoice, and customer reports rendered as printable Blade views.

## Actors

The code exposes one authenticated application user type. The UI calls this user an admin, but the application currently checks the `auth` middleware rather than a separate role or permission system.

| Actor | Access represented in code |
| --- | --- |
| Guest | Landing pages and guest authentication routes |
| Authenticated user/admin | All admin, master-data, transaction, report, and AJAX routes |

## High-level lifecycle

```text
Configure master data
        -> Create products with zero opening quantity
        -> Record purchase(s) as pending
        -> Approve purchase(s) to increase Product.quantity
        -> Create invoice as pending and record payment snapshot
        -> Approve invoice after stock check to decrease Product.quantity
        -> Record later customer payments in PaymentDetail
        -> Review and print operational reports
```

## Important current-state facts

- The dashboard contains template/sample figures and rows in `resources/views/admin/index.blade.php`; it is not populated from inventory tables.
- Purchases and invoices use `0 = Pending` and `1 = Approved`; master-data status defaults to `1`, but is not consistently used to filter lists.
- There are no foreign-key constraints in the business migrations; relationships are expressed in Eloquent models and integer ID columns.
- Validation is limited and inconsistent. Authentication uses request validation, while most POS actions rely on browser forms and controller assumptions.
- The app uses Blade views for printable “PDF” pages. The repository does not include a PDF-generation package or a dedicated PDF response layer.
