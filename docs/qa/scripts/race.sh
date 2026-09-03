#!/bin/zsh
# usage: race.sh <scenario> <comma-separated args>
QA="$(cd "$(dirname "$0")" && pwd)"
cd /Users/khaled/Herd/mall-management
scenario=$1; shift
rm -f "$QA/race_${scenario}_"*.out
# The QA baseline can be behind `main` (it is a dump, not a migrated database), and a missing
# column reads as REFUSED on both workers — which looks exactly like a guard doing its job. When
# that happens the answer is `composer qa:baseline`, not another database: `boot.php` refuses
# anything but `mall_management_qa`, at require time and OUTSIDE the worker's try/catch, so no
# `.out` file is written at all and both workers print "(no output)" — the very failure this note
# exists to warn about. RACE_DB is here for a differently-NAMED qa database, nothing more.
start=$(php -r 'echo (int) ((microtime(true) + 3) * 1000);')
for w in A B; do
  DB_DATABASE="${RACE_DB:-mall_management_qa}" RACE_SCENARIO="$scenario" RACE_START="$start" RACE_LABEL="$w" RACE_ARGS="$1" \
    php artisan tinker --execute="require '$QA/race_worker.php';" 2>&1 | tail -3 &
done
wait
for w in A B; do
  printf "  %s: %s\n" "$w" "$(cat "$QA/race_${scenario}_${w}.out" 2>/dev/null || echo '(no output)')"
done
