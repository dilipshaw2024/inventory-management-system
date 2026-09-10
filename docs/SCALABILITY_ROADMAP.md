# Small, Medium, and Enterprise Scalability Roadmap

## Executive assessment

The current application is a functional inventory and POS MVP. It can support a small company with a limited number of users, products, and transactions. To support medium and enterprise companies, the system needs stronger controls, broader business coverage, multi-location support, integrations, and production operations.

```text
Current application
  -> Small-company ready after security and validation cleanup
  -> Medium-company ready after operational and workflow expansion
  -> Enterprise ready after multi-tenant, scalable, auditable architecture
```

## Capability by company size

| Capability | Small company | Medium company | Enterprise company |
| --- | --- | --- | --- |
| Current master data, purchasing, invoicing, stock, payments | Available | Available with improvements | Foundation only |
| Multiple users and permissions | Basic authentication only | Required | Required with segregation of duties |
| Multiple branches/warehouses | Missing | Required | Required with transfer and consolidation |
| Tax, accounting, and financial controls | Limited | Required | Required with integrations and auditability |
| Returns, adjustments, and stock counts | Missing | Required | Required with approval workflows |
| API, integrations, and imports | Missing/limited | Useful | Required |
| Reporting and dashboards | Basic/static dashboard and Blade reports | Live and filterable | Scalable analytics and exports |
| Reliability and observability | Basic | Required | Mission-critical |

## Priority levels

- **P0 – Security and data integrity:** required before real production use.
- **P1 – Medium-company operations:** required for multiple staff, higher volume, and repeatable processes.
- **P2 – Enterprise platform:** required for multiple entities, locations, integrations, and compliance.
- **P3 – Optional product enhancements:** valuable differentiators after the foundation is stable.

## P0: production foundation

### 1. Authorization and user administration

The current application has authenticated users but no roles or permissions. Add:

- Roles such as Super Admin, Manager, Purchasing, Sales, Warehouse, Accountant, and Viewer.
- Permission checks per action, not only per page.
- Approval authority rules for purchases, invoices, payments, returns, stock adjustments, and master-data deletion.
- User activation/deactivation, login history, password policy, optional two-factor authentication, and session controls.
- Separation of duties so the same user cannot silently create and approve sensitive transactions when policy forbids it.

**Acceptance criteria:** every protected action has an authorization test; unauthorized users receive a controlled response; role changes are audited.

### 2. Validation and transaction safety

Strengthen every POS input with Form Requests and database constraints:

- Required supplier, customer, product, category, date, quantity, and price fields.
- Positive numeric quantities and prices.
- Maximum payment cannot exceed the outstanding balance.
- Invoice approval must be idempotent and must lock/recheck product rows.
- Purchase approval must be transactional and cannot run twice.
- Invoice number and purchase number uniqueness must be enforced.
- Delete and approval actions should use POST/PATCH/DELETE with CSRF protection, not GET.

**Acceptance criteria:** invalid direct HTTP requests fail safely; concurrent approvals cannot create negative or duplicated stock.

### 3. Database integrity and money handling

- Add foreign keys and indexes for all relationship IDs.
- Replace monetary `double` columns with fixed-precision decimal columns.
- Add unique indexes for usernames, invoice numbers, and business reference numbers.
- Add check constraints where supported and use explicit status constants/enums.
- Add `deleted_at` soft deletes for records that must remain auditable.
- Move repeated calculations from Blade templates into query/service layers.

### 4. Testing and release quality

Add automated tests for:

- Authentication and authorization.
- Master-data CRUD and deletion restrictions.
- Purchase create/approve/retry behavior.
- Invoice create/approve/insufficient-stock behavior.
- Full, due, partial, and subsequent payment behavior.
- Reports, date ranges, totals, and exports.
- Upload validation and cleanup.

Use CI to run PHP linting, tests, migrations on a clean database, dependency auditing, and asset builds for every change.

## P1: medium-company functionality

### 5. Warehouse and branch management

Add `companies`, `branches`, `warehouses`, and user-location assignments. Every product balance and transaction should carry a location context.

Required workflows:

- Opening stock by warehouse.
- Warehouse-to-warehouse transfers.
- Branch-specific purchases and sales.
- Consolidated and branch-level reports.
- Stock reservation and available-versus-on-hand quantities.

### 6. Complete purchasing

Expand purchases into a procurement lifecycle:

```text
Purchase request -> Purchase order -> Approval -> Goods receipt
                 -> Supplier invoice -> Supplier payment -> Close
```

Add supplier price history, reorder levels, minimum order quantities, purchase returns, partial receipts, and supplier balances.

### 7. Complete sales and POS

- Quotations and sales orders before invoices.
- Cash, card, bank, wallet, and mixed payment methods.
- Sales returns, exchanges, cancellations, and credit notes.
- Customer price lists, discounts, promotions, and credit limits.
- Barcode/QR scanning and receipt printing.
- Offline queue or resilient POS mode where required.

### 8. Inventory control

- Stock adjustments with reason codes and approval.
- Physical stock-count sessions and variance approval.
- Batch, lot, serial number, expiry date, and warranty tracking where applicable.
- Low-stock, overstock, expiry, and negative-stock alerts.
- Inventory valuation using a documented method such as weighted average or FIFO.
- Immutable stock movement ledger instead of relying only on a mutable quantity field.

### 9. Accounting and tax

Add a finance boundary or integrate with accounting software:

- Chart of accounts and journals.
- Accounts receivable and accounts payable.
- Tax/VAT/GST configuration, tax-inclusive/exclusive prices, and tax reports.
- Expenses, cash drawer, bank reconciliation, and settlement reports.
- Credit notes, debit notes, refunds, and period closing.
- Export to approved accounting systems.

### 10. Reporting and data operations

- Replace dashboard sample values with live aggregates.
- Add server-side pagination, filtering, sorting, and export jobs.
- Add CSV/XLSX import and export with validation and error files.
- Add saved reports and scheduled email reports.
- Add dashboards for sales, gross margin, stock turnover, receivables, payables, and purchasing.

## P2: enterprise platform requirements

### 11. Multi-company and tenancy

Decide between a shared database with `tenant_id`, separate schemas, or separate databases. Add tenant isolation to every query and test it. A user must never see another company’s customers, products, stock, invoices, or payments.

Support:

- Company-level configuration and branding.
- Per-company tax, currency, numbering, fiscal year, and approval policy.
- Consolidated reporting for parent organizations.
- Tenant onboarding, suspension, export, and deletion policies.

### 12. Integration and API platform

Provide versioned, documented APIs with authentication, rate limits, idempotency keys, and audit logs. Typical integrations include:

- Accounting/ERP systems.
- Payment gateways and banks.
- Shipping and courier providers.
- E-commerce marketplaces and web stores.
- Email, SMS, WhatsApp, and push notifications.
- Barcode scanners, label printers, and warehouse devices.

Use queues for email, imports, exports, webhooks, and long-running reports. Add retry and dead-letter handling.

### 13. Enterprise security and compliance

- Centralized audit log for every create, update, approval, payment, export, and permission change.
- Encryption in transit and at rest where required.
- Secrets management instead of credentials in `.env` on shared hosts.
- IP/device/session policy, two-factor authentication, and SSO/SAML/OIDC where required.
- Data retention, privacy requests, access reports, and configurable archival.
- Regular dependency, vulnerability, penetration, and backup-restore testing.

### 14. Reliability and scale

- Production monitoring for application errors, database health, queues, storage, latency, and business failures.
- Centralized structured logs and alerting.
- Redis-backed cache/session/queues where appropriate.
- Read replicas or reporting database for heavy analytics.
- Horizontal PHP-FPM/web scaling behind a load balancer.
- Object storage/CDN for uploads.
- Automated backups, point-in-time recovery, disaster recovery, and documented RTO/RPO.
- Load tests for invoice approval, stock lookup, imports, and reports.

## Module expansion matrix

| Existing module | Required enhancement |
| --- | --- |
| Authentication/Admin | Roles, permissions, MFA, SSO, audit logs, user-location access |
| Supplier | Supplier onboarding, contracts, ratings, price lists, balances, returns |
| Customer | Credit limits, customer groups, pricing, statements, refunds, privacy controls |
| Unit/Category/Product | Attributes, variants, barcodes, brands, tax class, reorder rules, batches/serials |
| Purchase | Requests, purchase orders, receipts, partial deliveries, procurement approval |
| Invoice | Quotes, orders, POS payments, returns, credit notes, shipping, tax calculation |
| Payment | Payment methods, gateways, refunds, reconciliation, receivable aging |
| Stock | Warehouses, transfers, counts, adjustments, reservations, valuation, movement ledger |
| Reports | Live dashboards, exports, schedules, analytics, financial statements |
| AJAX/API | Authentication, validation, versioning, rate limits, idempotency, integrations |

## Recommended implementation sequence

1. P0 authorization, validation, database integrity, safe money types, and transaction tests.
2. Replace mutable-only stock logic with a stock movement ledger while preserving current balances.
3. Add warehouse/branch context and inventory controls.
4. Add procurement, sales returns, payment methods, taxation, and accounting integration.
5. Add APIs, queues, imports/exports, notifications, and live dashboards.
6. Add tenancy, SSO/MFA, audit/compliance, monitoring, disaster recovery, and load testing.

## Go-live gates

### Small company

P0 complete, one company/location, tested backup restore, validated users, and documented operational procedures.

### Medium company

P0 complete plus branch/warehouse support, role permissions, returns, tax/accounting workflow, pagination, imports/exports, and monitoring.

### Enterprise company

P0/P1 complete plus tenant isolation or multi-company architecture, APIs/integrations, queues, audit/compliance, SSO/MFA, scalable infrastructure, disaster recovery, load testing, and formal support processes.

See [`diagrams/scalability-roadmap.mmd`](diagrams/scalability-roadmap.mmd) for the visual progression.
