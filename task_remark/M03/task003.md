# task003 — Tenant, branch and first-owner boundaries

Completed 4 November 2026.

Implemented tenant/branch migrations, user memberships, composite tenant foreign keys, Spatie role/permission seeds and the branch resolver. Seeds are repeatable and contain no default user password. The local shop:setup command creates the first owner after validating prompted credentials and issues a one-time device pairing code. A tenant lock prevents duplicate concurrent owner creation.

Changed application models User, Branch and Tenant; ShopAccessSeeder/DatabaseSeeder; shop access migration; ResolveBranch; SetupShop command; BranchAccessTest and DeviceAuthenticationTest.

Verified assigned access, foreign-tenant and inactive-branch denial, repeatable seeds, owner setup and duplicate setup refusal. Authenticated API demonstrations are automated feature tests rather than temporary UI.

See [results](test-results.md). Staff/branch administration screens and management endpoints remain M06/M29. No SaaS or public signup was added.

Next: M04, completed in task004.
