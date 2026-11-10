<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, database-free parts of erLhcoreClassLoginThrottle:
 * settings normalisation, the failure state machine and lock escalation.
 */
class LoginThrottleTest extends TestCase
{
    private $settings;

    protected function setUp(): void
    {
        $this->settings = erLhcoreClassLoginThrottle::normalizeSettings(array(
            'throttle_window' => 15,
            'throttle_lock' => 15,
            'throttle_lock_max' => 1440,
        ));
    }

    public function testDefaultsAreAppliedForMissingOrEmptyKeys()
    {
        $settings = erLhcoreClassLoginThrottle::normalizeSettings(array('throttle_window' => '', 'throttle_lock' => null));

        $this->assertSame(erLhcoreClassLoginThrottle::$defaults, $settings);
    }

    public function testStoredValuesOverrideDefaultsAndAreCoercedToIntegers()
    {
        $settings = erLhcoreClassLoginThrottle::normalizeSettings(array(
            'throttle_enabled' => '0',
            'throttle_ip_attempts' => '7',
            'throttle_user_attempts' => -3,
        ));

        $this->assertSame(0, $settings['throttle_enabled']);
        $this->assertSame(7, $settings['throttle_ip_attempts']);
        $this->assertSame(0, $settings['throttle_user_attempts'], 'negative limits are clamped to 0 (counter off)');
    }

    public function testLockCapIsNeverBelowBaseLock()
    {
        $settings = erLhcoreClassLoginThrottle::normalizeSettings(array('throttle_lock' => 60, 'throttle_lock_max' => 10));

        $this->assertSame(60, $settings['throttle_lock_max']);
    }

    public function testFailuresBelowLimitOnlyCount()
    {
        $now = 1000;
        $state = array();

        for ($i = 1; $i < 5; $i++) {
            $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $now + $i);
            $this->assertSame($i, $state['attempts']);
            $this->assertSame(0, $state['locked_until']);
            $this->assertSame(0, erLhcoreClassLoginThrottle::remainingLock($state, $now + $i));
        }

        $this->assertSame(1001, $state['first_attempt'], 'window starts at the first failure');
    }

    public function testReachingLimitLocksForBaseDuration()
    {
        $now = 1000;
        $state = array();

        for ($i = 0; $i < 5; $i++) {
            $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $now);
        }

        $this->assertSame(1, $state['lock_count']);
        $this->assertSame($now + 15 * 60, $state['locked_until']);
        $this->assertSame(15 * 60, erLhcoreClassLoginThrottle::remainingLock($state, $now));
        $this->assertSame(1, erLhcoreClassLoginThrottle::remainingLock($state, $now + 15 * 60 - 1));
        $this->assertSame(0, erLhcoreClassLoginThrottle::remainingLock($state, $now + 15 * 60));
    }

    public function testAttemptsDuringLockDoNotExtendIt()
    {
        $now = 1000;
        $state = array('attempts' => 0, 'first_attempt' => 0, 'last_attempt' => $now, 'locked_until' => $now + 900, 'lock_count' => 1);

        $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $now + 100);

        $this->assertSame($now + 900, $state['locked_until']);
        $this->assertSame(1, $state['lock_count']);
        $this->assertSame($now + 100, $state['last_attempt']);
    }

    public function testConsecutiveLocksDoubleUpToTheCap()
    {
        $this->assertSame(15 * 60, erLhcoreClassLoginThrottle::lockDuration(1, $this->settings));
        $this->assertSame(30 * 60, erLhcoreClassLoginThrottle::lockDuration(2, $this->settings));
        $this->assertSame(60 * 60, erLhcoreClassLoginThrottle::lockDuration(3, $this->settings));
        $this->assertSame(960 * 60, erLhcoreClassLoginThrottle::lockDuration(7, $this->settings), '15 * 2^6 = 960 min, still under the cap');
        $this->assertSame(1440 * 60, erLhcoreClassLoginThrottle::lockDuration(8, $this->settings), '15 * 2^7 = 1920 min, capped at 1440');
        $this->assertSame(1440 * 60, erLhcoreClassLoginThrottle::lockDuration(500, $this->settings), 'absurd counts must not overflow');
    }

    public function testSecondLockAfterExpiryIsLonger()
    {
        $now = 1000;
        $state = array();

        for ($i = 0; $i < 5; $i++) {
            $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $now);
        }
        $firstLockEnd = $state['locked_until'];

        // Lock expired, attacker comes back and fails five more times.
        $later = $firstLockEnd + 1;
        for ($i = 0; $i < 5; $i++) {
            $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $later);
        }

        $this->assertSame(2, $state['lock_count']);
        $this->assertSame($later + 30 * 60, $state['locked_until']);
    }

    public function testWindowExpiryResetsTheCounter()
    {
        $now = 1000;
        $state = array();

        for ($i = 0; $i < 4; $i++) {
            $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $now);
        }
        $this->assertSame(4, $state['attempts']);

        $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $this->settings, $now + 15 * 60 + 1);

        $this->assertSame(1, $state['attempts'], 'a failure after the window restarts counting');
        $this->assertSame(0, $state['locked_until']);
        $this->assertSame($now + 15 * 60 + 1, $state['first_attempt']);
    }

    public function testLockWithZeroDurationNeverLocks()
    {
        $settings = erLhcoreClassLoginThrottle::normalizeSettings(array('throttle_lock' => 0, 'throttle_lock_max' => 0));
        $state = array();

        for ($i = 0; $i < 5; $i++) {
            $state = erLhcoreClassLoginThrottle::applyFailure($state, 5, $settings, 1000);
        }

        $this->assertSame(0, erLhcoreClassLoginThrottle::remainingLock($state, 1000));
    }

    public function testUsernameNormalisationIsCaseInsensitiveAndTrimmed()
    {
        $this->assertSame('admin', erLhcoreClassLoginThrottle::normalizeUsername("  AdMin \n"));
        $this->assertSame(190, mb_strlen(erLhcoreClassLoginThrottle::normalizeUsername(str_repeat('x', 500))));
    }
}
