#!/usr/bin/env bash
# Boots docker-compose.yml from scratch and checks a production-style deployment:
#   first-boot install from environment variables, health probe, admin login,
#   schema in sync, scheduler running jobs, no secrets persisted to the settings
#   file, and a web container recreate (redeploy) that keeps the installation.
set -euo pipefail

# The tls profile adds Caddy on :80/:443 with a local CA for "localhost".
export COMPOSE_PROFILES=tls
BASE_URL=http://127.0.0.1:8080
TLS_URL=https://localhost
ADMIN_PASSWORD='Ci"Stack${pw}!9'
DB_PASSWORD='ci-db-pass-4f1e'

cat > .env <<ENV
LHC_DB_PASSWORD=${DB_PASSWORD}
MARIADB_ROOT_PASSWORD=ci-root-pass
LHC_SECRET_HASH=ci-secret-hash-0123456789abcdef0123456789abcdef0123456789abcdef
LHC_ADMIN_PASSWORD='${ADMIN_PASSWORD}'
LHC_ADMIN_EMAIL=ci@example.com
LHC_HEALTH_TOKEN=ci-health-token
LHC_METRICS_TOKEN=ci-metrics-token
LHC_HSTS_MAX_AGE=300
LHC_DOMAIN=localhost
LHC_PROXY_MODE=1
LHC_AI_BASE_URL=http://ollama:11434/v1
LHC_AI_MODEL=ci-compose-model
ENV

fail() { echo "::error::$1"; docker compose ps; docker compose logs --tail=80; exit 1; }
trap 'docker compose down -v > /dev/null 2>&1 || true; rm -f .env' EXIT

ADMIN_JAR=""
login() {
  local jar token
  jar="$(mktemp)"
  ADMIN_JAR="$jar"
  token=$(curl -s -c "$jar" -b "$jar" "$BASE_URL/index.php/site_admin/user/login" | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
  [ -n "$token" ] || return 1
  curl -s -c "$jar" -b "$jar" -o /dev/null -X POST "$BASE_URL/index.php/site_admin/user/login" \
    --data-urlencode "Username=admin" --data-urlencode "Password=$ADMIN_PASSWORD" \
    --data-urlencode "csfr_token=$token" --data-urlencode "Login=Login"
  curl -s -b "$jar" "$BASE_URL/index.php/site_admin" | grep 'user/logout' > /dev/null
}

echo "1. build and start the stack"
docker compose up -d --build --wait --wait-timeout 300 || fail "stack did not become healthy"

echo "2. health probe"
curl -sf "$BASE_URL/index.php/system/health" | grep '"status":"ok"' > /dev/null || fail "health probe not ok"
curl -sf "$BASE_URL/index.php/system/health?token=ci-health-token" | grep '"checks"' > /dev/null || fail "health token from the environment is not honoured"

echo "3. administrator created from the environment can log in"
login || fail "admin login failed"

echo "4. installed schema matches the bundled structure.json"
docker compose exec -T web php cli/update-db.php --dry-run | grep 'up to date' > /dev/null || fail "fresh install is out of sync with structure.json"

echo "5. no secrets in the persisted settings file"
if docker compose exec -T web grep -F "$DB_PASSWORD" /data/settings/settings.ini.php > /dev/null; then
  fail "database password was written to settings.ini.php"
fi

# Capture logs before grepping: with pipefail, `docker compose logs | grep -q` fails
# whenever grep exits early and docker compose gets SIGPIPE.
service_logs() { docker compose logs "$1" 2>&1 || true; }

echo "6. scheduler runs the background jobs"
for _ in $(seq 1 30); do
  case "$(service_logs scheduler)" in *'ok   cron/data_retention'*) break ;; esac
  sleep 2
done
SCHEDULER_LOGS="$(service_logs scheduler)"
grep -F 'ok   cron/workflow' <<< "$SCHEDULER_LOGS" > /dev/null || fail "scheduler did not run cron/workflow"
grep -F 'ok   cron/data_retention' <<< "$SCHEDULER_LOGS" > /dev/null || fail "scheduler did not run cron/data_retention"
if grep -F 'FAIL ' <<< "$SCHEDULER_LOGS" > /dev/null; then fail "a scheduled job failed"; fi

echo "7. redeploy: recreate the web container, installation survives"
# `docker compose logs` only covers the current container, so check first boot now.
[ "$(service_logs web | grep -c 'install-from-env: installation complete')" = "1" ] || fail "first boot did not install exactly once"
docker compose up -d --force-recreate --no-deps --wait --wait-timeout 180 web || fail "web did not come back after recreate"
login || fail "admin login failed after recreate"
WEB_LOGS="$(service_logs web)"
if grep -F 'installing from environment' <<< "$WEB_LOGS" > /dev/null; then fail "recreated container tried to install again"; fi
grep -F 'Database schema is up to date' <<< "$WEB_LOGS" > /dev/null || fail "recreated container did not check the schema"

echo "8. Prometheus metrics behind a bearer token, security headers"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/index.php/system/metrics")" = "401" ] || fail "metrics without a token must be refused"
METRICS="$(curl -sf -H 'Authorization: Bearer ci-metrics-token' "$BASE_URL/index.php/system/metrics")" || fail "metrics with the bearer token failed"
grep -F 'lhc_up 1' <<< "$METRICS" > /dev/null || fail "metrics do not report lhc_up 1"
grep -F 'lhc_chats{state="pending"}' <<< "$METRICS" > /dev/null || fail "metrics miss the pending chat gauge"
grep -F 'lhc_ai_requests_last_hour{action="autoreply",result="error"} 0' <<< "$METRICS" > /dev/null || fail "metrics miss the AI request gauge"
# LHC_AI_* from .env reach PHP under Apache and lock the settings fields.
AI_PAGE="$(curl -s -b "$ADMIN_JAR" "$BASE_URL/index.php/site_admin/ai/configuration")"
grep -F 'value="http://ollama:11434/v1" readonly' <<< "$AI_PAGE" > /dev/null || fail "LHC_AI_BASE_URL from the environment is not applied"
grep -F 'value="ci-compose-model" readonly' <<< "$AI_PAGE" > /dev/null || fail "LHC_AI_MODEL from the environment is not applied"
HEADERS="$(curl -s -D - -o /dev/null "$BASE_URL/index.php/site_admin/user/login")"
grep -i 'Referrer-Policy: strict-origin-when-cross-origin' <<< "$HEADERS" > /dev/null || fail "admin pages miss Referrer-Policy"
grep -i 'Strict-Transport-Security' <<< "$(curl -s -D - -o /dev/null -H 'X-Forwarded-Proto: https' "$BASE_URL/index.php/site_admin/user/login")" > /dev/null || fail "HSTS missing on HTTPS responses although LHC_HSTS_MAX_AGE is set"
# .htaccess: private folders are not reachable over HTTP, static assets still are
for private in settings/settings.ini.php cache/ var/ lib/core/lhcore/lhmodule.php doc/update_db/structure.json cron.php; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/$private")
  case "$private" in
    cron.php) curl -s "$BASE_URL/cron.php" | grep -q 'command line' || fail "cron.php must refuse to run over HTTP" ;;
    *) [ "$CODE" = "403" ] || fail "/$private must be forbidden over HTTP (got $CODE)" ;;
  esac
done
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/design/defaulttheme/css/app-dark.css")" = "200" ] || fail "static design assets must still be served"

echo "9. HTTPS through Caddy: Secure session cookie, HSTS, real client IP"
for _ in $(seq 1 30); do curl -skf -o /dev/null "$TLS_URL/index.php/system/health" && break; sleep 2; done
curl -skf "$TLS_URL/index.php/system/health" | grep '"status":"ok"' > /dev/null || fail "health over HTTPS through Caddy failed"
TLS_HEADERS="$(curl -sk -D - -o /dev/null "$TLS_URL/index.php/site_admin/user/login")"
grep -i '^set-cookie: PHPSESSID=.*; secure' <<< "$TLS_HEADERS" > /dev/null || fail "session cookie over HTTPS lacks the Secure flag"
grep -i '^strict-transport-security: max-age=300' <<< "$TLS_HEADERS" > /dev/null || fail "HSTS missing over HTTPS"
# A failed login records the client IP. Caddy replaces client-sent X-Forwarded-For,
# so the app must log the docker gateway (the real peer), not the spoofed address.
TLS_JAR="$(mktemp)"
TOKEN=$(curl -sk -c "$TLS_JAR" -b "$TLS_JAR" "$TLS_URL/index.php/site_admin/user/login" | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -sk -c "$TLS_JAR" -b "$TLS_JAR" -o /dev/null -H 'X-Forwarded-For: 203.0.113.50' -X POST "$TLS_URL/index.php/site_admin/user/login" \
  --data-urlencode "Username=admin" --data-urlencode "Password=wrong-on-purpose" --data-urlencode "csfr_token=$TOKEN" --data-urlencode "Login=Login"
GATEWAY=$(docker network inspect "$(docker compose ps -q web | xargs docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')" -f '{{(index .IPAM.Config 0).Gateway}}')
LOGGED_IP=$(docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT ip FROM lh_users_login ORDER BY id DESC LIMIT 1"')
[ "$LOGGED_IP" = "$GATEWAY" ] || fail "app logged client IP '$LOGGED_IP', expected the real peer '$GATEWAY' (proxy mode / X-Forwarded-For handling)"

echo "10. disaster recovery: back up, destroy every volume, restore"
db_query() { printf '%s\n' "$1" | docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -N'; }
PROBE="probe-$RANDOM-$RANDOM"
db_query "INSERT INTO lh_chat_config (\`identifier\`, \`value\`, \`type\`, \`explain\`, \`hidden\`) VALUES ('backup_probe', '$PROBE', 0, 'ci', 1)"
docker compose exec -T web sh -c "echo $PROBE > /var/www/html/var/storage/backup-probe.txt"
BACKUP_ROOT="$(mktemp -d)"
BACKUP_DIR=$(deploy/backup.sh "$BACKUP_ROOT" | tail -n 1)
[ -s "$BACKUP_DIR/database.sql.gz" ] && [ -s "$BACKUP_DIR/uploads.tar.gz" ] && [ -s "$BACKUP_DIR/settings.ini.php" ] || fail "backup is missing files"
docker compose down -v
deploy/restore.sh "$BACKUP_DIR" --yes || fail "restore failed"
[ "$(db_query "SELECT value FROM lh_chat_config WHERE identifier='backup_probe'")" = "$PROBE" ] || fail "database content did not survive the restore"
[ "$(docker compose exec -T web cat /var/www/html/var/storage/backup-probe.txt)" = "$PROBE" ] || fail "uploaded files did not survive the restore"
login || fail "admin login failed after the restore"
if grep -F 'installing from environment' <<< "$(service_logs web)" > /dev/null; then fail "restored stack reinstalled instead of using the restored data"; fi

echo "compose stack smoke test passed"
