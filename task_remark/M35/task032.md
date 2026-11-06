# M35-R1 · Branch dashboard and basic reports

The workspace Reports page uses the same branch-scoped operations as the API. Owners and managers can open the dashboard, daily sales, sales by product or cashier, stock valuation, low stock, customer and supplier outstanding, payments, and provisional gross profit. Cashiers see their own daily sales and collections for the active branch.

The selected period uses inclusive dates in IST. The browser retains that period when switching reports or branches. Stock and dues are labeled as current, and the dashboard identifies its short cache window. Reports flag provisional valuation and cost estimates, plus incomplete opening balances where applicable.

## Verification

- Vite production build and Blade compilation passed.
- The focused MySQL regression passed with 5 tests and 399 assertions. It exercised browser-session report routes, branch scope, owner totals, cashier denials, payment-method collections, empty dates, invalid dates and current stock metadata.
- Chrome showed a ₹10.00 cash sale on the owner dashboard and daily sales report. It verified invalid-date feedback, an empty historical period, preservation of that period after visiting current stock, and a separate branch with no sales.
- Chrome verified the cashier could see their own daily report and empty state, and received HTTP 403 when requesting the dashboard endpoint directly. Both the populated daily report and cashier view fit a 360 px viewport.
- [Owner dashboard](screenshots/dashboard.png), [daily report on phone](screenshots/daily-phone.png), and [cashier view on phone](screenshots/cashier-phone.png).

Run `npm run build` and `php artisan test --compact '--filter=CompletedSaleTest|WorkspaceTest'` from the application directory with the dedicated test database available.

Transfer, advanced reporting, export and audit screens remain in the Release 2 slice of M35.
