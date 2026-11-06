# M31 Release 1 test results

## MySQL regression

**Passed.** 15 tests with 249 assertions. The regression exercised duplicate form submissions, stale revision recovery, paid removal retaining supplier advances, manager denials, original versions after removal, and atomic purchase and correction posting. Linked supplier-return cases belong to the Release 2 slice.

## Browser and build

The production Vite build passed. Playwright CLI drove real Chrome with a paired owner and disposable data in the dedicated MySQL test database. At 360 px, Enter added a purchase line and moved keyboard focus to the new product picker. The form had no document overflow. The desktop walkthrough recorded a two-line purchase with a payment, posted a quantity correction, opened the previous version and previewed removal. The removal preview showed the ₹600 payment retained as a supplier advance.

- [Purchase entry on phone](screenshots/entry-phone.png)
- [Correction preview](screenshots/correction-preview-desktop.png)
- [Before and after versions](screenshots/history-desktop.png)
- [Paid removal preview](screenshots/paid-removal-preview.png)

## Reproduce

Run from the application directory with the configured MySQL test database available.

```powershell
npm run build
php artisan test --compact '--filter=PurchasePostingTest|PurchaseCorrectionTest|PurchaseRevisionTest'
```
