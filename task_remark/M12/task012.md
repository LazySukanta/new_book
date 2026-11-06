# task012 — Suppliers and dated accounts

Implemented supplier records, branch accounts, opening dues and advances, explicit zero confirmation, payment preview and historical payment evidence. Supplier payment, application and reversal endpoints sit below the supplier's API route and use branch scope and idempotency keys. Historical opening and payment creation requires the owner.

Supplier ledger supports effective and recorded cutoffs. Its opening-status metadata reports current setup status. Payment evidence already included in an opening amount has zero additional balance effect. FIFO allocates only eligible openings. A later explicit application must be unapplied before its payment can reverse. A backdated application respects allocation capacity across subsequent effective dates.

The M13 purchase integration will add invoice debt and invoice allocations to the supplier ledger. Customer endpoints are later milestones.

See [test results](test-results.md).
