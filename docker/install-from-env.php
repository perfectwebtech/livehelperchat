<?php
/**
 * First-boot installer for container deployments.
 *
 * Same steps as cli/install-cli.php, but the values come from environment
 * variables instead of an INI file, so passwords may contain any character.
 * Run with the application root as working directory (docker/entrypoint.sh
 * does that); it lives outside the web root at /opt/lhc in the image.
 *
 * Required: LHC_DB_HOST, LHC_DB_USER, LHC_DB_PASSWORD, LHC_DB_DATABASE,
 *           LHC_ADMIN_PASSWORD (max 40 characters), LHC_ADMIN_EMAIL
 * Optional: LHC_DB_PORT (3306), LHC_ADMIN_USERNAME (admin), LHC_ADMIN_NAME,
 *           LHC_ADMIN_SURNAME, LHC_DOMAIN, LHC_DEFAULT_DEPARTMENT (Support),
 *           LHC_TIMEZONE (UTC)
 * Every variable also accepts a <NAME>_FILE form (Docker / Kubernetes secrets).
 */

if (php_sapi_name() != 'cli') {
    exit(1);
}

$env = function ($name, $default = '') {
    $value = getenv($name);
    if ($value === false) {
        $file = getenv($name . '_FILE');
        if ($file !== false && $file !== '' && is_readable($file)) {
            $value = rtrim((string)file_get_contents($file), "\r\n");
        }
    }
    return $value === false || $value === '' ? $default : (string)$value;
};

foreach (array('LHC_DB_HOST', 'LHC_DB_USER', 'LHC_DB_PASSWORD', 'LHC_DB_DATABASE', 'LHC_ADMIN_PASSWORD', 'LHC_ADMIN_EMAIL') as $required) {
    if ($env($required) === '') {
        fwrite(STDERR, "install-from-env: $required is not set\n");
        exit(1);
    }
}

require_once 'lib/core/lhcore/password.php';
require_once 'ezcomponents/Base/src/base.php';
require_once 'cli/lib/install.php';

ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');

if (erConfigClassLhConfig::getInstance()->getSetting('site', 'installed') == true) {
    echo "install-from-env: already installed\n";
    exit(0);
}

erLhcoreClassSystem::instance();

$emptyIni = tempnam(sys_get_temp_dir(), 'lhc-install');
$install = new Install($emptyIni); // settings are supplied below, not from an INI file
@unlink($emptyIni);
$install->settings = array(
    'db' => array(
        'host' => $env('LHC_DB_HOST'),
        'user' => $env('LHC_DB_USER'),
        'password' => $env('LHC_DB_PASSWORD'),
        'database' => $env('LHC_DB_DATABASE'),
        'port' => (int)$env('LHC_DB_PORT', '3306'),
    ),
    'admin' => array(
        'AdminUsername' => $env('LHC_ADMIN_USERNAME', 'admin'),
        'AdminPassword' => $env('LHC_ADMIN_PASSWORD'),
        'AdminEmail' => $env('LHC_ADMIN_EMAIL'),
        'AdminName' => $env('LHC_ADMIN_NAME', 'Admin'),
        'AdminSurname' => $env('LHC_ADMIN_SURNAME', ''),
        'Domain' => $env('LHC_DOMAIN', 'localhost'),
        'DefaultDepartament' => $env('LHC_DEFAULT_DEPARTMENT', 'Support'),
        'ForceVirtualHost' => 0,
        'Extensions' => '',
        'ApacheUserGroupName' => 'www-data',
        'ApacheUserName' => 'www-data',
        'TimeZone' => $env('LHC_TIMEZONE', 'UTC'),
        'WebhooksEnabled' => 0,
        'WebhooksWorker' => 'http',
        'DefaultConfigs' => array(),
    ),
);

foreach (array('step1', 'step2', 'step3', 'step4') as $step) {
    $response = $install->$step();
    if (is_array($response)) {
        fwrite(STDERR, "install-from-env: $step failed:\n  " . implode("\n  ", $response) . "\n");
        exit(1);
    }
}

echo "install-from-env: installation complete\n";
exit(0);
