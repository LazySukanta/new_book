# task013 — Purchase posting API

Purchase posting saves the supplier invoice, tax and product snapshots, stock receipt, initial revision and optional supplier payment in one transaction. The supplier invoice date is retained separately from the current stock receipt date. The API takes explicit supplier tax treatment and input-tax recoverability so the bookstore can configure its actual GST profile later.

Supplier ledgers include posted purchases. Ordinary payments allocate to eligible opening dues and invoices in date order. A new purchase updates the catalogue's last purchase cost while earlier purchase snapshots retain their recorded amounts.

The local migration and stock reconciliation passed. [Test results](test-results.md)
