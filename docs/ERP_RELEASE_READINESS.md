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

`erp:system:health --json --strict` is suitable for CI or an uptime monitor. The unauthenticated `GET /health/erp` endpoint returns only `{"status":"healthy|unhealthy","checked_at":"..."}` and uses HTTP 503 when the same readiness checks fail, making it suitable for a load balancer without exposing schema details. Readiness also verifies that the configured queue and cache stores exist, and that a database-backed queue has its `jobs` table. A release is blocked when it reports an unhealthy application key, database, migration table, pending migration, core ERP table, queue/cache configuration, production-debug, or private-storage check.

## 2. Background processing

Run a continuously supervised queue worker and the Laravel scheduler. The scheduler drives inventory expiry, low-stock, excess-stock, slow-moving, and dead-stock alerts, replenishment generation, production planning, reservation expiry, carrier tracking polling, webhook delivery, accounting jobs, approval escalation, snapshots, audit-chain verification, legacy-versus-ledger reconciliation, and product-import processing. Recurring tasks use `withoutOverlapping` and `onOneServer`; production must use a shared scheduler cache/lock store when more than one application node is active. Audit-chain verification and ledger reconciliation are read-only scheduled controls; monitor failures when integrity or quantity drift is detected.

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

The repository workflow at `.github/workflows/erp-ci.yml` automates this release evidence on pushes and pull requests: PHP dependency installation, strict Composer manifest validation, frontend compilation, isolated MySQL setup, PHP syntax checks, Blade and route compilation, deployment-script syntax validation, whitespace checks, and the complete `bin/test-erp` suite. A production promotion should require this workflow to pass in addition to the tenant-specific acceptance script above.

## Latest repository evidence

The latest authoritative clean isolated Laravel/MySQL regression passes **399 tests** with zero failures in **795.76 seconds** after migration `2026_11_11_000457`, including **96 unit tests** at that time. The current complete fast unit suite passes **99 tests / 212 assertions**, including the regional-pack and inventory-aging coverage added after that baseline. PHP syntax, Blade compilation, route registration, and `git diff --check` also pass. Earlier paragraphs in this section are historical audit entries; this is the current release baseline.

The HR statutory-remittance integration now exposes a configurable provider registry and submission gateway. Migration `000450` adds a tenant-scoped submission ledger with package hash, status, provider reference/response, submitter, and replay-safe uniqueness. The default HTTP adapter uses an integrity hash and idempotency headers; custom provider classes are configured with `ERP_STATUTORY_REMITTANCE_ADAPTERS`. Provider readiness is available through `GET /api/hr/statutory-remittance-providers`, submissions through `POST /api/hr/statutory-remittances/submit`, and history through `GET /api/hr/statutory-remittance-submissions`.

When run locally without the application MySQL service, `php artisan erp:system:health --json --strict` correctly reports `database`, `migrations`, and `erp_schema` as unavailable while still evaluating queue, cache, production-debug, and private-storage checks. This is an environment readiness failure, not evidence of a regression-suite failure; restore the configured application database before promoting a tenant.
The manufacturing integration now provides tenant-scoped routing read/write endpoints, including setup groups and alternate work centers, so setup-optimized dispatch can be configured by external ERP/MES clients. Full database recertification remains pending for migrations 000450–000453.
Migration 2026_11_11_000454 adds expiry-aware customer and supplier tax exemption certificates. Full database recertification must include this migration and remains pending while the disposable MySQL service is unavailable.

The tax compliance integration now exposes tenant-scoped `GET /api/accounting/tax-exemption-certificates` for active, expired, and expiring certificate monitoring, with expiry horizon/cutoff filters and paginated status output for authorized accounting clients.

Browser accounting parity is available at `/erp/accounting/tax-exemption-certificates`, with tenant-scoped filtering and renewal-status presentation. Database-backed browser regression remains pending until the configured MySQL service is available.

Permission-protected CSV/XLSX export is available from the same report and reuses its status and expiry-horizon filters.

`php artisan erp:system:health --json --strict` now reports the configured database connection target (without credentials) when the database is unreachable, making deployment remediation clearer while retaining a non-throwing unhealthy response.

The manufacturing integration now provides guarded batch production release with per-order partial-success reporting. Database-backed regression coverage is added but remains pending until MySQL connectivity is restored.

Warehouse integration now provides guarded batch pick/pack completion with idempotent operation handling and tenant/store isolation. Database-backed regression coverage is added but remains pending until MySQL connectivity is restored.

Sales integration now provides guarded batch delivery approval by reusing the existing stock/batch/serial/accounting transaction. Database-backed regression coverage is added but remains pending until MySQL connectivity is restored.

Latest isolated verification: the canonical `bin/test-erp` suite passes **390 tests** with zero failures in **672.22 seconds** against disposable MySQL after migrations `000454–000455`. The unit suite passes **93 tests**, the tax-exemption certificate-feed test passes, Blade and route caches compile, the production frontend build succeeds, PHP lint passes for the changed files, and `git diff --check` passes. The current application migration head is `2026_11_11_000455`.

Latest dispatch increment: migration `2026_11_11_000456` adds immutable structured proof-of-delivery records and audited, idempotent integration endpoints at `/api/integration/deliveries/{deliveryId}/proofs`. The complete canonical regression passes **390 tests** with zero failures in **702.30 seconds** after this migration. The unit suite, Blade/route cache, PHP lint, frontend build, and `git diff --check` also pass. The root route now uses a cache-safe `Route::view` declaration, so the compiled production route cache is compatible with the test workflow.

This is the current authoritative release evidence. Earlier verification paragraphs above are historical audit entries and should not be used as the current test baseline.

Post-baseline implementation note: migration `2026_11_11_000457` adds optional company-validated, per-tier approval-escalation recipients with permission checks and backward-compatible fallback routing. Focused coverage passes **1 test**; the full canonical suite must be rerun before production promotion because the 390-test baseline predates this migration.

Latest authoritative verification after migration `000457`: `./bin/test-erp` passes **399 tests** with zero failures in **795.76 seconds** against disposable MySQL; the unit suite passes **96 tests**. The escalation-routing, scheduled-window, calendar-capacity, daily-workload, profitability, customer-dimension, sales-period-comparison, supplier corrective-action performance, company localization, India GST/EU VAT compliance-pack, and purchase period-comparison/export regressions are included in this count. Earlier 390/391/392/393-test paragraphs are historical audit entries.

The approval-policy browser administration screen now exposes tier-recipient routing with company-user guidance; Blade compilation and PHP lint pass after this UI parity change.

The browser delivery board now displays tenant-scoped structured proof-of-delivery details and retains the legacy POD reference fallback; Blade compilation, PHP lint, and whitespace checks pass.

The browser maintenance dispatch board now includes a seven-day calendar presentation of scheduled work while preserving the detailed schedule-edit table and existing technician calendar, leave, overlap, and skill validation. Blade compilation, PHP lint, and `git diff --check` pass after this UI-only slice; the authoritative database regression baseline is 396 tests / 0 failures in 665.12 seconds.

Open maintenance orders can now be dragged between calendar days and are rescheduled through the existing audited browser endpoint; closed orders are not draggable, and backend technician/leave/overlap/skill guards remain authoritative. Blade and route caches compile after this UI-only enhancement.

Service integrations can now synchronize only the maintenance orders in a requested scheduled-start window through optional `scheduled_from`/`scheduled_to` filters, with tenant scoping and cursor pagination preserved. Feature coverage was added for inclusion and reversed-window rejection.

Focused verification: `ServiceMeterTelemetryTest` passes **5 tests** with zero failures in **697.24 seconds** after the service calendar feed update. The canonical full regression baseline is now **393 tests / 0 failures** in **688.50 seconds**, including the new scheduled-window test.

Service integrations now expose `GET /api/service/dispatch/calendar` with tenant-scoped, bounded day groups, scheduled work, technician workload totals, and unassigned counts. Focused verification passes **6 tests** with zero failures in **652.82 seconds**, including the calendar feed and range guard; the authoritative full-suite baseline is now **393 tests / 0 failures**.

The same calendar feed now returns shift-derived technician capacity and utilization percentages with weekend/holiday exclusions. Focused verification after the capacity enhancement passes **6 tests** with zero failures in **620.27 seconds**; the full-suite baseline is now **393 tests / 0 failures** in **688.50 seconds**.

The dispatch calendar feed now adds per-technician daily workload/capacity/utilization rows for the requested range. Focused verification passes **6 tests** with zero failures in **689.56 seconds** after this enhancement; the authoritative full-suite baseline remains **393 tests / 0 failures** until cumulative recertification.

Inventory profitability now allocates existing invoice discounts proportionally to product lines and exposes gross revenue, discount, and net revenue consistently in browser and CSV/XLSX reports. Focused reconciliation verification passes **4 tests** with zero failures in **703.98 seconds**; the authoritative full-suite baseline is now **396 tests / 0 failures** in **665.12 seconds**.

Inventory profitability now also supports tenant-safe customer filtering and customer dimension metadata across the browser report, CSV/XLSX exports, and `/api/inventory/analytics`; API summaries expose gross revenue and allocated discounts alongside net revenue. Focused reconciliation coverage passes **4 tests** in **703.46 seconds**, and cumulative full-suite recertification passes **396 tests** with zero failures in **665.12 seconds**.

Sales reporting now supports an optional tenant-safe prior-period comparison in the browser using the same product, customer, channel, salesperson, and delivery filters, with quantity/revenue/tax/COGS/gross-profit amount and percentage variances. Focused sales-report coverage passes **1 test** in **697.01 seconds**, and cumulative full-suite recertification passes **396 tests** with zero failures in **665.12 seconds**.

Supplier performance browser rows, CSV/XLSX exports, and the optional monthly integration trend now include tenant-scoped corrective-action totals, open counts, overdue counts, invoiced quantity/value, and absolute approved-invoice price variance alongside the percentage variance for the selected period, including suppliers with corrective actions but no orders in that period. Focused supplier corrective-action/performance coverage passes **1 test** with zero failures in **648.33 seconds**. The latest clean cumulative baseline now passes **396 tests** with zero failures in **665.12 seconds**.

Company localization metadata is now applied per authenticated web/API request: valid company timezone and locale values temporarily govern request date/time and translation behavior and are restored after the response, preserving safe defaults for long-lived workers. `BranchScopeTest` passes **11 tests** with zero failures in **766.19 seconds**; the latest clean cumulative regression passes **396 tests** with zero failures in **665.12 seconds**, and PHP lint, route caching, Blade compilation, and `git diff --check` also pass.

The legacy daily purchase PDF now accepts optional `compare_start_date` and `compare_end_date` filters and presents current/prior document count, quantity, amount, average unit price, and amount variance metrics while preserving the existing current-period detail rows and route. The report screen also exposes permission-protected CSV/XLSX exports using the same tenant-scoped current-period filters.
Focused `PurchaseReportComparisonTest` passes **1 test** with zero failures in **693.13 seconds**; the latest clean cumulative regression now passes **399 tests** with zero failures in **795.76 seconds**.

The integration provider manifest now exposes a pluggable regional compliance-pack family. A generic fallback preserves existing single-country deployments, while built-in India GST, EU VAT, UK VAT, and Canada GST/HST packs select from company country and tax-registration metadata, validate conservative regional identifier shapes, and describe tax/classification capabilities. `GET /api/integration/compliance` returns the selected pack’s normalized context and validation status. Additional country packs can be registered through `ERP_REGIONAL_COMPLIANCE_PACKS`; the registry unit and provider-discovery/compliance-context feature tests are included in the current verification evidence.

Warehouse picking now supports hierarchy-aware `sort_by=route` ordering on both pick-list retrieval and automated pick-wave creation. The route key is deterministic and built from the warehouse location hierarchy, with route metadata returned to external clients. Focused `WarehousePickListTest` passes **1 test** with zero failures in **551.21 seconds**; lint, route/view compilation, and whitespace checks pass. The last authoritative complete regression remains **399 tests / 0 failures / 795.76 seconds**, because the latest full runner did not return a terminal summary.

The regional compliance registry now includes built-in UK VAT and Canada GST/HST packs in addition to India GST and EU VAT. These packs provide conservative country/scheme selection, identifier-shape validation, and normalized tax/classification context; certified filing behavior remains adapter/deployment specific. The fast registry suite passes **5 tests** in **0.25 seconds**. The database-backed provider-discovery rerun did not return a terminal summary, so the previous complete regression baseline remains authoritative.

Inventory aging is now available through tenant-scoped `GET /api/inventory/analytics/aging`. It reports remaining cost-layer stock by product, category, warehouse location, batch, deterministic age bucket, quantity, unit cost, and value, with historical cutoff replay of cost-layer consumption and bucket summaries. The deterministic aging unit test passes, and database-backed endpoint coverage passes **1 test** in **618.72 seconds**, including tenant isolation and historical consumption replay.

Browser reporting parity is available at `/stock/aging`, including as-of and tenant-safe filters, bucket summaries, paginated layer detail, and permission-protected CSV/XLSX exports. Route registration, Blade compilation, PHP lint, and the deterministic unit test pass.

The versioned integration capability manifest now exposes a read-only `reports` module and explicit inventory analytics, aging, dashboard, and traceability endpoint paths for external ERP discovery. Existing module abilities and routes remain backward-compatible.

Dashboard metrics now include cost-layer inventory value and stock value older than 365 days in both the browser dashboard and the authenticated inventory dashboard API, while preserving role-aware inventory visibility and the existing dashboard cache contract.

Focused capability-manifest verification passes **1 test** with zero failures in **697.93 seconds**, including the reports-module and inventory-aging endpoint assertions.

Dashboard metrics now include tenant-scoped cost-layer inventory value and stock value older than 365 days in both browser and API responses, retaining role-aware inventory visibility. Focused `DashboardMetricsTest` passes **3 tests** with zero failures in **673.35 seconds**; PHP lint, route/view compilation, and `git diff --check` pass.

The system-settings API now allow-lists `aging_stock_value` and `aged_stock_value`, completing persistence and selection of the new dashboard widgets. Focused `DashboardMetricsTest` passes **3 tests** with zero failures in **669.62 seconds**; the fast unit suite remains **99 tests / 212 assertions**.

The executive dashboard now exposes a tenant-scoped approved-purchase trend next to sales, and preserves the configured widget order while applying finance visibility rules consistently across browser and API responses. Focused `DashboardMetricsTest` passes **3 tests** with zero failures in **701.53 seconds**; the fast unit suite remains **100 tests / 214 assertions**.

The dashboard now also exposes aggregate warehouse quantity-capacity utilization from tenant-owned locations and approved movement balances, with inventory visibility enforcement in API and browser responses. Focused `DashboardMetricsTest` passes **3 tests** with zero failures in **675.34 seconds**; lint, route/view compilation, and `git diff --check` pass.

Product imports now provide blank schema templates through the browser and inventory integration API in CSV or XLSX format, using the same canonical header list consumed by import validation. Existing import jobs, dry-runs, audit history, and retries remain unchanged; the fast unit suite passes **100 tests / 214 assertions**.

The integration capability manifest now exposes the product import-template endpoint under catalog discovery for external ERP clients.

Planning now exposes a tenant-scoped action queue in the browser dashboard and integration API, with prioritized replenishment, production, and expiry actions plus approval metadata and existing guarded execution endpoints.

Focused `ReplenishmentPlanningTest` verification passes **9 tests** with zero failures in **692.37 seconds**, including the action queue and existing planning workflows.

The action queue now covers excess and dead stock as well as replenishment, production, and expiry. Expanded `ReplenishmentPlanningTest` verification passes **9 tests** with zero failures in **661.83 seconds**.

Planning capability discovery now includes the action-queue endpoint for external ERP clients.

The planning dashboard now includes tenant-scoped cost-layer aging buckets with quantity and value across six age bands, linked to the detailed aging report and backed by the same valuation service as the API.

Browser-inclusive `ReplenishmentPlanningTest` verification passes **9 tests** with zero failures in **672.47 seconds**, covering the dashboard action queue and aging data.

Focused `EInvoiceIntegrationTest` verification passes **5 tests** with zero failures in **682.32 seconds**, including planning capability discovery and the existing e-invoice workflows.

Focused `EInvoiceIntegrationTest` verification passes **5 tests** with zero failures in **695.55 seconds**, including catalog template discovery and the existing e-invoice provider workflows.

The consolidated planning dashboard now links directly to demand forecasting in addition to MRP, purchase suggestions, and production suggestions.

Planning exception defaults are now passed explicitly with the authenticated company context, preventing future callers from resolving slow-moving/dead-stock settings through an unintended tenant context.

Latest authoritative planning regression passes **9 tests** with zero failures in **757.93 seconds**.

Retention archives now support an optional configured external disk through `ERP_RETENTION_ARCHIVE_DISK`; archive objects are content-addressed and hash-validated before reuse, while the default remains backward-compatible database-only storage. Infrastructure should use an immutable/WORM-capable backend for production retention guarantees.
