# M04 test evidence

Executed 4 November 2026 on isolated MySQL khatapos_test, not SQLite.

| Check | Actual result |
|---|---|
| php artisan test | 18 passed, 95 assertions, 14487 ms |
| php vendor/bin/pint --test --format=json | Passed |
| php artisan migrate --force (local development) | Six new migrations completed |
| php artisan route:list --path=auth --except-vendor | Seven authentication routes listed |
| Local HTTP GET /up | 200 |
| Local HTTP GET /auth/csrf | 200 |
| Local HTTP POST /auth/login without CSRF | 419 |

The HTTP smoke server was stopped after verification. Browser cookie/session behavior and CSRF enforcement are exercised in DeviceAuthenticationTest with the framework's test CSRF bypass explicitly disabled for that case. Database session columns are asserted. Native devices and production UI are outside this milestone.

Earlier failed runs found and fixed unloaded new-model default values, a conventional pivot-name mismatch, Sanctum fillable bindings and test request cookie/guard handling. Final suite has no failures.

Reproduce from application using the PHP executable and local setup in the root README. Test environment credentials stay ignored. Raw passing summaries are in test-output.txt.
