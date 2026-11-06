# SPEC.md â€” POS & Inventory Management (Laravel + MySQL) â€” v3 draft for review

Read with `SCHEMA.md` (tables) and `RULES.md` (mandatory rules). Precedence if they disagree: RULES.md > SCHEMA.md > SPEC.md. Ask before deviating.

---

## 1. Overview

Web-based POS and inventory system for a small Indian shop with 1â€“3 branches. Retail and wholesale selling, GST documents, purchases, inter-branch transfers, customer credit (udhaar), payments and basic reports.

**Users:** Owner (all branches, everything), Manager (assigned branches), Cashier (assigned branches, billing).
**Hosting:** shared hosting or small VPS. The API returns JSON; Livewire renders and updates web pages. The browser renders receipts and PDFs. Avoid unnecessary network round trips.
**Future (not now):** mobile/desktop apps, offline. Therefore: API-first, token/session auth via Sanctum, request-scoped business endpoints (browser authentication uses a session), all ids/numbers/totals generated server-side.

### 1.1 Stack
PHP 8.3+, Laravel 13, MySQL 8 (MariaDB requires separate compatibility verification), Sanctum, `spatie/laravel-permission`, `maatwebsite/excel` (on-demand exports only), browser-side `pdfmake` (A4) and HTML+print-CSS (thermal), PHPUnit/Pest on MySQL. Frontend (phase 7): Blade + Livewire 4 + Alpine.js + Tailwind. Livewire and API controllers call the same application services; no internal HTTP calls between them. Alpine handles local interactions, scanner focus and receipt printing; use Livewire-bundled Alpine rather than installing a duplicate. No Vue or Inertia.

### 1.2 Out of scope
See RULES.md Â§M.

---

## 2. Global conventions

### 2.1 API
- Base `/api/v1`, JSON only. All routes except credential-verifying POST /auth/login and POST /auth/device-token need prior session/token authentication. Both credential endpoints are throttled; device-token also requires an approved device.
- Success: `{ "data": â€¦, "meta": â€¦ }`. Lists paginate: `?page=&per_page=` (default 25, max 100) with `meta.current_page, last_page, per_page, total`. Lists accept `search`, `sort`, `dir`.
- Errors: 422 `{message, errors:{field:[â€¦]}}`; 401; 403; 404 (also used when a record belongs to a branch the user cannot access); 409 business conflict with a stable `code`.
- Stable error codes: `INSUFFICIENT_STOCK`, `PRODUCT_HAS_HISTORY`, `DUPLICATE_SUPPLIER_INVOICE`, `CREDIT_LIMIT_EXCEEDED`, `OVERPAYMENT`, `OVER_RECEIVE`, `DOCUMENT_FINALIZED`, `PURCHASE_REVISION_CONFLICT`, `PURCHASE_CORRECTION_DEPENDENCY`, `NOT_ALLOWED_FOR_BRANCH`, `PRICE_CHANGED`, `DOCUMENT_SERIES_UNAVAILABLE`, `DOCUMENT_SERIES_EXHAUSTED`.
- Money fields: exact INR decimal strings with up to 2 fractional digits, stored as DECIMAL(18,2), serialized with two places, e.g. "12.35". Reject monetary input beyond two places. Never use floating-point JSON numbers. Prices, discounts, taxes, totals, payments and balances use this contract; calculated components round half-up to INR 0.01. Internal average-cost/intermediate precision may exceed two places but exposed monetary amounts round to two. Percent inputs use basis points. Quantities retain up to 3 decimals. See RULES section B.
- Dates: ISO 8601 UTC in API; UI shows Asia/Kolkata `DD/MM/YYYY`.

### 2.2 Branch context
Requests touching branch data send `X-Branch-Id`. Middleware `ResolveBranch` checks the user has access (`has_all_branches` or `branch_user` pivot, branch active) and sets the current branch. Report endpoints accept `branch_id=all` for users with several branches (results limited to allowed branches).

### 2.3 Code layout
```
app/Http/Controllers/Api/V1, Http/Requests, Http/Resources, Policies
app/Services (StockService, SaleService, PurchaseService, PaymentService, GstService,
              Money, DocumentNumberService, TransferService, AdjustmentService,
              CustomerLedgerService, ReportService)
app/Console/Commands (StockReconcile, LedgerVerify, BackupDatabase)
database/migrations, seeders, factories Â· tests/Feature, tests/Unit
```

### 2.4 Validation
Qty > 0 with â‰¤ 3 decimals (whole numbers if unit `allow_decimal=false`); phone 10 digits (optional +91, store digits); GSTIN regex `^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$` and first two digits equal `state_code`; HSN 4/6/8 digits; state code from the seeded GST list; no duplicate `product_id` lines in one document (422).

### 2.5 Audit
Log create/update/cancel/reverse on products, prices, customers, suppliers, sales, purchases, payments, transfers, adjustments, users, roles, settings (before/after JSON).

---

## 3. Roles and permissions

| Permission | Owner | Manager | Cashier |
|---|:-:|:-:|:-:|
| branches.manage, settings.manage | âœ” | | |
| users.manage | âœ” | âœ” (cashiers, own branches) | |
| products.view | âœ” | âœ” | âœ” |
| products.manage, prices.manage | âœ” | âœ” | |
| customers.view | âœ” | âœ” | âœ” |
| customers.manage | âœ” | âœ” | create only |
| suppliers.manage, purchases.manage | âœ” | âœ” | |
| sales.create | âœ” | âœ” | âœ” |
| sales.discount | âœ” | âœ” | âœ” up to `max_cashier_discount_bp` |
| sales.override_price, sales.override_credit, sales.override_place_of_supply | âœ” | âœ” | |
| sales.cancel, sales.return | âœ” | âœ” | |
| payments.receive | âœ” | âœ” | âœ” |
| payments.pay_supplier, payments.reverse | âœ” | âœ” | |
| stock.view | âœ” | âœ” | âœ” |
| stock.adjust, stock.transfer | âœ” | âœ” | |
| reports.view | âœ” | âœ” (own branches) | own daily summary |
| audit.view | âœ” | | |

Settings: `gst_registration_type` (default `regular` â€” confirm), `price_inclusive_default` (true), `max_cashier_discount_bp` (500 = 5%), `allow_negative_stock` (true by default for sales during gradual onboarding; owner can disable; see RULES sections C and Q), `manager_cancel_window_days` (0 = same day only; owner unlimited), `hsn_required` (false), `receipt_width_mm` (80).

---

## 4. Modules

Format: purpose â†’ endpoints â†’ rules â†’ UI â†’ acceptance.

### 4.1 Authentication & users
- `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` (user, allowed branches, permissions).
- `users` CRUD, `PATCH /users/{id}/active`, `POST /users/{id}/reset-password`, branch assignment through `branch_user`.
- Email unique; password â‰¥ 8; throttle 5 attempts/min per email+IP; deactivation revokes tokens; manager can create/edit only cashiers and only assign branches they themselves have.
- UI: login, users list, user form (role, branches, default branch, active).
- Acceptance: a cashier of branch A requesting branch B data gets 404/403; endpoint Ã— role Ã— branch matrix test passes.

### 4.2 Branches & settings
- `branches` CRUD (owner), `PATCH /branches/{id}/active`; code 2â€“3 chars unique, immutable after first document; creating a branch creates balance rows for all products. Owner configures document series before billing; missing allocations block issuance.
- `GET/PUT /settings` (owner); logo upload png/jpg â‰¤ 1 MB.
- Changing `gst_registration_type` affects only new documents; show a warning in the UI.

### 4.3 Catalogue

The first shop sells books, bookstore accessories and study accessories. Configure categories, units and tax references for its catalogue. Broader retail, electronics and clothing are parked. Optional expiry is a separate extension. When enabled for a product, expiry belongs to individual batches and must follow those batches through receipts, issues and returns.
- `categories`, `units` CRUD; `tax-rates` (owner; `rate_bp` 0â€“10000).
- `GET /products` filters: `search`, `category_id`, `active`, `low_stock=1`, `with_stock=1`. `GET /products/lookup?code=` (barcode/SKU exact) returns product, prices, stock at current branch.
- `POST/PUT /products`, `PATCH /products/{id}/active`, `DELETE` (soft; 409 `PRODUCT_HAS_HISTORY` if any ledger rows).
- Fields: name, sku (auto `P000001` if blank), barcode, category, unit, tax rate, hsn, `tax_inclusive`, retail/wholesale/purchase price, reorder level, up to 5 slabs, optional existing stock for the current branch (current-date adjustment; unit cost required for additions).
- Creating a product creates `stock_balances` rows for every active branch.
- CSV import `POST /products/import?dry_run=1` columns `name,sku,barcode,category,unit,gst_rate,hsn,retail_price,wholesale_price,purchase_price,reorder_level,opening_qty` (prices in rupees â†’ validated decimal strings, `gst_rate` percent â†’ bp). Commit only with zero row errors; max 2,000 rows.
- UI: list (search, filters, low-stock badge), form, import page.
- Acceptance: slab `{min_qty:"10.000", price:"90.00"}` applies at qty 10, not at 9.

### 4.4 Suppliers & purchases
- `suppliers` CRUD; `GET /suppliers/{id}/ledger`.
- `GET/POST /purchases`, `GET /purchases/{id}`; owner-only POST /purchases/{id}/corrections/preview, POST /purchases/{id}/revisions, DELETE /purchases/{id} (soft delete, reason and expected_revision required), GET /purchases/{id}/history. Legacy POST /purchases/{id}/cancel uses the same owner-only soft-delete transaction; it is not a separate strict cancellation workflow.
- Payload: `supplier_id, supplier_invoice_no, supplier_invoice_date, cost_includes_tax, items[{product_id, qty, unit_cost, rate_bp?}], discount_amount, payments[{mode, amount, reference}]`. `rate_bp` defaults to the product's tax rate.
- Transaction: compute per RULES Â§B, write purchase + items + snapshots, ledger `purchase` rows, update moving-average cost and `products.purchase_price`, write payments (`out`/supplier, allocated) if supplied, audit.
- Duplicate (`supplier_id`, `supplier_invoice_no`) â†’ 409 `DUPLICATE_SUPPLIER_INVOICE`.
- Owner can edit or soft-delete old purchases with mandatory reason and full version history; later product movement alone does not block it. See RULES section D.4 and U. Supplier returns use POST /purchases/{id}/returns and GET /purchase-returns/{id}; quantities are bounded by received minus previously returned quantities.
- UI: entry grid (scan/search, qty, cost, tax), totals panel, list, supplier ledger with "Pay".

### 4.5 Customers & credit ledger
- `customers` CRUD with `search` (name/phone); `GET /customers/{id}/ledger` (opening dues/credits, sales, credit notes, payments, running balance); `GET /customers/outstanding`.
- Phone unique among active customers (nullable). Walk-in customer is read-only (`is_system`) and cannot have credit.
- Outstanding computed per SCHEMA Â§8.
- UI: list, form, quick-add modal inside POS, ledger page with "Receive payment".

### 4.6 POS / Sales (core)
**Endpoints:** `POST /sales/calculate` (stateless preview, same calculator as create), `POST /sales`, `GET /sales`, `GET /sales/{id}`, `GET /sales/held`, `POST /sales/{id}/cancel`, `POST /sales/{id}/returns`, `GET /sales/{id}/invoice`, `GET /sale-returns/{id}/invoice`, `DELETE /sales/{id}` (discard held draft only; retain its record/history).

**Create payload**
```json
{
  "sale_type": "retail",
  "customer_id": 1,
  "status": "completed",
  "held_sale_id": null,
  "acknowledged_pricing_fingerprint": "<server SHA-256 from last confirmed preview>",
  "place_of_supply_override": null,
  "items": [
    { "product_id": 12, "qty": "2.000", "unit_price": null,
      "discount": { "type": "percent", "value_bp": 500 } }
  ],
  "bill_discount": { "type": "amount", "value": "0.00" },
  "payments": [
    { "mode": "cash", "amount": "200.00" },
    { "mode": "upi",  "amount": "50.00", "reference": "UPI123" }
  ],
  "note": ""
}
```
- `discount.type` is `percent` (`value_bp`) or `amount` (`value` as a rupee decimal string). `unit_price: null` â†’ server picks price (retail, wholesale, or highest matching slab by line qty). Non-null requires `sales.override_price`; `price_source` recorded.
- Payments are only what was received now; any unpaid remainder is credit (customer must not be Walk-in; limit check â†’ 409 `CREDIT_LIMIT_EXCEEDED` unless `sales.override_credit`). Payments sum may not exceed total (cash change is a UI concern).
- `status: "held"` saves a draft with no invoice number, no stock effect, no payments. Completing a held bill: send held_sale_id; lock that existing sale row and transition held to completed in the same transaction. Retain the same sale ID, held pricing snapshot and history; assign invoice number only at completion. Never delete/recreate the held record.
- Client-sent totals/tax are ignored.

**Server flow for `completed` (one transaction, lock order per RULES Â§C.6)**
1. Validate; load products, prices, tax rates; resolve customer, place of supply, document type from settings.
2. Calculate per RULES Â§B (R1â€“R9) and Â§F.
3. Lock stock balances in global order; enforce availability when negative stock is disabled, otherwise capture shortage estimates and warnings.
4. Lock eligible document_series rows; allocate the next number from the authenticated channel's active series, rolling over only within its allocated series (RULES Â§F).
5. For a new sale insert sale/items/snapshots; for held completion finalize the same locked sales row, update its existing item projections and retain original held/cart snapshots in history. Never insert a second sale for held_sale_id.
6. Write ledger `sale` rows (unit_cost = current moving average); update balances.
7. `PaymentService`: write `in`/customer payments with allocations; set `paid_amount`, `balance_amount`.
8. Audit log; commit. Any failure rolls back everything including the number.

**Cancel** (`sales.cancel`; manager only within `manager_cancel_window_days`): reason required; writes `reversal` ledger rows; if payments exist, require `refund_mode` (`cash|upi|card|bank|credit_adjust`) and create the refund; status `cancelled`, number kept.

**Return** (`sales.return`): choose sale items + qty (â‰¤ sold âˆ’ already returned); computes per R10; creates credit note (own configured prefix and counter via document_series), `sale_return` ledger rows at the original `unit_cost`; settlement: part `credit_adjusted` against the sale balance/customer balance, remainder refunded as `out` payment with mode.

**POS screen (phase 7)**
- Top: search/scan box always focused; Enter on a barcode adds/increments the line; search results show name, price, stock.
- Centre: cart (item, qty, price, discount, line total, remove); every change uses the shared sale calculator through a debounced Livewire action (the API exposes the same operation as `/sales/calculate`) so shown amounts are the server's.
- Right: totals (subtotal, discount, taxable, CGST/SGST/IGST, round-off, total), customer picker (default Walk-in), Retail/Wholesale toggle (re-prices through calculate), payment panel with split modes and "balance on credit", buttons Hold / Clear / Pay & Print.
- Shortcuts: F2 search, F4 customer, F8 hold, F9 pay, Esc close dialog. Out-of-stock lines show a warning; sales may proceed when negative stock is enabled. Estimated cost is captured and flagged. When disabled, availability checks block overselling.
- After success: show invoice, print thermal receipt, New sale.

**Acceptance examples**
- `regular`, intra-state, 2 Ã— â‚¹118 inclusive at 18%: taxable 200.00, CGST 18.00, SGST 18.00, total 236.00.
- Same amounts with an independently established interstate place of supply: IGST 36.00. Customer GSTIN alone does not determine place of supply.
- `composition`: bill of supply and no separately collected GST. `unregistered`: ordinary commercial bill and no separately collected GST. Regular registration can also require a bill of supply for exempt supplies; mixed-taxability billing needs accountant-approved handling before launch.
- Last unit sold simultaneously: with negative stock disabled one succeeds and one gets INSUFFICIENT_STOCK; with it enabled both may succeed, with serialized ledger movements and estimated-cost flags as applicable.
- Editing the product name/price/GST rate, customer GSTIN or shop address afterwards leaves the old invoice JSON unchanged.

### 4.7 Payments
- `POST /payments` {direction, party_type, party_id, mode, amount, reference, paid_at, allocations?[{document_type, document_id, amount}]}; if `allocations` omitted for a customer receipt, auto-allocate to the oldest open sales (FIFO); remainder stays unallocated.
- `GET /payments` (filters: party, date, mode, status), `GET /payments/{id}`.
- `POST /payments/{id}/reverse` {reason} (`payments.reverse`): creates the reversing row and negative allocations, marks original `reversed`.
- Rules: allocation â‰¤ document balance (409 `OVERPAYMENT`); cannot reverse an already reversed payment; `amount > 0`.
- UI: "Receive payment" on customer ledger, "Pay supplier" on supplier ledger, payments list with reverse action.

### 4.8 Stock operations
- `GET /stock`, `GET /stock/ledger?product_id=`.
- Adjustments: `POST /stock/adjustments` {reason, note, items[{product_id, qty_change, unit_cost}]}, `GET /stock/adjustments`. Owner/admin can set an absolute physical stock count or post a signed quantity adjustment, always with a reason and ledger history. No direct editing of balances or historical ledger rows. Posted adjustments are immutable; correct with a new one. `POST /stock/count` takes physical counts and creates a `count_correction` adjustment of the differences.
- Transfers: `POST /stock/transfers` (draft), `PUT` (draft only), `DELETE` (draft only), `POST /stock/transfers/{id}/dispatch`, `POST /stock/transfers/{id}/receive` {items[{id, qty_received}]}, `GET /stock/transfers`, `GET /stock/transfers/discrepancies`, `POST /stock/transfer-items/{id}/resolve` {resolution: `written_off|recovered`, qty?, note}.
- Rules per RULES Â§I (who dispatches/receives, `OVER_RECEIVE`, discrepancy handling, no ledger row for shortfall).
- UI: stock list, adjustment form, transfer create/dispatch/receive screens showing who/when, discrepancies list with resolve actions, ledger viewer.

### 4.9 Reports
All reports: date range, branch filter, JSON endpoint under `/reports/â€¦`, `?format=xlsx` export on demand. SQL aggregation on stored values only.

| Report | Endpoint | Columns |
|---|---|---|
| Daily sales | `/reports/daily-sales` | date, bills, gross, discount, taxable, tax, round-off, net, collected by mode, credit |
| Sales by product | `/reports/sales-by-product` | product, qty, taxable, tax, total, cost, profit |
| Sales by cashier | `/reports/sales-by-cashier` | cashier, bills, total |
| Stock valuation | `/reports/stock-valuation` | product, qty, avg cost, value |
| Low stock | `/reports/low-stock` | product, qty, reorder level |
| Customer outstanding | `/reports/customer-outstanding` | customer, phone, balance, last payment |
| Supplier outstanding | `/reports/supplier-outstanding` | supplier, balance |
| Payments | `/reports/payments` | date, party, direction, mode, amount, status |
| Transfer discrepancies | `/reports/transfer-discrepancies` | transfer, product, sent, received, shortfall, value, status, resolved by |
| GST sales (GSTR-1 style export) | `/reports/gst-sales` | invoice/credit-note no, date, customer, GSTIN, place of supply, HSN, rate, taxable, CGST, SGST, IGST, total; B2B and B2C sections; credit notes shown negative |
| Documents issued by series | `/reports/documents-issued` | registration/GSTIN, branch, financial year, document type, series, channel, first number, last number, total issued, cancelled, net issued, gap exceptions; filter by reporting period |
| GST purchases | `/reports/gst-purchases` | supplier invoice, GSTIN, taxable, tax split |
| Profit | `/reports/profit` | period, net sales taxable, cost, gross profit (sales âˆ’ returns) |

Cashiers see only their own daily summary. Export â‰¤ 50,000 rows, chunked.

### 4.10 Dashboard & audit
- `GET /dashboard`: today's sales and bills, collections by mode, low-stock count, top 5 products (7 days), receivables, pending transfers and open discrepancies. Cached 60 s per branch.
- `GET /audit-logs` (owner), filters user/entity/date, read-only.

---

## 5. Invoice JSON contract

`GET /sales/{id}/invoice` reads **only stored values and snapshots**.
```json
{
  "data": {
    "document_type": "tax_invoice",
    "title": "TAX INVOICE",
    "invoice_no": "MN1/26-27/A00123",
    "date": "2026-11-04T08:30:00Z",
    "status": "completed",
    "sale_type": "retail",
    "shop": { "name": "", "legal_name": "", "logo_url": "", "footer": "" },
    "branch": { "name": "", "address": "", "phone": "", "gstin": "", "state_code": "36", "state_name": "Telangana" },
    "customer": { "name": "", "phone": "", "gstin": null, "address": "", "state_code": "36", "state_name": "Telangana" },
    "place_of_supply": { "code": "36", "name": "Telangana" },
    "is_interstate": false,
    "items": [
      { "sr": 1, "name": "", "sku": "", "hsn": "", "qty": "2.000", "unit": "pcs",
        "unit_price": "118.00", "price_includes_tax": true,
        "line_discount": "0.00", "bill_discount_share": "0.00",
        "taxable": "200.00", "rate_bp": 1800, "cgst": "18.00", "sgst": "18.00", "igst": "0.00",
        "line_total": "236.00" }
    ],
    "tax_summary": [ { "hsn": "", "rate_bp": 1800, "taxable": "200.00", "cgst": "18.00", "sgst": "18.00", "igst": "0.00" } ],
    "totals": { "subtotal": "200.00", "discount": "0.00", "taxable": "200.00",
                "cgst": "18.00", "sgst": "18.00", "igst": "0.00", "round_off": "0.00",
                "total": "236.00", "total_in_words": "Two Hundred Thirty Six Rupees Only" },
    "payments": [ { "mode": "cash", "amount": "236.00", "paid_at": "2026-11-04T08:30:00Z" } ],
    "paid": "236.00", "balance": "0.00",
    "cashier": "Name",
    "notice": null
  }
}
```
- Bill of Supply: `title` = "BILL OF SUPPLY", tax fields 0 and hidden, `notice` carries the composition statement when applicable.
- Cancelled: `status: "cancelled"`; the UI stamps CANCELLED.
- Credit note: same shape plus `original_invoice_no`, `original_invoice_date`, `reason`, `refund` and `credit_adjusted` amounts.
- `payments` reflects payments allocated to the invoice (later collections included).

**Client rendering:** thermal = one HTML template with `@page { size: 80mm auto; margin: 2mm }` (58 mm variant by setting) printed via `window.print()`; A4 = `pdfmake` definition built from this JSON (header, party block, items with HSN/rate, tax summary, totals, words, signature). The server never builds PDFs.

---

## 6. Build phases (release-aware dependency order)

A phase or release slice is done only when its tests pass and its manual check works. Stop after each reviewable milestone. Follow the two-release sequence in MILESTONES.md: Release 1 includes core trading/basic reports and required screen slices; Release 2 includes supplier returns, transfers and advanced reports/exports/audit viewer. Backend precedes its corresponding screen. Both releases require scoped hardening, backup/restore, deployment and acceptance checks; later phase numbers do not force deferred R2 features before R1 go-live.

- **Phase 0 Foundation:** app skeleton, tenant context, device registration/token binding, Sanctum, permissions, response/error format, `ResolveBranch`, audit helper, `Money` helper with property tests, seeders. Check: `migrate:fresh --seed` and owner login work.
- **Phase 1 Users, branches, settings:** 4.1, 4.2. Tests: branch pivot access, role limits, throttle.
- **Phase 2 Catalogue:** 4.3 with slabs, CSV import, balance-row creation. Tests: slabs, uniqueness, import dry-run, deletion block.
- **Phase 3 Stock core, suppliers, purchases:** `StockService` (locking, ledger, reversal, moving average), `stock:reconcile`, 4.4, stock list/ledger/adjustments/count from 4.8, `PaymentService` basics for supplier payments. Tests: ledger = balance, negative-stock rule, owner-only purchase revisions/soft deletion, complete history, correction rollback and ledger immutability.
- **Phase 4 Customers, sales, payments, returns:** `GstService`, `DocumentNumberService`, `SaleService`, 4.5, 4.6 (API), 4.7, invoice JSON, `ledger:verify`. Tests: RULES Â§K (GST matrix, concurrency, rollback, snapshots, reversals).
- **Phase 5 Transfers:** rest of 4.8 including discrepancies. Tests per RULES Â§I.
- **Phase 6 Reports & dashboard:** 4.9, 4.10, xlsx. Tests: report totals equal raw sums, branch isolation.
- **Phase 7 Web UI:** Livewire + Blade layout/login â†’ products â†’ purchases â†’ POS â†’ customers/payments â†’ stock/transfers â†’ reports/dashboard â†’ settings/users. Each screen has loading, empty and error states, API validation messages, and works from 360 px width (POS usable on tablet landscape).
- **Phase 8 Hardening:** backup command + schedule (retain 14 days), rate limits, EXPLAIN on heavy reports, demo-data seeder, README with deployment for shared hosting (document root `public/`, `.env`, `php artisan migrate --force`, cron `* * * * * php artisan schedule:run`) and VPS (Nginx + PHP-FPM + MySQL), accountant sign-off checklist for GST rules.

---

## 7. Non-functional targets
- Sale `POST` < 300 ms for a 10-line cart on a basic VPS; product search < 200 ms with 20,000 products.
- No N+1 queries (tests on list endpoints); no heavy work in request cycle; no queue required for MVP.
- UTC storage; financial year (1 April) computed in Asia/Kolkata.
- English UI and code.

## 8. Glossary
Money quantum (INR 0.01; one paisa) Â· basis points (1% = 100 bp) Â· HSN (goods classification code) Â· GSTIN (15-char GST id) Â· CGST/SGST/IGST (same-state vs inter-state GST) Â· Tax Invoice vs Bill of Supply Â· Credit note (document for a sales return) Â· Udhaar (sale on credit) Â· Held bill (saved cart, no number, no stock effect) Â· Moving weighted average cost Â· Reversal (new opposite record that cancels an earlier one).

## 9. Draft decisions and corrections

- This file preserves the supplied scope, including slabs and exports. It is broader than the smallest MVP; no application code is authorized in this stage.
- RULES and SCHEMA define the ledger, two-decimal money, rounding and relations missing from the supplied text.
- Browser Sanctum authentication uses secure session cookies with CSRF protection; future external clients may use tokens. API-first does not require stateless browser authentication.
- Same-GSTIN branch transfers only in this draft. Different-GSTIN transfers require taxable supply documents and an expanded design before implementation.
- No special GST cess, reverse charge, export/SEZ, mixed taxable/exempt baskets or regulated goods workflows until specified. Product type, registrations, GST policy remains a review decision; dated opening dues and historical financial entries are included.
- All posting endpoints need idempotency keys; retries must not duplicate sales, payments or stock.
- Invoice format: `MN1/26-27/A00001` (16 characters). Configurable channel-owned series and rollover follow RULES Â§F; letter allocations are not hard-coded.
- Invoice financial snapshots are immutable. Current payment allocations are a separate live settlement section, not part of the immutable invoice snapshot.
- Tax compliance and statutory rounding must be reviewed before launch; two-decimal storage is a precision choice, not a tax reporting rule.



## 10. Configurable document series

- Owner-only `GET/POST /document-series`, `PATCH /document-series/{id}` for unused allocation/configuration; activate/close actions are audited. Never expose a counter-edit API. Closed/exhausted or previously used series cannot be reset or reassigned.
- Invoice format `{branch}/{YY-YY}/{letter}{00001}`. Branch codes 2â€“3 uppercase alphanumeric characters, immutable after first issue. Invoice letters exclude I/O. Five digits allow 99,999 issues per letter per branch/FY. Web letters A/B/C, Windows Dâ€“H and mobile P/Q/R are illustrative only; save allocations as data. Only web issuance exists now; future-channel reservations issue no documents.
- Credit-note format `{branch}/{YY-YY}/{letter}{00001}`. Initially reserve X/Y/Z as configurable credit-note allocations, not hard-coded letters. Each supports 99,999 notes. Rollover X99999 to Y00001, then Y99999 to Z00001. After Z99999, owner must allocate another unused letter to this credit-note channel before issuance continues. Never consume another invoice/channel reservation.
- Current financial year on 4 November 2026: 26-27. 27-28 starts 1 April 2027. New-year series must be provisioned explicitly; do not silently reset old rows or backdate issuance.
- Server infers channel from trusted authentication/client configuration; a request cannot claim another channel. Exhaustion moves to the next eligible allocation in configured rollover order, with counter starting at 1. No allocation remaining: block issuance with DOCUMENT_SERIES_EXHAUSTED; owner may allocate unused series. No wrap/reuse.
- Number consumed only by a committed issued/cancelled document. Failed online posting rolls back number allocation; held carts and previews have no number. An already issued document abandoned later is retained as cancelled. Draft failures are not fake cancelled invoices.
- Documents-issued report groups committed records by registration/branch/FY/document type/series/channel for the selected local-date period, including first/last, total, cancelled and net. Unused reserved ranges are not issued documents. Show unexplained missing serials separately rather than fabricate cancellation records.
- Future offline design only: exclusive per-device series leases within a channel, local atomic numbering and durable cancelled records, synchronization deduplication, financial-year/time validation, and an incident procedure for device loss/unsynced records. Lost records cannot be promised gap-free or assumed cancelled. No offline implementation in this release.
- Future checks: boundary 99999â†’next owned letter, unrelated channel reservation untouched, no allocation available, credit-note boundary 99999 and X/Y/Z rollover, excluded letters, 16-character validation, new-year provisioning, simultaneous rollover, cancelled-number retention and series-report counts.


## 11. Optional invoice comment

- Sales invoices can include an optional plain-text comment (existing API `note` field), up to 1,000 characters. Example: "Deliver after 5 PM". No HTML or rich text.
- POS provides an optional Comment field. Print preview provides an Include comment checkbox (`include_comment`, default true when a comment exists), configurable separately for every print/reprint and A4 PDF export. This is a browser rendering option, not an invoice field. Empty comments occupy no receipt space; multiline comments wrap on thermal and A4 output.
- Cashiers/managers with sales.create may enter the comment before finalization. Only the owner may edit/remove it after finalization via PATCH /sales/{id}/comment; require reason and keep before/after, actor and timestamp in audit history. This changes no stock, tax, payments or document number.
- Persist only note with the sale and include it in authorized invoice JSON. Thermal/A4 renderers use the current print-preview include_comment choice. Unchecking it omits the comment from that receipt/PDF without editing the saved invoice. Customer-facing output contains the comment only when selected for inclusion; the authenticated staff API still returns the saved note.
- Invoice detail page shows the comment and its audited edit history. Reprints offer the inclusion choice again and use the current saved comment if included; earlier values remain in history. Changing the print choice requires no owner edit permission and changes no saved invoice data. Note is an explicit editable presentation field outside immutable financial/identity snapshots.
- Acceptance: blank comment, 1,000-character limit, line wrapping, comment included/omitted per print with saved note unchanged, unauthorized post-finalization edit rejected, owner edit audited, financial values unchanged.


## 12. Gradual onboarding and historical financial entries

- No mandatory onboarding completion date or setup lock. Start billing, add products, customers and suppliers individually, and continue entering old financial records later. Stock movement dates follow section 13, not historical financial dates.
- Add customer/supplier branch-account status: Opening balance not entered, Entered, or Confirmed zero. Not entered does not mean zero and never blocks an entry or billing. Show balance completeness warnings in ledgers, outstanding reports and credit checks; calculated credit limits still use recorded balances only.
- Dated opening entries support customer dues, supplier dues and advances/credits, with effective date, amount, reference/note, actual entry timestamp and actor. Separate effective date from immutable created_at. Example: application first used 10 November; record an opening due effective 9 November afterwards. No fabricated old invoice, new tax invoice number, stock movement or GST liability.
- POST /party-opening-entries, GET /party-opening-entries and POST /party-opening-entries/{id}/reverse; GET/PATCH /party-accounts/{id}/opening-status. Owner-only opening entry creation/correction and historical financial posting in v1; staff may continue creating permitted master records and ordinary transactions. Corrections are append-only reversal/replacement with reason, never overwritten balances. Owner can record a zero confirmation explicitly.
- POST /payments accepts historical paid_at for old receipts, supplier payments and advances, with historical_entry flag and reason. Created_at remains actual server entry time. Normal payments cannot be future-dated. Advance payments need no invoice and can be allocated later. Auto-allocation never targets an invoice issued after the payment's effective time; explicit later application has its own effective_at.
- Payments may allocate to dated opening dues as well as actual invoices. FIFO considers eligible opening dues and invoice debts together. Backdated entries refresh balances and running ledgers in effective-time order, without rewriting actual creation history. Past-period reports can change when late records are entered; support recorded_as_of cut-off for reproducible exports.
- Prevent double counting: when entering an older receipt/advance already included in a recorded opening balance, explicitly link it as included_in_opening evidence (no second balance impact). Otherwise post it as a new financial effect, or revise the opening amount through a linked audited correction. Never automatically add the same amount both as opening debt and an invented historical bill. Preview balance effect before posting.
- Include opening entries/status, allocations, reversals, historical-payment cases and reconciliation in phases 3–4. Opening due allocation, advance utilization and completeness markers appear in web customer/supplier ledgers in phase 7. Exports distinguish historical entry time from effective time.

## 13. Current-date stock onboarding and price history

- All stock movements post at actual server time in v1. Supplier invoice_date may be historical, but receipt/stock date is now. Historical financial entries never backdate stock. Existing stock added after sales is a current-date correction, not a historical receipt.
- Positive stock corrections require explicit unit cost. Physical count action computes count minus locked current quantity. Ledger records reason, actor, before/after quantities, unit cost, value movement and any valuation variance. Posted corrections are immutable.
- Sales below zero are allowed when enabled; shortage portions use estimated cost and are marked cost_estimated. Earlier sales and their cost flags never change when stock is entered later. Reports expose estimated-cost quantities and provisional gross profit.
- Example: sell 3 rice packs on 2 December without known stock/cost: qty becomes -3, sale cost estimated. On 5 December enter physical count 47 at INR 40 each: current-date qty change +50; closing qty 47 and inventory value INR 1,880. Prior invoices remain unchanged. Store any variance needed to reconcile previous provisional stock value separately from original sale cost.
- Product selling/wholesale prices, slabs, tax rate, name and cost changes affect only newly posted documents. Each sale/purchase/return preserves its own snapshots; returns use original line price/tax/cost. Backdated supplier documents entered now also retain explicitly approved recorded values, never dynamically read future product values.
- Held carts are repriced at completion. If the current price/tax/slab differs from the held snapshot or last confirmed preview, show the changes and require confirmation before payment; server rejects stale pricing acknowledgement with PRICE_CHANGED.
- Audit every product price/slab/tax change (actor, time, old/new values) and display price history on the product page. Stock quantities do not depend on selling prices. Future scheduled pricing is out of scope.


## 14. Tenant/device authentication and application roadmap

- Current delivery: Laravel 13 website with Blade + Livewire 4, Alpine and Tailwind, plus versioned JSON API. Both web components and API use the same validated business services, authorization, idempotency and transactions. API-first means reusable contracts; Livewire responses are not required to be JSON API resources.
- Include device registration and tenant/device-bound Sanctum bearer authentication in foundation now. A tenant is the shop business, not a branch. Seed one tenant per installation; SaaS onboarding, billing and multiple active businesses remain outside scope.
- Owner registers/approves a device: tenant, name, platform/channel, allowed branches and active/revoked status. GET/POST /devices, POST /devices/{id}/revoke (owner); POST /auth/device-token authenticates a user against an approved device and issues a finite-lifetime token. No open device registration or token minting by device ID alone. Default token lifetime 30 days; authenticated reissue required, no custom refresh-token system in v1.
- Each bearer token stores tenant_id, device_id, user owner, abilities and expiry. Every request resolves context from that authenticated binding and checks active tenant/device/user, device branch grants and current user roles/branches. X-Branch-Id selects only within this intersection; caller-supplied tenant/device headers cannot change the binding. Revocation disables all device tokens immediately. Logout deletes current token; user deactivation revokes all tokens. Audit enrollment, token issue/revocation and branch/device changes; never log token plaintext.
- Website uses secure Laravel session cookies and CSRF, not bearer tokens in browser storage. Sessions also record tenant_id and device_id; an approved browser workstation can be enrolled by owner at login. Every Livewire action rechecks session binding/device status and current access. Tokens and sessions never bypass policies.
- Device ID binding is server-side authorization metadata, not hardware attestation: a copied bearer token is still a bearer credential. Hardware-backed signing/proof-of-possession is a separate future design if needed.
- Later, separate delivery phase: desktop and mobile applications consume /api/v1 and invoice JSON. Desktop has its own ESC/POS renderer/printer adapter with printer configuration, text encoding, widths, QR/barcode and retry/reprint handling. Browser thermal HTML printing is not the desktop renderer. Reprinting never creates another sale or document number.
- After online web/API, desktop and mobile are implemented and validated, revisit offline sync as its own project. Offline is currently out of scope: no local sales DB, offline document issuance, sync endpoints, conflict resolution, device sequence leasing or replay engine in this version. Preserve existing series/device provenance as preparation only.
- Foundation checks: mismatched token tenant/device/user denied; revoked device blocked for token and Livewire session; device/user branch intersection; expired token; no token privilege escalation; session CSRF; tenant-bound idempotency; browser registration without open token issuance. Verify Livewire/package compatibility at implementation.

## 15. Held pricing fields and completion contract

- sales stores held_pricing_snapshot JSON nullable and held_pricing_fingerprint CHAR(64) nullable. sale_items stores pricing_snapshot JSON and pricing_fingerprint CHAR(64). At hold time store the canonical server-resolved prices/slabs/tax/discount basis, not just product IDs; held values remain drafts, never issued invoice economics.
- /sales/calculate and the equivalent Livewire action return pricing_fingerprint for the entire cart. Client submits acknowledged_pricing_fingerprint on completion. Compare the current server-resolved fingerprint with the acknowledged one before taking payment; PRICE_CHANGED returns a fresh preview for explicit confirmation.
- If completing a held bill whose saved fingerprint differs from current values, show the changed prices/taxes/slabs before payment. Acknowledgement uses the current fingerprint. Even after acknowledgement, a concurrent change causes PRICE_CHANGED and another preview; no number/stock/payment is consumed.


## 16. Number source for every issued document

- Complete mapping is in SCHEMA section 14. Sale invoices, credit notes, purchase returns and stock transfers all use DocumentNumberService with document_series and issued_documents; no unexplained document_no/transfer_no fields remain.
- Add enabled series types purchase_return and stock_transfer. Purchase return number is allocated at posting from its purchase branch and current issue-date financial year. Transfer number is allocated once at dispatch from source branch and dispatch-date financial year; receiver retains it. Draft transfers have no number. Supplier return posting/transfer dispatch rolls back number allocation if posting fails.
- All types use configurable one-letter, five-digit series, exclusive per tenant/branch/FY/type/channel. No fixed PR/DN/TR prefixes. Owner assigns unused letters; X/Y/Z stay reserved for credit notes. Rollover never crosses type or channel. If an allocation is absent/exhausted, block only that action with the existing series error code.
- debit_note is a reserved future series type, not an implemented feature. No creation endpoint, number reservation for an issued note, or activated debit-note series until its backing schema, accounting/GST rules and reversal workflow are specified. Existing internal purchase returns are supported independently and must not be double-posted as a second debit note.
- Purchases keep the supplier's invoice number as external reference; supplier credit/debit note acknowledgements can be stored with purchase returns independently of our internal return number.
- Documents-issued report includes all numbered document types for internal reconciliation, with a document_type filter. GST-oriented output uses an explicit approved mapping; do not classify stock transfers or internal supplier-return references as outward sales documents automatically.
- Future verification: every enabled number field has one allocator and registry link; correct branch and issue FY; transfer receipt does not renumber; atomic rollback/idempotent posting; cross-type prefix collision rejected; disabled debit_note cannot issue; report categories remain distinct.


## 17. Owner purchase correction and history (agreed option A)

- Only owner can edit or soft-delete a posted/old purchase, regardless of age. A later sale, transfer or other movement of its product is not by itself a prohibition. Managers may enter purchases and supplier returns but cannot edit/delete posted purchases.
- Delete removes the purchase from active lists/totals, not storage/history. Owner can view deleted purchases, every prior version, changed fields, actor, timestamp, reason and resulting stock/valuation/party/payment adjustments. No role, including owner, can erase this history through the application.
- Show current revision, correction preview and history on purchase detail. Every committed correction has an immutable before/after snapshot and revision number. Posting requires expected_revision, reason and idempotency key; stale version returns PURCHASE_REVISION_CONFLICT without effects.
- Financial corrections update supplier debt; quantity corrections post current-date stock deltas; valuation corrections are current-date ledger adjustments/variances. Old sale invoices, historical costs and stock ledger rows remain unchanged. No fake supplier return for a typing error.
- Metadata-only corrections have no stock/payment effect. Financial/quantity edits and soft deletion are atomic with revised purchase view, adjustment entries, allocations and audit. Preview shows every effect before owner confirms. Correcting purchase dates does not backdate stock.
- Resolve paid funds explicitly: retain supplier advance, reallocate eligible payments, or record an actual supplier refund. Do not invent a cash movement when deleting/editing an invoice. A supplier change must settle/detach allocations under the old supplier before reassignment; show this in preview. Validate existing supplier returns and their product/quantity links before reducing/removing a line.
- Invalid outcomes (stock-removing correction below zero, correction below already-returned quantity, incompatible consumed payments/credits) block with specific explanations. They are dependency checks, not the removed blanket later-movement restriction. Negative-stock sales permission does not enable owner stock-removing corrections below zero.
- Additional implementation checks: unauthorized edits/deletes rejected; correction after later sales succeeds when its outcomes are valid; original versions preserved; soft-deleted purchase readable in history; supplier balance/payment conservation; no duplicate effect under retry; unchanged historic sale COGS; stale revision rejected; negative-stock/dependency failure rolls everything back.

## 18. Held bill lifecycle: retain, finalize, or discard

- Saving a hold records held_at/held_by and its cart/pricing snapshots. Completion transitions that same sales.id from held to completed. completed_at/completed_by record who finalized it; original held snapshot remains available. No physical deletion or second sale creation.
- Two terminals completing the same hold: row lock permits one posting; retry with same idempotency key returns its result, another completion request gets DOCUMENT_FINALIZED. Failed completion leaves status held and consumes no invoice number/stock/payment.
- Clearing an unsaved cart has no persistence. Discarding a saved held bill transitions held to discarded, saving discarded_at/discarded_by/reason; it remains in history with no invoice number, debt, payments or stock effect. DELETE /sales/{id} means this logical discard only and never deletes a completed sale. Held list returns status held only.
- Current finalized item projections may be recalculated; held_cart_snapshot and held_pricing_snapshot/fingerprint preserve what was saved before completion. Hold updates are audited. Tests verify stable sale ID, retained snapshots, no duplicate completion and rollback back to held.
