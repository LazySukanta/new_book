# M03 test evidence

4 November 2026. Complete.

php artisan test — 7 passed, 31 assertions, 7048 ms.

BranchAccessTest covers repeatable seeds, cashier permissions, assigned access, foreign-tenant denial including all-branches users, and inactive branches.

Final combined run: php artisan test — 18 passed, 95 assertions, 14487 ms. Includes local owner setup, duplicate owner prevention, authenticated branch checks and owner-approved device registration/recovery. See M04/test-results.md for the full regression.

Approved decision: local setup creates first owner and one-time device pairing code. Later devices require owner approval.
