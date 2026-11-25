<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure logic of scoped, expiring REST API keys (erLhAbstractModelRestAPIKey).
 */
class RestApiKeyTest extends TestCase
{
    public function testScopesAreCleanedAndDeduplicated()
    {
        $this->assertSame(array('chats', 'chat', 'departments'), erLhAbstractModelRestAPIKey::parseScopes(' Chats, chat ,,departments, chats, bad name, x;y '));
        $this->assertSame(array(), erLhAbstractModelRestAPIKey::parseScopes(''));
    }

    public function testEmptyScopeListAllowsEveryView()
    {
        $this->assertTrue(erLhAbstractModelRestAPIKey::viewAllowed('', 'chats'));
        $this->assertTrue(erLhAbstractModelRestAPIKey::viewAllowed(' , ', 'anything'));
    }

    public function testScopedKeyOnlyAllowsListedViews()
    {
        $this->assertTrue(erLhAbstractModelRestAPIKey::viewAllowed('chats,chat', 'chats'));
        $this->assertTrue(erLhAbstractModelRestAPIKey::viewAllowed('chats,chat', 'Chat'), 'view names are case insensitive');
        $this->assertFalse(erLhAbstractModelRestAPIKey::viewAllowed('chats,chat', 'departments'));
        $this->assertFalse(erLhAbstractModelRestAPIKey::viewAllowed('chats', ''));
    }

    public function testExpiryIsNormalisedToIsoDate()
    {
        $this->assertSame('', erLhAbstractModelRestAPIKey::normalizeExpiry(''));
        $this->assertSame('', erLhAbstractModelRestAPIKey::normalizeExpiry('0'));
        $this->assertSame('', erLhAbstractModelRestAPIKey::normalizeExpiry('not a date'));
        $this->assertSame('2030-01-31', erLhAbstractModelRestAPIKey::normalizeExpiry(' 2030-01-31 '));
        $this->assertSame('2030-01-31', erLhAbstractModelRestAPIKey::normalizeExpiry('31 January 2030'));
    }

    public function testKeyExpiresAtTheEndOfItsExpiryDay()
    {
        $endOfDay = strtotime('2030-01-31 23:59:59');
        $this->assertFalse(erLhAbstractModelRestAPIKey::isExpiredAt('2030-01-31', $endOfDay));
        $this->assertTrue(erLhAbstractModelRestAPIKey::isExpiredAt('2030-01-31', $endOfDay + 1));
        $this->assertFalse(erLhAbstractModelRestAPIKey::isExpiredAt('', PHP_INT_MAX), 'no expiry means never');
        $this->assertFalse(erLhAbstractModelRestAPIKey::isExpiredAt('garbage', PHP_INT_MAX), 'an unparsable date is treated as no expiry, never as "expired yesterday"');
    }
}
