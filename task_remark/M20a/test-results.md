# M20a test results

## Retained held bills

Focused MySQL sales regression: 1 test passed, 141 assertions.

Draft isolation, stale pricing, insufficient-stock rollback, competing completions and retained discard.

To rerun the current sales regression, run `php artisan test --filter=CompletedSaleTest` from the application directory with the configured MySQL test database available.

These counts are recorded results from the milestone runs.
