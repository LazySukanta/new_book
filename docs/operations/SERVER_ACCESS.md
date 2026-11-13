# Testing server access and deployment handoff

User-provided access details, recorded 9 November 2026. Read this before deploying this project.

## Connection

- SSH host: `51.222.29.250`
- SSH login: `ubuntu` (not `root`)
- Connect with the authorized private key: `ssh ubuntu@51.222.29.250`
- Hestia site account: `kagajghar02`
- Authorized testing domain: https://pos.kagajghar.com
- User-provided site location: `web/pos.kagajghar.com` under the Hestia account. Expected absolute location is `/home/kagajghar02/web/pos.kagajghar.com`; verify it on the server before changing files.

The user reports that this public key has been added for the Ubuntu login:

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEDORAb3ZXx8/xJXRO7AiwR46e4cIXZCCZ/0eYcReac5 ubuntu@51.222.29.250
```

The public key is identification only. The matching private key must be available locally or through the SSH agent. Never put a private key, password, token or database credential in this repository. This handoff records the supplied access information; it does not establish that a connection has been tested.

## Deployment boundaries

The user authorizes `pos.kagajghar.com` as this project's testing ground. Another application already runs on the server: **do not touch its code, database or data**. Keep this project's application files, database, credentials and runtime configuration separate. Do not reset, migrate, seed, replace or restore another application's database, and do not restart shared services as part of this project's deployment.

Before writing, inspect the domain's existing public front controller and private release layout to identify this project's release. Verify the target database from its private environment configuration without printing credentials. If the domain contains an unrelated application, stop before overwriting it and resolve the target location with the user.

Existing project deployment notes in the root README describe a private Laravel release, PHP 8.3 and a dedicated staging MySQL service on localhost port `33319`. Those are prior recorded facts, not a substitute for inspecting current server state. Reuse this project's isolated database if present; create a separate project database if needed. Leave the shared MariaDB instance and other applications alone.

## Deployment procedure

1. Read the root README's staging section and the M37/M38 verification notes. Confirm SSH access as `ubuntu` and the Hestia account/site paths.
2. Identify the current project release, take this project's backup, and retain the previous release and assets for rollback.
3. Prepare the verified local changes and built assets from `application/`. Keep environment files and dependencies outside the public web root.
4. Deploy under the `kagajghar02` site account using its existing release layout. Run Composer and Artisan commands as the site account, against this project's verified database only. Apply migrations without resetting data, then refresh configuration and view caches.
5. Verify HTTPS sign-in, `/up`, assets and the changed feature. Record the deployed version, results and any outstanding checks.

See [README staging instructions](../../README.md#staging-on-hestia), [backup and restore evidence](../../task_remark/M37/task034.md), and [deployment rehearsal evidence](../../task_remark/M38/task035.md).
