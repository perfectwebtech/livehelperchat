<?php

/**
 *
 * @author Remigijus Kiminas
 *
 * @desc Rest API Key
 *
 */
#[\AllowDynamicProperties]
class erLhAbstractModelRestAPIKey
{

    use erLhcoreClassDBTrait;

    public static $dbTable = 'lh_abstract_rest_api_key';
    public static $dbTableId = 'id';
    public static $dbSessionHandler = 'erLhcoreClassAbstract::getSession';
    public static $dbSortOrder = 'DESC';

    public function getState()
    {
        $stateArray = array(
            'id' => $this->id,
            'user_id' => $this->user_id,
            'api_key' => $this->api_key,
            'active' => $this->active,
            'ip_restrictions' => $this->ip_restrictions,
            'scopes' => $this->scopes,
            'expires_at' => $this->expires_at,
            'last_used' => $this->last_used
        );

        return $stateArray;
    }

    public function __toString()
    {
        return $this->api_key;
    }

    public function getModuleTranslations()
    {
        $metaData = array('path' => array('url' => erLhcoreClassDesign::baseurl('restapi/index'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('theme/index', 'Rest API')), 'permission_delete' => array('module' => 'lhrestapi', 'function' => 'use_admin'), 'permission' => array('module' => 'lhrestapi', 'function' => 'use_admin'), 'name' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/product', 'API Key'));
        /**
         * Get's executed before permissions check. It can redirect to frontpage throw permission exception etc
         * */
        erLhcoreClassChatEventDispatcher::getInstance()->dispatch('feature.can_use_product', array('object_meta_data' => & $metaData));

        return $metaData;
    }

    public function __get($var)
    {
        switch ($var) {

            case 'left_menu':
                $this->left_menu = '';
                return $this->left_menu;

            case 'user':
                $this->user = erLhcoreClassModelUser::fetch($this->user_id);
                return $this->user;

            case 'scopes_list':
                $this->scopes_list = self::parseScopes($this->scopes);
                return $this->scopes_list;

            case 'is_expired':
                $this->is_expired = self::isExpiredAt($this->expires_at, time());
                return $this->is_expired;

            case 'expires_at_front':
                $this->expires_at_front = $this->expires_at != '' ? $this->expires_at : erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'never');
                return $this->expires_at_front;

            case 'last_used_front':
                $this->last_used_front = $this->last_used > 0 ? date(erLhcoreClassModule::$dateDateHourFormat, $this->last_used) : erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'never');
                return $this->last_used_front;

            case 'username':
                $this->username = null;

                if ($this->user instanceof erLhcoreClassModelUser) {
                    $this->username = $this->user->username != '' ? $this->user->username : $this->user->email;
                }
                
                return $this->username;


            default:
                break;
        }
    }

    public $id = null;
    public $user_id = '';
    public $api_key = '';
    public $active = 0;
    public $ip_restrictions = '';

    /** Comma separated REST view names this key may call; empty = every endpoint. */
    public $scopes = '';

    /** YYYY-MM-DD after which the key stops working; empty = never. */
    public $expires_at = '';

    /** Unix timestamp of the last accepted request (updated at most once a minute). */
    public $last_used = 0;

    public $hide_add = false;
    public $hide_delete = false;

    /**
     * The admin form posts `scopes_list` as an array (checkbox_multi); fold it
     * back into the stored string and normalise the expiry date before saving.
     */
    public function beforeSave($params = array())
    {
        $this->normalizeInput();
    }

    public function beforeUpdate($params = array())
    {
        $this->normalizeInput();
    }

    private function normalizeInput()
    {
        if (isset($this->scopes_list) && is_array($this->scopes_list)) {
            $this->scopes = implode(',', self::parseScopes(implode(',', $this->scopes_list)));
        }

        $this->expires_at = self::normalizeExpiry($this->expires_at);
    }

    /**
     * @return string[] cleaned, unique view names
     */
    public static function parseScopes($scopes)
    {
        $list = array();
        foreach (explode(',', (string)$scopes) as $scope) {
            $scope = strtolower(trim($scope));
            if ($scope !== '' && preg_match('/^[a-z0-9_]+$/', $scope) && !in_array($scope, $list)) {
                $list[] = $scope;
            }
        }
        return $list;
    }

    /**
     * Accepts "YYYY-MM-DD" (or anything strtotime understands) and returns
     * "YYYY-MM-DD", or '' when the value is empty or not a date.
     */
    public static function normalizeExpiry($value)
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0') {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    /**
     * A key expires at the end of its expiry day (server time zone).
     */
    public static function isExpiredAt($expiresAt, $now)
    {
        $expiresAt = self::normalizeExpiry($expiresAt);
        if ($expiresAt === '') {
            return false;
        }
        return $now >= strtotime($expiresAt . ' 23:59:59') + 1;
    }

    /**
     * Pure scope check: an empty scope list means unrestricted.
     */
    public static function viewAllowed($scopes, $view)
    {
        $list = self::parseScopes($scopes);
        if (empty($list)) {
            return true;
        }
        return in_array(strtolower((string)$view), $list, true);
    }

    public function allowsView($view)
    {
        return self::viewAllowed($this->scopes, $view);
    }

    /**
     * Records usage without going through the ORM (no cache flush, no audit noise);
     * writes at most once per minute per key.
     */
    public function touchLastUsed()
    {
        $now = time();
        if ($this->id === null || $now - (int)$this->last_used < 60) {
            return;
        }
        try {
            $db = ezcDbInstance::get();
            $stmt = $db->prepare('UPDATE `' . self::$dbTable . '` SET `last_used` = :now WHERE `id` = :id');
            $stmt->bindValue(':now', $now, PDO::PARAM_INT);
            $stmt->bindValue(':id', (int)$this->id, PDO::PARAM_INT);
            $stmt->execute();
            $this->last_used = $now;
        } catch (Exception $e) {
            // best effort
        }
    }

    /**
     * Options for the scopes checkbox list: every view the REST API module declares.
     * @return object[] with ->id and ->name
     */
    public static function getScopeOptions($params = array())
    {
        $ViewList = array();
        $FunctionList = array();
        $Module = array();
        include 'modules/lhrestapi/module.php';

        $options = array();
        foreach (array_keys($ViewList) as $view) {
            if (in_array($view, array('index', 'swagger', 'onlineimage'))) {
                continue;
            }
            $option = new stdClass();
            $option->id = $view;
            $option->name = $view;
            $options[] = $option;
        }
        usort($options, function ($a, $b) { return strcmp($a->id, $b->id); });
        return $options;
    }
}
