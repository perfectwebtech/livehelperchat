<?php
/**
 * Liveness/readiness probe for load balancers, Kubernetes and uptime monitors.
 *
 *   GET /index.php/system/health
 *
 * Returns HTTP 200 and {"status":"ok"} when the database answers and the
 * writable directories are writable, HTTP 503 and {"status":"fail"} otherwise.
 * The payload intentionally contains no versions, hostnames or paths: it is
 * safe to expose unauthenticated. Per-check details are only added for a
 * logged-in operator (or when `token` matches the `health_token` setting in
 * settings.ini.php), so monitoring systems can still see what is broken.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$checks = array();
$healthy = true;

// 1. Database round trip
$start = microtime(true);
try {
    $db = ezcDbInstance::get();
    $stmt = $db->prepare('SELECT 1');
    $stmt->execute();
    $stmt->fetchColumn();
    $checks['database'] = array('status' => 'ok', 'latency_ms' => round((microtime(true) - $start) * 1000, 1));
} catch (Exception $e) {
    $healthy = false;
    $checks['database'] = array('status' => 'fail', 'error' => 'unreachable');
}

// 2. Writable directories the application cannot run without
$writable = array();
foreach (array('cache/compiledtemplates', 'cache/cacheconfig', 'cache/translations', 'cache/userinfo', 'var/storage', 'var/userphoto') as $dir) {
    $writable[$dir] = is_dir($dir) && is_writable($dir);
    if (!$writable[$dir]) {
        $healthy = false;
    }
}
$checks['writable'] = array('status' => in_array(false, $writable, true) ? 'fail' : 'ok', 'dirs' => $writable);

// 3. Cache backend (only reported when configured, failure is non fatal)
try {
    $cache = CSCacheAPC::getMem();
    $cache->getCacheVersion('site_version');
    $checks['cache'] = array('status' => 'ok');
} catch (Exception $e) {
    $checks['cache'] = array('status' => 'warn');
}

$response = array(
    'status' => $healthy ? 'ok' : 'fail',
    'time' => gmdate('c'),
);

$configuredToken = erConfigClassLhConfig::getInstance()->getSetting('site', 'health_token', false);
$presentedToken = isset($_GET['token']) ? (string)$_GET['token'] : '';

$verbose = (isset($currentUser) && $currentUser->isLogged())
    || erLhcoreClassUser::instance()->isLogged()
    || (is_string($configuredToken) && $configuredToken !== '' && hash_equals($configuredToken, $presentedToken));

if ($verbose) {
    $response['checks'] = $checks;
}

http_response_code($healthy ? 200 : 503);
echo json_encode($response);
exit;
