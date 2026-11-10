<?php

/**
 * One row per throttled identifier (an IP address or a username).
 *
 * Rows are created on the first failed login and keep a rolling count of
 * failures inside the configured window. When the count reaches the limit
 * the identifier is locked until `locked_until`. Consecutive locks grow
 * exponentially (see erLhcoreClassLoginThrottle).
 */
#[\AllowDynamicProperties]
class erLhcoreClassModelLoginThrottle {

    use erLhcoreClassDBTrait;

    public static $dbTable = 'lh_login_throttle';

    public static $dbTableId = 'id';

    public static $dbSessionHandler = 'erLhcoreClassUser::getSession';

    public static $dbSortOrder = 'DESC';

    const TYPE_IP = 1;
    const TYPE_USERNAME = 2;

    public function getState()
    {
        return array(
            'id' => $this->id,
            'type' => $this->type,
            'identifier' => $this->identifier,
            'attempts' => $this->attempts,
            'first_attempt' => $this->first_attempt,
            'last_attempt' => $this->last_attempt,
            'locked_until' => $this->locked_until,
            'lock_count' => $this->lock_count,
        );
    }

    public function __get($var)
    {
        switch ($var) {
            case 'type_front':
                $this->type_front = $this->type == self::TYPE_IP
                    ? erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle', 'IP address')
                    : erTranslationClassLhTranslation::getInstance()->getTranslation('user/loginthrottle', 'Username');
                return $this->type_front;

            case 'is_locked':
                $this->is_locked = $this->locked_until > time();
                return $this->is_locked;

            case 'locked_until_front':
                $this->locked_until_front = $this->locked_until > 0 ? date(erLhcoreClassModule::$dateDateHourFormat, $this->locked_until) : '';
                return $this->locked_until_front;

            case 'last_attempt_front':
                $this->last_attempt_front = $this->last_attempt > 0 ? date(erLhcoreClassModule::$dateDateHourFormat, $this->last_attempt) : '';
                return $this->last_attempt_front;

            default:
                ;
            break;
        }
    }

    public $id = null;
    public $type = self::TYPE_IP;
    public $identifier = '';
    public $attempts = 0;
    public $first_attempt = 0;
    public $last_attempt = 0;
    public $locked_until = 0;
    public $lock_count = 0;
}
