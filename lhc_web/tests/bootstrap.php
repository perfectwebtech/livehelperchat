<?php
/**
 * Minimal bootstrap for unit tests.
 *
 * Unit tests cover pure logic only, so the full application kernel (database,
 * sessions, templates) is deliberately not started. Classes under test are
 * loaded through the regular class repository so autoloading stays identical
 * to production.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

chdir(__DIR__ . '/..');

require_once 'ezcomponents/Base/src/base.php';

ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(static function ($className) {
    ezcBase::autoload($className);
}, true, false);
