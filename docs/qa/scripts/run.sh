#!/bin/zsh
# usage: run.sh <suite.php> [--no-reset]
QA="$(cd "$(dirname "$0")" && pwd)"
cd /Users/khaled/Herd/mall-management
# A reset that fails SILENTLY runs this suite on the previous suite's leftovers — a random-looking
# failure that vanishes when the script is run alone. Fail loudly instead.
if [[ "${2:-}" != "--no-reset" ]]; then
  if ! "$QA/reset.sh" > /dev/null 2>&1; then
    echo "RESET FAILED for $1 — baseline not restored; refusing to run on dirty state" >&2
    exit 70
  fi
fi
DB_DATABASE=mall_management_qa php artisan tinker --execute="require '$QA/$1';" 2>&1
