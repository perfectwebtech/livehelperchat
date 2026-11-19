<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure parts of erLhcoreClassGdpr: settings, policy validation, cut-offs and
 * data subject identifier matching.
 */
class GdprTest extends TestCase
{
    public function testSettingsDefaultsAndClamping()
    {
        $this->assertSame(erLhcoreClassGdpr::$defaults, erLhcoreClassGdpr::normalizeSettings(array()));

        $s = erLhcoreClassGdpr::normalizeSettings(array('anonymize_after_days' => '30', 'delete_after_days' => -5, 'batch_size' => 999999, 'anonymize_operator_messages' => '7'));
        $this->assertSame(30, $s['anonymize_after_days']);
        $this->assertSame(0, $s['delete_after_days'], 'negative days mean off');
        $this->assertSame(5000, $s['batch_size'], 'batch is capped');
        $this->assertSame(1, $s['anonymize_operator_messages']);
        $this->assertSame(1, erLhcoreClassGdpr::normalizeSettings(array('batch_size' => 0))['batch_size'], 'batch has a floor');
    }

    public function testDeletionMustComeAfterAnonymisation()
    {
        $this->assertSame(array(), erLhcoreClassGdpr::validateSettings(erLhcoreClassGdpr::normalizeSettings(array('anonymize_after_days' => 30, 'delete_after_days' => 365))));
        $this->assertSame(array(), erLhcoreClassGdpr::validateSettings(erLhcoreClassGdpr::normalizeSettings(array('anonymize_after_days' => 0, 'delete_after_days' => 10))), 'delete-only policy is fine');
        $this->assertSame(array(), erLhcoreClassGdpr::validateSettings(erLhcoreClassGdpr::normalizeSettings(array('anonymize_after_days' => 30))), 'anonymise-only policy is fine');
        $this->assertCount(1, erLhcoreClassGdpr::validateSettings(erLhcoreClassGdpr::normalizeSettings(array('anonymize_after_days' => 30, 'delete_after_days' => 30))));
        $this->assertCount(1, erLhcoreClassGdpr::validateSettings(erLhcoreClassGdpr::normalizeSettings(array('anonymize_after_days' => 90, 'delete_after_days' => 30))));
    }

    public function testCutoff()
    {
        $this->assertSame(0, erLhcoreClassGdpr::cutoff(0, 1700000000), 'rule off');
        $this->assertSame(1700000000 - 30 * 86400, erLhcoreClassGdpr::cutoff(30, 1700000000));
    }

    public function testIdentifierParsing()
    {
        $this->assertSame(array('type' => 'email', 'value' => 'jane.doe@example.com'), erLhcoreClassGdpr::parseIdentifier('  Jane.Doe@Example.COM '));
        $this->assertSame(array('type' => 'phone', 'value' => '+447700900123'), erLhcoreClassGdpr::parseIdentifier('+44 (7700) 900-123'));
        $this->assertNull(erLhcoreClassGdpr::parseIdentifier(''));
        $this->assertNull(erLhcoreClassGdpr::parseIdentifier('not-an-email@'));
        $this->assertNull(erLhcoreClassGdpr::parseIdentifier('1234'), 'too short to identify anybody');
        $this->assertNull(erLhcoreClassGdpr::parseIdentifier('john smith'), 'names are not accepted: too ambiguous to erase by');
    }

    public function testPhoneMatching()
    {
        $this->assertTrue(erLhcoreClassGdpr::phoneMatches('+44 7700 900123', '+447700900123'));
        $this->assertTrue(erLhcoreClassGdpr::phoneMatches('07700 900123', '+44 7700 900123'), 'national format matches international');
        $this->assertTrue(erLhcoreClassGdpr::phoneMatches('(555) 010-9999', '5550109999'));
        $this->assertFalse(erLhcoreClassGdpr::phoneMatches('+44 7700 900124', '+447700900123'));
        $this->assertFalse(erLhcoreClassGdpr::phoneMatches('', '+447700900123'));
        $this->assertFalse(erLhcoreClassGdpr::phoneMatches('123', '99123'), 'short numbers never suffix-match');
    }
}
