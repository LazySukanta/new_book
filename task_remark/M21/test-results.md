# M21 test results

## Cancellation and invoice comments

Full MySQL suite: 48 tests passed, 887 assertions. Final focused MySQL sales regression: 1 test passed, 228 assertions.

Paid and unpaid cancellation, refund recovery, debt restoration, rounding, stock reconciliation and comment history.

To rerun the current sales regression, run `php artisan test --filter=CompletedSaleTest` from the application directory with the configured MySQL test database available.

These counts are recorded results from the milestone runs.
