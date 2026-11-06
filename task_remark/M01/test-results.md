# M01 test evidence

Recorded 4 November 2026 from execution.

- php artisan test: initial baseline passed, 3 tests, 4 assertions.
- npm.cmd run build: passed.
- HTTP GET /up: 200.
- Playwright Chrome screenshot of http://127.0.0.1:38881/: passed.
- TestEnvironmentTest confirms mysql and khatapos_test.
- Latest regression after initial M03 work: php artisan test — 7 passed, 31 assertions, 7048 ms.

Use installed PHP when absent from PATH. Credentials come from ignored .env.testing. Never run resets against development/production.
