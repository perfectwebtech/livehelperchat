<?php
/**
 * Lets PHPStan resolve application classes through the project's own class
 * repository (lib/autoloads/*_autoload.php) instead of scanning the whole tree.
 */
chdir(__DIR__ . '/..');

require_once 'ezcomponents/Base/src/base.php';

ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(static function ($className) {
    ezcBase::autoload($className);
}, true, false);

// ezcBase reads lib/autoloads/lhcore_autoload.php lazily on the first erLhcore* lookup;
// prime it so classes with other prefixes (erConfigClass*, erLhAbstract*) resolve too.
class_exists('erLhcoreClassSystem');
