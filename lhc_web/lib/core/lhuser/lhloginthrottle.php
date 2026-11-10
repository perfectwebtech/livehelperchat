<?php

/**
 * Brute-force protection for operator logins.
 *
 * Two independent counters are kept for every failed login:
 *  - one per source IP address, so a single host cannot hammer many accounts;
 *  - one per username, so a distributed attack on one account is still slowed down.
 *
 * Each counter is a rolling window. Once the configured number of failures is
 * reached inside the window the identifier is locked for `lock` minutes. Every
 * consecutive lock doubles the lock duration up to `lock_max` minutes, and a
 * successful login resets the username counter.
 *
 * Unlike the existing `max_attempts` option (which permanently disables the
 * account and therefore lets an attacker lock administrators out) a throttle
 * lock expires on its own and never touches the user record.
 *
 * All settings live in the `password_data` configuration row and are edited
 * under System configuration -> Password requirements.
 */
class erLhcoreClassLoginThrottle
{
    /**
     * Default settings, used when a key is missing from `password_data`.
     * Durations are in minutes.
     */
    public static $defaults = array(
        'throttle_enabled' => 1,
        'throttle_ip_attempts' => 20,
        'throttle_user_attempts' => 5,
        'throttle_window' => 15,
        'throttle_lock' => 15,
        'throttle_lock_max' => 1440,
    );

    /**
     * Allows tests (and the CLI) to inject settings without touching the database.
     * @var array|null
     */
    public static $settingsOverride = null;

    /**
     * Returns the effective settings, merging stored values over the defaults.
     */
    public static function getSettings()
    {
        if (self::$settingsOverride !== null) {
            return self::normalizeSettings(self::$settingsOverride);
        }

        $stored = (array)erLhcoreClassModelChatConfig::fetch('password_data')->data;

        return self::normalizeSettings($stored);
    }

    /**
     * Merges `$data` over the defaults and coerces every value to a sane integer.
     * Pure function - safe to unit test.
     */
    public static function normalizeSettings(array $data)
    {
        $settings = array();

        foreach (self::$defaults as $key => $default) {
            $value = array_key_exists($key, $data) && $data[$key] !== '' && $data[$key] !== null ? (int)$data[$key] : (int)$default;
            $settings[$key] = max(0, $value);
        }

        // A lock cap below the base lock makes no sense - treat it as "no cap growth".
        if ($settings['throttle_lock_max'] < $settings['throttle_lock']) {
            $settings['throttle_lock_max'] = $settings['throttle_lock'];
        }

        return $settings;
    }

    public static function isEnabled()
    {
        $settings = self::getSettings();
        return $settings['throttle_enabled'] == 1;
    }

    /**
     * Seconds the caller still has to wait before another attempt is allowed.
     * Returns 0 when the login may proceed.
     *
     * @param string $ip
     * @param string $username
     * @return int
     */
    public static function getRetryAfter($ip, $username)
    {
        if (!self::isEnabled()) {
            return 0;
        }

        $now = time();
        $retryAfter = 0;

        try {
            foreach (self::identifiers($ip, $username) as $type => $identifier) {
                $record = self::findRecord($type, $identifier);
                if ($record instanceof erLhcoreClassModelLoginThrottle) {
                    $retryAfter = max($retryAfter, self::remainingLock($record->getState(), $now));
                }
            }
        } catch (Exception $e) {
            self::storageFailure($e);
            return 0;
        }

        return $retryAfter;
    }

    /**
     * Registers a failed login for both the IP and the username counters.
     *
     * @param string $ip
     * @param string $username
     * @return int seconds the caller is now locked for (0 = not locked yet)
     */
    public static function recordFailure($ip, $username)
    {
        if (!self::isEnabled()) {
            return 0;
        }

        $settings = self::getSettings();
        $now = time();
        $lockedFor = 0;

        try {
            foreach (self::identifiers($ip, $username) as $type => $identifier) {
                $limit = $type == erLhcoreClassModelLoginThrottle::TYPE_IP ? $settings['throttle_ip_attempts'] : $settings['throttle_user_attempts'];

                if ($limit <= 0) {
                    continue; // This counter is switched off.
                }

                $record = self::findRecord($type, $identifier);

                if (!($record instanceof erLhcoreClassModelLoginThrottle)) {
                    $record = new erLhcoreClassModelLoginThrottle();
                    $record->type = $type;
                    $record->identifier = $identifier;
                }

                $record->setState(self::applyFailure($record->getState(), $limit, $settings, $now));
                $record->saveThis();

                $lockedFor = max($lockedFor, self::remainingLock($record->getState(), $now));
            }
        } catch (Exception $e) {
            self::storageFailure($e);
            return 0;
        }

        if ($lockedFor > 0) {
            erLhcoreClassChatEventDispatcher::getInstance()->dispatch('user.login_throttled', array('ip' => $ip, 'username' => $username, 'locked_for' => $lockedFor));
        }

        return $lockedFor;
    }

    /**
     * A successful login clears the username counter. The IP counter is left
     * alone on purpose: one valid credential must not reset the budget an
     * attacker has for guessing other accounts from the same host.
     */
    public static function recordSuccess($ip, $username)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $record = self::findRecord(erLhcoreClassModelLoginThrottle::TYPE_USERNAME, self::normalizeUsername($username));

            if ($record instanceof erLhcoreClassModelLoginThrottle) {
                $record->removeThis();
            }
        } catch (Exception $e) {
            self::storageFailure($e);
        }
    }

    /**
     * Removes a lock manually (admin action).
     */
    public static function unlock($id)
    {
        $record = erLhcoreClassModelLoginThrottle::fetch((int)$id, false);
        if ($record instanceof erLhcoreClassModelLoginThrottle) {
            $record->removeThis();
            return true;
        }
        return false;
    }

    /**
     * Deletes records that are neither locked nor inside any window anymore.
     * Meant for the login_monitoring cron. Returns the number of removed rows.
     */
    public static function prune()
    {
        $settings = self::getSettings();
        $now = time();

        // Keep a record while it could still influence a decision: either its lock
        // is active, or its window has not expired yet. lock_count is deliberately
        // forgotten only after the longest possible lock has passed, so repeat
        // offenders keep escalating.
        $staleBefore = $now - max($settings['throttle_window'], $settings['throttle_lock_max']) * 60;

        $db = ezcDbInstance::get();
        $stmt = $db->prepare('DELETE FROM `' . erLhcoreClassModelLoginThrottle::$dbTable . '` WHERE `locked_until` < :now AND `last_attempt` < :stale');
        $stmt->bindValue(':now', $now, PDO::PARAM_INT);
        $stmt->bindValue(':stale', $staleBefore, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Pure state transition for one counter. Returns the new state array.
     *
     * @param array $state   current record state (attempts, first_attempt, last_attempt, locked_until, lock_count)
     * @param int   $limit   failures allowed inside the window for this counter
     * @param array $settings normalized settings
     * @param int   $now     unix timestamp
     * @return array
     */
    public static function applyFailure(array $state, $limit, array $settings, $now)
    {
        $state = array_merge(array(
            'attempts' => 0,
            'first_attempt' => 0,
            'last_attempt' => 0,
            'locked_until' => 0,
            'lock_count' => 0,
        ), $state);

        // Attempts made while a lock is active do not count again - the caller is
        // rejected before credentials are checked. Just note the activity.
        if ($state['locked_until'] > $now) {
            $state['last_attempt'] = $now;
            return $state;
        }

        $windowSeconds = $settings['throttle_window'] * 60;

        // Start a fresh window if the previous one expired (or a lock just ended).
        if ($state['first_attempt'] == 0 || $state['locked_until'] > 0 && $state['locked_until'] <= $now || $now - $state['first_attempt'] > $windowSeconds) {
            $state['attempts'] = 0;
            $state['first_attempt'] = $now;
            $state['locked_until'] = 0;
        }

        $state['attempts']++;
        $state['last_attempt'] = $now;

        if ($state['attempts'] >= $limit) {
            $state['lock_count']++;
            $state['locked_until'] = $now + self::lockDuration($state['lock_count'], $settings);
            $state['attempts'] = 0;
            $state['first_attempt'] = 0;
        }

        return $state;
    }

    /**
     * Lock length in seconds for the n-th consecutive lock: base * 2^(n-1), capped.
     */
    public static function lockDuration($lockCount, array $settings)
    {
        $base = $settings['throttle_lock'] * 60;
        $cap = $settings['throttle_lock_max'] * 60;

        if ($base <= 0) {
            return 0;
        }

        $exponent = max(0, min((int)$lockCount - 1, 30)); // avoid integer overflow on absurd counts
        $duration = $base * (2 ** $exponent);

        return (int)min($duration, $cap);
    }

    /**
     * Seconds left on a lock, 0 if not locked.
     */
    public static function remainingLock(array $state, $now)
    {
        if (!isset($state['locked_until']) || $state['locked_until'] <= $now) {
            return 0;
        }

        return (int)($state['locked_until'] - $now);
    }

    /**
     * Human readable wait time for error messages.
     */
    public static function formatRetryAfter($seconds)
    {
        $minutes = (int)ceil($seconds / 60);

        if ($minutes >= 120) {
            return sprintf(erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle', '%d hours'), (int)ceil($minutes / 60));
        }

        return sprintf(erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle', '%d minute(s)'), max(1, $minutes));
    }

    /**
     * The error shown to a throttled visitor. Deliberately identical for IP and
     * username locks so it leaks nothing about which accounts exist.
     */
    public static function getLockedMessage($seconds)
    {
        return sprintf(
            erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle', 'Too many failed login attempts. Please try again in %s.'),
            self::formatRetryAfter($seconds)
        );
    }

    /**
     * Storage problems (typically the lh_login_throttle table missing right after an
     * upgrade, before doc/update_db/update_290.sql was applied) must never lock
     * operators out of the system: log once per request and fail open.
     */
    private static function storageFailure(Exception $e)
    {
        if (class_exists('erLhcoreClassLog')) {
            erLhcoreClassLog::write('Login throttle storage unavailable, brute-force protection skipped: ' . $e->getMessage());
        }
    }

    public static function normalizeUsername($username)
    {
        return mb_substr(mb_strtolower(trim((string)$username)), 0, 190);
    }

    /**
     * @return array type => identifier
     */
    private static function identifiers($ip, $username)
    {
        $list = array();

        $ip = trim((string)$ip);
        if ($ip !== '') {
            $list[erLhcoreClassModelLoginThrottle::TYPE_IP] = mb_substr($ip, 0, 190);
        }

        $username = self::normalizeUsername($username);
        if ($username !== '') {
            $list[erLhcoreClassModelLoginThrottle::TYPE_USERNAME] = $username;
        }

        return $list;
    }

    private static function findRecord($type, $identifier)
    {
        return erLhcoreClassModelLoginThrottle::findOne(array('filter' => array('type' => $type, 'identifier' => $identifier)));
    }
}
