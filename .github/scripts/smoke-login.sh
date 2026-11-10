#!/usr/bin/env bash
# End-to-end smoke test against an installed Live Helper Chat.
#
#   smoke-login.sh <base-url> <username> <password>
#
# Starts PHP's built-in web server in the current directory (lhc_web), then:
#   1. the login page renders,
#   2. repeated wrong passwords trigger the brute-force lock,
#   3. the lock also blocks the REST API login with HTTP 429,
#   4. after the lock is removed, the right password logs in.
set -euo pipefail

BASE_URL="$1"; USERNAME="$2"; PASSWORD="$3"
PORT="${BASE_URL##*:}"
JAR="$(mktemp)"
LOG="$(mktemp)"

php -S "127.0.0.1:${PORT}" -t . > "$LOG" 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true; echo "--- php -S log"; cat "$LOG"' EXIT

for _ in $(seq 1 30); do
  curl -sf -o /dev/null "$BASE_URL/index.php/site_admin/user/login" && break
  sleep 1
done

fail() { echo "::error::$1"; exit 1; }

csrf_token() {
  curl -s -c "$JAR" -b "$JAR" "$BASE_URL/index.php/site_admin/user/login" \
    | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'
}

post_login() { # $1 = password, prints response body
  local token; token="$(csrf_token)"
  [ -n "$token" ] || fail "could not read CSRF token from the login page"
  curl -s -c "$JAR" -b "$JAR" -X POST "$BASE_URL/index.php/site_admin/user/login" \
    --data-urlencode "Username=$USERNAME" --data-urlencode "Password=$1" \
    --data-urlencode "csfr_token=$token" --data-urlencode "Login=Login"
}

echo "1. login page renders"
curl -sf -c "$JAR" "$BASE_URL/index.php/site_admin/user/login" | grep 'name="csfr_token"' > /dev/null || fail "login page did not render a login form"

echo "2. wrong passwords trigger the throttle"
for i in 1 2 3 4; do
  post_login "wrong-$i" | grep 'Incorrect username or password' > /dev/null || fail "attempt $i: expected 'Incorrect username or password'"
done
post_login "wrong-5" | grep 'Too many failed login attempts' > /dev/null || fail "5th failure should lock the account"
post_login "$PASSWORD" | grep 'Too many failed login attempts' > /dev/null || fail "correct password must be rejected while locked"

echo "3. REST API login honours the lock"
STATUS=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/index.php/restapi/login" \
  --data-urlencode "username=$USERNAME" --data-urlencode "password=$PASSWORD")
[ "$STATUS" = "429" ] || fail "REST login while locked returned HTTP $STATUS, expected 429"

echo "4. lock removal restores access"
php cron.php -s site_admin -c cron/login_monitoring > /dev/null   # prune must not delete an active lock
mysql_args=(--host=127.0.0.1 --user=lhc --password=lhcpass lhc)
LOCKS=$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_login_throttle WHERE locked_until > UNIX_TIMESTAMP()")
[ "$LOCKS" -ge 1 ] || fail "expected an active lock row in lh_login_throttle, found $LOCKS"
mysql "${mysql_args[@]}" -e "DELETE FROM lh_login_throttle"
post_login "$PASSWORD" > /dev/null
curl -s -b "$JAR" "$BASE_URL/index.php/site_admin" | grep 'user/logout' > /dev/null || fail "session is not logged in after a correct password"

echo "5. admin settings page shows the throttle section and active locks"
curl -s -o /dev/null -w '' -X POST "$BASE_URL/index.php/restapi/login" --data-urlencode "username=$USERNAME" --data-urlencode "password=nope-1"
curl -s -o /dev/null -w '' -X POST "$BASE_URL/index.php/restapi/login" --data-urlencode "username=$USERNAME" --data-urlencode "password=nope-2"
curl -s -o /dev/null -w '' -X POST "$BASE_URL/index.php/restapi/login" --data-urlencode "username=$USERNAME" --data-urlencode "password=nope-3"
curl -s -o /dev/null -w '' -X POST "$BASE_URL/index.php/restapi/login" --data-urlencode "username=$USERNAME" --data-urlencode "password=nope-4"
curl -s -o /dev/null -w '' -X POST "$BASE_URL/index.php/restapi/login" --data-urlencode "username=$USERNAME" --data-urlencode "password=nope-5"
PAGE=$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/index.php/site_admin/user/passwordrequirements")
echo "$PAGE" | grep 'name="throttle_ip_attempts"' > /dev/null || fail "password requirements page is missing the throttle settings"
echo "$PAGE" | grep "name=\"UnlockThrottle\"" > /dev/null || fail "active lock is not listed on the password requirements page"
LOCK_ID=$(echo "$PAGE" | grep -o 'name="UnlockThrottle" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
TOKEN=$(echo "$PAGE" | grep -o 'name="csfr_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/index.php/site_admin/user/passwordrequirements" \
  --data-urlencode "UnlockThrottle=$LOCK_ID" --data-urlencode "csfr_token=$TOKEN" | grep 'Lock removed' > /dev/null || fail "unlock action did not confirm"

echo "smoke test passed"
