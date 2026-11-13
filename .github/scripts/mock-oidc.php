<?php
/**
 * Minimal OpenID Connect provider for the CI smoke test. Run with:
 *   php -S 127.0.0.1:9400 .github/scripts/mock-oidc.php
 *
 * Any login succeeds and returns a fixed user with the groups claim
 * ["support-agents"]. The RSA key pair is generated once and kept in the
 * system temp directory so every request of the built-in server shares it.
 */
$issuer = 'http://127.0.0.1:9400';
$keyFile = sys_get_temp_dir() . '/lhc-mock-oidc-key.pem';
if (!file_exists($keyFile)) {
    $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    openssl_pkey_export($key, $pem);
    file_put_contents($keyFile, $pem);
}
$privateKey = openssl_pkey_get_private(file_get_contents($keyFile));
$details = openssl_pkey_get_details($privateKey);
$b64 = function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$codes = sys_get_temp_dir() . '/lhc-mock-oidc-codes.json';
$store = file_exists($codes) ? json_decode(file_get_contents($codes), true) : array();

switch ($path) {
    case '/.well-known/openid-configuration':
        header('Content-Type: application/json');
        echo json_encode(array(
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/authorize',
            'token_endpoint' => $issuer . '/token',
            'jwks_uri' => $issuer . '/jwks',
            'userinfo_endpoint' => $issuer . '/userinfo',
            'response_types_supported' => array('code'),
            'id_token_signing_alg_values_supported' => array('RS256'),
        ));
        break;

    case '/jwks':
        header('Content-Type: application/json');
        echo json_encode(array('keys' => array(array('kty' => 'RSA', 'use' => 'sig', 'kid' => 'mock-1', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])))));
        break;

    case '/authorize':
        $code = bin2hex(random_bytes(8));
        $store[$code] = array('nonce' => isset($_GET['nonce']) ? $_GET['nonce'] : '', 'client_id' => $_GET['client_id'], 'challenge' => isset($_GET['code_challenge']) ? $_GET['code_challenge'] : '');
        file_put_contents($codes, json_encode($store));
        header('Location: ' . $_GET['redirect_uri'] . (strpos($_GET['redirect_uri'], '?') === false ? '?' : '&') . http_build_query(array('code' => $code, 'state' => $_GET['state'])), true, 302);
        break;

    case '/token':
        header('Content-Type: application/json');
        $code = isset($_POST['code']) ? $_POST['code'] : '';
        if (!isset($store[$code]) || $_POST['client_id'] !== 'lhc-smoke' || $_POST['client_secret'] !== 'smoke-secret') {
            http_response_code(400);
            echo json_encode(array('error' => 'invalid_grant'));
            break;
        }
        $pending = $store[$code];
        unset($store[$code]);
        file_put_contents($codes, json_encode($store));
        if ($pending['challenge'] !== '' && $b64(hash('sha256', $_POST['code_verifier'], true)) !== $pending['challenge']) {
            http_response_code(400);
            echo json_encode(array('error' => 'invalid_grant', 'error_description' => 'PKCE verifier mismatch'));
            break;
        }
        $now = time();
        $claims = array('iss' => $issuer, 'aud' => $pending['client_id'], 'sub' => 'mock-user-42', 'iat' => $now, 'exp' => $now + 300, 'nonce' => $pending['nonce'],
            'preferred_username' => 'sso.user', 'email' => 'sso.user@example.com', 'given_name' => 'Sso', 'family_name' => 'User', 'groups' => array('support-agents'));
        $header = $b64(json_encode(array('alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'mock-1')));
        $payload = $b64(json_encode($claims));
        openssl_sign($header . '.' . $payload, $sig, $privateKey, OPENSSL_ALGO_SHA256);
        echo json_encode(array('access_token' => 'mock-access-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => $header . '.' . $payload . '.' . $b64($sig)));
        break;

    case '/userinfo':
        header('Content-Type: application/json');
        echo json_encode(array('sub' => 'mock-user-42', 'preferred_username' => 'sso.user', 'email' => 'sso.user@example.com'));
        break;

    default:
        http_response_code(404);
        echo 'mock oidc: unknown path ' . $path;
}
