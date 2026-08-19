#!/bin/zsh
# Build the seeded snapshot the harness resets to. Run once, and again after any migration or
# seeder change — a stale baseline is how a suite starts failing for a reason nobody changed.
set -eu
QA="$(cd "$(dirname "$0")" && pwd)"
cd "$QA/../../.." || exit 1

MYSQL="${MYSQL_BIN:-/opt/homebrew/opt/mysql-client/bin}"
DB="${QA_DATABASE:-mall_management_qa}"

echo "Building $DB …"
"$MYSQL/mysql" -h127.0.0.1 -uroot -e \
  "create database if not exists \`$DB\` character set utf8mb4 collate utf8mb4_unicode_ci;"
DB_DATABASE="$DB" php artisan migrate:fresh --seed --force
"$MYSQL/mysqldump" -h127.0.0.1 -uroot --single-transaction --no-tablespaces "$DB" > "$QA/baseline.sql"
echo "Snapshot written: $(du -h "$QA/baseline.sql" | cut -f1)  →  docs/qa/scripts/baseline.sql"
echo "Now run:  composer qa"
