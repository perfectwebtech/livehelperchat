<?php

/**
 * OpenID Connect single sign-on for operators (authorization code flow with PKCE).
 *
 * Works with any standards compliant provider (Microsoft Entra ID / Azure AD,
 * Okta, Keycloak, Google Workspace, Auth0, ...) through OIDC discovery:
 * only the issuer URL, client id and client secret are required.
 *
 * Flow:
 *   sso/login     -> builds state, nonce and PKCE verifier, redirects to the provider
 *   sso/callback  -> validates state, exchanges the code, verifies the ID token
 *                    signature (RS256 via JWKS) and claims, provisions / maps the
 *                    operator, starts the session.
 *
 * Settings live in the `sso_oidc` configuration row (System configuration ->
 * Single sign-on). No external library is required: JWT verification uses
 * ext/openssl, HTTP uses ext/curl.
 */
class erLhcoreClassSsoOidc
{
    public static $defaults = array(
        'enabled' => 0,
        'issuer' => '',
        'client_id' => '',
        'client_secret' => '',
        'scopes' => 'openid profile email',
        'button_label' => 'Sign in with single sign-on',
        'username_claim' => 'preferred_username',
        'email_claim' => 'email',
        'name_claim' => 'given_name',
        'surname_claim' => 'family_name',
        'groups_claim' => 'groups',
        'group_map' => '',          // one "provider-group=lhc-group-id" per line
        'default_group_id' => 0,    // group for newly provisioned operators (0 = none)
        'auto_provision' => 1,      // create unknown operators on first login
        'sync_groups' => 0,         // on every login, align mapped groups with the provider
        'allowed_domains' => '',    // comma separated email domains, empty = any
        'force_sso' => 0,           // hide the password form (append ?local=1 to show it)
    );

    /** @var array|null injected by tests */
    public static $settingsOverride = null;

    /** @var callable|null injected by tests: function($url, $postFields = null, $headers = array()) : string */
    public static $httpClient = null;

    const SESSION_KEY = 'lhc_sso_oidc';

    // ------------------------------------------------------------------ settings

    public static function getSettings()
    {
        if (self::$settingsOverride !== null) {
            return self::normalizeSettings(self::$settingsOverride);
        }
        return self::normalizeSettings((array)erLhcoreClassModelChatConfig::fetch('sso_oidc')->data);
    }

    public static function normalizeSettings(array $data)
    {
        $settings = array();
        foreach (self::$defaults as $key => $default) {
            $value = array_key_exists($key, $data) && $data[$key] !== null ? $data[$key] : $default;
            $settings[$key] = is_int($default) ? (int)$value : trim((string)$value);
        }
        $settings['issuer'] = rtrim($settings['issuer'], '/');
        return $settings;
    }

    public static function isEnabled()
    {
        $s = self::getSettings();
        return $s['enabled'] == 1 && $s['issuer'] !== '' && $s['client_id'] !== '';
    }

    public static function saveSettings(array $data)
    {
        $row = erLhcoreClassModelChatConfig::fetch('sso_oidc');
        $row->identifier = 'sso_oidc';
        $row->explain = 'OpenID Connect single sign-on settings';
        $row->type = 0;
        $row->hidden = 1;
        $row->value = serialize(self::normalizeSettings($data));
        $row->saveThis();
        erConfigClassLhCacheConfig::getInstance()->expireCache();
    }

    // ------------------------------------------------------------------ discovery / http

    public static function http($url, $postFields = null, array $headers = array())
    {
        if (self::$httpClient !== null) {
            return call_user_func(self::$httpClient, $url, $postFields, $headers);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Accept: application/json'), $headers));
        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postFields) ? http_build_query($postFields) : $postFields);
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new Exception('Identity provider request failed (' . ($error !== '' ? $error : 'HTTP ' . $status) . ')');
        }
        return $body;
    }

    public static function discover($issuer)
    {
        $doc = json_decode(self::http(rtrim($issuer, '/') . '/.well-known/openid-configuration'), true);
        foreach (array('authorization_endpoint', 'token_endpoint', 'jwks_uri') as $required) {
            if (!is_array($doc) || empty($doc[$required])) {
                throw new Exception('Identity provider discovery document is missing ' . $required);
            }
        }
        return $doc;
    }

    // ------------------------------------------------------------------ login start

    public static function startLogin($redirectAfter = '')
    {
        $settings = self::getSettings();
        $config = self::discover($settings['issuer']);

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = self::base64url(random_bytes(48));

        $_SESSION[self::SESSION_KEY] = array(
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'redirect' => (string)$redirectAfter,
            'created' => time(),
        );

        $query = array(
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => self::redirectUri(),
            'scope' => $settings['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        );

        return $config['authorization_endpoint'] . (strpos($config['authorization_endpoint'], '?') === false ? '?' : '&') . http_build_query($query);
    }

    public static function redirectUri()
    {
        return erLhcoreClassSystem::getHost() . erLhcoreClassDesign::baseurl('sso/callback');
    }

    // ------------------------------------------------------------------ callback

    /**
     * Completes the login. Returns the operator, throws on any failure.
     *
     * @return erLhcoreClassModelUser
     */
    public static function completeLogin($code, $state)
    {
        $settings = self::getSettings();
        $pending = isset($_SESSION[self::SESSION_KEY]) ? $_SESSION[self::SESSION_KEY] : null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($pending) || $state === '' || !hash_equals($pending['state'], (string)$state) || time() - $pending['created'] > 600) {
            throw new Exception('Single sign-on session is invalid or has expired, please try again');
        }

        $config = self::discover($settings['issuer']);

        $tokens = json_decode(self::http($config['token_endpoint'], array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'code_verifier' => $pending['verifier'],
        )), true);

        if (!is_array($tokens) || empty($tokens['id_token'])) {
            throw new Exception('Identity provider did not return an ID token');
        }

        $jwks = json_decode(self::http($config['jwks_uri']), true);

        $claims = self::verifyIdToken($tokens['id_token'], is_array($jwks) ? $jwks : array(), array(
            'issuer' => $settings['issuer'],
            'client_id' => $settings['client_id'],
            'nonce' => $pending['nonce'],
            'now' => time(),
        ));

        // Fill in missing profile claims from the userinfo endpoint when available.
        if (!empty($tokens['access_token']) && !empty($config['userinfo_endpoint']) && (empty($claims[$settings['email_claim']]) || empty($claims[$settings['username_claim']]))) {
            $info = json_decode(self::http($config['userinfo_endpoint'], null, array('Authorization: Bearer ' . $tokens['access_token'])), true);
            if (is_array($info) && isset($info['sub']) && $info['sub'] === $claims['sub']) {
                $claims = array_merge($info, $claims);
            }
        }

        return self::provisionUser($claims, $settings);
    }

    // ------------------------------------------------------------------ JWT

    /**
     * Verifies signature (RS256 against the JWKS) and the standard claims.
     * Pure: no I/O, so it is unit tested with a locally generated key.
     *
     * @return array claims
     */
    public static function verifyIdToken($jwt, array $jwks, array $expected)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Exception('ID token is malformed');
        }

        $header = json_decode(self::base64urlDecode($parts[0]), true);
        $claims = json_decode(self::base64urlDecode($parts[1]), true);
        $signature = self::base64urlDecode($parts[2]);

        if (!is_array($header) || !is_array($claims)) {
            throw new Exception('ID token is malformed');
        }

        if (!isset($header['alg']) || $header['alg'] !== 'RS256') {
            throw new Exception('Unsupported ID token algorithm, RS256 is required');
        }

        $key = self::findJwk($jwks, isset($header['kid']) ? $header['kid'] : null);
        if ($key === null) {
            throw new Exception('No matching signing key found in the provider JWKS');
        }

        $pem = self::jwkToPem($key);
        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new Exception('ID token signature is invalid');
        }

        $now = isset($expected['now']) ? (int)$expected['now'] : time();
        $leeway = 60;

        if (!isset($claims['iss']) || rtrim((string)$claims['iss'], '/') !== rtrim((string)$expected['issuer'], '/')) {
            throw new Exception('ID token issuer does not match');
        }

        $aud = isset($claims['aud']) ? (array)$claims['aud'] : array();
        if (!in_array($expected['client_id'], $aud, true)) {
            throw new Exception('ID token audience does not match');
        }

        if (!isset($claims['exp']) || (int)$claims['exp'] + $leeway < $now) {
            throw new Exception('ID token has expired');
        }

        if (isset($claims['nbf']) && (int)$claims['nbf'] - $leeway > $now) {
            throw new Exception('ID token is not valid yet');
        }

        if (isset($expected['nonce']) && $expected['nonce'] !== '' && (!isset($claims['nonce']) || !hash_equals((string)$expected['nonce'], (string)$claims['nonce']))) {
            throw new Exception('ID token nonce does not match');
        }

        if (empty($claims['sub'])) {
            throw new Exception('ID token has no subject');
        }

        return $claims;
    }

    private static function findJwk(array $jwks, $kid)
    {
        $keys = isset($jwks['keys']) && is_array($jwks['keys']) ? $jwks['keys'] : array();
        $candidate = null;
        foreach ($keys as $key) {
            if (!is_array($key) || (isset($key['kty']) && $key['kty'] !== 'RSA') || empty($key['n']) || empty($key['e'])) {
                continue;
            }
            if ($kid !== null && isset($key['kid']) && $key['kid'] === $kid) {
                return $key;
            }
            if ($candidate === null) {
                $candidate = $key;
            }
        }
        return $kid === null || count($keys) === 1 ? $candidate : null;
    }

    /**
     * Builds a PEM encoded SubjectPublicKeyInfo from an RSA JWK (n, e).
     */
    public static function jwkToPem(array $jwk)
    {
        $modulus = self::base64urlDecode($jwk['n']);
        $exponent = self::base64urlDecode($jwk['e']);

        $rsaPublicKey = self::asn1Sequence(self::asn1Integer($modulus) . self::asn1Integer($exponent));
        $algorithm = self::asn1Sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"); // rsaEncryption OID + NULL
        $spki = self::asn1Sequence($algorithm . "\x03" . self::asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1Integer($bytes)
    {
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes; // keep it positive
        }
        return "\x02" . self::asn1Length(strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence($content)
    {
        return "\x30" . self::asn1Length(strlen($content)) . $content;
    }

    private static function asn1Length($length)
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function base64url($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode($data)
    {
        $data = strtr((string)$data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? '' : $decoded;
    }

    // ------------------------------------------------------------------ provisioning

    /**
     * Username for an operator derived from the claims: the configured claim,
     * then the e-mail address, then the subject. Lower-cased and trimmed.
     */
    public static function usernameFromClaims(array $claims, array $settings)
    {
        foreach (array($settings['username_claim'], $settings['email_claim'], 'sub') as $claim) {
            if ($claim !== '' && !empty($claims[$claim]) && is_string($claims[$claim])) {
                return mb_strtolower(trim($claims[$claim]));
            }
        }
        return '';
    }

    /**
     * Resolves "provider-group=lhc-group-id" lines against the groups claim.
     * @return int[] LHC group ids the operator should be in according to the provider
     */
    public static function mappedGroupIds(array $claims, array $settings)
    {
        $map = self::parseGroupMap($settings['group_map']);
        if (empty($map)) {
            return array();
        }
        $providerGroups = isset($claims[$settings['groups_claim']]) ? (array)$claims[$settings['groups_claim']] : array();
        $ids = array();
        foreach ($providerGroups as $group) {
            $group = mb_strtolower(trim((string)$group));
            if (isset($map[$group])) {
                foreach ($map[$group] as $id) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * @return array provider group (lower-cased) => int[] lhc group ids
     */
    public static function parseGroupMap($text)
    {
        $map = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($group, $ids) = explode('=', $line, 2);
            $group = mb_strtolower(trim($group));
            foreach (explode(',', $ids) as $id) {
                $id = (int)trim($id);
                if ($group !== '' && $id > 0) {
                    $map[$group][] = $id;
                }
            }
        }
        return $map;
    }

    /**
     * All LHC group ids mentioned anywhere in the map (the set this feature manages).
     * @return int[]
     */
    public static function managedGroupIds($text)
    {
        $ids = array();
        foreach (self::parseGroupMap($text) as $list) {
            foreach ($list as $id) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    public static function emailDomainAllowed($email, $allowedDomains)
    {
        $allowed = array_filter(array_map('trim', explode(',', mb_strtolower((string)$allowedDomains))));
        if (empty($allowed)) {
            return true;
        }
        $at = strrpos((string)$email, '@');
        if ($at === false) {
            return false;
        }
        return in_array(mb_strtolower(substr($email, $at + 1)), $allowed, true);
    }

    /**
     * Finds (by username, then e-mail) or creates the operator and applies group mapping.
     *
     * @return erLhcoreClassModelUser
     */
    public static function provisionUser(array $claims, array $settings)
    {
        $username = self::usernameFromClaims($claims, $settings);
        $email = !empty($claims[$settings['email_claim']]) && is_string($claims[$settings['email_claim']]) ? mb_strtolower(trim($claims[$settings['email_claim']])) : '';

        if ($username === '') {
            throw new Exception('Identity provider did not supply a usable username');
        }

        if (!self::emailDomainAllowed($email !== '' ? $email : $username, $settings['allowed_domains'])) {
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_SSO, 'denied_domain', array('username' => $username, 'email' => $email, 'sub' => $claims['sub']));
            throw new Exception('Your account domain is not allowed to sign in here');
        }

        $user = erLhcoreClassModelUser::findOne(array('filter' => array('username' => $username)));
        if (!($user instanceof erLhcoreClassModelUser) && $email !== '') {
            $user = erLhcoreClassModelUser::findOne(array('filter' => array('email' => $email)));
        }

        $created = false;

        if (!($user instanceof erLhcoreClassModelUser)) {
            if ($settings['auto_provision'] != 1) {
                erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_SSO, 'denied_unknown_user', array('username' => $username, 'email' => $email, 'sub' => $claims['sub']));
                throw new Exception('No operator account exists for this identity and automatic provisioning is disabled');
            }

            $user = new erLhcoreClassModelUser();
            $user->username = mb_substr($username, 0, 40);
            $user->email = mb_substr($email !== '' ? $email : $username, 0, 100);
            $user->name = mb_substr(!empty($claims[$settings['name_claim']]) ? (string)$claims[$settings['name_claim']] : $username, 0, 100);
            $user->surname = mb_substr(!empty($claims[$settings['surname_claim']]) ? (string)$claims[$settings['surname_claim']] : '', 0, 100);
            $user->disabled = 0;
            $user->time_zone = '';
            $user->setPassword(bin2hex(random_bytes(24))); // unknown to anyone: SSO is the only way in
            $user->saveThis();
            $created = true;

            if ($settings['default_group_id'] > 0) {
                self::addToGroup($user->id, $settings['default_group_id']);
            }
        }

        if ($user->disabled == 1) {
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_SSO, 'denied_disabled', array('user_id' => (int)$user->id, 'username' => $user->username), $user->id);
            throw new Exception('Your account is disabled!');
        }

        $mapped = self::mappedGroupIds($claims, $settings);
        if ($created || $settings['sync_groups'] == 1) {
            self::applyGroups($user->id, $mapped, $settings['sync_groups'] == 1 ? self::managedGroupIds($settings['group_map']) : array());
        }

        erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_SSO, $created ? 'login_provisioned' : 'login', array(
            'user_id' => (int)$user->id,
            'username' => $user->username,
            'sub' => $claims['sub'],
            'issuer' => $settings['issuer'],
            'mapped_groups' => $mapped,
        ), $user->id);

        return $user;
    }

    private static function addToGroup($userId, $groupId)
    {
        $existing = erLhcoreClassModelGroupUser::getCount(array('filter' => array('user_id' => $userId, 'group_id' => $groupId)));
        if ($existing == 0) {
            $groupUser = new erLhcoreClassModelGroupUser();
            $groupUser->group_id = (int)$groupId;
            $groupUser->user_id = (int)$userId;
            $groupUser->saveThis();
        }
    }

    /**
     * Ensures membership of $wanted. When $managed is given, memberships of
     * managed groups that the provider no longer grants are removed; groups
     * outside the map are never touched.
     */
    private static function applyGroups($userId, array $wanted, array $managed)
    {
        foreach ($wanted as $groupId) {
            self::addToGroup($userId, $groupId);
        }

        $toRemove = array_diff($managed, $wanted);
        if (!empty($toRemove)) {
            $memberships = erLhcoreClassModelGroupUser::getList(array('limit' => false, 'filter' => array('user_id' => $userId), 'filterin' => array('group_id' => array_values($toRemove))));
            foreach ($memberships as $membership) {
                $membership->removeThis();
            }
        }

        if (!empty($wanted) || !empty($toRemove)) {
            erLhcoreClassAdminChatValidatorHelper::clearUsersCache();
        }
    }
}
