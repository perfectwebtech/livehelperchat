<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure parts of erLhcoreClassSsoOidc: JWT verification against a JWKS,
 * claim validation, group mapping and username derivation.
 */
class SsoOidcTest extends TestCase
{
    private static $privateKey;
    private static $jwk;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
        self::$privateKey = $key;
        $details = openssl_pkey_get_details($key);
        self::$jwk = array(
            'kty' => 'RSA',
            'kid' => 'test-key',
            'n' => erLhcoreClassSsoOidc::base64url($details['rsa']['n']),
            'e' => erLhcoreClassSsoOidc::base64url($details['rsa']['e']),
        );
    }

    private function token(array $claims, $alg = 'RS256', $kid = 'test-key', $tamper = false)
    {
        $header = erLhcoreClassSsoOidc::base64url(json_encode(array('alg' => $alg, 'typ' => 'JWT', 'kid' => $kid)));
        $payload = erLhcoreClassSsoOidc::base64url(json_encode($claims));
        openssl_sign($header . '.' . $payload, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);
        if ($tamper) {
            $payload = erLhcoreClassSsoOidc::base64url(json_encode(array_merge($claims, array('sub' => 'someone-else'))));
        }
        return $header . '.' . $payload . '.' . erLhcoreClassSsoOidc::base64url($signature);
    }

    private function expected()
    {
        return array('issuer' => 'https://idp.example.com/', 'client_id' => 'lhc-client', 'nonce' => 'n0nce', 'now' => 1700000000);
    }

    private function goodClaims()
    {
        return array('iss' => 'https://idp.example.com', 'aud' => 'lhc-client', 'sub' => 'user-1', 'exp' => 1700000300, 'iat' => 1699999900, 'nonce' => 'n0nce', 'email' => 'Jane@Example.com');
    }

    public function testJwkToPemMatchesOpensslExport()
    {
        $details = openssl_pkey_get_details(self::$privateKey);
        $this->assertSame(trim($details['key']), trim(erLhcoreClassSsoOidc::jwkToPem(self::$jwk)));
    }

    public function testValidTokenIsAccepted()
    {
        $claims = erLhcoreClassSsoOidc::verifyIdToken($this->token($this->goodClaims()), array('keys' => array(self::$jwk)), $this->expected());
        $this->assertSame('user-1', $claims['sub']);
    }

    public function testAudienceMayBeAnArray()
    {
        $claims = $this->goodClaims(); $claims['aud'] = array('other', 'lhc-client');
        $this->assertSame('user-1', erLhcoreClassSsoOidc::verifyIdToken($this->token($claims), array('keys' => array(self::$jwk)), $this->expected())['sub']);
    }

    public function testTamperedPayloadIsRejected()
    {
        $this->expectExceptionMessage('signature is invalid');
        erLhcoreClassSsoOidc::verifyIdToken($this->token($this->goodClaims(), 'RS256', 'test-key', true), array('keys' => array(self::$jwk)), $this->expected());
    }

    public function testAlgNoneIsRejected()
    {
        $this->expectExceptionMessage('RS256 is required');
        erLhcoreClassSsoOidc::verifyIdToken($this->token($this->goodClaims(), 'none'), array('keys' => array(self::$jwk)), $this->expected());
    }

    public function testUnknownKeyIdIsRejected()
    {
        $this->expectExceptionMessage('No matching signing key');
        erLhcoreClassSsoOidc::verifyIdToken($this->token($this->goodClaims(), 'RS256', 'other-kid'), array('keys' => array(self::$jwk, array_merge(self::$jwk, array('kid' => 'second')))), $this->expected());
    }

    public function testWrongIssuerAudienceExpiryAndNonceAreRejected()
    {
        foreach (array(
            array('iss' => 'https://evil.example.com'),
            array('aud' => 'another-client'),
            array('exp' => 1699999000),
            array('nonce' => 'different'),
        ) as $override) {
            try {
                erLhcoreClassSsoOidc::verifyIdToken($this->token(array_merge($this->goodClaims(), $override)), array('keys' => array(self::$jwk)), $this->expected());
                $this->fail('token with ' . json_encode($override) . ' must be rejected');
            } catch (Exception $e) {
                $this->assertStringContainsString('ID token', $e->getMessage());
            }
        }
    }

    public function testGroupMapParsingAndResolution()
    {
        $settings = erLhcoreClassSsoOidc::normalizeSettings(array('groups_claim' => 'groups', 'group_map' => "# comment\nSupport-Agents = 2, 3\nadmins=1\nbroken line\n"));
        $this->assertSame(array('support-agents' => array(2, 3), 'admins' => array(1)), erLhcoreClassSsoOidc::parseGroupMap($settings['group_map']));
        $this->assertSame(array(2, 3), erLhcoreClassSsoOidc::mappedGroupIds(array('groups' => array('support-agents', 'unknown')), $settings));
        $this->assertSame(array(), erLhcoreClassSsoOidc::mappedGroupIds(array(), $settings));
        $this->assertSame(array(2, 3, 1), erLhcoreClassSsoOidc::managedGroupIds($settings['group_map']));
    }

    public function testUsernameDerivation()
    {
        $settings = erLhcoreClassSsoOidc::normalizeSettings(array());
        $this->assertSame('jdoe', erLhcoreClassSsoOidc::usernameFromClaims(array('preferred_username' => ' JDoe ', 'email' => 'j@x.com'), $settings));
        $this->assertSame('j@x.com', erLhcoreClassSsoOidc::usernameFromClaims(array('email' => 'J@X.com'), $settings));
        $this->assertSame('sub-1', erLhcoreClassSsoOidc::usernameFromClaims(array('sub' => 'sub-1'), $settings));
    }

    public function testEmailDomainAllowList()
    {
        $this->assertTrue(erLhcoreClassSsoOidc::emailDomainAllowed('a@b.com', ''));
        $this->assertTrue(erLhcoreClassSsoOidc::emailDomainAllowed('a@Example.COM', 'example.com, other.org'));
        $this->assertFalse(erLhcoreClassSsoOidc::emailDomainAllowed('a@evil.com', 'example.com'));
        $this->assertFalse(erLhcoreClassSsoOidc::emailDomainAllowed('no-at-sign', 'example.com'));
    }

    public function testSettingsNormalisation()
    {
        $s = erLhcoreClassSsoOidc::normalizeSettings(array('issuer' => ' https://idp.example.com/ ', 'enabled' => '1', 'default_group_id' => '7'));
        $this->assertSame('https://idp.example.com', $s['issuer']);
        $this->assertSame(1, $s['enabled']);
        $this->assertSame(7, $s['default_group_id']);
        $this->assertSame('openid profile email', $s['scopes']);
    }
}
