<?php

/**
 * php cron.php -s site_admin -c cron/data_retention
 *
 * Run every hour. Applies the data retention policy configured under
 * System configuration -> Data retention (anonymise / delete old closed chats,
 * live and archived). See erLhcoreClassGdpr.
 */

$settings = erLhcoreClassGdpr::getSettings();

if ($settings['anonymize_after_days'] == 0 && $settings['delete_after_days'] == 0) {
    echo "Data retention policy is not configured\n";
    return;
}

$result = erLhcoreClassGdpr::runRetention();

echo $result['anonymized'], " chats anonymised, ", $result['deleted'], " chats deleted\n";
