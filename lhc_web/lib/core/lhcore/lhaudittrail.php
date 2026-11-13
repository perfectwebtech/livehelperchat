<?php

/**
 * Security audit trail.
 *
 * Thin, always-on wrapper around the existing audit log (lh_audits, viewable
 * under System configuration -> Audit log). Unlike erLhcoreClassLog::logObjectChange()
 * these entries are not gated by the "What objects changes log?" setting:
 * they cover security-relevant actions an enterprise deployment must always
 * be able to reconstruct (who deleted or exported chats, who changed roles,
 * who changed the password policy, which identifiers were locked out).
 *
 * Every entry records the acting operator, the client IP and the request so
 * the log answers "who did what, from where, when" without correlation work.
 */
class erLhcoreClassAuditTrail
{
    const CATEGORY_CHAT_DELETE = 'ChatDelete';
    const CATEGORY_CHAT_EXPORT = 'ChatExport';
    const CATEGORY_ROLE = 'RoleChange';
    const CATEGORY_PASSWORD_POLICY = 'PasswordPolicy';
    const CATEGORY_LOGIN_THROTTLE = 'LoginThrottle';
    const CATEGORY_API_KEY = 'ApiKey';
    const CATEGORY_SSO = 'SingleSignOn';

    /**
     * @param string $category one of the CATEGORY_* constants (shown in the audit log list)
     * @param string $action   short verb, e.g. "delete", "export", "lock"
     * @param array  $details  anything useful to reconstruct the event; must be JSON serialisable
     * @param int    $objectId id of the primary object the action was applied to, when there is one
     */
    public static function record($category, $action, array $details = array(), $objectId = 0)
    {
        try {
            $entry = array_merge(array(
                'action' => $action,
                'actor' => self::actor(),
                'ip' => erLhcoreClassIPDetect::getIP(),
                'request' => isset($_SERVER['REQUEST_URI']) ? mb_substr((string)$_SERVER['REQUEST_URI'], 0, 500) : (PHP_SAPI === 'cli' ? 'cli' : ''),
            ), $details);

            erLhcoreClassLog::write(
                json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ezcLog::SUCCESS_AUDIT,
                array(
                    'source' => 'lhc',
                    'category' => $category,
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'object_id' => (int)$objectId,
                )
            );
        } catch (Exception $e) {
            // The audit trail must never break the action it describes.
        }
    }

    /**
     * Identity of whoever triggered the current request: operator id and
     * username when logged in, "system" for cron and CLI, "anonymous" otherwise.
     */
    public static function actor()
    {
        if (PHP_SAPI === 'cli') {
            return array('type' => 'system');
        }

        try {
            $currentUser = erLhcoreClassUser::instance();
            if ($currentUser->isLogged()) {
                $userData = $currentUser->getUserData(true);
                return array(
                    'type' => 'operator',
                    'id' => (int)$currentUser->getUserID(),
                    'username' => isset($userData->username) ? $userData->username : '',
                );
            }
        } catch (Exception $e) {
            // Session not available (e.g. REST API with key auth); fall through.
        }

        return array('type' => 'anonymous');
    }
}
