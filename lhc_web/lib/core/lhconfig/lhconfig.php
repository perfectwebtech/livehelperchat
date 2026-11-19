<?php

class erConfigClassLhConfig
{
    private static $instance = null;
    public $conf;

    /**
     * Settings that can be supplied through environment variables, so container
     * and twelve-factor deployments never need secrets inside settings.ini.php.
     * Every variable also accepts a `<NAME>_FILE` form pointing at a file with
     * the value (Docker / Kubernetes secrets).
     *
     * env name => array(section, key, type)
     */
    public static $environmentMap = array(
        'LHC_DB_HOST' => array('db', 'host', 'string'),
        'LHC_DB_PORT' => array('db', 'port', 'int'),
        'LHC_DB_USER' => array('db', 'user', 'string'),
        'LHC_DB_PASSWORD' => array('db', 'password', 'string'),
        'LHC_DB_DATABASE' => array('db', 'database', 'string'),
        'LHC_SECRET_HASH' => array('site', 'secrethash', 'string'),
        'LHC_HEALTH_TOKEN' => array('site', 'health_token', 'string'),
        'LHC_PROXY_MODE' => array('site', 'proxy_mode', 'bool'),
        'LHC_DEBUG_OUTPUT' => array('site', 'debug_output', 'bool'),
        'LHC_TRUSTED_HOST_PATTERNS' => array('site', 'trusted_host_patterns', 'list'),
        'LHC_REDIS_HOST' => array('redis', 'server.host', 'string'),
        'LHC_REDIS_PORT' => array('redis', 'server.port', 'int'),
        'LHC_REDIS_PASSWORD' => array('redis', 'server.auth', 'string'),
    );

    /**
     * Values the environment replaced, so save() can keep them out of the file.
     * @var array<int, array{0:string,1:string,2:bool,3:mixed,4:mixed}>
     */
    private $environmentOverridden = array();

    public function __construct()
    {
	     $this->conf = @include('settings/settings.ini.php');
	  		     
         if ( !is_array($this->conf) ) {
		    	$this->conf = include('settings/settings.ini.default.php');
         }

         $this->conf = self::applyEnvironmentOverrides($this->conf, self::readEnvironment(), $this->environmentOverridden);
    }

    /**
     * @return array<string, string> env name => raw value, for every mapped variable that is set
     */
    public static function readEnvironment()
    {
        $values = array();
        foreach (array_keys(self::$environmentMap) as $name) {
            $value = getenv($name);
            if ($value === false) {
                $file = getenv($name . '_FILE');
                if ($file !== false && $file !== '' && is_readable($file)) {
                    $value = rtrim((string)file_get_contents($file), "\r\n");
                }
            }
            if ($value !== false) {
                $values[$name] = (string)$value;
            }
        }
        return $values;
    }

    /**
     * Pure: returns $conf with the given environment values applied.
     *
     * @param array $conf      full settings array (with the top level 'settings' key)
     * @param array $env       env name => raw string value
     * @param array $overridden receives (section, key, existed, original, applied) for each applied value
     */
    public static function applyEnvironmentOverrides(array $conf, array $env, array &$overridden = array())
    {
        foreach (self::$environmentMap as $name => $target) {
            if (!array_key_exists($name, $env)) {
                continue;
            }
            list($section, $key, $type) = $target;
            $raw = $env[$name];

            switch ($type) {
                case 'int':
                    $value = (int)$raw;
                    break;
                case 'bool':
                    $value = in_array(strtolower(trim($raw)), array('1', 'true', 'yes', 'on'), true);
                    break;
                case 'list':
                    $value = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
                    break;
                default:
                    $value = $raw;
            }

            $path = array_merge(array('settings', $section), explode('.', $key));
            $existed = true;
            $original = self::arrayGetPath($conf, $path, $existed);
            self::arraySetPath($conf, $path, $value);
            $overridden[] = array($section, $key, $existed, $original, $value);
        }
        return $conf;
    }

    private static function arrayGetPath(array $array, array $path, &$existed)
    {
        foreach ($path as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                $existed = false;
                return null;
            }
            $array = $array[$segment];
        }
        $existed = true;
        return $array;
    }

    private static function arraySetPath(array &$array, array $path, $value)
    {
        $ref = &$array;
        foreach ($path as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = array();
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    private static function arrayUnsetPath(array &$array, array $path)
    {
        $last = array_pop($path);
        $ref = &$array;
        foreach ($path as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                return;
            }
            $ref = &$ref[$segment];
        }
        unset($ref[$last]);
    }

    /**
     * The settings array as it should be written to disk: values that came
     * from the environment (and were not changed since) revert to what the
     * file had, so secrets supplied by the environment never land in the file.
     */
    public function getPersistableConf()
    {
        $conf = $this->conf;
        foreach ($this->environmentOverridden as $entry) {
            list($section, $key, $existed, $original, $applied) = $entry;
            $path = array_merge(array('settings', $section), explode('.', $key));
            $found = true;
            $current = self::arrayGetPath($conf, $path, $found);
            if ($found && $current === $applied) {
                if ($existed) {
                    self::arraySetPath($conf, $path, $original);
                } else {
                    self::arrayUnsetPath($conf, $path);
                }
            }
        }
        return $conf;
    }

    public function getSetting($section, $key, $throwException = true)
    {
        if (isset($this->conf['settings'][$section][$key])) {
            return $this->conf['settings'][$section][$key];
        } else {
        	if ($throwException === true) {
            	throw new Exception('Setting with section {'.$section.'} value {'.$key.'}');
        	} else {
        		return false;
        	}
        }
    }

    public function hasSetting($section, $key)
    {
        return isset($this->conf['settings'][$section][$key]);
    }

    public function setSetting($section, $key, $value)
    {
        $this->conf['settings'][$section][$key] = $value;
    }

    /**
     * This function should be used then value can be override by siteAccess
     *
     * */
    public function getOverrideValue($section, $key)
    {
        $value = null;

        if ($this->hasSetting($section,$key))
        $value = $this->getSetting( $section, $key );

        $valueOverride = $this->getSetting('site_access_options',erLhcoreClassSystem::instance()->SiteAccess);
        
        if (key_exists($key,$valueOverride))
              return $valueOverride[$key];

        return $value;
    }

    /**
     * This function should be used then value can be override by siteAccess
     * or user language has influence to returned value
     *
     * */
    public function getDirLanguage($attribute = 'dir_language')
    {
        $value = null;

        if ($this->hasSetting('site',$attribute))
        $value = $this->getSetting('site',$attribute);

        $siteAccess = erLhcoreClassSystem::instance()->SiteAccess;
        
        if ($siteAccess == 'site_admin') {
	        $valueOverride = $this->getSetting('site_access_options',$siteAccess);
	
	        if (key_exists($attribute,$valueOverride)){
	        	// User has not changed default site access language. So just return current value.
	        	if (erLhcoreClassModelUserSetting::getSetting('user_language',$valueOverride['locale']) == $valueOverride['locale']){
	              	return $valueOverride[$attribute];
	        	} else { // User has changed default siteaccess language, we need to check does ltr or rtl matches
	        		foreach ($this->getSetting( 'site','available_site_access' ) as $siteaccess) { // Loop untill we find our locate siteaccess and check it's language direction
	        			$siteAccessOptions = $this->getSetting('site_access_options',$siteaccess);
	        			if ($siteAccessOptions['locale'] == erLhcoreClassModelUserSetting::getSetting('user_language',$valueOverride['locale'])){
	        				return $siteAccessOptions[$attribute];
	        			}
	        		}
	        	}
	        }
        } else {
        	$value = $this->getOverrideValue('site', $attribute);        	
        }
        
        return $value;
    }

    public static function getInstance()
    {
        if ( is_null( self::$instance ) )
        {
            self::$instance = new erConfigClassLhConfig();
        }
        return self::$instance;
    }

    public function save()
    {
        file_put_contents('settings/settings.ini.php',"<?php\n return ".var_export($this->getPersistableConf(),true).";\n?>");
    }
}


?>