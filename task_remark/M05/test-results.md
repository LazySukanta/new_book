# M05 test evidence

4 November 2026. On the dedicated MySQL test database, `php artisan test` passed 20 tests with 114 assertions in 50.814 seconds. `php vendor/bin/pint --dirty` passed.

The test-only posting endpoint exercised missing keys, reordered fields, changed payload conflicts, rollback, retry after failure and audit redaction. A separate PHP process submitted the duplicate while the original transaction remained open. It waited and returned the original JSON after commit. The test asserted that the duplicate callback did not execute and that one request row remained.
