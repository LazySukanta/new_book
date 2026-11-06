# M11 test evidence

The isolated MySQL run of `php artisan test --filter=PurchaseRevisionTest` passed 2 tests with 17 assertions in 20.968 seconds. Pint passed. Fixtures covered the INR 60 inventory change and INR 40 variance, missing or excessive confirmation, quantity and supplier settlement changes, manager denial, stale revisions and immutable before/after snapshots.
