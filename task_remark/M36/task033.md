# M36-R1 · Trading-day and browser regression

The integrated workspace test posts a purchase, checks out a split-payment sale, records and reverses a credit note, then posts a credit sale, a partial return, a collection and a purchase correction. Independent expected values are 3 units worth ₹12.00, ₹20.00 customer due, ₹24.00 supplier due, ₹70.00 net sales, and ₹50.00 collected across cash and UPI. The focused integrated test passed with 87 assertions.

Chrome exercised the purchase form, POS credit checkout, invoice return, branch-scoped collection endpoint, purchase correction preview and confirmation, and the dashboard on a disposable MySQL shop. Five units purchased, two sold, one returned and one added by correction left five units worth ₹20.00. Net sales were ₹10.00, collections ₹5.00, customer due ₹5.00 and supplier due ₹24.00. The cashier view showed no owner sales and denied direct access to the dashboard. [Trading dashboard](screenshots/trading-day.png) · [Cashier phone view](screenshots/cashier-empty.png)

The opt-in `TradingDemoSeeder` supplies test staff, catalogue, party records and document series. It requires the testing environment and the dedicated `khatapos_test` schema, uses random initial passwords, and leaves stock and documents to be posted through the app.

Run `php vendor/phpunit/phpunit/phpunit` from the application directory with an isolated MySQL test database.

The full suite passed 55 tests with 1,246 assertions on fresh MySQL 8.4.13. The final workspace rerun passed 5 tests with 153 assertions, including the repeatable demo fixture. The suite exercised concurrent sale posting, credit limits, retained bill completion, numbering, device revocation and stale purchase corrections. Chrome also verified a paired manager could open branch reports and purchase entry. The last migration rolled back and reapplied successfully on the disposable database. Prior M33 browser evidence covers lost-response and print-launch retry.

## R2 local regression

On local MySQL 8.4.9, 86 distinct tests passed with 2,366 assertions across three runs, counting the latest passing result for each test. The initial run passed 65 tests before a separate PHP worker exceeded its startup timeout. The follow-up passed 22 tests after increasing the test timeout from 15 to 60 seconds without changing the concurrency assertions. The final database guard and extended trading-day tests passed with 114 assertions.

The extended trading day returned one unit to the supplier after the purchase correction, then dispatched the remaining two units, received one and wrote off one. Source stock finished at zero, destination stock at one unit worth ₹4.00, supplier due at ₹20.00, and transfer loss at ₹4.00 with nothing left in transit. The earlier ₹70.00 net sales, ₹50.00 collections and ₹20.00 customer due also reconciled.

Fresh migrations ran during the tests. All four release migrations were also rolled back and reapplied on `khatapos_test`, and `stock:reconcile` passed. The rollback removes the transfer foreign key before its supporting index. The browser evidence for supplier returns, transfer resolution, historical reporting, Excel downloads and owner audit views is linked under M22 and M35 in the [milestone ledger](../../docs/MILESTONES.md). No R2 server deployment was performed.
