<?php

use PHPUnit\Framework\TestCase;

/**
 * Environment variable overrides in erConfigClassLhConfig.
 */
class ConfigEnvironmentTest extends TestCase
{
    private function fileConf()
    {
        return array('settings' => array(
            'site' => array('secrethash' => 'from-file', 'proxy_mode' => false, 'debug_output' => false),
            'db' => array('host' => 'file-host', 'user' => 'u', 'password' => 'file-pass', 'database' => 'lhc', 'port' => 3306),
            'redis' => array('server' => array('host' => 'localhost', 'port' => 6379, 'auth' => null, 'database' => 0)),
        ));
    }

    public function testMappedValuesOverrideTheFileWithTypes()
    {
        $conf = erConfigClassLhConfig::applyEnvironmentOverrides($this->fileConf(), array(
            'LHC_DB_HOST' => 'db',
            'LHC_DB_PORT' => '3307',
            'LHC_DB_PASSWORD' => 's3cret',
            'LHC_PROXY_MODE' => 'true',
            'LHC_DEBUG_OUTPUT' => '0',
            'LHC_TRUSTED_HOST_PATTERNS' => 'chat.example.com, ,admin.example.com',
            'LHC_REDIS_HOST' => 'redis',
        ));

        $this->assertSame('db', $conf['settings']['db']['host']);
        $this->assertSame(3307, $conf['settings']['db']['port']);
        $this->assertSame('s3cret', $conf['settings']['db']['password']);
        $this->assertTrue($conf['settings']['site']['proxy_mode']);
        $this->assertFalse($conf['settings']['site']['debug_output']);
        $this->assertSame(array('chat.example.com', 'admin.example.com'), $conf['settings']['site']['trusted_host_patterns']);
        $this->assertSame('redis', $conf['settings']['redis']['server']['host']);
        $this->assertSame(6379, $conf['settings']['redis']['server']['port'], 'untouched nested values survive');
        $this->assertSame('u', $conf['settings']['db']['user'], 'unset variables leave the file value alone');
    }

    public function testUnknownVariablesAreIgnored()
    {
        $conf = erConfigClassLhConfig::applyEnvironmentOverrides($this->fileConf(), array('LHC_SOMETHING_ELSE' => 'x', 'PATH' => '/bin'));
        $this->assertSame($this->fileConf(), $conf);
    }

    public function testSecretsFromTheEnvironmentAreNotPersisted()
    {
        $config = new erConfigClassLhConfig();
        $overridden = array();
        $config->conf = erConfigClassLhConfig::applyEnvironmentOverrides($this->fileConf(), array('LHC_DB_PASSWORD' => 'env-secret', 'LHC_HEALTH_TOKEN' => 'tok'), $overridden);
        $this->setOverridden($config, $overridden);

        // Something else changes and the installer / admin saves the settings.
        $config->setSetting('site', 'templatecache', true);
        $persist = $config->getPersistableConf();

        $this->assertSame('file-pass', $persist['settings']['db']['password'], 'file keeps its own password');
        $this->assertArrayNotHasKey('health_token', $persist['settings']['site'], 'a key that only came from the environment is not written');
        $this->assertTrue($persist['settings']['site']['templatecache'], 'genuine changes are still persisted');
        $this->assertSame('env-secret', $config->getSetting('db', 'password'), 'runtime still uses the environment value');
    }

    public function testExplicitChangeAfterOverrideIsPersisted()
    {
        $config = new erConfigClassLhConfig();
        $overridden = array();
        $config->conf = erConfigClassLhConfig::applyEnvironmentOverrides($this->fileConf(), array('LHC_DB_HOST' => 'env-host'), $overridden);
        $this->setOverridden($config, $overridden);

        $config->setSetting('db', 'host', 'admin-chosen-host');
        $this->assertSame('admin-chosen-host', $config->getPersistableConf()['settings']['db']['host']);
    }

    private function setOverridden(erConfigClassLhConfig $config, array $overridden)
    {
        $property = new ReflectionProperty(erConfigClassLhConfig::class, 'environmentOverridden');
        $property->setAccessible(true);
        $property->setValue($config, $overridden);
    }
}
