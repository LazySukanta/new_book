# Task 024 - M25 Release 1 operational reports

The authorized branch has JSON endpoints for daily, product and cashier sales, stock valuation and low stock, customer and supplier balances, payments, provisional gross profit and the dashboard. Cashiers can read their own daily summary.

Sales, returns and cancellations are signed on their event dates. The trading day uses Asia/Kolkata. Daily credit is the original credit at issue. Collections use signed actual customer payments independently of invoice allocations. Current balances include opening entries, current documents and actual payments. Stock valuation and party balances are current snapshots.

The dashboard caches for 60 seconds per tenant, branch, user, device and date range. Gross profit uses net taxable sales and recorded issue/return costs. Estimated COGS and valuation variance are separate.

See [test results](test-results.md). Next is M28, the shared website shell. The M25 transfer reporting slice belongs to Release 2.
