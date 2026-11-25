<?php
/**
 * Applies the database schema shipped with this code base
 * (doc/update_db/structure.json) to an installed Live Helper Chat.
 *
 * This is the same operation as System configuration -> Update -> "Update
 * database", but it reads the local structure file instead of downloading
 * upstream master, so the schema always matches the deployed code. It only
 * creates missing tables, columns and indexes, fixes collations and drops the
 * columns upstream explicitly lists as deprecated.
 *
 *   php cli/update-db.php             apply pending changes
 *   php cli/update-db.php --dry-run   print pending queries, change nothing
 *
 * Exit codes: 0 up to date / applied, 1 some queries failed, 2 not installed.
 */

if (php_sapi_name() != 'cli') {
    echo "Run from the command line\n";
    exit(2);
}

chdir(dirname(__DIR__));

require_once 'ezcomponents/Base/src/base.php';
ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
spl_autoload_register(array('erLhcoreClassSystem', 'autoload'), true, false);

$_SERVER['REQUEST_URI'] = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

erLhcoreClassSystem::init();
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');

$dryRun = in_array('--dry-run', $argv, true);

if (erConfigClassLhConfig::getInstance()->getSetting('site', 'installed', false) != true) {
    fwrite(STDERR, "Live Helper Chat is not installed yet, nothing to update.\n");
    exit(2);
}

$definition = json_decode(file_get_contents('doc/update_db/structure.json'), true);
if (!is_array($definition)) {
    fwrite(STDERR, "doc/update_db/structure.json is missing or not valid JSON.\n");
    exit(1);
}

$pending = array();
foreach (erLhcoreClassUpdate::getTablesStatus($definition) as $table => $status) {
    if ($status['error'] == true) {
        foreach ($status['queries'] as $query) {
            $pending[] = $query;
        }
    }
}

if (empty($pending)) {
    echo "Database schema is up to date.\n";
    exit(0);
}

echo count($pending), " schema change(s) pending:\n";
foreach ($pending as $query) {
    echo '  ', preg_replace('/\s+/', ' ', $query), "\n";
}

if ($dryRun) {
    echo "Dry run, nothing applied.\n";
    exit(0);
}

$errors = erLhcoreClassUpdate::doTablesUpdate($definition);
erConfigClassLhCacheConfig::getInstance()->expireCache();

if (!empty($errors)) {
    fwrite(STDERR, "Some changes failed:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}

echo "Schema updated.\n";
exit(0);
