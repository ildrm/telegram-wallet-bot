#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
DOCKER_BIN="$(command -v docker)"
export DOCKER_BIN
container=telegram-wallet-mysql-test
"$DOCKER_BIN" run --name "$container" --rm \
  -e MYSQL_ROOT_PASSWORD=wallet-test-only -e MYSQL_DATABASE=wallet_test \
  -p 127.0.0.1:33384:3306 -d mysql:8.4 >/dev/null
# Only stop the container created successfully by this invocation.
trap '"$DOCKER_BIN" stop "$container" >/dev/null 2>&1 || true' EXIT HUP INT TERM
attempt=0
until "$DOCKER_BIN" exec -e MYSQL_PWD=wallet-test-only "$container" mysqladmin ping --protocol=TCP -h 127.0.0.1 -u root --silent >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo 'Isolated MySQL did not become ready.' >&2
    exit 1
  fi
  sleep 1
done
"${PHP_BIN:-php}" vendor/bin/phpunit -c phpunit.mysql.xml "$@"
