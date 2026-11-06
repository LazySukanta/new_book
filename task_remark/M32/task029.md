# M32 · POS cart and held bills

The Point of sale link opens the cart for the selected branch. Staff can search by name, scan a barcode or enter a SKU, edit quantities, select or quick-add a customer, switch retail and wholesale pricing, and view stock warnings and GST totals. The server applies quantity slabs, permissions for discounts and price overrides, and the configured cashier discount cap. Unfinished drafts are restored per branch, staff member and browser tab.

The held list supports resume and discard with a reason. Resuming compares the held pricing fingerprint with current pricing. Staff can review the old and current amounts and pricing basis, acknowledge the update, and hold the same bill ID again. The hold transaction checks the pricing fingerprint against current prices. An interrupted hold can be retried using the same request and idempotency key.

## Verification

- The Vite production build and Blade compilation passed.
- A JavaScript check queued two scans and confirmed quantity 2, rejected an out-of-order pricing response and checked the acknowledgement state.
- The focused regression passed with 5 tests and 371 assertions on a fresh MySQL 8.4.13 test database. The POS test used the paired browser routes to look up a barcode, hold two units at ₹10.00 each, change the price to ₹20.00, reject the stale fingerprint, re-hold the same ID at ₹40.00 and retain it after discard. Issued document, stock ledger and payment counts stayed at zero.
- Chrome verified F2, two scanner-style Enter submissions, F4 and Escape, F8, and F9. Two units totalled ₹20.00 at retail and ₹16.00 at wholesale. The cart survived navigation, displayed an insufficient-stock warning, and fit at 360 px without horizontal overflow. At 1440 px, resuming after a price change showed ₹16.00 held and ₹40.00 current, disabled holding until acknowledgement, and then re-held bill #2.
- [Phone cart at 360 px](screenshots/cart-phone.png) and [desktop repricing at 1440 px](screenshots/repricing-desktop.png).

Run `npm run build` and `php artisan test --compact '--filter=CompletedSaleTest|SalePricingTest|WorkspaceTest'` from the application directory with the dedicated test database available.

M33 adds split payment and invoice printing. F9 currently focuses the checkout availability message.
