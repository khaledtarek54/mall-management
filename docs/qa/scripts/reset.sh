#!/bin/zsh
QA="$(cd "$(dirname "$0")" && pwd)"
/opt/homebrew/opt/mysql-client/bin/mysql -h127.0.0.1 -uroot -e "drop database if exists mall_management_qa; create database mall_management_qa character set utf8mb4 collate utf8mb4_unicode_ci;"
/opt/homebrew/opt/mysql-client/bin/mysql -h127.0.0.1 -uroot mall_management_qa < "$QA/baseline.sql"
echo "QA db reset to seeded baseline"
