#!/bin/zsh
# usage: run.sh <suite.php> [--no-reset]
QA="$(cd "$(dirname "$0")" && pwd)"
cd /Users/khaled/Herd/mall-management
if [[ "$2" != "--no-reset" ]]; then "$QA/reset.sh" > /dev/null 2>&1; fi
DB_DATABASE=mall_management_qa php artisan tinker --execute="require '$QA/$1';" 2>&1
