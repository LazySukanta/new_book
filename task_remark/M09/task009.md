# task009 — Stock ledger and costing

Added transactional purchase-receipt and sale-issue primitives. They lock the branch, product and balance, append signed quantity and value movements, and update the balance projection. Sales preserve actual and estimated quantities and cost provenance. Receipts settle provisional value and record current variance. The read-only reconciliation command compares projections to ledger sums.

The internal service uses fixture source IDs until purchase and sale item tables land. The matching foreign keys and source-document validation belong with those integrations. Stock API reads check the resolved branch and hide valuation from users without reporting permission.

See [test results](test-results.md).
