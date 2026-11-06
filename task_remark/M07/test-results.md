# M07 test evidence

4 November 2026. The full dedicated MySQL suite passed 24 tests with 183 assertions in 152.060 seconds. Pint passed and the catalogue migration applied to the local development database.

CatalogueTest exercised API lookup and filtering, duplicate SKU and barcode rejection, integer-only units, wholesale slab boundaries, price snapshots and audit history, branch balance initialization, a second process contending for the same balance row, and the stock-history delete guard. Document and ledger fixtures cover future table integration.
