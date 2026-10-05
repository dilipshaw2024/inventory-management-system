# ERP Integration Contract

External clients can discover the current tenant-safe integration contract with:

```http
GET /api/integration/capabilities
Authorization: Bearer <Sanctum token>
```

The endpoint requires the `integration:read` ability and returns `erp.integration.v1`. It reports module feed roots, the read/write abilities required by each module, the abilities granted to the current token, and the synchronization conventions. It never returns provider credentials or webhook secrets.

## Compatibility contract

- Tenant scope is derived from the token's company; clients must not send a company override.
- Incremental feeds use deterministic `updated_at,id` ordering.
- Feeds support either signed cursor pagination or the backward-compatible `updated_since` pattern.
- Write operations use `external_reference` where the resource supports idempotent synchronization.
- Inventory writers may explicitly assign an open batch/lot reservation with `PATCH /api/inventory/reservations/{id}/batch`; the server validates layer availability and competing reservations before changing allocation metadata.
- Inventory writers may explicitly assign one or more available serials with `PATCH /api/inventory/reservations/{id}/serials`; the server validates serial tracking, tenant/location/batch ownership, duplicate reservations, and delivery approval consumes the assigned serials.
- Reservation feeds support `serial_id` filtering and include the assigned serial relation, allowing warehouse clients to reconcile one-unit serialized allocations incrementally.
- Replaying the same external reference returns the original resource or a duplicate status; it must not post stock, accounting, or fulfillment twice.
- Approval, posting, dispatch, receipt, and settlement remain explicit lifecycle actions.
- Error responses are JSON with an explanatory `message` and an appropriate HTTP status.
- Secrets are accepted only through protected provider-configuration endpoints and are omitted from responses.

## Module contract

| Module | Read ability | Write ability | Feed root |
|---|---|---|---|
| Organization | `integration:read` | `integration:write` | `/api/integration/organization` |
| Catalog | `inventory:read` | `inventory:write` | `/api/integration/products` |
| Inventory | `inventory:read` | `inventory:write` | `/api/inventory` |
| Warehouse | `warehouse:read` | `warehouse:write` | `/api/integration/warehouse` |
| Purchasing | `purchasing:read` | `purchasing:write` | `/api/integration` |
| Sales | `sales:read` | `sales:write` | `/api/integration` |
| Manufacturing | `manufacturing:read` | `manufacturing:write` | `/api/integration/manufacturing` |
| Planning | `planning:read` | `planning:write` | `/api/integration/planning` |
| Service | `service:read` | `service:write` | `/api/service` |
| Accounting | `accounting:read` | `accounting:write` | `/api/accounting` |
| Security | `integration:read` | `integration:write` | `/api/integration/security` |

A client should call the capability endpoint after token creation and cache the response for the token lifetime. It should enable only the modules whose required ability is present.

## Example response shape

```json
{
  "data": {
    "contract": "erp.integration.v1",
    "api_version": "v1",
    "modules": {
      "inventory": {
        "read_ability": "inventory:read",
        "write_ability": "inventory:write",
        "feed_root": "/api/inventory",
        "conventions": {
          "cursor_pagination": true,
          "idempotency": "external_reference",
          "timestamps": "updated_at,id"
        }
      }
    },
    "abilities": ["integration:read", "inventory:read"],
    "conventions": {
      "tenant_scope": "token_company",
      "pagination": "signed_cursor_or_updated_since",
      "idempotency": "external_reference",
      "timestamps": "updated_at,id",
      "errors": "json_message_with_http_status"
    }
  },
  "meta": {
    "company_id": 42,
    "generated_at": "2026-09-30T12:00:00Z"
  }
}
```

This is a contract-discovery document, not a replacement for endpoint-specific validation. Clients must still honor each endpoint's required fields, approval rules, and lifecycle constraints.

### Unified traceability feed

`GET /api/inventory/traceability` returns the company-scoped immutable inventory movement ledger with eager-loaded product, batch, serial, allocation, location, creator, and source-document context. Supported filters are `product_id`, `batch_id`, `serial_id`, `location_id`, `movement_type`, `direction=all|inbound|outbound`, `posted_from`, `posted_to`, and `updated_since`; the response uses the standard cursor pagination contract and accepts `per_page` up to 100.

### Intercompany inventory transfers

Affiliated companies can synchronize shared-product inventory through the authenticated `/api/inventory/intercompany-transfers` lifecycle. The source company creates and independently approves a transfer, dispatches stock from its location, and the destination company receives it into its own location. `GET` lists transfers visible to either participating company; `POST` create uses `external_reference` for replay-safe creation, while receipt uses its own `external_reference` for replay-safe receiving. Destination locations are checked against the declared destination company, and source/destination ledger movements retain separate company ownership.


## Quality inspection contract

Inventory clients with `inventory:read` can list quality plans and inspections. Clients with `inventory:write` can create idempotent product-specific plans and inspections, submit numeric/text/boolean characteristic results, and complete inspections with `release`, `quarantine`, `rework`, or `scrap` dispositions. Required characteristics must be recorded before completion, and failed inspections cannot be released. The disposition-application endpoint applies quarantine, rework, or scrap through the inventory status-transfer ledger and is replay-safe; release records completion without changing stock. All records are company-scoped and external references are replay-safe. Status transfers with a `batch_id` cannot exceed that batch's remaining available layer quantity after active reservations, and serialized transfers reject a selected serial held at a different requested location. Warehouse-scoped users may use only locations in their assigned warehouse; cross-warehouse location references are rejected. Receiving clients can set `inspection_required=true` and provide `lines[].quality_plan_id`; each receipt line then exposes its linked inspection, and goods-receipt approval remains blocked until every linked inspection is completed with a release disposition. Return clients can use the same pattern with a return-type plan; return approval remains blocked until every linked return inspection passes, and the legacy binary inspection endpoint cannot override linked inspections.

### Automatic carrier quote selection

`POST /api/integration/deliveries/{id}/carrier-quote/auto` requires `warehouse:write` and selects a matching active rate card for an approved, incomplete delivery. It accepts optional `carrier`, `origin_zone`, `destination_zone`, `as_of`, `selection_strategy=cheapest|fastest`, and `external_reference`. The selection uses the delivery/package weight, validates rate-card bounds and validity dates, persists the quote atomically, and is replay-safe by the external reference. The response reports `status=auto_selected` and the selected strategy; repeated references return `status=duplicate_ignored`.
