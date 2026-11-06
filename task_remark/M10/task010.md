# task010 — Physical counts and existing-stock entry

Added stock adjustments and physical counts with an idempotency key, an explicit reason, immutable evidence and current server posting time. Physical counts require the last ledger ID observed by the operator. A stale count returns a conflict. Stock additions require explicit two-decimal unit cost. A cost-only correction can change carrying value with zero quantity movement.

Product creation and CSV import can post opening stock atomically with catalogue changes. Use both opening_qty and opening_unit_cost. CSV opening rows also require explicit SKUs so reimports can detect previously posted opening stock. Opening quantity is a signed-ledger addition, while a count sets an absolute shelf quantity. Repeated opening entries for the same branch and product require a separate correction.

The purchase-cost examples cover recoverable and nonrecoverable GST in RULES §W. Actual registration, GSTINs and eligibility will be configured later.

See [test results](test-results.md).
