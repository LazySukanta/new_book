# task014 — Owner purchase correction and soft deletion

Owner-only correction previews and commits update the current purchase while retaining every version, original line identity, signed stock movement and actual payment. Quantity and cost changes post now; metadata-only corrections leave stock and payments unchanged. Costs on already-issued stock remain untouched. Cost changes require the owner's confirmed remaining quantity under the M11 policy.

The preview fingerprint covers the correction, current purchase, relevant stock ledger versions and allocations. Commit recomputes it under branch/party/document/product/stock locks. Stale revisions return `PURCHASE_REVISION_CONFLICT`; changed previews return `PURCHASE_PREVIEW_CHANGED`. Idempotent retries return the original result. Failed writes roll back purchase, stock, allocations, history and audit together.

Excess allocations are detached using linked negative entries and any retained replacement allocation. Actual payments keep their supplier and amount; freed funds remain supplier advance. A supplier change detaches old-party allocations before assigning the purchase to the new supplier. No refund is invented. Invalid allocation projections or overconsumed payment funds block with `PURCHASE_ALLOCATION_CONFLICT`.

Soft deletion removes only the current active stock contribution and retains all database rows. Active lists omit deleted purchases; owners can read deleted lists, detail and revision history. Removed products remain in old line rows and revision snapshots, while current detail returns active lines. The cancel alias uses the same deletion workflow. Supplier ledger cutoffs select the corresponding retained revision; backdated advance applications respect purchase capacity across revision history.

## Entry points

- `POST /api/v1/purchases/{id}/revision-preview`
- `PATCH /api/v1/purchases/{id}`
- `DELETE /api/v1/purchases/{id}`
- `POST /api/v1/purchases/{id}/cancel` — legacy alias
- `GET /api/v1/purchases/{id}/revisions`
- `GET /api/v1/purchases?status=deleted` — owner view

Every correction needs `expected_revision` and `reason`. Commit also needs the returned `preview_hash` and `Idempotency-Key`. Item edits submit the complete active item list; omitted products are retained as removed lines. Financial correction effects use current time separately from the retained supplier invoice date. Preview creates no financial/stock records.

## Changed areas

- `application/app/Services/PurchaseCorrectionPosting.php`: shared preview/commit orchestration.
- `application/app/Http/Controllers/PurchaseCorrectionController.php`: validation, owner gate and paginated history.
- `application/app/Http/Controllers/PurchaseController.php`, `application/routes/api.php`: active/deleted views and routes.
- `application/app/Services/PurchaseRevisionHistory.php`: actual before-state snapshots, including current settlement.
- `application/app/Services/SupplierAccountPosting.php`, `application/app/Http/Controllers/SupplierController.php`: revision-aware allocation capacity and historical ledgers.
- `application/database/migrations/2026_11_05_230000_link_purchase_corrections.php`: foreign keys linking stock and allocation effects to revisions; one-source stock constraint.
- `application/tests/Feature/PurchaseCorrectionTest.php`, `PurchaseRevisionTest.php`: API regressions and future return dependency fixtures.

## Scope boundaries

There is no restore or actual-refund action in this milestone. Excess paid funds follow the supported retain-as-advance path. Supplier-return creation remains disabled until M22; its returned-quantity/supplier-link rules are verified with M11 preview fixtures. M22 must replace the explicit zero-return assumptions with real linked return data before enabling returns. Sale-issue fixtures verify immutable historical issue costs; the complete sales posting endpoint arrives in M19.

The production purchase screen and its browser acceptance belong to M31. No browser UI or production deployment is claimed here. No new package was installed.

[Verification](test-results.md) · [Tests the owner can perform](manual-tests.md)

Next eligible milestone: **M15 — Customers and opening credit accounts**. Stop at this milestone boundary for the user's testing.
