<?php

/**
 * Data protection (GDPR / CCPA) for visitor data.
 *
 *  - Retention policy, run hourly by cron/data_retention:
 *      closed chats older than N days are fully anonymised, chats older than
 *      M days are deleted. Live and archived chats (lh_chat_archive_*) alike.
 *  - Data subject requests (System configuration -> Data protection, or
 *    cli/gdpr.php): find every chat of a person by e-mail or phone, export
 *    it as JSON (right of access / portability) or erase it (right to erasure).
 *
 * "Fully anonymised" (anonymized = 2) means: visitor messages and their form
 * data blanked, name, e-mail, phone, IP, user agent, referrers, chat
 * variables, additional data, operator remarks, geo position and typing
 * preview cleared, uploaded files deleted and the visitor tracking rows of the
 * chat removed. Country and time zone are kept for statistics. The legacy
 * cron/encrypt job sets anonymized = 1 (messages only); such chats are still
 * picked up and completed by this policy.
 */
class erLhcoreClassGdpr
{
    const ANONYMIZED_MESSAGES = 1;
    const ANONYMIZED_FULL = 2;

    const PLACEHOLDER = '[anonymized]';

    public static $defaults = array(
        'anonymize_after_days' => 0,       // 0 = off
        'delete_after_days' => 0,          // 0 = off
        'anonymize_operator_messages' => 0,
        'batch_size' => 500,
    );

    /** @var array|null injected by tests */
    public static $settingsOverride = null;

    // ------------------------------------------------------------------ settings

    public static function getSettings()
    {
        if (self::$settingsOverride !== null) {
            return self::normalizeSettings(self::$settingsOverride);
        }
        return self::normalizeSettings((array)erLhcoreClassModelChatConfig::fetch('gdpr_retention')->data);
    }

    public static function normalizeSettings(array $data)
    {
        $settings = array();
        foreach (self::$defaults as $key => $default) {
            $settings[$key] = isset($data[$key]) && $data[$key] !== '' ? max(0, (int)$data[$key]) : $default;
        }
        $settings['batch_size'] = max(1, min(5000, $settings['batch_size']));
        $settings['anonymize_operator_messages'] = $settings['anonymize_operator_messages'] ? 1 : 0;
        return $settings;
    }

    /**
     * Configuration problems to show to the administrator. Empty = valid.
     * @return string[]
     */
    public static function validateSettings(array $settings)
    {
        $errors = array();
        if ($settings['anonymize_after_days'] > 0 && $settings['delete_after_days'] > 0 && $settings['delete_after_days'] <= $settings['anonymize_after_days']) {
            $errors[] = 'Deletion must happen after anonymisation: set "delete after" to more days than "anonymise after", or to 0.';
        }
        return $errors;
    }

    public static function saveSettings(array $data)
    {
        $row = erLhcoreClassModelChatConfig::fetch('gdpr_retention');
        $row->identifier = 'gdpr_retention';
        $row->explain = 'Data retention and anonymisation policy';
        $row->type = 0;
        $row->hidden = 1;
        $row->value = serialize(self::normalizeSettings($data));
        $row->saveThis();
        erConfigClassLhCacheConfig::getInstance()->expireCache();
    }

    /**
     * Unix timestamp before which chats fall under a "N days" rule, or 0 when the rule is off.
     */
    public static function cutoff($days, $now)
    {
        return $days > 0 ? (int)$now - (int)$days * 86400 : 0;
    }

    // ------------------------------------------------------------------ tables

    /**
     * Every (chat table, message table) pair: live tables first, then archives.
     * @return array<int, array{chat:string, msg:string, archive:bool}>
     */
    public static function tablePairs()
    {
        $pairs = array(array('chat' => 'lh_chat', 'msg' => 'lh_msg', 'archive' => false));
        try {
            $ranges = erLhcoreClassModelChatArchiveRange::getList(array('limit' => false, 'sort' => 'id ASC', 'ignore_fields' => array('year_month', 'range_from', 'range_to', 'older_than', 'last_id', 'first_id')));
            foreach ($ranges as $range) {
                $pairs[] = array('chat' => 'lh_chat_archive_' . (int)$range->id, 'msg' => 'lh_chat_archive_msg_' . (int)$range->id, 'archive' => true);
            }
        } catch (Exception $e) {
            // no archives configured
        }
        return $pairs;
    }

    private static function tableExists($table)
    {
        $stmt = ezcDbInstance::get()->prepare('SHOW TABLES LIKE ' . ezcDbInstance::get()->quote($table));
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    private static function inList(array $ids)
    {
        return implode(',', array_map('intval', $ids));
    }

    // ------------------------------------------------------------------ anonymise / delete

    /**
     * Fully anonymises the given chats inside one table pair.
     * @param int[] $chatIds
     */
    public static function anonymizeChats(array $pair, array $chatIds, $includeOperatorMessages = false)
    {
        if (empty($chatIds)) {
            return 0;
        }
        $db = ezcDbInstance::get();
        $in = self::inList($chatIds);

        // Visitor messages (user_id 0) carry what the visitor typed and form data in meta_msg.
        $authorCondition = $includeOperatorMessages ? '`user_id` >= 0' : '`user_id` = 0';
        $db->query("UPDATE `{$pair['msg']}` SET `msg` = " . $db->quote(self::PLACEHOLDER) . ", `meta_msg` = '' WHERE `chat_id` IN ($in) AND $authorCondition");

        $db->query("UPDATE `{$pair['chat']}` SET
            `nick` = 'Visitor', `email` = '', `phone` = '', `ip` = '', `uagent` = '',
            `referrer` = '', `session_referrer` = '', `chat_variables` = '', `additional_data` = '',
            `remarks` = '', `lat` = '0', `lon` = '0', `city` = '', `user_typing_txt` = '',
            `anonymized` = " . self::ANONYMIZED_FULL . "
            WHERE `id` IN ($in)");

        self::removeVisitorTraces($chatIds);

        return count($chatIds);
    }

    /**
     * Deletes the given chats inside one table pair, including messages and files.
     * Live chats go through the model so every dependent table is cleaned up.
     * @param int[] $chatIds
     */
    public static function deleteChats(array $pair, array $chatIds)
    {
        if (empty($chatIds)) {
            return 0;
        }

        if ($pair['archive'] === false) {
            $deleted = 0;
            foreach (erLhcoreClassModelChat::getList(array('limit' => false, 'filterin' => array('id' => array_map('intval', $chatIds)))) as $chat) {
                erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.delete', array('chat' => & $chat, 'user' => false));
                $chat->removeThis();
                $deleted++;
            }
            self::removeVisitorTraces($chatIds);
            return $deleted;
        }

        $db = ezcDbInstance::get();
        $in = self::inList($chatIds);
        $db->query("DELETE FROM `{$pair['msg']}` WHERE `chat_id` IN ($in)");
        $stmt = $db->prepare("DELETE FROM `{$pair['chat']}` WHERE `id` IN ($in)");
        $stmt->execute();
        self::removeVisitorTraces($chatIds);
        return $stmt->rowCount();
    }

    /**
     * Files, visitor tracking rows and page footprints linked to the chats.
     * @param int[] $chatIds
     */
    private static function removeVisitorTraces(array $chatIds)
    {
        $db = ezcDbInstance::get();
        $in = self::inList($chatIds);

        foreach ($chatIds as $chatId) {
            erLhcoreClassModelChatFile::deleteByChatId((int)$chatId);
        }

        $db->query("DELETE FROM `lh_chat_online_user_footprint` WHERE `chat_id` IN ($in)");
        $db->query("DELETE FROM `lh_chat_online_user` WHERE `chat_id` IN ($in)");
    }

    /**
     * Applies the retention policy once. Each rule handles at most batch_size
     * chats per table so an hourly run stays short; backlogs drain over runs.
     *
     * @return array{anonymized:int, deleted:int}
     */
    public static function runRetention($now = null)
    {
        $settings = self::getSettings();
        $now = $now === null ? time() : (int)$now;
        $result = array('anonymized' => 0, 'deleted' => 0);
        $db = ezcDbInstance::get();

        foreach (self::tablePairs() as $pair) {
            if ($pair['archive'] && (!self::tableExists($pair['chat']) || !self::tableExists($pair['msg']))) {
                continue;
            }

            if (($deleteBefore = self::cutoff($settings['delete_after_days'], $now)) > 0) {
                $stmt = $db->prepare("SELECT `id` FROM `{$pair['chat']}` WHERE `time` < :before AND `status` = :closed ORDER BY `id` ASC LIMIT " . (int)$settings['batch_size']);
                $stmt->bindValue(':before', $deleteBefore, PDO::PARAM_INT);
                $stmt->bindValue(':closed', erLhcoreClassModelChat::STATUS_CLOSED_CHAT, PDO::PARAM_INT);
                $stmt->execute();
                $result['deleted'] += self::deleteChats($pair, $stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            if (($anonymizeBefore = self::cutoff($settings['anonymize_after_days'], $now)) > 0) {
                $stmt = $db->prepare("SELECT `id` FROM `{$pair['chat']}` WHERE `time` < :before AND `status` = :closed AND `anonymized` < :full ORDER BY `id` ASC LIMIT " . (int)$settings['batch_size']);
                $stmt->bindValue(':before', $anonymizeBefore, PDO::PARAM_INT);
                $stmt->bindValue(':closed', erLhcoreClassModelChat::STATUS_CLOSED_CHAT, PDO::PARAM_INT);
                $stmt->bindValue(':full', self::ANONYMIZED_FULL, PDO::PARAM_INT);
                $stmt->execute();
                $result['anonymized'] += self::anonymizeChats($pair, $stmt->fetchAll(PDO::FETCH_COLUMN), $settings['anonymize_operator_messages'] == 1);
            }
        }

        if ($result['anonymized'] > 0 || $result['deleted'] > 0) {
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_DATA_PROTECTION, 'retention', array_merge($result, array('settings' => $settings)));
        }

        return $result;
    }

    // ------------------------------------------------------------------ data subject requests

    /**
     * Normalises an e-mail address or phone number for matching.
     * @return array{type:string, value:string}|null
     */
    public static function parseIdentifier($identifier)
    {
        $identifier = trim((string)$identifier);
        if ($identifier === '') {
            return null;
        }
        if (strpos($identifier, '@') !== false) {
            return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? array('type' => 'email', 'value' => mb_strtolower($identifier)) : null;
        }
        $digits = preg_replace('/[^0-9+]/', '', $identifier);
        return strlen(preg_replace('/[^0-9]/', '', $digits)) >= 5 ? array('type' => 'phone', 'value' => $digits) : null;
    }

    /**
     * @return array{type:string, value:string}
     */
    private static function requireIdentifier($identifier)
    {
        $parsed = self::parseIdentifier($identifier);
        if ($parsed === null) {
            throw new Exception('Enter a valid e-mail address or phone number');
        }
        return $parsed;
    }

    /**
     * Phone numbers are stored as typed; compare them on digits only.
     */
    public static function phoneMatches($stored, $wanted)
    {
        $a = preg_replace('/[^0-9]/', '', (string)$stored);
        $b = preg_replace('/[^0-9]/', '', (string)$wanted);
        if ($a === '' || $b === '') {
            return false;
        }
        // Allow a missing country prefix on either side (e.g. +44 7700 vs 07700).
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        return $a === $b || (strlen($a) >= 7 && strlen($b) >= 7 && (substr($a, -strlen($b)) === $b || substr($b, -strlen($a)) === $a));
    }

    /**
     * Every chat (live and archived) that belongs to the identifier.
     * @return array<int, array{pair:array, chat:array}>
     */
    public static function findSubjectChats($identifier)
    {
        $parsed = self::requireIdentifier($identifier);

        $db = ezcDbInstance::get();
        $found = array();
        $fields = '`id`, `time`, `status`, `nick`, `email`, `phone`, `ip`, `uagent`, `referrer`, `session_referrer`, `country_code`, `country_name`, `city`, `dep_id`, `user_id`, `chat_variables`, `additional_data`, `remarks`, `anonymized`';

        foreach (self::tablePairs() as $pair) {
            if ($pair['archive'] && !self::tableExists($pair['chat'])) {
                continue;
            }
            if ($parsed['type'] === 'email') {
                $stmt = $db->prepare("SELECT $fields FROM `{$pair['chat']}` WHERE LOWER(`email`) = :value ORDER BY `id` ASC");
                $stmt->bindValue(':value', $parsed['value']);
            } else {
                // Narrow on the last digits in SQL, then compare exactly in PHP.
                $tail = substr(preg_replace('/[^0-9]/', '', $parsed['value']), -7);
                $stmt = $db->prepare("SELECT $fields FROM `{$pair['chat']}` WHERE `phone` <> '' AND REPLACE(REPLACE(REPLACE(REPLACE(`phone`, ' ', ''), '-', ''), '(', ''), ')', '') LIKE :tail ORDER BY `id` ASC");
                $stmt->bindValue(':tail', '%' . $tail);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($parsed['type'] === 'phone' && !self::phoneMatches($row['phone'], $parsed['value'])) {
                    continue;
                }
                $found[] = array('pair' => $pair, 'chat' => $row);
            }
        }

        return $found;
    }

    /**
     * Right of access / portability: everything stored about the subject.
     */
    public static function exportSubject($identifier)
    {
        $parsed = self::requireIdentifier($identifier);
        $db = ezcDbInstance::get();
        $chats = array();

        foreach (self::findSubjectChats($identifier) as $item) {
            $chat = $item['chat'];
            $stmt = $db->prepare("SELECT `time`, `user_id`, `name_support`, `msg` FROM `{$item['pair']['msg']}` WHERE `chat_id` = :id ORDER BY `id` ASC");
            $stmt->bindValue(':id', (int)$chat['id'], PDO::PARAM_INT);
            $stmt->execute();

            $messages = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $msg) {
                $messages[] = array(
                    'time' => gmdate('c', (int)$msg['time']),
                    'from' => $msg['user_id'] == 0 ? 'visitor' : ($msg['user_id'] > 0 ? 'operator: ' . $msg['name_support'] : 'system'),
                    'text' => $msg['msg'],
                );
            }

            $files = array();
            foreach (erLhcoreClassModelChatFile::getList(array('limit' => false, 'filter' => array('chat_id' => (int)$chat['id']))) as $file) {
                $files[] = array('name' => $file->upload_name, 'type' => $file->type, 'size' => (int)$file->size, 'uploaded' => gmdate('c', (int)$file->date));
            }

            $chats[] = array(
                'chat_id' => (int)$chat['id'],
                'started' => gmdate('c', (int)$chat['time']),
                'archived' => $item['pair']['archive'],
                'anonymized' => (int)$chat['anonymized'],
                'visitor' => array(
                    'name' => $chat['nick'],
                    'email' => $chat['email'],
                    'phone' => $chat['phone'],
                    'ip' => $chat['ip'],
                    'user_agent' => $chat['uagent'],
                    'country' => $chat['country_name'],
                    'city' => $chat['city'],
                    'referrer' => $chat['referrer'],
                    'session_referrer' => $chat['session_referrer'],
                ),
                'chat_variables' => self::decodeMaybeJson($chat['chat_variables']),
                'additional_data' => self::decodeMaybeJson($chat['additional_data']),
                'operator_remarks' => $chat['remarks'],
                'messages' => $messages,
                'files' => $files,
            );
        }

        return array(
            'subject' => $parsed,
            'generated' => gmdate('c'),
            'chat_count' => count($chats),
            'chats' => $chats,
        );
    }

    /**
     * Right to erasure: deletes every chat of the subject. Returns the count.
     */
    public static function eraseSubject($identifier)
    {
        $parsed = self::requireIdentifier($identifier);
        $byPair = array();
        foreach (self::findSubjectChats($identifier) as $item) {
            $key = $item['pair']['chat'];
            $byPair[$key]['pair'] = $item['pair'];
            $byPair[$key]['ids'][] = (int)$item['chat']['id'];
        }

        $deleted = 0;
        foreach ($byPair as $entry) {
            $deleted += self::deleteChats($entry['pair'], $entry['ids']);
        }

        erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_DATA_PROTECTION, 'erase_subject', array(
            // the identifier itself is personal data: keep only a hash to prove which request was executed
            'subject_hash' => hash('sha256', $parsed['type'] . ':' . $parsed['value']),
            'subject_type' => $parsed['type'],
            'chats_deleted' => $deleted,
        ));

        return $deleted;
    }

    private static function decodeMaybeJson($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
