# Setup and Deployment Guide

This guide explains how to run the Inventory Management System locally and how to deploy it to a production Linux server.

Current migration head: `2026_09_20_000365`. The older migration-summary paragraphs below retain historical release notes; apply the complete migration sequence in filename order.

Planning integrations can read tenant-scoped inter-location replenishment suggestions from `GET /api/inventory/replenishment/transfer-suggestions` with `inventory:read`. The feed pairs policy locations with surplus stock to locations below target, returns source/destination quantities and a reason, and marks the result as approval-required. Authorized inventory writers can create a rechecked pending transfer from a suggestion with `POST /api/inventory/replenishment/transfer-suggestions/create-transfer`; the existing warehouse-transfer approval, dispatch, receipt, serial, and ledger workflow remains authoritative, so creation does not post stock.

The scheduler command `php artisan erp:planning:generate-transfer-orders --company=COMPANY_ID` creates the same approval-pending replenishment transfers using deterministic external references; `--dry-run` reports proposals without creating documents. It is scheduled daily at `02:30` and is idempotent across repeated runs. Approval, dispatch, receipt, and ledger posting remain explicit downstream steps.

Planning consumers can run a read-only demand scenario with `GET /api/inventory/replenishment/scenario?horizon_days=30&demand_multiplier=1.25` or an explicit `daily_demand` override. The response compares baseline and scenario projected balances, includes open purchase and inter-location transfer receipts by expected date, and reports shortfalls and stockout status; it does not create or modify ERP documents.

Browser planners with `reports.view` can use `/planning/replenishment-scenario` for the same read-only scenario projections, including horizon, multiplier, daily-demand override, product, and location filters; the page renders every projected horizon bucket.

Migration authority: the head above is authoritative; any older migration numbers in historical notes below are informational only.

Carrier polling is available through the built-in `http` adapter when `ERP_CARRIER_TRACKING_HTTP_ENDPOINT` is configured. Use `{tracking_number}` in the endpoint URL, and optionally set `ERP_CARRIER_TRACKING_HTTP_TOKEN`, `ERP_CARRIER_TRACKING_HTTP_TIMEOUT`, `ERP_CARRIER_TRACKING_HTTP_RETRIES`, and `ERP_CARRIER_TRACKING_HTTP_RETRY_SLEEP`; authorized sales clients can then call `POST /api/integration/deliveries/{id}/delivery-tracking/sync` with `{"provider":"http"}`. Provider responses may be a single event, an `events` array, or a `data` array and are normalized into immutable delivery-tracking events with replay protection.

Migration `2026_09_19_000341` adds tenant-scoped delivery package and package-line records. Package contents can be created and listed through `GET/POST /api/integration/deliveries/{deliveryId}/packages`, with idempotent external references, quantity limits, serial capture, audit history, and packed/dispatched/delivered/cancelled lifecycle synchronization; run `php artisan migrate` before using the package endpoints.

Migration `2026_09_19_000342` adds company-scoped external references and synchronization indexes for purchase RFQs. Procurement integrations can use `GET/POST /api/integration/rfqs`, submit supplier quotations through `/api/integration/rfqs/{id}/quote`, and award a complete quotation into a submitted purchase order through `/api/integration/rfqs/{id}/award`.

Migration `2026_09_21_000375` adds expiring hashed supplier-portal invitation tokens. Purchasing clients can issue a one-time-visible invitation through `POST /api/integration/rfqs/{id}/suppliers/{supplierId}/portal-invitation` and revoke it through the matching `/revoke` action; the supplier can inspect its assigned RFQ through `GET /api/supplier-portal/rfqs/{token}`, submit its quotation through `POST /api/supplier-portal/rfqs/{token}/quote`, or decline with a required reason through `POST /api/supplier-portal/rfqs/{token}/decline`. Tokens are never stored in plaintext, are limited to the assigned supplier response, expire at the RFQ deadline or configured expiry, and preserve the normal internal award workflow.

Migration `2026_09_21_000376` adds customer quotation portal responses. Sales clients can issue or revoke a hashed, expiring invitation through `POST /api/integration/sales-quotations/{id}/customer-portal-invitation` and its `/revoke` action; customers can view the quotation, accept it, or decline it with a reason through the throttled customer-portal endpoints. Customer acceptance is recorded and audited but does not bypass internal quotation approval or conversion controls.

Migration `2026_09_19_000343` adds company-scoped external references and synchronization indexes for purchase requisitions. Procurement integrations can use `GET/POST /api/integration/requisitions`, approve or reject through `/approve` and `/reject`, and convert an approved requisition into a submitted purchase order through `/convert`.

Migration `2026_09_19_000344` links RFQs to approved purchase requisitions. RFQ integrations may send `purchase_requisition_id`; the tenant and approved-status checks are enforced and the linked requisition is included in the RFQ feed.

Migration `2026_09_19_000345` adds company-scoped external references and synchronization indexes for sales quotations. Sales integrations can use `GET/POST /api/integration/sales-quotations`, approve or reject through `/approve` and `/reject`, and convert approved quotations into submitted sales orders through `/convert`.

Migration `2026_09_19_000347` adds optional parent-company and consolidation-currency metadata. Parent companies can read `GET /api/accounting/consolidated-trial-balance` and `GET /api/accounting/consolidated-financial-statements` with `accounting:read` to combine active subsidiary posted journals by account code, translate company base currencies as of the report end date, and retain company-level contribution detail. Elimination metadata identifies whether traceable manual elimination journals are present.

Migration `2026_09_19_000348` adds traceable consolidation-elimination metadata to journal entries. Parent companies with active subsidiaries can create balanced, idempotent manual elimination journals through `POST /api/accounting/consolidation-eliminations` with `accounting:write`; consolidated reports identify when these manual journals are included. Automatic intercompany matching remains separate.

Migration `2026_09_19_000349` adds explicit intercompany references and counterparty-company metadata to journal entries. Consolidated reports automatically omit paired entries only when the reference spans at least two companies, counterparties are declared, and translated balances net to zero by account code; asymmetric groups remain visible for manual review.

Migration `2026_09_19_000350` adds persisted tenant-scoped product import jobs. Integration clients can submit CSV/TXT/XLSX files to `POST /api/inventory/product-import-jobs` with `inventory:write` or `integration:write`, inspect jobs through `GET /api/inventory/product-import-jobs` and `/{id}`, and receive queued status/error metadata. An existing company-scoped `external_reference` is replay-safe and returns the original job without storing a duplicate upload. The hourly scheduler runs `erp:products:process-import-jobs`; use `--limit=N` for controlled batches. Dry-run jobs validate without changing products, and committed jobs retain product and batch audit history.

Migration `2026_09_19_000351` adds bounded retry metadata to product import jobs. Transient processor failures are rescheduled for up to the configured `max_attempts` (default 3); validation failures remain terminal. Authorized clients can request a retry with `POST /api/inventory/product-import-jobs/{id}/retry`, or cancel an unstarted pending job with `POST /api/inventory/product-import-jobs/{id}/cancel`; cancellation removes the private upload and every lifecycle decision is audited.

The scheduler runs `erp:sales:expire-quotations` daily at 00:45. It marks overdue submitted or approved quotations as `expired`, records audit history, and accepts an optional `--company=ID` scope for controlled operations.

The scheduler runs `erp:procurement:close-overdue-rfqs` daily at 00:50. It closes submitted RFQs past their response due date, records audit history, and accepts an optional `--company=ID` scope.

Migration `2026_09_12_000255` adds optional quality inspection fields to inventory status transfers. Transfers marked for inspection remain pending until an independent inspector records pass/fail; approval is allowed only after a pass.

Migration `2026_09_12_000256` widens inventory status columns for MySQL compatibility and future dispositions, allowing status workflows such as blocked and release-to-available without restrictive enum failures.

Migration `2026_09_12_000257` adds optional approval escalation SLAs to company approval policies.

Migration `2026_09_12_000258` adds immutable inventory reconciliation snapshots. Run `php artisan erp:inventory:capture-snapshot --date=YYYY-MM-DD` after a controlled close or let the daily scheduler capture the current date; repeating a company/date is idempotent and never rewrites the original snapshot. Authorized users can review/capture snapshots from `/erp/accounting/reconciliation`, and inventory integrations can read them from `GET /api/inventory/reconciliation-snapshots` with status/as-of filters and cursor pagination.

Migration `2026_09_12_000259` links fiscal-year closure to the immutable inventory snapshot for that company and fiscal-year end date. Browser and accounting integration close actions capture or reuse the snapshot and persist its ID on the closed fiscal year before posting is blocked. Migration `2026_09_13_000260` adds company-scoped external references for idempotent landed-cost integration writes. Migration `2026_09_13_000261` adds immutable per-layer landed-cost adjustment history.

Testing note: the unit suite is database-independent. Feature tests use the configured MySQL connection and require a running test database; this environment currently has no `pdo_sqlite` extension, so do not expect the feature suite to fall back to SQLite. Start MySQL and configure a dedicated test database before running `php artisan test`.

API requests use per-Sanctum-token throttling (falling back to user/IP throttling for other requests). Set `ERP_API_RATE_LIMIT` in the environment to change the default 60 requests per minute.

Negative-stock policy supports `block` (default), `approval` (only independently approved documents may exceed available stock), and `allow`. Configure it from ERP System Settings; approval mode requires a posting reference with approved status, an approver, and a checker different from the creator. A company-authorized Store with `allow_negative_stock` enabled can explicitly allow negative stock for postings linked to that store when company policy is `block`; it does not bypass `approval` mode. Approval policies may also set `escalation_after_hours`; the scheduled `erp:approvals:escalate` command runs hourly, notifies users with the required permission about overdue pending steps, and suppresses duplicate alerts for the same document on the same day. Any audited `.rejected` transaction also sends its active creator a database notification with the rejection reason.

Password policy defaults to 12 characters with mixed case, numbers, and symbols. Configure `ERP_PASSWORD_MIN_LENGTH`, `ERP_PASSWORD_MIXED_CASE`, `ERP_PASSWORD_NUMBERS`, and `ERP_PASSWORD_SYMBOLS` as needed; existing passwords remain valid until changed or reset. Password changes also reject the current and ten most recent historical passwords.

Service asset valuation supports straight-line, declining-balance, and units-of-production methods. Set `depreciation_method` and the corresponding `depreciation_units_total` and `depreciation_units_used` values through the service integration asset API for usage-based equipment; depreciation posting remains idempotent per asset/date and uses the configured account mappings.

Production planning runs `erp:planning:generate-production-orders` daily at 02:15. It creates approval-pending draft production orders only for finished goods below target and nets existing open production quantities. Use `php artisan erp:planning:generate-production-orders --dry-run` to review proposals before creation. The opt-in `erp:planning:auto-release-production-orders` scheduler runs at 02:20; enable the company ERP setting `auto_release_production_orders` to release eligible draft orders through the normal component-availability and reservation transaction. Orders covered by an approval policy remain checker-controlled. Use `--dry-run`, `--horizon-days`, or `--force` for controlled operations.

External manufacturing planners can read the same tenant-scoped production suggestions through GET /api/manufacturing/production-suggestions with the manufacturing:read token ability; filter by bom_id or paginate with per_page and page.

Tax rates support nullable effective periods. Accounting integrations can create versioned rates with effective_from and effective_until, and can read the rate set applicable on a date with the as_of filter; overlapping periods for the same company and code are rejected.

Products may reference a company-scoped tax-rate record through tax_rate_id while retaining the legacy numeric tax_rate field for older integrations and products.

Browser and API sales and purchase invoices now resolve a linked product tax rate for the invoice date, including effective-period and active-state checks; an explicitly supplied purchase-line tax rate remains an intentional override, and products without a valid linked rate continue using the legacy numeric value.

Migration 2026_09_12_000220 adds billing and shipping address snapshots to sales invoices. API-created invoices may provide explicit addresses; otherwise the customer master address is copied when the invoice is created.

Migration 2026_09_12_000221 adds structured supplier contacts with retry-safe external references and tenant-safe integration synchronization.

Migration 2026_09_12_000222 scopes supplier-contact external references to each supplier, matching the integration retry contract.
Migration 2026_09_12_000223 adds immutable movement-level batch/lot/serial allocations. The inventory movement API and traceability report include allocations when an issue consumes multiple cost layers, including serial IDs for serial-tracked movements; legacy movement-level batch and serial fields remain supported.
Migration 2026_09_12_000224 adds optional batch assignment to stock reservations. Sales-order and production-component reservations for batch/lot-tracked products allocate available cost-layer stock in FEFO order, while legacy or unallocated balances remain compatible.
Migration 2026_09_12_000225 adds optional batch assignment to inventory return lines, allowing browser and integration return approvals and feeds to expose batch-aware movements while omitted batches retain automatic costing-layer allocation.
Migration 2026_09_12_000226 adds immutable transfer-line batch/serial allocations with received quantities, preserving batch lineage through warehouse dispatch, partial receipt, and integration feeds.
Migration 2026_09_12_000227 adds company-scoped external references for chart-of-accounts synchronization. Accounting integrations can create, update, and deactivate tenant accounts through `/api/accounting/accounts` with idempotent external references.
Migration 2026_09_12_000228 adds location, batch, and serial-history fields to maintenance-part consumption records. Browser and service-integration maintenance usage now posts location-aware, batch-aware, and serial-linked inventory movements.
Migration 2026_09_12_000229 adds controlled maintenance-part return records and returned-quantity tracking. Unused service parts can be returned with quantity limits, batch/location preservation, serial restoration, audit history, and correcting ledger movements. Best-before policy is configurable in ERP system settings and is enforced centrally for outbound inventory movements.
Migration 2026_09_12_000230 adds optional company-scoped department allocation to independent inventory documents and their immutable ledger movements.
Migration 2026_09_12_000231 adds optional company-scoped cost-center allocation to independent inventory documents and their immutable ledger movements.
Migration 2026_09_12_000232 adds company-scoped external references to independent inventory documents, enabling idempotent integration creation of approval-pending receipts and issues.
Migration 2026_09_12_000233 adds optional line-level department and cost-center allocation to inventory documents, with document-level values retained as fallback dimensions during ledger posting.
Migration 2026_09_12_000234 adds explicit version metadata to bills of material; production orders retain the selected BOM record, while effective-date selection remains backward compatible.
Migration 2026_09_12_000235 snapshots the selected BOM version on production orders for manufacturing traceability.
Migration 2026_09_12_000236 adds company-scoped external references to BOMs for idempotent manufacturing integration synchronization.
Migration 2026_09_12_000237 stores cumulative production-order material, operation, by-product, and net production-cost breakdowns across partial receipts.
Migration 2026_09_12_000238 stores an immutable effective BOM tree snapshot on production orders; release and completion use it when present, with legacy live-BOM fallback.
Manufacturing integrations can update BOM metadata and nested lines with `PATCH /api/manufacturing/boms/{id}` and deactivate safe revisions with `POST /api/manufacturing/boms/{id}/deactivate`; open production orders prevent deactivation.
The BOM feed accepts `version` and `as_of` filters so external planners can select an effective revision deterministically.
Manufacturing cost summaries are available through `GET /api/manufacturing/costs`, grouped by finished product with planned/completed quantity and material, operation, by-product, and net production cost totals.
Work-center utilization is available through `GET /api/manufacturing/work-center-utilization`, with date filters and setup/run hours, completed quantity, operation count, and labor/machine cost totals.
Open-work-in-progress is available through `GET /api/manufacturing/wip`, with planned-date filters, order/product/BOM/location context, completion percentage, remaining quantity, and accumulated production cost.
Active BOM revisions for the same finished product cannot have overlapping effective date ranges; leave a revision open-ended only when it is the sole active revision for that product.
Inventory-document integrations can create records with `POST /api/inventory/documents`, inspect pending receipts with `POST /api/inventory/documents/{id}/inspect`, and approve or reject them with `POST /api/inventory/documents/{id}/approve` or `/reject`; all require `inventory:write` or `integration:write` and preserve maker/checker, inspection, batch/serial, ledger, accounting, and audit controls.
Migration 2026_09_12_000224 adds optional batch assignment to stock reservations. Sales-order reservations for batch/lot-tracked products allocate available cost-layer stock in FEFO order, while legacy or unallocated balances remain compatible.

Warehouse location zoning rules are available through `POST /api/integration/organization/location-rules` and can allow or deny a product or category at a location; rules inherit through parent locations, and no rules preserves unrestricted behavior. Deactivate rules with `POST /api/integration/organization/location-rules/{id}/deactivate`.

Recovery note: if MySQL is stopped, run `sudo systemctl restart mysql` and confirm it with `mysqladmin ping` before running migrations or database-backed tests. The command may prompt for the server administrator password.

Warehouse locations use the hierarchy `zone → rack → shelf → bin`; browser-created child locations must use the immediately preceding level as their parent, and company users cannot attach locations or warehouses to another company’s branch.

Product dimensions use kilograms and metres (`weight_kg`, `length_m`, `width_m`, `height_m`). Locations may define quantity capacity plus optional `capacity_weight_kg` and `capacity_volume_m3`; inbound ledger postings enforce each configured limit, while records without dimensions remain compatible with quantity-only capacity.

Demand forecasting supports an optional tenant-authorized `location_id` on the browser report and `GET /api/inventory/forecast`; location forecasts use outbound ledger issues and location availability, while requests without a location retain approved invoice-history behavior. Apply migration `2026_09_11_000207` before saving location-specific forecast overrides.

## 1. Application requirements

The versions declared by the project are:

| Requirement | Version / value |
| --- | --- |
| PHP | `^8.0.2` or newer compatible PHP 8.x |
| Composer | Current Composer 2 recommended |
| Database | MySQL, configured through Laravel |
| Node.js/npm | Required to compile front-end assets |
| Framework | Laravel `^9.2` |
| Web root | The `public/` directory |

PHP should have the extensions required by Laravel and the installed Composer packages, including PDO/MySQL, OpenSSL, Mbstring, Tokenizer, XML, XMLReader, Zip, Ctype, JSON, Fileinfo, and GD or the image driver required by Intervention Image. XML and Zip are also required for native product XLSX import/export. QR label generation uses the `qrencode` executable; set `ERP_QR_CODE_BINARY` if it is installed at a non-standard path.

## 2. Local development setup

### Clone and install PHP dependencies

From the project directory:

```bash
composer install
```

For a production-style dependency install, use:

```bash
composer install --no-dev --optimize-autoloader
```

### Create the environment file

```bash
cp .env.example .env
php artisan key:generate
```

Do not commit `.env`. Set at least these values:

```dotenv
APP_NAME="Inventory Management System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_management
DB_USERNAME=your_mysql_user
DB_PASSWORD=your_mysql_password
```

Create the configured MySQL database and grant the configured user access before migrating.

### Install and compile front-end assets

```bash
npm install
npm run development
```

For a minified build:

```bash
npm run production
```

### Initialize the database

```bash
php artisan migrate
```

### Run and restart application processes

For local development, start the HTTP server with `php artisan serve`. Stop it with `Ctrl+C`, then run the same command again to restart it. Run the scheduler and queue worker in separate terminals with `php artisan schedule:work` and `php artisan queue:work`; stop and rerun either command to restart that process. After deploying changed application code, run `php artisan queue:restart` and allow the worker to exit gracefully before starting it again. On production, restart the PHP-FPM service matching the installed PHP version (for example, `sudo systemctl restart php8.1-fpm`) and reload the web server only when its configuration changed. `pws` is not a project command; use `pwd` to print the current directory.

### Configure the isolated test database

Copy `.env.testing.example` to `.env.testing`, create the dedicated MySQL database and test user, then run `php artisan config:clear` and `php artisan key:generate --env=testing` followed by `vendor/bin/phpunit`. If configuration was cached after changing environment files, clear it again before database-backed commands; otherwise Laravel can continue using the default database connection. For a disposable local database, use `docker compose -f docker-compose.testing.yml up -d` first; it exposes MySQL on port 3307 with the credentials in the template. Feature tests use Laravel’s refresh-database behavior and must never use the production database. The lightweight unit suite can run without a database with `vendor/bin/phpunit tests/Unit`. 

Migration `2026_09_11_000160` adds customer-group and sales-channel price scopes with customer-first pricing fallback. Migration `2026_09_11_000161` adds tenant-scoped webhook subscriptions and a durable signed delivery outbox. Migrations `2026_09_11_000162` through `2026_09_11_000167` add approval delegation, audit integrity hashes, recurring journals, stock recount controls, acknowledged integration cursors, and named price lists. Existing customer-specific price agreements remain valid. Always back up the database before applying migrations.

Migration `2026_09_12_000215` adds the customer overdue-credit policy. Customers can be configured with a maximum overdue age that blocks new sales-order approval; the credit-control screen reports outstanding balance, overdue balance, age, and collection status, and the same policy is enforced by sales integrations. The scheduled `erp:receivables:overdue-alerts` command sends one repeat-safe database reminder per customer and user per day; use `--min-days` and `--company` to narrow it. Accounting integrations can query `GET /api/accounting/customers/{id}/credit-assessment?order_value=...` for the same customer-level approval decision.
Migration `2026_09_12_000216` adds category-level required variant attributes. Configure them through the category create/edit screens or `PATCH /api/inventory/categories/{id}/attribute-requirements`; variant creation then requires one active, tenant-authorized value for every configured attribute. Product edit screens also support private media/document upload and download. The organization screen supports tenant-safe edit/deactivation of companies, departments, branches, warehouses, stores, and storage locations while preserving active-child, assigned-user, warehouse-hierarchy, sales-order, and capacity checks.

The ERP migration set includes the original `000001`–`000192` increment described in the historical release notes, plus subsequent migrations through the authoritative current head `2026_09_19_000345`. The complete sequence adds manufacturing routings/work centers, by-products, warranty claims, asset spare-part catalogs, accounting integration references, blocked inventory status, retention/legal-hold controls, transaction currency context, tax modes, order currency propagation, receipt/delivery UOM context, normalized product barcodes, payment currency context, persistent user-session governance, inventory API idempotency, encrypted TOTP MFA fields, supplier commercial fields, customer master fields, company scope for core masters and organization records, core transaction documents, inventory operations, reservations, status balances, payments, company-scoped brands and categories, company-scoped private document attachments, tenant-scoped audit/history/activity records, configurable approval policies, independent stock receipts/issues, validated multi-batch issue allocations, batch/serial tracking for those documents, warehouse transfer lifecycle timestamps, partial transfer receiving quantities/notes, optional warehouse allocation for stock reservations, optional fulfillment/production locations on orders, optional batch/serial locations, exact serial allocation through warehouse transfers, company-scoped ERP system settings, customer-linked sales invoices, customer payment allocation records, company-scoped idempotent external payment references, idempotent allocation references, auditable journal reversal links, supplier payment allocation records, fiscal-year close metadata, reconciliation review snapshots, bank reconciliation tables, tracked opening-stock adjustment fields, product media metadata, company-scoped stock count freeze controls, production output tracking fields, payment reversal metadata, audited maintenance execution, tenant scoping for service/manufacturing/tax/commercial masters, transfer carrier/tracking/expected-arrival fields, voidable payment allocations, quantity-confirmed delivery picking, company-scoped UOM codes/precision metadata, promotion usage limits/redemption counters, purchase-return source goods-receipt matching, customer-group/sales-channel pricing scopes, recurring journal schedules, independent count/recount controls, multi-counter stock-count assignments and completion accountability, integration cursor acknowledgements, reusable sales/purchase named price lists, idempotent inventory status-transfer integration references, organization-master integration references, replenishment-policy integration references, idempotent product-barcode references, product lifecycle/purchase/sales eligibility controls, company-scoped audit-log ownership, company-scoped tax-rate codes, company-scoped inventory-adjustment external references, physical location capacity, promotion traceability, location zoning rules, and maintenance-part inventory traceability, BOM versioning, production-order BOM-version snapshots, and idempotent BOM integration synchronization, persistent production-cost breakdowns, immutable production-order BOM tree snapshots, and provider-neutral e-invoice submission envelopes with payload hashes and idempotent records. Supplier price selection is shared by purchase-order and MRP workflows and matches active agreements by supplier, product, quantity, validity dates, and transaction currency, with assigned named lists as a fallback. Opening stock is available through the authenticated `/inventory/opening-stock/add` workflow. Always take a database backup before applying migrations.
Important: the long historical migration paragraph immediately above ends at `2026_09_12_000214` for historical context only. Do not use that historical number as the deployment head.
Migration `2026_09_12_000217` adds persisted sales-invoice due dates. The sales invoice form accepts an optional due date; when omitted, it is derived from the customer’s credit terms. Migration `2026_09_12_000218` adds tenant-scoped external references for idempotent sales-invoice integration writes. Migration `2026_09_12_000219` adds retry-safe external references to customer contacts. The integration API supports POST `/api/integration/sales-invoices`, approval, and rejection, plus customer contact synchronization.

The migration summary above predates migrations `2026_09_12_000215` through `2026_09_12_000219`; those historical migrations add overdue-credit policy, receivables reminders, category-level variant attribute requirements, persisted sales-invoice due dates, idempotent sales-invoice integration references, and retry-safe customer-contact references. The deployment head is documented at the top of this file.

The detailed historical migration summary above ends at the earlier `000206` increment; later migrations through `2026_09_12_000216` are authoritative for the current deployment.

Inventory status synchronization is available through authenticated inventory API endpoints: `GET /api/inventory/status-balances`, `GET/POST /api/inventory/status-transfers`, and `POST /api/inventory/status-transfers/{id}/approve|reject|inspect`. These endpoints support quarantine, damaged, blocked, scrap, optional quality inspection, and release-to-available workflows, external-reference idempotency, tenant-safe location/product validation, audit history, and cursor-based synchronization.

Write-side integration routes use Sanctum “any ability” guards (for example `inventory:write`, `sales:write`, `purchasing:write`, or `accounting:write`, with `integration:write` accepted for service integrations) and retain controller-level document and tenant checks.

The older migration references in this section are historical release notes. Use the complete migration sequence through the current head `2026_09_18_000332` as the authoritative latest increment. Approval governance is available through `/api/integration/security/sod/simulate`, `/api/integration/security/sod/exceptions`, company-scoped role-conflict administration, approval-override, and retention-purge request endpoints; exceptions are reasoned, independently decided, audited, and consumed once where applicable. Optional company fiscal periods are available through the accounting integration API and enforce open/closed posting dates when configured. Cost-center budget synchronization is available through `GET/POST /api/accounting/cost-centers/budgets` with external-reference idempotency and `POST /api/accounting/cost-centers/budgets/{id}/deactivate`; budget-versus-actual reporting is available through `GET /api/accounting/cost-centers/budget-vs-actual` with period, cost-center, and alert-threshold filters; the scheduled `erp:accounting:budget-alerts` command runs daily at 07:00 and suppresses duplicate alerts for the same budget on the same day. Retention purge requests can be synchronized and independently decided through `/api/integration/security/retention/purge-requests`.
The historical note above predates the current tail of the migration sequence. The authoritative deployment head is `2026_09_19_000346`.

The historical migration block `2026_09_11_000193` through `2026_09_11_000206` covers location-aware returns, company-scoped identifiers and service integration references. Later migrations add physical capacity, sales-order promotion traceability, and location zoning through the current head. Planning policy feeds are available at `/api/inventory/replenishment-policies`; receivables and payables aging feeds are available at `/api/accounting/customer-aging` and `/api/accounting/supplier-aging`. Tenant-scoped AP/AR/inventory reconciliation is available at `GET /api/accounting/reconciliation?to=YYYY-MM-DD`; COGS reconciliation is available at `GET /api/accounting/cogs-reconciliation?from=YYYY-MM-DD&to=YYYY-MM-DD` with optional `product_id`, `location_id`, and `tolerance` filters. Back up the database before running `php artisan migrate`.

Accounting integrations can also read `GET /api/accounting/sales-reconciliation?from=YYYY-MM-DD&to=YYYY-MM-DD` with optional `product_id`, `customer_id`, and `tolerance` filters. The feed allocates each approved invoice's stored header discount proportionally across its product lines and compares net revenue with the mapped posted `sales_revenue` journal balance.

The sales-performance feed reports line-level gross revenue from the legacy invoice-detail model. Legacy discounts are recorded at the payment/invoice level and are intentionally not allocated across product rows; use invoice totals for net settlement reporting until historical discount-allocation rules are defined.

The current reporting and integration API surface includes:

- `/api/integration/sales-performance`
- `/api/integration/supplier-performance`
- `/api/integration/warehouse/report`
- `/api/inventory/products/lookup?code=...`
- `/api/accounting/customer-aging`
- `/api/accounting/supplier-aging`
- `/api/accounting/bank-reconciliation/lines/{id}/suggestions`
- `/api/inventory/replenishment-policies`
- `/api/inventory/planning-exceptions?type=dead&days=180`
- `/api/integration/security/audit-logs`

Warehouse reports accept optional `from` and `to` dates to include period inbound, outbound, and net movement totals alongside current quantity occupancy/capacity and configured weight/volume occupancy, availability, and utilization values. Put-away task creation applies quantity, weight, and volume capacity checks consistently with ledger posting.

Bank statement reconciliation periods are available through `GET/POST /api/accounting/bank-reconciliation/statements` and the close/reopen endpoints. A statement stores opening and closing balances, recalculates book balance and variance from imported lines, blocks closure when unmatched lines remain or variance exceeds tolerance, and requires a reason to reopen a closed period.

Product integration writes generate an SKU from the company `product` numbering sequence when `sku` is omitted; set `auto_sku=false` to preserve a deliberately blank SKU. Retrieve tenant-authorized product attachment metadata with `GET /api/inventory/products/{id}/media` using `inventory:read`; private file bytes remain behind authenticated attachment routes.

Product classification synchronization is available through `GET/POST/PATCH /api/inventory/product-classifications` and its deactivation endpoint. Classification schemes, codes, jurisdictions, descriptions, active state, and external references are company-scoped; products may retain the legacy `hsn_sac_code` while also linking a managed classification record.

Migration `2026_09_11_000189` adds idempotent external references to organization masters. Integration clients with `integration:write` can create tenant-authorized branches, warehouses, stores, departments, and warehouse locations through the corresponding `POST /api/integration/organization/...` endpoints.

Migration `2026_09_11_000108` adds ordered multi-step approval policies and persisted approval actions. Migration `2026_09_11_000109` adds database notifications. Users can review alerts at `/erp/notifications`. The scheduler prunes revoked and stale session registry records daily, generates purchase-order drafts from replenishment shortages at 02:00, sends repeat-safe inventory expiry alerts at 06:00, low-stock alerts at 06:15, customer overdue alerts at 06:30, supplier overdue alerts at 06:45, and delivers signed webhook outbox records every five minutes. Run `php artisan erp:system:health --strict` during deployment to verify database connectivity, migration-table availability, and private storage writability. Preview replenishment without writes using `php artisan erp:planning:generate-purchase-orders --dry-run`; generated orders remain drafts until normal approval. Ensure `php artisan schedule:work` or the production scheduler is running.

Migration `2026_09_11_000110` adds customer refund records and settlement journals. Purchase price variance tolerance is configured in ERP System Settings and defaults to disabled (`0`). Closed fiscal periods now block accounting journal posting and central inventory movement posting; reopen periods only through controlled accounting procedures. The current `DatabaseSeeder` does not create a default user or business records. Register the first user through `/register`, or add a project-specific seeder before deployment.

Review purchase price variance exceptions at `/procurement/invoices/price-variance` after configuring the tolerance. Migration `2026_09_11_000111` adds refund exchange-rate and base-currency amounts. Migration `2026_09_11_000112` adds branch/category approval-policy routing. Migration `2026_09_11_000113` adds company scope to commercial documents. Migrations `2026_09_11_000114` and `000115` add company-scoped cost centers and journal-line cost-center dimensions. Migration `2026_09_11_000116` adds company-scoped cost-center budgets with optional fiscal-year validation. Review posted activity at `/erp/accounting/cost-centers/report` and maintain budgets at `/erp/accounting/cost-centers/budgets`. To load the included example records instead, run:

```bash
php artisan db:seed
```

Migration `2026_09_11_000117` converts legacy floating-point quantities and monetary fields to fixed-precision decimal columns. Migration `2026_09_11_000118` adds optional batch and serial capture to legacy POS invoice lines. Migration `2026_09_11_000119` adds store warehouse assignment and POS defaults. Migrations `2026_09_11_000120` and `000121` add batch/serial fields to deliveries and store context to sales orders/invoices. Opening stock can be imported with `php artisan erp:import-opening-stock path/to/opening-stock.csv COMPANY_ID --dry-run`; a successful non-dry run creates a pending adjustment that must be approved. Back up the database before applying migrations.
Migration `2026_09_11_000122` adds formal accepted/waived transfer-variance disposition with reason, resolver, and timestamp audit fields. Opening stock can be imported with `php artisan erp:import-opening-stock path/to/opening-stock.csv COMPANY_ID --dry-run`; a successful non-dry run creates a pending adjustment that must be approved. Back up the database before applying migrations.

Migrations 2026_09_11_000123 through 000132 add audited maintenance-order execution fields, lifecycle actions, company scope for service records, manufacturing BOM/routing/work-center masters, tax rates, commercial pricing/promotions, warehouse-transfer shipping fields, safe delivery cancellation/reversal fields including issued-serial history, company-scoped demand-forecast overrides, and purchase-order cancellation audit fields. The approved-document tax report is available at /erp/accounting/tax-report and summarizes sales output tax, purchase input tax, and net tax by rate for a selected period.
Expired-batch outbound issue and costing consumption are blocked centrally by default; authorized administrators can manage the override at /erp/settings.
The sales analysis report is available at /reports/sales with period, product, and customer filters. Sales orders can apply a valid promotion code across eligible lines; the promotion is persisted and its usage is redeemed only on order approval. Sales orders can explicitly allow backorders; those orders reserve available stock and are automatically allocated newly available stock by the hourly \`erp:inventory:allocate-backorders\` scheduler command. Goods receipts and deliveries can be rejected with a reason before stock posting. Foreign-currency open-balance revaluation is available at /erp/accounting/fx-revaluation. Configure \`fx_gain\` and \`fx_loss\` account mappings before using the controlled, idempotent gain/loss journal posting action.

The example login is:

```text
Email:    demo.admin@example.com
Username: demo_admin
Password: Demo@12345
```

The seeder creates approved and pending purchase/invoice examples, linked master data, payment examples, and stock quantities suitable for demonstrating the application. Use this only for development/demo environments; do not use the example password in production.

For a high-volume dataset, run the separate bulk seeder:

```bash
php artisan db:seed --class=LargeExampleDataSeeder
```

It creates 1,000 records for each major module, including 1,000 suppliers, customers, units, categories, products, purchases, invoices, invoice details, payments, and payment-detail history rows. It creates both approved and pending transactions. It is repeatable and removes only records created by this seeder, identified by `BULK-*` and `Bulk Demo *` markers.

### Prepare upload directories

The application writes images directly into these public directories:

```text
public/upload/customer
public/upload/admin_images
```

Make sure both directories exist and are writable by the PHP process.

### Start the local server

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000`, register, verify or sign in as configured, and use the dashboard.

## 3. Local verification checklist

Run these checks after setup:

```bash
php artisan about
php artisan migrate:status
php artisan route:list
php artisan test
```

The feature tests require a reachable MySQL instance matching the `.env` connection. If MySQL is unavailable, Laravel feature tests will fail before application assertions run; install/enable PDO MySQL and start the configured database before treating those failures as application defects. The unit suite can still be run independently with `vendor/bin/phpunit tests/Unit`.

### Accounting integration API

The Sanctum-protected integration endpoints are:

```text
GET  /api/accounting/accounts
GET  /api/accounting/journals
POST /api/accounting/journals
POST /api/accounting/journals/{id}/reverse
GET  /api/accounting/customer-payments
GET  /api/accounting/supplier-payments
GET  /api/accounting/customer-refunds
GET  /api/accounting/tax-report?from=YYYY-MM-DD&to=YYYY-MM-DD
GET  /api/accounting/supplier-payments/{id}
POST /api/accounting/supplier-payments/{id}/allocations
POST /api/accounting/customer-payments/{id}/reverse
POST /api/accounting/supplier-payments/{id}/reverse
GET  /api/accounting/bank-reconciliation/lines
POST /api/accounting/bank-reconciliation/lines
POST /api/accounting/bank-reconciliation/lines/bulk
GET  /api/accounting/bank-reconciliation/import-batches
POST /api/accounting/bank-reconciliation/lines/{id}/match
PATCH /api/accounting/bank-reconciliation/lines/{id}/status
GET  /api/inventory/valuation
GET  /api/inventory/counts
GET  /api/inventory/reservations
PATCH /api/inventory/reservations/{id}/location
POST /api/inventory/reservations/{id}/release
```

External journal imports should provide an `external_reference`; repeated submissions with the same value return the existing journal instead of creating a duplicate. Use an API token with the least privilege required and keep provider credentials outside source control.

Administrators can create and revoke scoped integration tokens at `/erp/security/api-tokens`. Grant `accounting:read` for synchronization reads and `accounting:write` only for journal imports. Tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes by default (30 days); reduce this value for higher-security environments.

Inventory integrations use `inventory:read` for `GET /api/inventory/products`, `GET /api/inventory/stock`, `GET /api/inventory/valuation`, `GET /api/inventory/counts`, `GET /api/inventory/documents`, `GET /api/inventory/movements`, `GET /api/inventory/transfers`, and `GET /api/inventory/adjustments`, and `inventory:write` for `POST /api/inventory/adjustments`, `POST /api/inventory/adjustments/{id}/approve`, and `POST /api/inventory/adjustments/{id}/reject`. Warehouse transfer integrations also support `POST /api/warehouse/transfers/{id}/resolve-variance` with `resolution` (`accepted` or `waived`) and a required `variance_reason`. The adjustment feed supports status, reason-code, `updated_since`, and signed cursor filters and includes line product/location data and approval actors. The product feed supports lifecycle status, product type, purchase/sales eligibility, stock-managed state, search, and `updated_since` filters. The valuation feed supports product/category/location/batch and as-of-date filters, returns cost-layer quantity/value, and includes changed cost layers in `updated_since` synchronization. The count feed exposes physical-count status, recount requirement, location, line quantities, variance, and count actors. The movement feed includes batch/lot, serial, location, actor, and source-reference data and supports batch/serial filters. The transfer feed exposes lifecycle, locations, quantities, serial allocations, shipping, receipt, and variance state. Map the company-specific `inventory_in_transit` and `inventory_loss` accounts before posting transfer accounting; dispatch then credits inventory/debits transit, receipt debits inventory/credits transit, and resolved shortages debit loss/credit transit. API-created adjustments remain pending until an authorized checker approves or rejects them; rejection requires `rejection_reason`. Send a stable `external_reference` to make retries idempotent. Valuation, count, document, movement, transfer, and adjustment feeds support deterministic cursor mode.

Transactional feeds use `sales:read` for `GET /api/integration/sales-orders`, `/api/integration/deliveries`, `/api/integration/sales-invoices`, and `/api/integration/sales-returns`, and `purchasing:read` for `GET /api/integration/purchase-orders`, `/api/integration/goods-receipts`, `/api/integration/purchase-invoices`, and `/api/integration/purchase-returns`; `sales:write` also permits idempotent `POST /api/integration/sales-orders`, approval through `/api/integration/sales-orders/{id}/approve`, cancellation through `/api/integration/sales-orders/{id}/cancel`, pending delivery creation through `POST /api/integration/deliveries`, delivery approval, and delivered confirmation through `/api/integration/deliveries/{id}/delivered`, creating approval-pending orders/deliveries with tenant-validated customer/store/location/product references, UOM normalization, currency, customer-price fallback, credit controls, quantity checks, and safe reservation release. `purchasing:write` permits idempotent `POST /api/integration/purchase-orders`, approval through `/api/integration/purchase-orders/{id}/approve`, receipt-aware cancellation through `/api/integration/purchase-orders/{id}/cancel`, and inbound goods-receipt creation through `POST /api/integration/goods-receipts`; receipts validate approved PO lines and remaining quantities, normalize UOM/cost, capture batch/serial/date metadata, and optionally enter the existing quality-inspection gate before approval posts stock. Stock issuance remains behind delivery approval, and delivered confirmation requires dispatch. These feeds support status, updated-since, inspection-status where applicable, and bounded per-page filters. Manufacturing integrations use `manufacturing:read` for `GET /api/manufacturing/orders`, `GET /api/manufacturing/boms`, and `GET /api/manufacturing/operations`, and `manufacturing:write` for idempotent `POST /api/manufacturing/orders`, approval-protected release, partial/full completion, and cancel actions, plus controlled operation start/complete actions. Feeds expose production status, quantities, location, operations, work centers, components, by-products, and independent shop-floor operation changes. Pricing integrations use the corresponding read ability for supplier/customer agreements, while `integration:read` exposes `GET /api/integration/price-lists` with sales/purchase list filters. All supported feeds accept opt-in `cursor_mode=1` and return signed cursors for acknowledgement.

### Return integrations

The integration API accepts `POST /api/integration/returns`, `/returns/{id}/approve`, and `/returns/{id}/reject`. The return type determines authorization: sales returns require `sales:write`, purchase returns require `purchasing:write`, or a token may use `integration:write`. Creation is idempotent by external reference; approval validates source invoice/receipt quantities, serialized stock lifecycle, availability for supplier returns, inventory movements, accounting reversal, and audit history.

### Retention processing

Procurement integrations also support `POST /api/integration/purchase-invoices` and `/purchase-invoices/{id}/approve` with `purchasing:write`. Invoice creation is idempotent by external reference, validates the tenant purchase order, calculates tax/currency totals, and remains pending until maker/checker approval; approval enforces received-quantity and purchase-price variance controls before posting the AP journal.

Run `php artisan erp:retention:process` to archive eligible audit/revision records while honoring active legal holds. The command is non-destructive by default. A source purge requires `purge_enabled`, an independently approved company purge request, and both `--purge` and `--purge-request=ID`:

```bash
php artisan erp:retention:process --purge --purge-request=REQUEST_ID
```

Then verify manually:

1. Register and log in.
2. Create a supplier, unit, category, and product.
3. Create and approve a purchase; confirm product quantity increases.
4. Create an invoice; confirm it appears in the pending list.
5. Approve the invoice with sufficient stock; confirm product quantity decreases.
6. Test full-due or partial payment and confirm the credit/paid lists.
7. Open stock, invoice, purchase, and customer reports.

### Dotenv formatting

Values containing spaces must be quoted in `.env`, for example:

```dotenv
APP_NAME="Inventory Management System"
```

An unquoted value such as `APP_NAME=Inventory Management System` prevents Laravel from booting with an “unexpected whitespace” dotenv error.

## 4. Production server layout

Deploy the repository outside the publicly served directory. The web server should expose only the Laravel `public/` directory.

Example:

```text
/var/www/inventory-management-system/       application root
/var/www/inventory-management-system/public web-server document root
```

Never point Apache or Nginx at the repository root, because `.env`, source code, and private files must not be web-accessible.

## 5. Production environment configuration

Use a production `.env` with values similar to:

```dotenv
APP_NAME="Inventory Management System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://inventory.example.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_management
DB_USERNAME=inventory_app
DB_PASSWORD=strong-secret

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
SANCTUM_TOKEN_EXPIRATION=43200
```

Set a real application key with `php artisan key:generate` before first use. Do not change `APP_KEY` on an existing deployment unless you understand that encrypted sessions and data may become invalid.

Configure mail settings if password resets or verification emails are required. The example environment uses MailHog and is not suitable for production mail delivery.

For a local environment without MailHog, use the log mailer:

```dotenv
MAIL_MAILER=log
```

Messages will be written to `storage/logs/laravel.log` instead of being sent. If MailHog is running through Docker, keep `MAIL_MAILER=smtp`, set `MAIL_HOST` to the reachable MailHog service name, and expose port `1025`.

For Gmail SMTP, port `465` maps to implicit SSL, so Laravel uses:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=your-gmail-address
MAIL_PASSWORD=your-gmail-app-password
MAIL_FROM_ADDRESS=your-gmail-address
```

Use a Gmail App Password rather than a normal Gmail account password. Keep SMTP credentials out of source control and rotate the supplied credential if it has been exposed in chat, logs, screenshots, or a committed `.env` file.

## 6. Production deployment procedure

Run the following from the application root during a maintenance window or controlled release:

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run production
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If the deployment uses release directories, build and migrate in the new release, switch the web-server symlink, then restart PHP-FPM. Keep the previous release available for rollback.

## 7. Nginx example

Replace the domain, PHP-FPM socket, and paths for the target server:

```nginx
server {
    listen 80;
    server_name inventory.example.com;

    root /var/www/inventory-management-system/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable HTTPS with the organization’s certificate and redirect HTTP to HTTPS before exposing the application.

## 8. Apache example

Enable the rewrite and PHP modules, then use a virtual host whose `DocumentRoot` points to `public/`:

```apache
<VirtualHost *:80>
    ServerName inventory.example.com
    DocumentRoot /var/www/inventory-management-system/public

    <Directory /var/www/inventory-management-system/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The repository includes Laravel's `public/.htaccess` behavior through the standard Laravel public entry point. Confirm `mod_rewrite` is enabled.

## 9. Permissions and ownership

The deployment user should own the application files, while the web-server/PHP user needs write access to Laravel runtime directories and upload directories. Typical writable locations are:

```text
storage/
bootstrap/cache/
public/upload/customer/
public/upload/admin_images/
```

Avoid making the entire repository world-writable. Use the least-privilege user/group arrangement provided by the server.

## 10. Database and file backups

Back up both database and uploaded files:

```bash
mysqldump -u inventory_app -p inventory_management > inventory-$(date +%F).sql
tar -czf inventory-uploads-$(date +%F).tar.gz public/upload
```

Store backups outside the web root, encrypt them where required, and periodically test restoration. A database backup alone does not restore customer/admin images.

## 11. Health checks after deployment

- Open the landing page and `/login` over HTTPS.
- Register or authenticate a test account according to the release policy.
- Confirm the database-backed dashboard loads.
- Test AJAX category/product lookup requests in the browser.
- Upload a test customer or profile image if permitted.
- Create a controlled purchase and invoice test record, then remove it according to the data-retention policy.
- Check Laravel logs in `storage/logs` and the PHP-FPM/web-server logs.

The current migration sequence continues through `2026_09_18_000336`; run the normal `php artisan migrate` command so the reservation-expiry column and all preceding ERP tables are installed. Configure `reservation_expiry_days` through `/erp/settings` or `PATCH /api/accounting/settings`; zero preserves indefinite reservations for backward compatibility. The scheduler runs `php artisan erp:inventory:expire-reservations` hourly and releases expired active reservations with an audit event; use `--company=ID` for a tenant-specific run or `--before="YYYY-MM-DD HH:MM:SS"` for controlled replay. Webhook integration is included in migration `2026_09_11_000161`. Approval delegation is included in migration `2026_09_11_000162`. Audit integrity chaining is included in migration `2026_09_11_000163`. Recurring journals are included in migration `2026_09_11_000164`, independent stock recount controls in `2026_09_11_000165`, acknowledged integration cursors in `2026_09_11_000166`, and reusable sales/purchase price lists in `2026_09_11_000167`. Run `php artisan erp:integration:deliver-webhooks` from the scheduler or queue host; deliveries are signed with HMAC-SHA256, include a stable `X-ERP-Delivery-Id` idempotency header, and are retried up to five times. Cursor mode is opt-in with `cursor_mode=1` on supported feeds; acknowledge a returned cursor through `/api/integration/cursors/{feed}/acknowledge` using the same integration token. Create subscriptions through the Sanctum integration API and store the returned secret securely. Configure date-bounded approval delegations at `/erp/security/approval-delegations`; delegated users must still be different from the document creator. Recurring journal generation runs with `php artisan erp:accounting:generate-recurring-journals`.

The migration sequence now extends through `2026_09_11_000201`; the earlier `000180` note describes the original retention, tax-exemption, inspection, and integration-reference increment. Authorized administrators can browse archive snapshots at `/erp/security/retention` and export the tenant-scoped archive CSV. The purchasing integration feed `/api/integration/goods-receipts` exposes deterministic, cursor-capable receipt and inspection state, and accepts approval-pending inbound receipt creation from approved purchase orders. Apply the complete migration sequence through the current head before enabling production ERP workflows.

Audit integrity chaining is included in migration `2026_09_11_000163`. Run `php artisan erp:security:verify-audit-chain` after migration rehearsal and periodically in production; use `--company=<id>` to verify one tenant. Existing pre-chain events remain unsealed and are skipped by the verifier.

Migrations `2026_09_11_000176` through `2026_09_11_000181` add idempotency references and write workflows for manufacturing orders, sales orders/deliveries, purchase orders/goods receipts, and purchase invoices. Run `php artisan migrate` before using these integration endpoints.

Migration `2026_09_11_000182` adds company scope and idempotency references to inventory returns; return integration writes support source-document matching, approval, rejection, serialized stock handling, and accounting reversal.

Migration `2026_09_11_000183` adds idempotency references to supplier payments. Accounting integrations can use `POST /api/accounting/supplier-payments` and the corresponding `/approve` and `/reject` actions with `accounting:write`; payment creation validates supplier/invoice ownership and currency, while approval enforces invoice status, outstanding balance, maker/checker, and AP/cash journal posting.

Security integrations can use `GET /api/integration/audit-logs` with `integration:read`. The feed is tenant-scoped, supports action/user/document/date filters, deterministic ordering, `updated_since`, and signed cursor acknowledgement; it is read-only.

Planning integrations can use `GET /api/inventory/replenishment` with `inventory:read`, optionally filtered by product or authorized location. It uses the same company-scoped policy, stock, open-purchase-order, lead-time, and supplier-price calculation as `erp:planning:generate-purchase-orders`, and returns paginated replenishment proposals without creating documents.

MRP integrations can use `GET /api/inventory/mrp` with `inventory:read`. It returns company-scoped multi-level BOM leaf requirements, on-hand quantity, calculated shortage, supplier-price context, and circular-BOM errors in a paginated response; the existing web MRP report uses the same service.

Forecast integrations can use `GET /api/inventory/forecast` with `inventory:read` and `POST /api/inventory/forecast-overrides` with `inventory:write`. Forecast reads accept history, horizon, and product filters and return historical demand, daily rate, forecast quantity, projected balance, and a 95% confidence band; overrides are company-scoped, auditable, and applied by both the API and web forecast report.

Reporting integrations can use `GET /api/inventory/analytics` with `inventory:read`. Date and product filters return tenant-scoped product profitability, revenue, COGS, gross profit, quantity sold, turnover, and cumulative-revenue ABC classification together with summary totals; the existing CSV/web report uses the same analytics service.

Dashboard integrations can use `GET /api/inventory/dashboard` with `inventory:read`. The response uses the same metrics service as the web dashboard and reports current-month approved sales, location-aware low-stock count, product count, allocation-aware receivables, and pending ERP approval count across purchasing, sales, receipts, invoices, returns, adjustments, documents, and stock counts.

Batch-risk integrations can use `GET /api/inventory/batches/expiry` with `inventory:read`. The feed supports expiry horizon, product, authorized location, and expired-batch filters and returns only positive ledger-derived batch stock with expiry status for FEFO/risk processing.

## 12. Rollback

If a release fails:

1. Stop or drain traffic if the deployment strategy requires it.
2. Switch the web-server symlink back to the previous release.
3. Restore the database only if a migration or data change requires it; take a backup before restoring.
4. Restore upload files only if the release changed or removed them.
5. Clear/rebuild cached configuration and restart PHP-FPM if needed.

Laravel migrations should be reviewed for rollback safety before production use. Avoid destructive rollback commands on a live database without an approved backup and recovery plan.

## 13. Deployment-specific risks in the current code

- The complete feature suite requires a reachable MySQL test database; the lightweight unit suite does not prove migration, transaction, or tenant-isolation behavior against a real database.
- Legacy POS controllers and screens remain alongside ERP workflows, so production rollout should enable the ERP routes and permissions deliberately and validate legacy-to-ledger reconciliation. Run `php artisan erp:reconcile-inventory-ledger --fail-on-mismatch` as a read-only drift check before retiring the compatibility quantity field; use `--company=<id>` for a tenant-specific check. If a reviewed drift must be synchronized, use the explicit `--apply --reason="..."` options; only products with ledger history are changed and each synchronization is audited.
- Webhook delivery depends on a continuously running scheduler (`php artisan schedule:work` or an equivalent production scheduler); without it, the outbox remains pending even though business transactions succeed.
- External webhook endpoints and accounting/inventory integrations require operational monitoring, secret rotation, retry inspection, and an approved network policy before high-volume production use.

Complete database-backed tests, migration rehearsal, backup/restore validation, and production scheduler monitoring before treating the application as a high-trust or high-volume deployment. Fiscal-year and fiscal-period close also require all tenant bank statement lines through the close date to be matched, ignored, or settled and all dated bank reconciliations to be closed; resolve or reclose the affected reconciliation before retrying the close.
Warehouse integrations can complete pending delivery picking and packing with `POST /api/integration/deliveries/{id}/operations/{pick|pack}/complete` using a token with `warehouse:write`, `sales:write`, or `integration:write`. Send `confirmed_quantities` keyed by delivery-line ID when the scanner confirms quantities; the server enforces exact line quantities and pick-before-pack ordering.
Warehouse clients can query capacity-aware put-away destinations with `GET /api/integration/warehouse/putaway/locations?quantity=...&product_id=...` using `warehouse:read`, then create an idempotent approval-pending task with `POST /api/integration/warehouse/putaway/tasks` using `warehouse:write` or `integration:write`; both paths enforce configured quantity, weight, and volume limits.
Warehouse transfer clients can create, approve, dispatch, and receive transfers through `/api/integration/warehouse/transfers` and its `/{id}/approve`, `/{id}/dispatch`, and `/{id}/receive` actions with `warehouse:write` or `integration:write`; receipt requests may provide `received_quantities` keyed by transfer-line ID for partial receiving.

Carrier tracking clients may continue sending normalized fields to `POST /api/integration/delivery-tracking-events`. They may also send `provider` (currently `generic`) and a provider payload under `payload`; the adapter registry normalizes common `deliveryId`, `status`, `occurred_at`, `event_id`, `location`, and `message` aliases, stores the provider/raw payload, and applies only valid forward delivery transitions. Provider-specific adapters can be registered without changing the tracking-event API contract: add fully qualified adapter classes implementing `App\Services\Integrations\CarrierTrackingAdapter` to the comma-separated `ERP_CARRIER_TRACKING_ADAPTERS` environment variable, then clear cached configuration. Authorized integration clients with `integration:read` can inspect registered adapter keys and readiness through `GET /api/integration/providers`; the response never exposes tokens or endpoint credentials.

Bank-feed clients may continue sending normalized fields to `POST /api/accounting/bank-reconciliation/lines`. They may also send `provider` (currently `generic`) and a payload under `payload`; the bank adapter normalizes `account_id`, `date`, `transaction_id`, and `narration` aliases, retains provider/raw payload data, and applies provider-scoped idempotency. Register custom classes implementing `App\Services\Integrations\BankStatementAdapter` with the comma-separated `ERP_BANK_STATEMENT_ADAPTERS` environment variable, then clear cached configuration.

The built-in `http` bank provider can poll an external statement service through `POST /api/accounting/bank-reconciliation/sync` with `accounting:write` access and `{"bank_account_id":42,"provider":"http","from":"2026-09-01","to":"2026-09-30"}`. Configure `ERP_BANK_STATEMENT_HTTP_ENDPOINT` with `{bank_account_id}` in the URL, and optionally set `ERP_BANK_STATEMENT_HTTP_TOKEN`, `ERP_BANK_STATEMENT_HTTP_TIMEOUT`, `ERP_BANK_STATEMENT_HTTP_RETRIES`, and `ERP_BANK_STATEMENT_HTTP_RETRY_SLEEP`. Provider responses may be a `lines` array, a `data` array, or a single line object; the result is imported through the existing idempotent batch/audit pipeline. The provider is disabled until an endpoint is configured.

Approved sales invoices can be prepared as provider-neutral e-invoice documents through `POST /api/accounting/invoices/{id}/e-invoice` and inspected through `GET /api/accounting/e-invoices`. The generic provider creates the canonical `erp.einvoice.v1` payload without making an external network call; submissions are tenant-scoped, approval-gated, hashed, audited, and idempotent by invoice/provider. `POST /api/accounting/e-invoices/{id}/submit` invokes a provider only when its adapter also implements `App\Services\Integrations\EInvoiceSubmitter`; unsupported providers fail safely without changing invoice accounting or inventory. An opt-in `App\Services\Integrations\HttpEInvoiceProvider` can be registered by placing its class name in `ERP_E_INVOICE_ADAPTERS` and configuring `ERP_E_INVOICE_HTTP_ENDPOINT`, optional `ERP_E_INVOICE_HTTP_TOKEN`, `ERP_E_INVOICE_HTTP_TIMEOUT`, `ERP_E_INVOICE_HTTP_RETRIES`, and `ERP_E_INVOICE_HTTP_RETRY_SLEEP`; it sends the hashed canonical envelope with a stable idempotency key, retries transient gateway failures, and accepts submitted/pending/accepted/rejected provider responses. Provider callbacks can acknowledge a submitted external reference through unauthenticated, HMAC-protected `POST /api/integration/e-invoices/{provider}/callback` using `ERP_E_INVOICE_CALLBACK_SECRET`; migration `2026_09_20_000356` enforces globally unique non-null `(provider, external_reference)` values so callback lookup cannot be ambiguous across companies, and accepted/rejected final states are replay-safe. Register provider classes implementing `App\Services\Integrations\EInvoiceProvider` with the comma-separated `ERP_E_INVOICE_ADAPTERS` environment variable, then clear cached configuration. A custom provider may transform and submit the canonical invoice into a jurisdiction-specific document; certified government connectors remain deployment-specific.

High-volume bank feeds can submit up to 500 records atomically to `POST /api/accounting/bank-reconciliation/lines/bulk` with `provider` and a `lines` array. The response includes an auditable `batch_id` and reports `created` and `duplicates`; imported lines retain the batch link, and any validation failure rolls back the complete batch.

Finance users can monitor batch history through `GET /api/accounting/bank-reconciliation/import-batches` with accounting read access and optional provider, status, and date filters.
Catalog integrations can create, update, and deactivate products through `POST /api/inventory/products`, `PATCH /api/inventory/products/{id}`, and `POST /api/inventory/products/{id}/deactivate` with `inventory:write` or `integration:write`; external references are idempotent and product ownership, lifecycle, stock-managed state, tracking changes, and non-zero-stock deactivation are validated. Lookup and stock responses expose product type, lifecycle status, and purchase/sales/stock eligibility flags.
Barcode and QR records can be added with `POST /api/inventory/products/{id}/barcodes`; product synchronization responses include the product’s barcode collection, and setting `is_primary` updates the legacy primary barcode field.
Product-specific UOM conversion rules can be synchronized with `GET/POST/PATCH /api/inventory/products/{id}/uoms` and `POST /api/inventory/products/{id}/uoms/{uomId}/deactivate` using inventory write access; rules validate company-owned units, matching dimensions, conversion factors, purchase/sales usage, and decimal precision. Technician synchronization accepts either module-specific or generic integration abilities and treats `external_reference` as the repeat-safe identity.
Service asset spare-part catalogs can be synchronized with `GET/POST/PATCH /api/service/assets/{id}/spare-parts` using service permissions; products must be active stock-managed items, and quantities, min/max levels, and audit history are validated and retained.

Service assets support optional straight-line valuation fields through the asset create/update APIs. Read a non-posting valuation snapshot with `GET /api/service/assets/{id}/valuation?as_of=YYYY-MM-DD`; the response includes acquisition cost, salvage value, useful life, elapsed months, depreciation to date, and book value. Alternative depreciation methods remain future accounting work.

Configured account mappings `asset_depreciation_expense` and `accumulated_depreciation` enable auditable posting through `POST /api/service/assets/{id}/depreciation` with a `date`; fiscal-period controls, duplicate-date protection, journal linkage, and asset accumulated-depreciation updates are enforced.

Asset customer ownership changes use `POST /api/service/assets/{id}/ownership-transfer` with `new_customer_id`, `effective_date`, `reason`, and optional `external_reference`; the current asset owner is updated transactionally and prior ownership is available from `GET /api/service/assets/{id}/ownership-history`.

Completed maintenance orders optionally post labor cost when `service_labor_expense` and `service_labor_payable` account mappings exist. The linked journal is stored on the order, and completion remains operationally compatible when those mappings are not configured.

Completed customer-owned maintenance orders can create one source-linked pending service invoice with `POST /api/service/orders/{id}/invoice`, using a non-stock service product, amount, date, optional currency/tax/due-date fields, and optional external reference. Approve or reject it with `POST /api/service/orders/{id}/invoice/approve` or `/reject`; service invoice approval posts receivable/revenue/tax accounting without issuing inventory.

The service-order synchronization feed includes the linked service invoice and its invoice lines, allowing external systems to reconcile work-order completion with billing status.

Service contracts are synchronized through `GET/POST/PATCH /api/service/contracts`. Contracts are company-scoped, customer-linked, optionally asset-linked, date-bounded, and support full/parts/labor/preventive coverage, response hours, value, currency, cancellation, and audit history.

Service requests may reference an active contract through `contract_id`; the API validates customer/asset ownership and derives `response_due_at` from the contract response-hour SLA.

Run `php artisan erp:service:expire-contracts` (scheduled daily at 04:45) to transition active contracts past `ends_on` to `expired`; use `--company=ID` for a tenant-specific run. Each transition is audited.

Service-request API records include computed `sla_status`: `not_tracked`, `due`, `met`, `breached`, or `closed`, and the feed accepts `sla_status` filtering. Assignment before the response deadline satisfies the SLA; unresolved requests past the deadline are marked breached.

Manufacturing planners can use `GET /api/manufacturing/capacity-load` with optional `from` and `to` dates to compare planned operation load against each work center's daily capacity and identify overloaded slots.

Use `GET /api/service/assets/{id}/history` for a consolidated traceability view containing the asset, requests, maintenance orders, service invoices, warranty claims, ownership transfers, and depreciation entries.

Run `php artisan erp:service:sla-alerts` to notify active service/report users about breached requests; it is scheduled daily at 06:35 and suppresses duplicate alerts for the same request on the same day.
Maintenance integrations can consume a spare part with `POST /api/service/orders/{id}/parts` using `service:write`; the operation checks the order and product tenant, blocks closed orders, validates available stock, posts an inventory issue, and records the part and audit event.
Service technician records can be synchronized with `GET/POST/PATCH /api/service/technicians` using `service:read`/`service:write` or the corresponding `integration:read`/`integration:write` abilities; user ownership, employee-code uniqueness, skills, availability, phone, and hourly-rate validation are enforced. The same ability alternatives apply to the service asset, spare-part, request, order, schedule, and warranty-claim endpoints.

HR employee synchronization supports the backward-compatible page response and opt-in signed cursor mode through `GET /api/hr/employees?cursor_mode=1&per_page=50`; subsequent requests use the returned `meta.next_cursor`. Cursor state is scoped to the company and access token, and should be acknowledged through the existing integration cursor endpoint when the consumer has completed processing. The same cursor mode is available for `GET /api/hr/pay-runs`, `/api/hr/payroll-rules`, `/api/hr/leave-types`, `/api/hr/leave-requests`, and `/api/hr/attendance`; non-cursor responses and filters remain unchanged.

Service inventory planning clients can call `GET /api/service/spare-part-replenishment` with optional `asset_id`, `product_id`, `page`, and `per_page` filters. The response is explicitly read-only and recommends replenishment up to the configured maximum (or minimum when no maximum is configured), using approved ledger availability and supplier cost metadata; it does not create a purchase order.

To convert selected recommendations into procurement, call `POST /api/service/spare-part-replenishment/purchase-orders` with a unique `external_reference`, date, optional expected date, and `items[]` containing `asset_spare_part_id` plus optional quantity. The endpoint rechecks current availability, creates supplier/currency-grouped submitted purchase orders, and returns `pending_approval`; repeat requests with the same reference return the existing order(s). Approval remains through the standard purchasing workflow.

Service integrations can synchronize the global spare-part catalog through `GET /api/service/spare-parts` with optional asset, product, supplier, and `updated_since` filters. Use `cursor_mode=1` and the returned signed cursor for deterministic incremental synchronization; the existing `/assets/{id}/spare-parts` endpoint remains unchanged.

HR employee synchronization supports the backward-compatible page response and opt-in signed cursor mode through `GET /api/hr/employees?cursor_mode=1&per_page=50`; subsequent requests use the returned `meta.next_cursor`. Cursor state is scoped to the company and access token, and should be acknowledged through the existing integration cursor endpoint when the consumer has completed processing.
Variants can be created with `POST /api/inventory/products/{id}/variants` using `inventory:write` or `integration:write`; only active parents are accepted, parent lifecycle and eligibility data is inherited, attribute values are tenant-validated, and each attribute may be selected only once.
Attribute catalogs can be synchronized with `GET /api/inventory/attributes` and maintained through `POST /api/inventory/attributes` and `POST /api/inventory/attributes/{id}/values` using `inventory:read`/`inventory:write` (or `integration:write`).
Finance integrations can read currencies and effective-dated exchange rates at `GET /api/accounting/currencies` and `GET /api/accounting/exchange-rates`, and create active-currency rates with `POST /api/accounting/exchange-rates` using `accounting:write` or `integration:write`.
They can also create/update currencies through `POST/PATCH /api/accounting/currencies` with the same write abilities; codes are normalized to uppercase and base-currency selection is serialized.
Tax integrations can read, create, and update rates through `GET/POST/PATCH /api/accounting/tax-rates` using accounting permissions; rates are tenant-scoped, cursor-synchronizable, and audited.
Fiscal-period integrations can read and create company-scoped periods through `GET/POST /api/accounting/fiscal-years`; creation rejects overlapping dates and starts the period open.
Organization integrations can synchronize authorized hierarchy records through `GET /api/integration/organization/companies`, `/branches`, `/warehouses`, and `/locations` using `integration:read`; feeds support `updated_since`, cursor pagination, and relevant parent/type filters.
The same organization feed supports `/stores` and `/departments`, completing the core company hierarchy used by POS and accounting.
Security integrations can read credential-free users, roles, and permissions from `/api/integration/security/users`, `/roles`, and `/permissions`; feeds require `integration:read`, support cursor synchronization, and keep tenant/user scope without exposing passwords or MFA secrets.
They can close or reopen periods with `POST /api/accounting/fiscal-years/{id}/close` and `/reopen`; both require `accounting:write` or `integration:write`, close requires `close_reason`, close captures the fiscal-year-end inventory snapshot, reopen requires `reopen_reason`, and close is blocked while controlled documents remain pending.
Trading-partner integrations can create or update customers at `/api/inventory/customers` and suppliers at `/api/inventory/suppliers` using the corresponding `sales:write` or `purchasing:write` ability (or `integration:write`); external references make create requests repeat-safe and tax, credit, payment, and tenant fields are validated.
The same endpoints support `GET` with `q`, `is_active`, `updated_since`, and `per_page` filters for deterministic cursor-based customer/supplier synchronization using `inventory:read`.

Inventory returns may include an optional `location_id` in both the browser workflow and `POST /api/integration/returns`. The selected company-authorized location is retained on the return, serial lifecycle updates, availability checks, and posted inventory movements; omit it only for legacy company-wide posting.
Pricing integrations can create named sales/purchase price lists at `POST /api/integration/price-lists`, add quantity-break items at `POST /api/integration/price-lists/{id}/items`, and deactivate lists with `POST /api/integration/price-lists/{id}/deactivate`; `sales:write` or `purchasing:write` is required by list type, with repeat-safe external references.

Migration `2026_09_11_000193` adds location-aware inventory returns. Migration `2026_09_11_000194` scopes journal external-reference idempotency by company; migration `2026_09_11_000176` also supplies the missing production-order company key required by its integration index. Back up the database before applying either migration.
Migration `2026_09_11_000195` scopes journal entry-number uniqueness by company so company-specific numbering sequences can safely reuse formats.
Migration `2026_09_11_000196` scopes operational document numbers by company across purchasing, sales, warehouse, inventory, manufacturing, service, and returns.
Migration `2026_09_11_000199` enforces company-scoped unique UOM codes.
Migration `2026_09_11_000200` adds and backfills company ownership for audit logs; verify the database backup exists before applying it. On a clean install, the audit-log base migration includes this column directly.
Migration `2026_09_11_000201` changes tax-rate code uniqueness from global to company-scoped uniqueness.
Migration `2026_09_11_000202` changes inventory-adjustment external-reference uniqueness from global to company-scoped uniqueness.
Migration `2026_09_11_000203` adds company-scoped external references for service-asset synchronization.
Migration `2026_09_11_000204` adds company-scoped external references for preventive-maintenance schedule synchronization.
Migration `2026_09_11_000205` scopes warranty-claim numbers and adds company-scoped external references for claim synchronization.
Migration `2026_09_11_000206` adds company-scoped external references for technician synchronization.
Migration `2026_09_12_000223` adds immutable movement-level batch/lot/serial allocations. FIFO/FEFO issues that consume multiple cost layers now expose every allocation through inventory movement feeds and traceability reports; the legacy movement batch field remains for backward compatibility.

Warehouse transfer variance resolution is available at `POST /api/integration/warehouse/transfers/{id}/resolve-variance`. It requires `inventory:write` or `integration:write`, a resolution of `accepted` or `waived`, and a reason. When the `inventory_loss` and `inventory_in_transit` mappings exist, resolving a shortage posts the balancing settlement journal.

Approval delegations are available to integration clients at `GET/POST /api/integration/security/approval-delegations` and `POST /api/integration/security/approval-delegations/{id}/deactivate` with `integration:read` or `integration:write`. Delegations validate same-company users, reject overlapping active periods for the same pair, support document-type scope, and write audit events.

Approval policies are available to integration clients at `GET/POST/PATCH /api/integration/security/approval-policies` and `POST /api/integration/security/approval-policies/{id}/deactivate`. Policy writes validate company-owned branch/category context, known permissions, amount ranges, ordered steps, and escalation-hour bounds.

Maintenance spare-part reservation/consumption and order status changes honor configured `MaintenanceOrder` approval policies in both browser workflows and the service API (`POST /api/service/orders/{id}/parts/reserve`, `POST /api/service/orders/{id}/parts`, `PATCH /api/service/orders/{id}/status`); reservations use the shared stock-reservation expiry and batch-allocation rules, and the existing maker/checker guard runs before stock, labor, or completion effects are posted. If no matching policy exists, the legacy behavior remains available.

Replenishment scenario analysis remains read-only at `GET /api/inventory/replenishment/scenario` and `/planning/replenishment-scenario`. Authorized planners can persist an exact snapshot with `POST /api/inventory/replenishment/scenarios` or the browser “Save snapshot” action; saved snapshots are tenant-scoped, auditable, replay-safe by external reference, and never create stock movements, purchase orders, or transfers.

Landed-cost approval allocates the cost into receipt layers and stores immutable before/after cost-layer adjustment records. If receipt stock was already consumed, the consumed portion updates recorded COGS while the remaining portion updates inventory. Controlled reversal creates immutable reverse adjustments and reverses the linked journal, subject to later-change and post-approval-consumption safeguards. To post the corresponding balanced journal, configure `inventory`, `cogs`, and `landed_cost_clearing` account mappings; the entry credits clearing in the company base currency.

Inventory integrations can synchronize immutable landed-cost layer events through `GET /api/inventory/cost-layer-adjustments` with `inventory:read`, filtered by product, location, batch, landed-cost document, and `updated_since`, using the standard cursor mode.

Migration `2026_09_18_000337` adds optional preferred batch/lot reservation to sales-order lines. Integrations can send `lines.*.batch_id`; when omitted, existing FEFO allocation remains unchanged. The selected batch must belong to the product and have sufficient available layer quantity unless backorders are explicitly enabled.
This migration is an earlier increment; it is superseded by the later migration sequence through current head `2026_09_19_000346`.

Accounting integrations expose `GET /api/accounting/supplier-payment-proposals` with `accounting:read`. The feed is tenant-scoped and supports `as_of`, `due_by`, `supplier_id`, pagination, and currency-aware supplier batches; it nets approved payments and invoice-linked supplier credits without creating or approving payments. Treasury workflows can use the proposed invoice rows and grouped batches as an approval input, then continue through the existing maker/checker payment APIs.

Accounting integrations also expose `GET /api/accounting/customer-receipt-proposals` with `accounting:read`. The feed is tenant-scoped and supports `as_of`, `due_by`, `customer_id`, pagination, and currency-aware customer batches; it nets approved customer payments and invoice-linked credit notes without mutating receivables, allowing collection teams to use the rows as a controlled receipt-follow-up queue.

Treasury clients can create a controlled customer receipt run through `POST /api/accounting/customer-receipt-runs` with `accounting:write`. The request selects eligible invoice IDs for one customer, payment date, method, currency, and an external run reference; it creates separate pending customer payments with stable per-invoice idempotency references and audit events. Pending receipts do not reduce receivables or post journals. A different checker must use `POST /api/accounting/customer-payments/{id}/approve` (or `/reject` with a reason); replaying a completed run reference returns the existing payments without duplication.

Treasury clients can create a controlled supplier payment run through `POST /api/accounting/supplier-payment-runs` with `accounting:write`. The request selects eligible proposal invoice IDs, supplier, payment date, method, and an external run reference; the endpoint creates separate `pending` supplier payments with stable per-invoice idempotency references, one-currency enforcement, audit events, and no journal posting until the existing independent checker approval endpoint is used. Replaying a completed run reference returns the existing payments without duplication.
Service asset spare-part catalogs now support optional active-supplier sourcing metadata (supplier part number, lead time, vendor cost/currency, and preferred supplier) through both the authenticated service API and browser workflow. Run the normal migration command before using these fields.

Supplier-item price agreements now support authenticated procurement integration create, idempotent replay by external reference, update, and deactivation through `/api/integration/supplier-prices`; currency codes are normalized to uppercase and all changes are audited.

Procurement integrations can bulk import supplier prices through `POST /api/integration/supplier-prices/import` with a CSV or XLSX file and optional `dry_run`; rows are tenant-validated and external references update existing agreements transactionally.

If an active `ApprovalPolicy` targets `App\\Models\\SupplierProductPrice`, new or revised supplier prices remain pending until an independent checker approves them through the supplier-price API or browser workflow; without such a policy, existing immediate-approval behavior is preserved.

Supplier-price versions can be compared without mutation through `GET /api/integration/supplier-prices/{id}/compare/{otherId}`; both records must belong to the same supplier and product, and the response includes field-level changes.

Webhook subscription secrets can be rotated through `POST /api/integration/webhooks/{id}/rotate-secret`. The new secret is returned only in that response, encrypted at rest, and recorded through an audit event; the previous secret is never returned.

Webhook subscription names, HTTPS endpoints, and event filters can be changed through audited `PATCH /api/integration/webhooks/{id}` updates. The secret is not accepted or returned by this endpoint; use the dedicated rotation endpoint when credentials must change.

Service spare-part replenishment can run automatically with `php artisan erp:service:generate-spare-part-purchase-orders`; it creates deterministic, submitted purchase orders that remain subject to normal procurement approval. Use `--dry-run` to inspect shortages or `--company=ID` for a single tenant. The scheduler runs this command daily at 02:45.

Webhook deliveries that fail five attempts enter `dead_letter` status with a timestamp and are excluded from scheduled retries. Migration `2026_09_20_000359` also backfills existing failed deliveries that already exhausted five attempts. Authorized integration users can inspect/filter them and manually requeue them through the existing delivery retry endpoint, which resets the attempt counter, clears the dead-letter marker, and records an audit event.
Planning integrations can query the multi-echelon replenishment feed with inventory read permission for a read-only network view of policy locations, hierarchy paths, current/target balances, transfer coverage, open purchase receipts, and remaining external purchase requirements. The scheduled purchase-order generator also nets eligible internal transfer suggestions before creating external draft orders. The feed itself does not create transfers, purchase orders, or stock movements.

Inventory users can query GET /api/inventory/valuation/revaluation-preview with inventory:read for a tenant-scoped, read-only layer variance preview. It supports product, location, and as-of filters for weighted-average, moving-average, and standard-cost items and never changes cost layers, movements, or journals.

Controlled revaluation runs are available through GET/POST /api/inventory/valuation/revaluations and independent approve/reject actions. A run snapshots the preview into immutable lines, requires an independent checker, rejects stale cost layers, and applies only today's approved open-layer variance; rejected runs require a reason and all lifecycle changes are audited.

Configure inventory plus inventory_revaluation_gain or inventory_revaluation_loss account mappings to post the balanced revaluation journal. If mappings are absent, the approved run records accounting_status=missing_mapping while retaining the layer change and audit history.

Approved revaluation runs can be reversed through POST /api/inventory/valuation/revaluations/{id}/reverse with a required reason. The operation requires unchanged layer costs, restores the snapshotted costs, and reverses the linked journal when one was posted.

Fiscal-period close now blocks when a pending inventory cost revaluation dated on or before the close date remains unresolved. Approve or reject the run before retrying period close.
