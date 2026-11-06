# task008 — Product CSV import

Added multipart `POST /api/v1/products/import` with a `file` field and optional `dry_run=1`. The API validates up to 2,000 rows and reports errors by record and field. A clean commit updates existing products by SKU or creates new ones through the same catalogue writer as manual entry.

Use active unit codes, category names, and GST percentages that resolve to a single active tax-rate reference. Supply SKU values for repeatable updates. Blank SKUs allocate new identifiers on each committed import. The `opening_qty` column is rejected until M10 connects imports to the stock adjustment service.

See [test results](test-results.md).
