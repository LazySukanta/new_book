# M25 Release 1 test results

**Passed on MySQL.** The focused run passed 2 tests with 293 assertions.

The trading-day fixture posts a 100.00 Walk-in sale and an 80.00 wholesale sale. The wholesale sale starts with 20.00 paid and 60.00 credit. A later 15.00 receipt leaves 45.00 outstanding. Daily reports show 180.00 net sales and 135.00 collected (125.00 cash and 10.00 UPI). Recorded cost is 60.00, so provisional gross profit is 120.00.

The regression also exercises the India day boundary, separate branch and cashier visibility, signed returns, current stock, the dashboard and supplier balances after reassignment. The old supplier keeps an 800.00 advance while the corrected supplier is owed 1,000.00.

Run from the application directory with the configured MySQL test database available.

```powershell
php artisan test --compact '--filter=CompletedSaleTest|test_supplier_change_preserves_payment_owner_and_historical_balance'
```
