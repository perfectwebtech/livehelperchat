<?php
/**
 * Prometheus metrics, see erLhcoreClassMetrics.
 *
 * Disabled (404) until a metrics token is configured with LHC_METRICS_TOKEN or
 * `metrics_token` in settings.ini.php. The token is required on every request:
 * the numbers reveal business volume.
 */

$configured = erConfigClassLhConfig::getInstance()->getSetting('site', 'metrics_token', false);

if (!is_string($configured) || $configured === '') {
    http_response_code(404);
    exit;
}

$requestHeaders = function_exists('getallheaders') ? (array)getallheaders() : array();

if (!hash_equals($configured, erLhcoreClassMetrics::presentedToken($_SERVER, $_GET, $requestHeaders))) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="lhc-metrics"');
    exit;
}

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store');

try {
    echo erLhcoreClassMetrics::render(erLhcoreClassMetrics::collect());
} catch (Exception $e) {
    http_response_code(503);
    echo "# HELP lhc_up Application and database reachable\n# TYPE lhc_up gauge\nlhc_up 0\n";
}
exit;
