<?php
/**
 * Lets PHPStan resolve application classes through the project's own class
 * repository (lib/autoloads/*_autoload.php) instead of scanning the whole tree.
 */
chdir(__DIR__ . '/..');

require_once 'ezcomponents/Base/src/base.php';

ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
