#!/usr/bin/env bash
# Restores a backup made by deploy/backup.sh into this docker-compose deployment.
# Works on an existing installation and on a new server with empty volumes
# (disaster recovery): copy the repository, .env and the backup directory over first.
#
#   deploy/restore.sh <backup-dir> --yes
#
# Everything currently stored (database, uploads, settings) is REPLACED.
set -euo pipefail

cd "$(dirname "$0")/.."

SRC="${1:-}"
[ -n "$SRC" ] && [ -d "$SRC" ] || { echo "usage: deploy/restore.sh <backup-dir> --yes" >&2; exit 2; }
[ "${2:-}" = "--yes" ] || { echo "restore replaces all current data; add --yes to confirm" >&2; exit 2; }
SRC="$(cd "$SRC" && pwd)"

echo "[restore] verifying checksums"
( cd "$SRC" && sha256sum -c --quiet SHA256SUMS )

echo "[restore] stopping application containers"
docker compose stop web scheduler > /dev/null 2>&1 || true

echo "[restore] database"
docker compose up -d --wait db
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$MARIADB_DATABASE\`.* TO \`$MARIADB_USER\`@\`%\`;"'
gunzip -c "$SRC/database.sql.gz" | docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

echo "[restore] uploaded files"
docker compose run --rm --no-deps -T --entrypoint sh web -c \
  'find /var/www/html/var -mindepth 1 -delete && tar xzf - -C /var/www/html && chown -R www-data:www-data /var/www/html/var && chmod -R ug+rwX /var/www/html/var' \
  < "$SRC/uploads.tar.gz"

echo "[restore] settings"
docker compose run --rm --no-deps -T --entrypoint sh web -c \
  'mkdir -p /data/settings && cat > /data/settings/settings.ini.php && chown -R www-data:www-data /data && chmod -R ug+rwX /data' \
  < "$SRC/settings.ini.php"

echo "[restore] starting the application"
docker compose up -d --wait
echo "[restore] done from $SRC"
