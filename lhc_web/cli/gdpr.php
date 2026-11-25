<?php
/**
 * Data subject requests from the command line (ticket automation, DPO scripts).
 *
 *   php cli/gdpr.php find   <email|phone>
 *   php cli/gdpr.php export <email|phone>          JSON on stdout
 *   php cli/gdpr.php erase  <email|phone> --yes    permanent
 *   php cli/gdpr.php retention                     run the retention policy now
 *
 * Every export, erase and retention run is written to the audit log.
 */

if (php_sapi_name() != 'cli') {
    exit(2);
}

chdir(dirname(__DIR__));
$_SERVER['REQUEST_URI'] = '/';

require_once 'ezcomponents/Base/src/base.php';
ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
spl_autoload_register(array('erLhcoreClassSystem', 'autoload'), true, false);
erLhcoreClassSystem::init();
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');

$command = isset($argv[1]) ? $argv[1] : '';
$identifier = isset($argv[2]) ? $argv[2] : '';

$usage = "usage: php cli/gdpr.php find|export|erase <email|phone> [--yes]\n       php cli/gdpr.php retention\n";

try {
    switch ($command) {
        case 'find':
            foreach (erLhcoreClassGdpr::findSubjectChats($identifier) as $item) {
                echo $item['chat']['id'], "\t", date('Y-m-d H:i', (int)$item['chat']['time']), "\t", $item['pair']['chat'], "\t", $item['chat']['nick'], "\n";
            }
            break;

        case 'export':
            $export = erLhcoreClassGdpr::exportSubject($identifier);
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_DATA_PROTECTION, 'export_subject', array(
                'subject_hash' => hash('sha256', $export['subject']['type'] . ':' . $export['subject']['value']),
                'subject_type' => $export['subject']['type'],
                'chats' => $export['chat_count'],
            ));
            echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            break;

        case 'erase':
            if (!in_array('--yes', $argv, true)) {
                fwrite(STDERR, "erase is permanent; add --yes to confirm\n");
                exit(2);
            }
            echo erLhcoreClassGdpr::eraseSubject($identifier), " chat(s) erased\n";
            break;

        case 'retention':
            $result = erLhcoreClassGdpr::runRetention();
            echo $result['anonymized'], " chats anonymised, ", $result['deleted'], " chats deleted\n";
            break;

        default:
            fwrite(STDERR, $usage);
            exit(2);
    }
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(0);
