# M09 test evidence

4 November 2026. The MySQL stock and catalogue regression run passed 4 tests with 97 assertions. A later endpoint-focused catalogue run passed 1 test with 39 assertions. Pint passed. The local stock migration and `stock:reconcile` succeeded.

The tests covered unknown estimates, mixed available and estimated issues, partial and complete negative-stock settlement, zero depletion, immutable prior COGS, rollback, detecting a damaged projection, branch-scoped stock reads, and overlapping last-unit sales with negative stock disabled.
