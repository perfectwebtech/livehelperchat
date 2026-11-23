#!/usr/bin/env bash
# Backs up a running docker-compose deployment of Live Helper Chat.
#
#   deploy/backup.sh [backup-root]        default: $LHC_BACKUP_DIR or ./backups
#
# Writes <backup-root>/<UTC timestamp>/ with:
#   database.sql.gz   consistent dump (--single-transaction, no table locks)
#   uploads.tar.gz    uploaded files, photos, themes (the var/ volume)
#   settings.ini.php  the installed settings file
#   SHA256SUMS
# and deletes backups older than LHC_BACKUP_KEEP_DAYS (default 14).
#
# Not included: .env (it holds the secrets). Keep a copy of it in your
# password manager or secret store; restore needs the same values.
#
# Cron example (02:30 every night):
#   30 2 * * * cd /opt/livehelperchat && deploy/backup.sh >> backups/backup.log 2>&1
set -euo pipefail

cd "$(dirname "$0")/.."
umask 077

ROOT="${1:-${LHC_BACKUP_DIR:-./backups}}"
KEEP_DAYS="${LHC_BACKUP_KEEP_DAYS:-14}"
DEST="$ROOT/$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

cleanup() { if [ "${OK:-0}" != "1" ]; then rm -rf "$DEST"; echo "backup FAILED, partial files removed" >&2; fi; }
trap cleanup EXIT

echo "[backup] database"
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$MARIADB_DATABASE"' \
  | gzip -6 > "$DEST/database.sql.gz"
gunzip -c "$DEST/database.sql.gz" | tail -n 1 | grep -q 'Dump completed' || { echo "database dump is incomplete" >&2; exit 1; }

echo "[backup] uploaded files"
docker compose exec -T web tar czf - -C /var/www/html var > "$DEST/uploads.tar.gz"
tar tzf "$DEST/uploads.tar.gz" > /dev/null

echo "[backup] settings"
docker compose exec -T web cat /data/settings/settings.ini.php > "$DEST/settings.ini.php"
grep -q "'installed' => true" "$DEST/settings.ini.php" || { echo "settings file looks wrong (not installed?)" >&2; exit 1; }

( cd "$DEST" && sha256sum database.sql.gz uploads.tar.gz settings.ini.php > SHA256SUMS )

if [ "$KEEP_DAYS" -gt 0 ]; then
  find "$ROOT" -mindepth 1 -maxdepth 1 -type d -name '20*' -mtime +"$KEEP_DAYS" -exec rm -rf {} +
fi

OK=1
echo "[backup] done: $DEST ($(du -sh "$DEST" | cut -f1))"
echo "$DEST"
