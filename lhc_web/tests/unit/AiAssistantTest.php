<?php

use PHPUnit\Framework\TestCase;

/**
 * Pure parts of erLhcoreClassAiAssistant: settings normalisation, personal data
 * masking, prompt building, response parsing and the auto-reply rules.
 */
class AiAssistantTest extends TestCase
{
    protected function tearDown(): void
    {
        erLhcoreClassAiAssistant::$settingsOverride = null;
        erLhcoreClassAiAssistant::$httpClient = null;
        putenv('LHC_AI_API_KEY');
    }

    private function settings(array $overrides = array())
    {
        return erLhcoreClassAiAssistant::normalizeSettings(array_merge(array(
            'enabled' => 1,
            'base_url' => 'http://gpu.internal:8000/v1/',
            'model' => 'qwen2.5-7b-instruct',
            'autoreply_enabled' => 1,
            'autoreply_departments' => '2, 3,x,0',
        ), $overrides));
    }

    private function chat($status, $userId = 0, $depId = 2)
    {
        $chat = new erLhcoreClassModelChat();
        $chat->status = $status;
        $chat->user_id = $userId;
        $chat->dep_id = $depId;
        return $chat;
    }

    public function testNormalizeClampsAndCleans()
    {
        $s = $this->settings(array('temperature' => '5', 'timeout' => 9999, 'max_tokens' => 1));
        $this->assertSame('http://gpu.internal:8000/v1', $s['base_url']);
        $this->assertSame('2,3', $s['autoreply_departments']);
        $this->assertSame('2', $s['temperature']);
        $this->assertSame(120, $s['timeout']);
        $this->assertSame(16, $s['max_tokens']);
        $this->assertTrue(erLhcoreClassAiAssistant::isConfigured($s));
        $this->assertFalse(erLhcoreClassAiAssistant::isConfigured($this->settings(array('base_url' => 'file:///etc/passwd'))));
        $this->assertFalse(erLhcoreClassAiAssistant::isConfigured($this->settings(array('enabled' => 0))));
    }

    public function testEnvironmentOverridesKey()
    {
        putenv('LHC_AI_API_KEY=env-secret');
        erLhcoreClassAiAssistant::$settingsOverride = array('api_key' => 'stored');
        $s = erLhcoreClassAiAssistant::getSettings();
        $this->assertSame('env-secret', $s['api_key']);
        $this->assertSame(1, $s['env_api_key']);
    }

    public function testMaskPii()
    {
        $masked = erLhcoreClassAiAssistant::maskPii('Mail jane.doe@example.com or call +44 20 7946 0958, card 4111 1111 1111 1111, IBAN GB82 WEST 1234 5698 7654 32, order 1234567890123');
        $this->assertStringContainsString('[email]', $masked);
        $this->assertStringContainsString('[phone]', $masked);
        $this->assertStringContainsString('[card]', $masked);
        $this->assertStringContainsString('[iban]', $masked);
        $this->assertStringNotContainsString('jane.doe', $masked);
        $this->assertStringNotContainsString('4111', $masked);
        $this->assertSame('My order is 42 and it costs 19.99', erLhcoreClassAiAssistant::maskPii('My order is 42 and it costs 19.99'));
        $this->assertTrue(erLhcoreClassAiAssistant::luhn('4111111111111111'));
        $this->assertFalse(erLhcoreClassAiAssistant::luhn('4111111111111112'));
    }

    public function testBuildMessages()
    {
        $s = $this->settings(array('knowledge' => 'We are open 9-17.', 'history_messages' => 3));
        $messages = erLhcoreClassAiAssistant::buildMessages(array(
            array('user_id' => 0, 'msg' => 'too old'),
            array('user_id' => 0, 'msg' => 'Hi, my email is a@b.co'),
            array('user_id' => -1, 'msg' => 'Chat was transferred'),
            array('user_id' => -2, 'msg' => '[b]Hello![/b] <i>How can I help?</i>'),
        ), $s, 'suggest');

        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('We are open 9-17.', $messages[0]['content']);
        $this->assertStringContainsString('human support operator', $messages[0]['content']);
        $this->assertCount(3, $messages);
        $this->assertSame(array('role' => 'user', 'content' => 'Hi, my email is [email]'), $messages[1]);
        $this->assertSame(array('role' => 'assistant', 'content' => 'Hello! How can I help?'), $messages[2]);
    }

    public function testParseResponse()
    {
        $r = erLhcoreClassAiAssistant::parseResponse(json_encode(array(
            'choices' => array(array('message' => array('role' => 'assistant', 'content' => "<think>reasoning</think>\n We open at 9. "))),
            'usage' => array('prompt_tokens' => 10, 'completion_tokens' => 5),
        )));
        $this->assertSame('We open at 9.', $r['text']);
        $this->assertSame(5, $r['usage']['completion_tokens']);

        $this->expectException(Exception::class);
        erLhcoreClassAiAssistant::parseResponse('{"error":"model not loaded"}');
    }

    public function testCompleteSendsOpenAiRequest()
    {
        $captured = null;
        erLhcoreClassAiAssistant::$httpClient = function ($url, $body, $headers, $timeout, $verifyTls) use (&$captured) {
            $captured = compact('url', 'body', 'headers', 'timeout');
            return array(200, json_encode(array('choices' => array(array('message' => array('content' => 'Sure!'))))));
        };
        $s = $this->settings(array('api_key' => 'k', 'timeout' => 7));
        $r = erLhcoreClassAiAssistant::complete(array(array('role' => 'user', 'content' => 'hi')), $s);

        $this->assertSame('Sure!', $r['text']);
        $this->assertSame('http://gpu.internal:8000/v1/chat/completions', $captured['url']);
        $this->assertContains('Authorization: Bearer k', $captured['headers']);
        $this->assertSame(7, $captured['timeout']);
        $body = json_decode($captured['body'], true);
        $this->assertSame('qwen2.5-7b-instruct', $body['model']);
        $this->assertFalse($body['stream']);

        erLhcoreClassAiAssistant::$httpClient = function () { return array(503, 'busy'); };
        $this->expectExceptionMessage('HTTP 503');
        erLhcoreClassAiAssistant::complete(array(), $s);
    }

    public function testWantsHuman()
    {
        $k = 'human, operator, live agent';
        $this->assertTrue(erLhcoreClassAiAssistant::wantsHuman('Can I talk to a HUMAN please?', $k));
        $this->assertTrue(erLhcoreClassAiAssistant::wantsHuman('live agent', $k));
        $this->assertFalse(erLhcoreClassAiAssistant::wantsHuman('This is inhumane', $k));
        $this->assertFalse(erLhcoreClassAiAssistant::wantsHuman('anything', ''));
    }

    public function testMayAutoReply()
    {
        $s = $this->settings(array('autoreply_max' => 2));
        $pending = erLhcoreClassModelChat::STATUS_PENDING_CHAT;

        $this->assertTrue(erLhcoreClassAiAssistant::mayAutoReply($this->chat($pending), $s, array()));
        $this->assertFalse(erLhcoreClassAiAssistant::mayAutoReply($this->chat($pending, 0, 9), $s, array()), 'department not enabled');
        $this->assertFalse(erLhcoreClassAiAssistant::mayAutoReply($this->chat($pending, 5), $s, array()), 'operator assigned');
        $this->assertFalse(erLhcoreClassAiAssistant::mayAutoReply($this->chat(erLhcoreClassModelChat::STATUS_ACTIVE_CHAT), $s, array()));
        $this->assertFalse(erLhcoreClassAiAssistant::mayAutoReply($this->chat($pending), $s, array('handoff' => true)));
        $this->assertFalse(erLhcoreClassAiAssistant::mayAutoReply($this->chat($pending), $s, array('replies' => 2)), 'reply limit');
        $this->assertFalse(erLhcoreClassAiAssistant::mayAutoReply($this->chat($pending), $this->settings(array('autoreply_enabled' => 0)), array()));
    }

    public function testMetricsAggregation()
    {
        $stats = erLhcoreClassMetrics::aiStats(array(
            json_encode(array('action' => 'suggest', 'ok' => true, 'ms' => 1000)),
            json_encode(array('action' => 'autoreply', 'ok' => true, 'ms' => 3000)),
            json_encode(array('action' => 'autoreply', 'ok' => false, 'error' => 'timeout')),
            json_encode(array('action' => 'settings', 'enabled' => 1)),
            'not json',
        ));
        $this->assertSame(1, $stats['requests']['suggest|ok']);
        $this->assertSame(1, $stats['requests']['autoreply|ok']);
        $this->assertSame(1, $stats['requests']['autoreply|error']);
        $this->assertSame(0, $stats['requests']['suggest|error']);
        $this->assertSame(2.0, $stats['latency_avg']);
    }
}
