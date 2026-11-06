# Task 026 - M29 sign-in and administration

The local landing page signs staff in through the existing browser session and device enrollment flow. A new browser needs a one-time pairing code. A paired browser accepts staff credentials on subsequent sign-ins. The root page clears a stale or revoked session so the browser can sign in again. The branch switcher continues to use the authorized device and staff intersection.

An owner can manage branches, staff, devices, shop settings and document series. Managers see the staff area within their cashier and branch scope. Cashiers use the workspace. The administration forms submit directly to Laravel and share the API authorization, validation and posting logic. There are no same-server HTTP requests. Device approval shows the one-time code once. Series counters are read-only.

## Pair the first local browser

1. From the application directory, run `php artisan shop:setup`. Choose your owner name, email and password when prompted.
2. Open [local sign-in](http://127.0.0.1:8000/) and enter those credentials and the code printed by setup.
3. To approve another browser, use Administration → Devices, choose its branches and approve it. Enter the displayed code with the staff credentials on that browser.
4. If an owner exists and every approved browser is unavailable, use `php artisan shop:pair-device` on the shop computer.

See [test results](test-results.md). Next is M30, catalogue and stock screens.
