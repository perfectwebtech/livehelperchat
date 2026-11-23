#!/usr/bin/env bash
# Single sign-on against a real OpenID Connect provider (Keycloak, see
# .github/keycloak/lhc-realm.json), run from lhc_web after the smoke test.
#
# Drives the browser flow with curl: LHC sso/login -> Keycloak login form ->
# credentials -> LHC callback -> back office. Keycloak enforces PKCE (S256)
# for the client, so the flow only succeeds if LHC sends a correct challenge
# and verifier. Then checks provisioning, group mapping, the audit entry,
# and a second sign-in on the existing Keycloak session with group sync.
set -euo pipefail

BASE_URL=http://127.0.0.1:8080
ISSUER=http://127.0.0.1:8180/realms/lhc
mysql_args=(--host=127.0.0.1 --user=lhc --password=lhcpass lhc)
LOG="$(mktemp)"
JAR="$(mktemp)"

fail() { echo "::error::$1"; echo "--- php -S log"; tail -n 40 "$LOG"; exit 1; }

echo "waiting for Keycloak"
for _ in $(seq 1 90); do
  curl -sf -o /dev/null "$ISSUER/.well-known/openid-configuration" && break
  sleep 2
done
curl -sf -o /dev/null "$ISSUER/.well-known/openid-configuration" || fail "Keycloak did not come up"

SETTINGS=$(php -r 'echo serialize(array("enabled"=>1,"issuer"=>"http://127.0.0.1:8180/realms/lhc","client_id"=>"lhc-web","client_secret"=>"kc-ci-client-secret","scopes"=>"openid profile email","auto_provision"=>1,"default_group_id"=>0,"groups_claim"=>"groups","group_map"=>"support-agents=1","sync_groups"=>1,"allowed_domains"=>"example.com","force_sso"=>0));')
mysql "${mysql_args[@]}" -e "INSERT INTO lh_chat_config (\`identifier\`, \`value\`, \`type\`, \`explain\`, \`hidden\`) VALUES ('sso_oidc', '$SETTINGS', 0, 'OpenID Connect single sign-on settings', 1) ON DUPLICATE KEY UPDATE \`value\`='$SETTINGS'; DELETE FROM lh_users WHERE username='kc.agent'"

php -S 127.0.0.1:8080 -t . > "$LOG" 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf -o /dev/null "$BASE_URL/index.php/site_admin/user/login" && break; sleep 1; done

echo "1. LHC redirects to the Keycloak login form"
LOGIN_PAGE=$(curl -s -L -c "$JAR" -b "$JAR" "$BASE_URL/index.php/site_admin/sso/login")
ACTION=$(tr '\n' ' ' <<< "$LOGIN_PAGE" | grep -o '<form[^>]*id="kc-form-login"[^>]*>' | grep -o 'action="[^"]*"' | head -1 | sed 's/^action="//; s/"$//; s/&amp;/\&/g') || true
[ -n "$ACTION" ] || { echo "$LOGIN_PAGE" | head -c 2000; fail "Keycloak login form not reached (check redirect_uri / PKCE parameters)"; }

echo "2. credentials at Keycloak, code exchange and session in LHC"
FINAL=$(curl -s -L -c "$JAR" -b "$JAR" -o /dev/null -w '%{url_effective}' \
  --data-urlencode "username=kc.agent" --data-urlencode 'password=KcAgent!234' --data-urlencode "credentialId=" "$ACTION")
echo "   landed on $FINAL"
curl -s -b "$JAR" "$BASE_URL/index.php/site_admin" | grep 'user/logout' > /dev/null || fail "not signed in to LHC after the Keycloak login"

echo "3. operator provisioned from the Keycloak ID token"
USER_ROW=$(mysql "${mysql_args[@]}" -N -e "SELECT CONCAT(username,'|',email,'|',name,'|',surname,'|',disabled) FROM lh_users WHERE username='kc.agent'")
[ "$USER_ROW" = "kc.agent|kc.agent@example.com|Keycloak|Agent|0" ] || fail "unexpected provisioned operator: '$USER_ROW'"
USER_ID=$(mysql "${mysql_args[@]}" -N -e "SELECT id FROM lh_users WHERE username='kc.agent'")
[ "$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_groupuser WHERE user_id=$USER_ID AND group_id=1")" = "1" ] || fail "Keycloak group support-agents was not mapped to LHC group 1"
[ "$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_audits WHERE category='SingleSignOn' AND message LIKE '%login_provisioned%' AND message LIKE '%kc.agent%'")" -ge 1 ] || fail "provisioning was not audited"

echo "4. second sign-in reuses the Keycloak session and re-applies group mapping"
mysql "${mysql_args[@]}" -e "DELETE FROM lh_groupuser WHERE user_id=$USER_ID"
LHC_ONLY_JAR="$(mktemp)"; grep -v 'PHPSESSID' "$JAR" > "$LHC_ONLY_JAR" || true   # keep Keycloak's cookies, drop the LHC session
FINAL=$(curl -s -L -c "$LHC_ONLY_JAR" -b "$LHC_ONLY_JAR" -o /dev/null -w '%{url_effective}' "$BASE_URL/index.php/site_admin/sso/login")
case "$FINAL" in "$BASE_URL"/index.php/site_admin*) ;; *) fail "second sign-in did not return to LHC directly (ended at $FINAL)" ;; esac
curl -s -b "$LHC_ONLY_JAR" "$BASE_URL/index.php/site_admin" | grep 'user/logout' > /dev/null || fail "second sign-in did not create a session"
[ "$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_groupuser WHERE user_id=$USER_ID AND group_id=1")" = "1" ] || fail "group sync did not restore the mapped group"
[ "$(mysql "${mysql_args[@]}" -N -e "SELECT COUNT(*) FROM lh_users WHERE username='kc.agent'")" = "1" ] || fail "second sign-in created a duplicate operator"

mysql "${mysql_args[@]}" -e "DELETE FROM lh_chat_config WHERE identifier='sso_oidc'"
echo "Keycloak single sign-on test passed"
