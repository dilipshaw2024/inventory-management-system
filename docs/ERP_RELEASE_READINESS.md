# ERP Release Readiness

This checklist defines the minimum evidence required before promoting the inventory/POS application to a production ERP tenant. It complements `docs/SETUP_AND_DEPLOYMENT.md` and `docs/MISSING_FEATURES.md`.

## 1. Application and schema

Run from the project root:

```bash
php artisan erp:system:health --strict
php artisan migrate:status
php artisan route:list
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`erp:system:health --json --strict` is suitable for CI or an uptime monitor. A release is blocked when it reports an unhealthy application key, database, migration table, pending migration, core ERP table, production-debug, or private-storage check.

## 2. Background processing

Run a continuously supervised queue worker and the Laravel scheduler. The scheduler drives inventory expiry and low-stock alerts, replenishment generation, production planning, reservation expiry, webhook delivery, accounting jobs, approval escalation, snapshots, audit-chain verification, legacy-versus-ledger reconciliation, and product-import processing. Recurring tasks use `withoutOverlapping` and `onOneServer`; production must use a shared scheduler cache/lock store when more than one application node is active. Audit-chain verification and ledger reconciliation are read-only scheduled controls; monitor failures when integrity or quantity drift is detected.

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120
php artisan schedule:work
```

Use Supervisor, systemd, or an equivalent process manager in production. Confirm failed jobs are monitored and replayed only after the underlying cause is corrected.

## 3. Security and tenancy

- `APP_KEY` is unique and stored outside source control.
- Production uses HTTPS and secure, HTTP-only session cookies.
- Sanctum tokens have only the abilities required by the integration.
- Company, branch, warehouse, location, product, document, journal, and attachment access is checked at the model/service boundary.
- MFA is enabled for administrators and accounting approvers.
- Private attachments are served through authenticated download routes, never public directory links.
- Audit-chain verification is scheduled and reviewed:

```bash
php artisan erp:security:verify-audit-chain
```

## 4. Accounting and inventory controls

Before opening a tenant for live transactions, verify:

- Base currency, tax rates, fiscal years, fiscal periods, numbering sequences, and account mappings are configured.
- Retained-earnings mapping exists before fiscal-period settlement.
- Inventory, COGS, inventory-loss, GRNI, tax, receivables, payables, and payment mappings are reviewed.
- A test receipt, transfer, issue, return, adjustment, purchase invoice, sales invoice, customer payment, and supplier payment reconcile to balanced journals.
- Costing method and opening inventory layers are reviewed; legacy data is backfilled with explicit operator approval:

```bash
php artisan erp:backfill-inventory-ledger --dry-run
php artisan erp:backfill-inventory-ledger --with-costing --with-accounting
```

- Fiscal-period close checklist passes, inventory reconciliation snapshots are balanced, and closed periods reject new postings.

## 5. Operational acceptance

For each enabled warehouse and store, execute a representative acceptance script covering:

1. Product, UOM, barcode, batch/serial, tax, price, and location setup.
2. Purchase requisition/RFQ/order, receipt, inspection, invoice, and supplier return.
3. Sales quotation/order, reservation, pick, pack, dispatch, invoice, payment, and customer return.
4. Stock transfer, adjustment, cycle count, quarantine/damaged stock, and traceability.
5. BOM, production order, component issue, finished-goods receipt, scrap, and WIP.
6. Replenishment, MRP, forecast, planning exceptions, and generated procurement.
7. Asset, service request, maintenance order, spare-part issue, warranty, and service history.
8. Approval, maker/checker, audit, attachment, export, and role-isolation checks.

Record the tenant, operator, date, expected result, actual result, and evidence for every scenario.

## 6. Verification gate

After implementation changes are complete, use the isolated test setup in `docs/TESTING_DATABASE.md` and point the test environment at a disposable MySQL database (never the live tenant database). Run the full Laravel/MySQL feature and unit suites, then record the exact test count, duration, failures, migration status, route registration, PHP lint, Blade compilation, and `git diff --check` result in `docs/MISSING_FEATURES.md`. A green focused test is not evidence for the full ERP scope. Database tests using `RefreshDatabase` must not be run against a shared or production database.
