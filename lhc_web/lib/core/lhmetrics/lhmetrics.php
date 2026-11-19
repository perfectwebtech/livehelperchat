<?php

/**
 * Operational metrics in the Prometheus text exposition format.
 *
 *   GET /index.php/system/metrics   (Authorization: Bearer <metrics_token> or ?token=)
 *
 * Every value is a gauge computed from the database at scrape time, so the
 * endpoint needs no state and works with any number of web containers.
 * Typical alerts:
 *   lhc_pending_chat_oldest_seconds > 120     visitors waiting too long
 *   lhc_operators_online == 0                 nobody to answer during business hours
 *   lhc_up == 0 / scrape failure              application or database down
 */
class erLhcoreClassMetrics
{
    /**
     * Collects all metrics. Each entry: name => array(help, value, labels[] = optional)
     * @return array<string, array{help:string, samples:array<int, array{labels:array<string,string>, value:float|int}>}>
     */
    public static function collect($now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $db = ezcDbInstance::get();
        $metrics = array();

        $start = microtime(true);
        $db->query('SELECT 1')->fetchColumn();
        self::add($metrics, 'lhc_db_query_seconds', 'Latency of a trivial database query', round(microtime(true) - $start, 6));

        // Chats by state (closed chats are history, not load).
        $states = array(
            erLhcoreClassModelChat::STATUS_PENDING_CHAT => 'pending',
            erLhcoreClassModelChat::STATUS_ACTIVE_CHAT => 'active',
            erLhcoreClassModelChat::STATUS_BOT_CHAT => 'bot',
        );
        $counts = array_fill_keys(array_values($states), 0);
        $stmt = $db->prepare('SELECT `status`, COUNT(*) FROM `lh_chat` WHERE `status` IN (' . implode(',', array_keys($states)) . ') GROUP BY `status`');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $counts[$states[(int)$row[0]]] = (int)$row[1];
        }
        foreach ($counts as $state => $count) {
            self::add($metrics, 'lhc_chats', 'Chats currently in each state', $count, array('state' => $state));
        }

        $stmt = $db->prepare('SELECT MIN(`time`) FROM `lh_chat` WHERE `status` = :pending');
        $stmt->bindValue(':pending', erLhcoreClassModelChat::STATUS_PENDING_CHAT, PDO::PARAM_INT);
        $stmt->execute();
        $oldest = $stmt->fetchColumn();
        self::add($metrics, 'lhc_pending_chat_oldest_seconds', 'How long the longest waiting visitor has been waiting (0 = nobody waiting)', $oldest ? max(0, $now - (int)$oldest) : 0);

        $stmt = $db->prepare('SELECT COUNT(*), COALESCE(AVG(CASE WHEN `wait_time` > 0 THEN `wait_time` END), 0) FROM `lh_chat` WHERE `time` > :since');
        $stmt->bindValue(':since', $now - 3600, PDO::PARAM_INT);
        $stmt->execute();
        list($startedLastHour, $avgWait) = $stmt->fetch(PDO::FETCH_NUM);
        self::add($metrics, 'lhc_chats_started_last_hour', 'Chats started during the last hour', (int)$startedLastHour);
        self::add($metrics, 'lhc_chat_wait_seconds_avg_last_hour', 'Average time until an operator accepted, for chats started during the last hour', round((float)$avgWait, 1));

        // Operators: same rule the chat widget uses to decide whether support is online.
        $timeout = (int)erLhcoreClassModelChatConfig::fetch('sync_sound_settings')->data['online_timeout'];
        $stmt = $db->prepare('SELECT COUNT(DISTINCT `user_id`), COUNT(DISTINCT CASE WHEN `hide_online` = 0 THEN `user_id` END) FROM `lh_userdep` WHERE (`last_activity` > :since OR `always_on` = 1)');
        $stmt->bindValue(':since', $now - ($timeout > 0 ? $timeout : 300), PDO::PARAM_INT);
        $stmt->execute();
        list($loggedIn, $online) = $stmt->fetch(PDO::FETCH_NUM);
        self::add($metrics, 'lhc_operators_online', 'Operators active and visible as online', (int)$online);
        self::add($metrics, 'lhc_operators_logged_in', 'Operators active, including those set to invisible/away', (int)$loggedIn);

        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM `lh_login_throttle` WHERE `locked_until` > :now');
            $stmt->bindValue(':now', $now, PDO::PARAM_INT);
            $stmt->execute();
            self::add($metrics, 'lhc_login_locks_active', 'IP addresses and usernames currently locked by brute-force protection', (int)$stmt->fetchColumn());
        } catch (Exception $e) {
            // table not migrated yet
        }

        self::add($metrics, 'lhc_up', 'Application and database reachable', 1);

        return $metrics;
    }

    private static function add(array &$metrics, $name, $help, $value, array $labels = array())
    {
        if (!isset($metrics[$name])) {
            $metrics[$name] = array('help' => $help, 'samples' => array());
        }
        $metrics[$name]['samples'][] = array('labels' => $labels, 'value' => $value);
    }

    /**
     * Pure: renders collected metrics in the Prometheus text format (version 0.0.4).
     */
    public static function render(array $metrics)
    {
        $out = '';
        foreach ($metrics as $name => $metric) {
            $out .= '# HELP ' . $name . ' ' . str_replace(array('\\', "\n"), array('\\\\', '\\n'), $metric['help']) . "\n";
            $out .= '# TYPE ' . $name . " gauge\n";
            foreach ($metric['samples'] as $sample) {
                $labels = '';
                if (!empty($sample['labels'])) {
                    $pairs = array();
                    foreach ($sample['labels'] as $key => $val) {
                        $pairs[] = $key . '="' . str_replace(array('\\', '"', "\n"), array('\\\\', '\\"', '\\n'), (string)$val) . '"';
                    }
                    $labels = '{' . implode(',', $pairs) . '}';
                }
                $value = $sample['value'];
                $out .= $name . $labels . ' ' . (is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string)(int)$value) . "\n";
            }
        }
        return $out;
    }

    /**
     * Token from "Authorization: Bearer ..." or ?token=. Pure apart from reading the request.
     */
    public static function presentedToken(array $server, array $get, array $headers = array())
    {
        $header = isset($server['HTTP_AUTHORIZATION']) ? $server['HTTP_AUTHORIZATION'] : (isset($server['REDIRECT_HTTP_AUTHORIZATION']) ? $server['REDIRECT_HTTP_AUTHORIZATION'] : '');
        if ($header === '') {
            // mod_php does not always copy Authorization into $_SERVER
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string)$value;
                }
            }
        }
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return isset($get['token']) && is_string($get['token']) ? $get['token'] : '';
    }
}
