# task004 — Approved devices and authentication

Completed 4 November 2026.

Implemented owner-approved device registration and revocation, 30-minute one-use pairing codes, returning-browser credentials in encrypted HttpOnly cookies, rotated sessions and 30-day tenant/device-bound Sanctum tokens. All current registration is server-derived web channel. Active user/tenant/device and current branch grants are checked on protected requests.

Added Device and PersonalAccessToken models, enrollment/authentication services and controllers, RequireDevice middleware, database session binding handler, device/session/token/audit migrations, web/API routes, local recovery command and authentication tests. AppServiceProvider configures Sanctum, database sessions and credential throttling. Device audit records contain metadata only. RULES/SCHEMA/README now describe the approved bootstrap.

Checks cover one-use/expired pairing, returning cookie login, actual CSRF middleware, persisted session columns, API/session revocation, user/token mismatch, current roles, inactive users/tenants, branch intersection, unauthorized registration, owner recovery, token logout and secret-free audit rows.

See [results](test-results.md) and [captured final output](test-output.txt).

Boundaries: production login/admin screens remain later website milestones. Native platform/channel enrollment, automatic token renewal, desktop/mobile printing and offline synchronization are not implemented. The audit table is introduced for required device security events; general append-only audit/idempotency primitives remain M05. Production HTTPS and deployment validation remain release gates.

Next eligible milestone: M05. Do not begin trading-feature work before its transaction/idempotency foundation.
