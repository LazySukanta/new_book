# M30 test results

## MySQL regression

**Passed.** Six tests passed with 173 assertions. The regression covers product and tier history, CSV dry runs and imports, fractional stock counts, stale preview recovery, idempotent posting, cross-branch confirmation denial, and cashier restrictions.

## Browser and build

The production Vite build passed. Chrome used a paired owner session and disposable inventory on the dedicated MySQL test database. The browser created a fractional unit and GST rate, saved a product and a wholesale tier, searched using Enter, previewed and posted a 1.250-unit physical count, inspected the ledger, and displayed the invalid CSV row number. At 360 px the catalogue and movement pages had no document overflow with the long product name and SKU. The product price rendered as ₹1,23,456.70.

- [Catalogue search on phone](screenshots/catalogue-phone.png)
- [Physical count preview on phone](screenshots/count-preview-phone.png)
- [Posted stock ledger](screenshots/ledger-desktop.png)
- [CSV row validation](screenshots/csv-row-error-desktop.png)

## Reproduce

Run from the application directory with the configured MySQL test database available.

```powershell
npm run build
php artisan test --compact '--filter=WorkspaceInventoryTest|CatalogueImportTest|PhysicalCountTest|StockPostingTest'
```
