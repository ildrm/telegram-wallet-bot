#!/bin/sh
set -eu
# Uses only unique wallet_drill_* databases in the isolated test MySQL container.
# It never restores over the configured wallet_test database or a live database.
cd "$(dirname "$0")/.."
exec "${PHP_BIN:-php}" vendor/bin/phpunit -c phpunit.mysql.xml --filter MySqlRestoreTest
