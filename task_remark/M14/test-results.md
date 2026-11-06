# M14 verification

5 November 2026, local Windows PHP 8.5 and dedicated MySQL `khatapos_test` database.

## Final results

- `php artisan test`: **44 passed, 531 assertions**, PHPUnit reported 385,386 ms.
- Targeted purchase/supplier run before the final additional cases: **12 passed, 215 assertions**.
- Pint `--test` on all ten changed PHP files: passed.
- `php artisan migrate --force` on the local development database: `2026_11_05_230000_link_purchase_corrections` applied.
- `php artisan stock:reconcile` on development: `Stock balances match the ledger.`
- `php artisan route:list --path=api/v1/purchases --except-vendor`: seven route entries, including preview, PATCH/DELETE, cancel alias and revisions.

## Verified behavior

- A 10 × ₹100 purchase, followed by a 4-unit issue, corrected to ₹110 with 6 units confirmed: quantity 6, stock value ₹660, signed variance -₹40. Original issue ledger row unchanged.
- A ₹1,000 purchase with ₹800 paid, reduced to ₹600: retained allocation ₹600, advance ₹200; only the original actual payment remains.
- Deleting that revised purchase removes 6 units, rather than reversing the original 10 again. Stock becomes zero, the original ₹800 remains an advance, and all three revisions and original line remain.
- Same-key retry has no duplicate effects; changed payload conflicts; a stale revision conflicts; stock or payment changes invalidate preview.
- Missing/excess remaining-unit confirmation and negative-stock removal are rejected before effects.
- Supplier reassignment preserves original payment ownership, transfers the full purchase payable, and leaves stock unchanged.
- Recorded/effective historical ledger cutoffs reproduce the prior supplier balance.
- A backdated advance cannot use the extra capacity created by a later purchase increase; the same amount can be applied at the current date when sufficient capacity exists.
- Metadata-only edits create no stock or payment entries; Manager and Cashier correction/history access is denied.
- Product substitution retains the removed original item and receipt ledger row and posts separate product effects.
- Incompatible consumed-allocation fixtures block correction.
- An injected exception after the final audit insert rolls back projection, stock, allocations, revision and idempotency completion together.
- Future supplier-return fixtures reject reducing quantity below returned units and changing supplier while returns remain linked.

The initial test fixture lacked the required `tax_inclusive` product field; this was corrected. The permission test needed Laravel's cached test auth guard refreshed between role changes; it now exercises the real current-role checks. Both issues were resolved before the final passing run.

## Limits

Browser purchase-screen testing is deferred to M31. Supplier returns are fixtures only until their M22 integration. Tests force stale versions/previews sequentially; this milestone does not claim a new simultaneous multi-process load test. Existing sale-issue services supply the historical-cost fixture; sales posting itself is a later milestone. No production deployment was performed.
