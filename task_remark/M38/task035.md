# M38-R1 · Staging deployment rehearsal

The deployed application release is `dc154b7` on the Hestia test domain. Subsequent M36 and M37 commits add verification coverage and operating procedures. PHP 8.3 Composer platform requirements passed. The initial staging install applied all 25 migrations to the dedicated MySQL 8.4.13 service and loaded built assets.

Public checks returned 200 for sign-in, the health route and the production JavaScript, 301 from HTTP to HTTPS, 302 from the signed-out workspace to login, and 404 for the environment file, Composer autoloader and project documentation.

A fresh Chrome browser paired with a disposable staging shop and recorded a purchase, credit sale, partial return, collection and correction. Reports reconciled to ₹10.00 net sales, ₹5.00 collections, ₹5.00 customer due, ₹24.00 supplier due and five units worth ₹20.00. The browser downloaded the saved A4 invoice and rendered the thermal receipt. [Staging dashboard](screenshots/staging-dashboard.png)

Only the dedicated staging MySQL service was restarted. A session-dependent request briefly returned 500 while the database was starting. After recovery, the same browser session reopened the issued invoice and saw the preserved settlement. The post-trade backup succeeded. Both staging MySQL and the shared MariaDB service reported active. The temporary account and device were disabled after verification. Test records remain isolated in the disposable tenant.

The [staging runbook](../../README.md#staging-on-hestia) describes the private release layout, runtime and rollback procedure. The separate-schema restore was rehearsed in M37. The application rollback procedure is documented for operational use.
