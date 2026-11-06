# M20c test results

## Returns and credit notes

Focused MySQL sales regression: 1 test passed, 175 assertions. Later coverage in M21 verifies one-paisa conservation and rollback.

Original-price partial returns, refunds, idempotent replay, credit-note references and over-return rejection.

To rerun the current sales regression, run `php artisan test --filter=CompletedSaleTest` from the application directory with the configured MySQL test database available.

These counts are recorded results from the milestone runs.
