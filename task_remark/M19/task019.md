# task019 — Completed sale posting

Completed sale posting uses the M17 preview calculator and M18 number allocator. The transaction locks branch, customer, product, tax, settings, stock and number records in the posting workflow. It recomputes prices and compares the acknowledged fingerprint before writing stock or cash. A stale preview returns HTTP 409 with `PRICE_CHANGED` and a fresh preview. Client totals and device-channel fields do not control posting.

A completed sale retains invoice, customer, branch, shop, product, tax and cost snapshots. The transaction also records the number, stock issues, receipts, allocations, customer debt and audit entry. Retries with an identical idempotency key and payload return the original response. A failure rolls back the effects and releases the uncommitted number.

Walk-in sales must be fully paid. Saved customers can buy on credit within the recorded limit across branches. The calculation sums positive branch exposures so one branch's unapplied credit cannot settle another branch's debt. A permitted override needs a reason. Incomplete opening data is flagged on posting.

The customer ledger includes sale totals and actual receipts. Receipt allocations and opening-credit applications can target `sale_id` in M16 settlement routes. They change the invoice balance without counting the same funds again in the party ledger. The invoice detail serves the stored snapshot with the current paid and outstanding amounts.

## Try it

Run `php artisan test --filter=CompletedSaleTest` from the application directory with MySQL running. It creates its own records in the dedicated test database. The scenarios include a cash sale, a wholesale sale paid partly by cash and UPI, invoice collection, opening credit, and two-terminal stock and credit races.

For a manual development test, use disposable records and a registered device with branch access.

1. Call `POST /api/v1/sales/calculate` with your cart. Save `data.pricing_fingerprint` and note `data.document_type`.
2. Use the numbering guide to provision the current year, type and channel if needed.
3. Send the same cart to `POST /api/v1/sales`. Add `acknowledged_pricing_fingerprint` with the saved value, `payments` with amounts and modes, and a new `Idempotency-Key` header. The cash amount must equal the preview total for a Walk-in cash sale.
4. Inspect `GET /api/v1/sales/{id}` and the stock ledger. Repeat the identical request with the same key and confirm the invoice number stays the same.
5. Preview another cart, change the selected product price, and submit with the old fingerprint and a fresh key. Expect `PRICE_CHANGED` and no invoice. Review the new preview before trying again.

The next milestone is **M20a — Retained held-bill lifecycle**. Held bills, printing, sale returns and cancellation are separate workflows.
