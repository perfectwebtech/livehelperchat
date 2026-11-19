#!/usr/bin/env bash
# Boots docker-compose.yml from scratch and checks a production-style deployment:
#   first-boot install from environment variables, health probe, admin login,
#   schema in sync, scheduler running jobs, no secrets persisted to the settings
#   file, and a web container recreate (redeploy) that keeps the installation.
set -euo pipefail

BASE_URL=http://127.0.0.1:8080
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
ENV

fail() { echo "::error::$1"; docker compose ps; docker compose logs --tail=80; exit 1; }
trap 'docker compose down -v > /dev/null 2>&1 || true; rm -f .env' EXIT

login() {
  local jar token
  jar="$(mktemp)"
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
HEADERS="$(curl -s -D - -o /dev/null "$BASE_URL/index.php/site_admin/user/login")"
grep -i 'Referrer-Policy: strict-origin-when-cross-origin' <<< "$HEADERS" > /dev/null || fail "admin pages miss Referrer-Policy"
grep -i 'Strict-Transport-Security' <<< "$(curl -s -D - -o /dev/null -H 'X-Forwarded-Proto: https' "$BASE_URL/index.php/site_admin/user/login")" > /dev/null || fail "HSTS missing on HTTPS responses although LHC_HSTS_MAX_AGE is set"

echo "compose stack smoke test passed"
