#!/usr/bin/env bash
set -euo pipefail
umask 077
dir=/var/backups/khatapos-staging
dump=/opt/khatapos-staging-mysql/bin/mysqldump
config=/etc/khatapos-staging-mysql-admin.cnf
install -d -m 0700 "$dir"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
temp=$(mktemp "$dir/.dump.XXXXXXXX")
trap 'rm -f "$temp"' EXIT
"$dump" --defaults-file="$config" --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF khatapos_staging | gzip -c > "$temp"
gzip -t "$temp"
mv -- "$temp" "$dir/khatapos-$stamp.sql.gz"
find "$dir" -maxdepth 1 -type f -name 'khatapos-????????T??????Z.sql.gz' -mmin +20160 -delete
