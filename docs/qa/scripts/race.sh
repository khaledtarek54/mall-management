#!/bin/zsh
# usage: race.sh <scenario> <comma-separated args>
QA="$(cd "$(dirname "$0")" && pwd)"
cd /Users/khaled/Herd/mall-management
scenario=$1; shift
rm -f "$QA/race_${scenario}_"*.out
start=$(php -r 'echo (int) ((microtime(true) + 3) * 1000);')
for w in A B; do
  DB_DATABASE=mall_management_qa RACE_SCENARIO="$scenario" RACE_START="$start" RACE_LABEL="$w" RACE_ARGS="$1" \
    php artisan tinker --execute="require '$QA/race_worker.php';" 2>&1 | tail -3 &
done
wait
for w in A B; do
  printf "  %s: %s\n" "$w" "$(cat "$QA/race_${scenario}_${w}.out" 2>/dev/null || echo '(no output)')"
done
