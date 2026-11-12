#!/usr/bin/env bash
# Runs the module tests inside a FreeScout checkout, against MariaDB, all in Docker.
#
#   tools/test.sh [freescout-ref]      (default: dist, the branch FreeScout releases with its vendor directory)
#
# Leaves nothing behind but a work directory under ${WORK:-/tmp}/postilio-freescout-test.* (printed at the end),
# and stops and removes the containers and network it started.
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
ref=${1:-dist}
work=$(mktemp -d "${WORK:-/tmp}/postilio-freescout-test.XXXXXX")
name=pfs-test-$$
uid="$(id -u):$(id -g)"

cleanup() {
  docker rm -f "$name-db" >/dev/null 2>&1 || true
  docker network rm "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "FreeScout $ref in $work"
git clone -q --depth 1 --branch "$ref" https://github.com/freescout-help-desk/freescout.git "$work/freescout"
docker build -q -t postilio-freescout-test - >/dev/null <<'EOF'
FROM php:8.2-cli
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mbstring gd zip intl imap @composer
EOF

# FreeScout's vendor is committed without its dev packages: PHPUnit as a phar, Faker (its factories need it) apart.
curl -sSfL -o "$work/phpunit.phar" https://phar.phpunit.de/phpunit-10.5.phar
mkdir "$work/tools"
docker run --rm -u "$uid" -e HOME=/tmp -v "$work/tools:/t" -w /t postilio-freescout-test \
  composer require -q --no-interaction --ignore-platform-reqs fzaninotto/faker:1.9.2

cd "$work/freescout"
ln -s /repo/PostilioSend Modules/PostilioSend
ln -s /repo/PostilioInbound Modules/PostilioInbound
cat > tests/bootstrap-modules.php <<'EOF'
<?php
$loader = require __DIR__.'/../vendor/autoload.php';
$loader->addPsr4('Tests\\', __DIR__);
$loader->addPsr4('Faker\\', '/tools/vendor/fzaninotto/faker/src/Faker');
EOF
printf 'DB_CONNECTION=testing\nDB_TEST_HOST=%s-db\nAPP_KEY=base64:ZnJlZXNjb3V0LXBocHVuaXQtdGVzdC1rZXktMDAwMDE=\n' "$name" > .env

docker network create "$name" >/dev/null
docker run -d --name "$name-db" --network "$name" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=freescout-test \
  -e MARIADB_USER=freescout-test -e MARIADB_PASSWORD=freescout-test mariadb:11 >/dev/null
until docker exec "$name-db" mariadb -ufreescout-test -pfreescout-test -e 'select 1' freescout-test >/dev/null 2>&1; do sleep 1; done

run() {
  docker run --rm --network "$name" -u "$uid" -e HOME=/tmp -v "$work/freescout:/app" -v "$repo:/repo:ro" \
    -v "$work/tools:/tools:ro" -v "$work/phpunit.phar:/phpunit.phar:ro" -w /app postilio-freescout-test "$@"
}
run php artisan migrate --database=testing --force >/dev/null
docker exec "$name-db" mariadb -ufreescout-test -pfreescout-test freescout-test \
  -e "INSERT INTO modules (alias, active, activated) VALUES ('postiliosend', 1, 1), ('postilioinbound', 1, 1)"
# Again, now that the modules are active (and FreeScout no longer remembers them as inactive): their own migrations.
run php artisan cache:clear >/dev/null
run php artisan migrate --database=testing --force >/dev/null
run php /phpunit.phar --bootstrap tests/bootstrap-modules.php Modules/PostilioSend/Tests Modules/PostilioInbound/Tests
echo "Work directory: $work"
