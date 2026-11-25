<?php

/**
 * AI assistant backed by a self-hosted (or any) OpenAI compatible model server:
 * vLLM, Ollama, LM Studio, llama.cpp server, Text Generation Inference, or a
 * hosted API with the same /v1/chat/completions contract.
 *
 * Two features use it:
 *   - reply suggestions: an operator asks for a draft answer inside the chat
 *     window (ai/suggest). Nothing reaches the visitor until the operator sends it.
 *   - auto-reply: while a chat in an enabled department waits for an operator,
 *     the assistant answers the visitor. It stops as soon as an operator takes the
 *     chat, the visitor asks for a person, the model answers [HANDOFF], or the
 *     per-chat reply limit is reached.
 *
 * Privacy: personal data (e-mail, phone, card numbers, IBAN) is replaced with
 * placeholders before anything is sent to the model when mask_pii is on, and
 * every call is written to the audit log (metadata only, never message text).
 *
 * Settings live in the `ai_assistant` configuration row (System configuration ->
 * AI assistant). LHC_AI_BASE_URL, LHC_AI_API_KEY and LHC_AI_MODEL (or their
 * *_FILE variants) override the stored values and are never saved.
 */
class erLhcoreClassAiAssistant
{
    const HANDOFF_TOKEN = '[HANDOFF]';
    const CHAT_VARIABLE = 'lhc_ai';

    public static $defaults = array(
        'enabled' => 0,
        'base_url' => '',              // e.g. http://gpu-host:8000/v1 (vLLM) or http://ollama:11434/v1
        'api_key' => '',
        'model' => '',
        'system_prompt' => "You are a helpful customer support assistant. Answer briefly and politely in the visitor's language. Only use facts from the conversation and the knowledge below. If you are not sure, or the visitor needs something you cannot do, reply with exactly [HANDOFF] and nothing else.",
        'knowledge' => '',             // optional facts appended to the system prompt (opening hours, policies, ...)
        'temperature' => '0.3',
        'max_tokens' => 400,
        'timeout' => 20,
        'history_messages' => 12,
        'mask_pii' => 1,
        'verify_tls' => 1,
        'suggest_enabled' => 1,
        'autoreply_enabled' => 0,
        'autoreply_departments' => '', // comma separated department ids
        'autoreply_max' => 6,          // assistant answers per chat before handing off
        'handoff_keywords' => 'human, operator, agent, person, representative',
        'handoff_message' => 'Let me connect you with a member of our team. Please wait a moment.',
    );

    /** @var array|null injected by tests */
    public static $settingsOverride = null;

    /** @var callable|null injected by tests: function($url, $body, $headers, $timeout, $verifyTls) : array(status, body) */
    public static $httpClient = null;

    // ------------------------------------------------------------------ settings

    public static function getSettings()
    {
        if (self::$settingsOverride !== null) {
            $data = self::$settingsOverride;
        } else {
            $data = (array)erLhcoreClassModelChatConfig::fetch('ai_assistant')->data;
        }
        return self::applyEnvironment(self::normalizeSettings($data));
    }

    public static function normalizeSettings(array $data)
    {
        $settings = array();
        foreach (self::$defaults as $key => $default) {
            $value = array_key_exists($key, $data) && $data[$key] !== null ? $data[$key] : $default;
            $settings[$key] = is_int($default) ? (int)$value : trim((string)$value);
        }
        $settings['base_url'] = rtrim($settings['base_url'], '/');
        $settings['temperature'] = (string)max(0, min(2, (float)str_replace(',', '.', $settings['temperature'])));
        $settings['max_tokens'] = max(16, min(8192, $settings['max_tokens']));
        $settings['timeout'] = max(2, min(120, $settings['timeout']));
        $settings['history_messages'] = max(1, min(50, $settings['history_messages']));
        $settings['autoreply_max'] = max(1, min(100, $settings['autoreply_max']));
        $settings['autoreply_departments'] = implode(',', self::departmentIds($settings['autoreply_departments']));
        return $settings;
    }

    /** Environment values win over stored ones (they are not persisted). */
    public static function applyEnvironment(array $settings)
    {
        foreach (array('LHC_AI_BASE_URL' => 'base_url', 'LHC_AI_API_KEY' => 'api_key', 'LHC_AI_MODEL' => 'model') as $env => $key) {
            $value = self::env($env);
            if ($value !== null && $value !== '') {
                $settings[$key] = $key === 'base_url' ? rtrim($value, '/') : $value;
                $settings['env_' . $key] = 1;
            }
        }
        return $settings;
    }

    private static function env($name)
    {
        $file = getenv($name . '_FILE');
        if ($file !== false && $file !== '' && is_readable($file)) {
            return trim((string)file_get_contents($file));
        }
        $value = getenv($name);
        return $value === false ? null : trim((string)$value);
    }

    public static function saveSettings(array $data)
    {
        $row = erLhcoreClassModelChatConfig::fetch('ai_assistant');
        $row->identifier = 'ai_assistant';
        $row->explain = 'AI assistant (OpenAI compatible model server) settings';
        $row->type = 0;
        $row->hidden = 1;
        $row->value = serialize(self::normalizeSettings($data));
        $row->saveThis();
        erConfigClassLhCacheConfig::getInstance()->expireCache();
    }

    public static function isConfigured(array $settings = null)
    {
        $s = $settings === null ? self::getSettings() : $settings;
        return $s['enabled'] == 1 && $s['base_url'] !== '' && $s['model'] !== '' && self::isAllowedUrl($s['base_url']);
    }

    public static function departmentIds($value)
    {
        $ids = array();
        foreach (preg_split('/[\s,]+/', (string)$value) as $id) {
            if (ctype_digit($id) && (int)$id > 0) {
                $ids[(int)$id] = (int)$id;
            }
        }
        return array_values($ids);
    }

    public static function isAllowedUrl($url)
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('http', 'https'), true) && (string)parse_url($url, PHP_URL_HOST) !== '';
    }

    // ------------------------------------------------------------------ privacy

    /**
     * Replaces personal data with placeholders. Card numbers are only replaced
     * when they pass the Luhn check so order numbers survive.
     */
    public static function maskPii($text)
    {
        $text = (string)$text;

        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);

        $text = preg_replace('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,3})?\b/', '[iban]', $text);

        $text = preg_replace_callback('/\b(?:\d[ \-]?){12,18}\d\b/', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);
            return self::luhn($digits) ? '[card]' : $m[0];
        }, $text);

        $text = preg_replace('/(?<![\w\[])\+?\d[\d\s().\-]{6,}\d(?!\w)/', '[phone]', $text);

        return $text;
    }

    public static function luhn($digits)
    {
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $d = (int)$digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    // ------------------------------------------------------------------ prompt

    /**
     * Turns chat messages into an OpenAI style message list.
     * @param array $history list of array('user_id' => int, 'msg' => string), oldest first
     */
    public static function buildMessages(array $history, array $settings, $mode = 'autoreply')
    {
        $system = $settings['system_prompt'];
        if ($settings['knowledge'] !== '') {
            $system .= "\n\nKnowledge:\n" . $settings['knowledge'];
        }
        if ($mode === 'suggest') {
            $system .= "\n\nYou are drafting the next reply for a human support operator, who will review it before sending. Write only the reply text.";
        }

        $messages = array(array('role' => 'system', 'content' => $system));

        foreach (array_slice($history, -$settings['history_messages']) as $item) {
            $userId = (int)$item['user_id'];
            if ($userId == -1) {
                continue; // system messages (transfers, status changes)
            }
            $text = trim(strip_tags(preg_replace('/\[\/?[a-z_]+(=[^\]]*)?\]/i', '', (string)$item['msg'])));
            if ($text === '') {
                continue;
            }
            if ($settings['mask_pii'] == 1) {
                $text = self::maskPii($text);
            }
            $messages[] = array('role' => $userId == 0 ? 'user' : 'assistant', 'content' => $text);
        }

        return $messages;
    }

    public static function chatHistory($chat, $limit)
    {
        $items = array();
        foreach (array_reverse(erLhcoreClassModelmsg::getList(array('filter' => array('chat_id' => $chat->id), 'sort' => 'id DESC', 'limit' => (int)$limit))) as $msg) {
            $items[] = array('user_id' => (int)$msg->user_id, 'msg' => (string)$msg->msg);
        }
        return $items;
    }

    // ------------------------------------------------------------------ model call

    /**
     * Calls the model server. Returns array('text' => string, 'usage' => array, 'ms' => int).
     * Throws on transport errors, HTTP errors and malformed responses.
     */
    public static function complete(array $messages, array $settings)
    {
        if (!self::isAllowedUrl($settings['base_url'])) {
            throw new Exception('AI base URL has to be an http(s) URL');
        }

        $body = json_encode(array(
            'model' => $settings['model'],
            'messages' => $messages,
            'temperature' => (float)$settings['temperature'],
            'max_tokens' => (int)$settings['max_tokens'],
            'stream' => false,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = array('Content-Type: application/json', 'Accept: application/json');
        if ($settings['api_key'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $settings['api_key'];
        }

        $start = microtime(true);
        list($status, $response) = self::http($settings['base_url'] . '/chat/completions', $body, $headers, (int)$settings['timeout'], $settings['verify_tls'] == 1);
        $ms = (int)round((microtime(true) - $start) * 1000);

        if ($status < 200 || $status >= 300) {
            throw new Exception('Model server returned HTTP ' . $status);
        }

        $parsed = self::parseResponse($response);
        $parsed['ms'] = $ms;
        return $parsed;
    }

    public static function parseResponse($response)
    {
        $data = json_decode((string)$response, true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content']) || !is_string($data['choices'][0]['message']['content'])) {
            throw new Exception('Unexpected response from the model server');
        }
        $text = trim($data['choices'][0]['message']['content']);
        // Reasoning models may prepend their chain of thought.
        $text = trim(preg_replace('#<think>.*?</think>#s', '', $text));
        return array('text' => $text, 'usage' => isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : array());
    }

    private static function http($url, $body, array $headers, $timeout, $verifyTls)
    {
        if (self::$httpClient !== null) {
            return call_user_func(self::$httpClient, $url, $body, $headers, $timeout, $verifyTls);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Model server not reachable: ' . $error);
        }
        return array($status, $response);
    }

    /** Runs a model call and writes the audit entry (metadata only). */
    public static function run($action, array $messages, array $settings, $chatId = 0)
    {
        try {
            $result = self::complete($messages, $settings);
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_AI, $action, array(
                'ok' => true,
                'chat_id' => (int)$chatId,
                'model' => $settings['model'],
                'ms' => $result['ms'],
                'prompt_tokens' => isset($result['usage']['prompt_tokens']) ? (int)$result['usage']['prompt_tokens'] : null,
                'completion_tokens' => isset($result['usage']['completion_tokens']) ? (int)$result['usage']['completion_tokens'] : null,
            ), (int)$chatId);
            return $result;
        } catch (Exception $e) {
            erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_AI, $action, array(
                'ok' => false,
                'chat_id' => (int)$chatId,
                'model' => $settings['model'],
                'error' => mb_substr($e->getMessage(), 0, 200),
            ), (int)$chatId);
            throw $e;
        }
    }

    // ------------------------------------------------------------------ reply suggestions

    public static function suggestReply($chat)
    {
        $settings = self::getSettings();
        if (!self::isConfigured($settings) || $settings['suggest_enabled'] != 1) {
            throw new Exception('AI reply suggestions are not enabled');
        }
        $messages = self::buildMessages(self::chatHistory($chat, $settings['history_messages']), $settings, 'suggest');
        $result = self::run('suggest', $messages, $settings, $chat->id);
        return $result['text'] === self::HANDOFF_TOKEN ? '' : $result['text'];
    }

    // ------------------------------------------------------------------ auto-reply

    public static function wantsHuman($text, $keywords)
    {
        $text = mb_strtolower((string)$text);
        foreach (preg_split('/\s*,\s*/', mb_strtolower((string)$keywords)) as $keyword) {
            if ($keyword !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u', $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the assistant may answer this chat now.
     * @param array $state the chat's lhc_ai variable: replies (int), handoff (bool)
     */
    public static function mayAutoReply($chat, array $settings, array $state)
    {
        return self::isConfigured($settings)
            && $settings['autoreply_enabled'] == 1
            && in_array((int)$chat->dep_id, self::departmentIds($settings['autoreply_departments']), true)
            && (int)$chat->status === erLhcoreClassModelChat::STATUS_PENDING_CHAT
            && (int)$chat->user_id === 0
            && empty($state['handoff'])
            && (int)(isset($state['replies']) ? $state['replies'] : 0) < $settings['autoreply_max'];
    }

    public static function chatState($chat)
    {
        $vars = json_decode((string)$chat->chat_variables, true);
        return (is_array($vars) && isset($vars[self::CHAT_VARIABLE]) && is_array($vars[self::CHAT_VARIABLE])) ? $vars[self::CHAT_VARIABLE] : array();
    }

    private static function saveChatState($chat, array $state)
    {
        $vars = json_decode((string)$chat->chat_variables, true);
        if (!is_array($vars)) {
            $vars = array();
        }
        $vars[self::CHAT_VARIABLE] = $state;
        $chat->chat_variables = json_encode($vars);
        $chat->updateThis(array('update' => array('chat_variables')));
    }

    /** Listener for chat.addmsguser */
    public static function visitorMessage($params)
    {
        try {
            if (!isset($params['chat'], $params['msg']) || isset($params['file']) || !($params['chat'] instanceof erLhcoreClassModelChat)) {
                return;
            }
            $settings = self::getSettings();
            if (!self::mayAutoReply($params['chat'], $settings, self::chatState($params['chat']))) {
                return;
            }

            $chatId = (int)$params['chat']->id;
            $text = (string)$params['msg']->msg;

            // Some endpoints dispatch while they still hold a row lock on the chat.
            // The model call can take seconds, so run it once the request committed.
            $db = ezcDbInstance::get();
            if ($db->inTransaction()) {
                register_shutdown_function(function () use ($chatId, $text) {
                    erLhcoreClassAiAssistant::autoReply($chatId, $text);
                });
                return;
            }

            self::autoReply($chatId, $text);
        } catch (Exception $e) {
            // Never break the visitor's message.
        }
    }

    public static function autoReply($chatId, $visitorText)
    {
        try {
            $chat = erLhcoreClassModelChat::fetch($chatId, false);
            if (!($chat instanceof erLhcoreClassModelChat)) {
                return;
            }
            $settings = self::getSettings();
            $state = self::chatState($chat);

            if (!self::mayAutoReply($chat, $settings, $state)) {
                return;
            }

            $state['replies'] = (int)(isset($state['replies']) ? $state['replies'] : 0);

            if (self::wantsHuman($visitorText, $settings['handoff_keywords'])) {
                self::handoff($chat, $state, $settings, 'visitor_request');
                return;
            }

            $messages = self::buildMessages(self::chatHistory($chat, $settings['history_messages']), $settings, 'autoreply');
            $result = self::run('autoreply', $messages, $settings, $chat->id);

            // An operator may have taken the chat while the model was thinking.
            $fresh = erLhcoreClassModelChat::fetch($chat->id, false);
            if (!($fresh instanceof erLhcoreClassModelChat) || !self::mayAutoReply($fresh, $settings, self::chatState($fresh))) {
                return;
            }
            $chat = $fresh;

            if ($result['text'] === '' || strpos($result['text'], self::HANDOFF_TOKEN) !== false) {
                self::handoff($chat, $state, $settings, 'model');
                return;
            }

            erLhcoreClassGenericBotWorkflow::sendAsBot($chat, $result['text']);
            $state['replies']++;
            self::saveChatState($chat, $state);

            if ($state['replies'] >= $settings['autoreply_max']) {
                self::handoff($chat, $state, $settings, 'limit', false);
            }
        } catch (Exception $e) {
            // A failing model server must never break the visitor's chat: hand off to people.
            if (isset($chat, $state, $settings) && $chat instanceof erLhcoreClassModelChat) {
                try { self::handoff($chat, $state, $settings, 'error'); } catch (Exception $ignore) {}
            }
        }
    }

    private static function handoff($chat, array $state, array $settings, $reason, $announce = true)
    {
        if (!empty($state['handoff'])) {
            return;
        }
        $state['handoff'] = true;
        $state['handoff_reason'] = $reason;
        self::saveChatState($chat, $state);
        if ($announce && $settings['handoff_message'] !== '') {
            erLhcoreClassGenericBotWorkflow::sendAsBot($chat, $settings['handoff_message']);
        }
    }
}
