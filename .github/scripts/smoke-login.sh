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

echo "1b. health probe answers 200 without details, details only with a login"
curl -sf "$BASE_URL/index.php/system/health" | grep '"status":"ok"' > /dev/null || fail "health probe is not ok"
curl -s "$BASE_URL/index.php/system/health" | grep -v '"checks"' > /dev/null || fail "anonymous health probe must not expose check details"

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

echo "6. health probe shows check details to a logged-in operator"
curl -s -b "$JAR" "$BASE_URL/index.php/system/health" | grep '"database":{"status":"ok"' > /dev/null || fail "logged-in health probe is missing check details"

echo "7. REST API keys honour scopes and expiry"
mysql "${mysql_args[@]}" -e "DELETE FROM lh_abstract_rest_api_key; INSERT INTO lh_abstract_rest_api_key (api_key, user_id, active, ip_restrictions, scopes, expires_at, last_used) VALUES ('scopedkey000000000000000000000000000000', 1, 1, '', 'chats,chat', '', 0), ('expiredkey00000000000000000000000000000', 1, 1, '', '', '2000-01-01', 0), ('openkey000000000000000000000000000000000', 1, 1, '', '', '', 0)"
api() { curl -s -o /dev/null -w '%{http_code}' -u "$USERNAME:$1" "$BASE_URL/index.php/restapi/$2"; }
api_body() { curl -s -u "$USERNAME:$1" "$BASE_URL/index.php/restapi/$2"; }
[ "$(api scopedkey000000000000000000000000000000 chats)" = "200" ] || fail "scoped key must reach an endpoint inside its scope"
STATUS=$(api scopedkey000000000000000000000000000000 departments)
[ "$STATUS" = "403" ] || fail "scoped key reached an endpoint outside its scope (HTTP $STATUS)"
api_body scopedkey000000000000000000000000000000 departments | grep 'not allowed to call this endpoint' > /dev/null || fail "scope denial message missing"
STATUS=$(api expiredkey00000000000000000000000000000 chats)
[ "$STATUS" = "401" ] || fail "expired key was accepted (HTTP $STATUS)"
[ "$(api openkey000000000000000000000000000000000 departments)" = "200" ] || fail "unscoped, unexpired key must work everywhere"
LAST_USED=$(mysql "${mysql_args[@]}" -N -e "SELECT last_used FROM lh_abstract_rest_api_key WHERE api_key='openkey000000000000000000000000000000000'")
[ "$LAST_USED" -gt 0 ] || fail "last_used was not recorded for an accepted key"
DENIALS=$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_audits WHERE category='ApiKey'")
[ "$DENIALS" -ge 2 ] || fail "API key denials were not written to the audit log (found $DENIALS)"

echo "8. OpenID Connect single sign-on provisions and signs in an operator"
MOCK_PORT=9400
rm -f "${TMPDIR:-/tmp}/lhc-mock-oidc-key.pem" "${TMPDIR:-/tmp}/lhc-mock-oidc-codes.json"
php -S "127.0.0.1:${MOCK_PORT}" "$(dirname "$0")/mock-oidc.php" > /dev/null 2>&1 &
MOCK_PID=$!
trap 'kill $SERVER_PID $MOCK_PID 2>/dev/null || true; echo "--- php -S log"; cat "$LOG"' EXIT
for _ in $(seq 1 20); do curl -sf -o /dev/null "http://127.0.0.1:${MOCK_PORT}/.well-known/openid-configuration" && break; sleep 1; done
SSO_SETTINGS=$(php -r 'echo serialize(array("enabled"=>1,"issuer"=>"http://127.0.0.1:9400","client_id"=>"lhc-smoke","client_secret"=>"smoke-secret","scopes"=>"openid profile email","auto_provision"=>1,"default_group_id"=>0,"groups_claim"=>"groups","group_map"=>"support-agents=1","sync_groups"=>1,"force_sso"=>0));')
mysql "${mysql_args[@]}" -e "INSERT INTO lh_chat_config (\`identifier\`, \`value\`, \`type\`, \`explain\`, \`hidden\`) VALUES ('sso_oidc', '$SSO_SETTINGS', 0, 'OpenID Connect single sign-on settings', 1) ON DUPLICATE KEY UPDATE \`value\`='$SSO_SETTINGS'; DELETE FROM lh_users WHERE username='sso.user'"
SSO_JAR="$(mktemp)"
curl -s -c "$SSO_JAR" -b "$SSO_JAR" "$BASE_URL/index.php/site_admin/user/login" | grep 'sso/login' > /dev/null || fail "login page does not show the single sign-on button"
# follow: sso/login -> provider /authorize -> sso/callback -> site_admin
FINAL=$(curl -s -L -c "$SSO_JAR" -b "$SSO_JAR" -o /dev/null -w '%{url_effective}' "$BASE_URL/index.php/site_admin/sso/login")
echo "   landed on $FINAL"
curl -s -b "$SSO_JAR" "$BASE_URL/index.php/site_admin" | grep 'user/logout' > /dev/null || fail "SSO callback did not produce a logged-in session"
PROVISIONED=$(mysql "${mysql_args[@]}" -N -e "SELECT CONCAT(username,'|',email,'|',name,'|',disabled) FROM lh_users WHERE username='sso.user'")
[ "$PROVISIONED" = "sso.user|sso.user@example.com|Sso|0" ] || fail "operator was not provisioned from the ID token claims (got '$PROVISIONED')"
IN_GROUP=$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_groupuser gu JOIN lh_users u ON u.id=gu.user_id WHERE u.username='sso.user' AND gu.group_id=1")
[ "$IN_GROUP" = "1" ] || fail "group mapping support-agents=1 was not applied"
SSO_AUDIT=$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_audits WHERE category='SingleSignOn'")
[ "$SSO_AUDIT" -ge 1 ] || fail "SSO login was not written to the audit log"
# a forged state must be rejected
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/index.php/site_admin/sso/callback?code=x&state=forged")" = "302" ] || fail "callback with a forged state should redirect back to the login page"
mysql "${mysql_args[@]}" -e "DELETE FROM lh_chat_config WHERE identifier='sso_oidc'"

echo "smoke test passed"
