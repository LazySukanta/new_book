# M37-R1 · Performance, backup and restore

Measured on the staging VPS with 4 vCPU, 7,747 MiB RAM, PHP 8.3 and the dedicated localhost MySQL 8.4.13 instance. An isolated schema held 20,000 products. Twenty sequential samples followed one warm-up. Measurements cover the authenticated catalogue controller with pagination and the completed-sale controller including stock, payment and document posting. They exclude network, browser and PHP process startup.

| Operation | Median | p95 | Max | Target |
|---|---:|---:|---:|---:|
| Prefix search, 12 results from 20,000 products | 54.99 ms | 56.83 ms | 56.87 ms | <200 ms |
| Ten-line completed cash sale | 78.39 ms | 86.53 ms | 96.57 ms | <300 ms |

Search alternated two prefix selectivities. EXPLAIN used the existing tenant/name index with a residual predicate over name, SKU and barcode. The query met the target without index or rate-limit changes.

The staging-only systemd timer runs daily at 03:00 IST and catches up after downtime. The [backup script](../../docs/operations/khatapos-staging-backup.sh) checks compressed output before publishing it, and prunes its own dumps after 14 days. Dumps are outside the web root, under a root-only directory with 0600 files. A 15-day-old retention probe was pruned. An attempted public backup URL returned 404.

Restored a scheduled-format staging dump into a separately named schema and matched 25 migrations, one owner, one branch and the empty trading totals. A second restore with populated disposable data matched 20,000 products, 21 sales, ₹2,100.00 in sales and payments, and 790.000 units. Both restore schemas were removed after reconciliation.

To rehearse recovery, create a new disposable schema on the isolated MySQL instance, then pipe the decompressed dump into the MySQL client with `--defaults-file=/etc/khatapos-staging-mysql-admin.cnf` and that schema as its database argument. Compare migration counts, row counts and sale, stock and payment totals before discarding the rehearsal schema. The admin option file is root-only and identifies the isolated socket.
