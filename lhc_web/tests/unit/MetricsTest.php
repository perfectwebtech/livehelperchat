<?php

use PHPUnit\Framework\TestCase;

class MetricsTest extends TestCase
{
    public function testRenderFollowsThePrometheusTextFormat()
    {
        $out = erLhcoreClassMetrics::render(array(
            'lhc_chats' => array('help' => 'Chats per state', 'samples' => array(
                array('labels' => array('state' => 'pending'), 'value' => 3),
                array('labels' => array('state' => 'act"ive'), 'value' => 0),
            )),
            'lhc_db_query_seconds' => array('help' => "Latency\nline", 'samples' => array(array('labels' => array(), 'value' => 0.0012))),
            'lhc_up' => array('help' => 'Up', 'samples' => array(array('labels' => array(), 'value' => 1))),
        ));

        $this->assertSame(
            "# HELP lhc_chats Chats per state\n# TYPE lhc_chats gauge\nlhc_chats{state=\"pending\"} 3\nlhc_chats{state=\"act\\\"ive\"} 0\n"
            . "# HELP lhc_db_query_seconds Latency\\nline\n# TYPE lhc_db_query_seconds gauge\nlhc_db_query_seconds 0.0012\n"
            . "# HELP lhc_up Up\n# TYPE lhc_up gauge\nlhc_up 1\n",
            $out
        );
    }

    public function testFloatsAreRenderedWithoutExponentOrTrailingZeros()
    {
        $out = erLhcoreClassMetrics::render(array('m' => array('help' => 'h', 'samples' => array(
            array('labels' => array(), 'value' => 0.00001),
            array('labels' => array(), 'value' => 12.5),
            array('labels' => array(), 'value' => 3.0),
        ))));
        $this->assertStringContainsString("m 0.00001\n", $out);
        $this->assertStringContainsString("m 12.5\n", $out);
        $this->assertStringContainsString("m 3\n", $out);
    }

    public function testTokenFromBearerHeaderQueryOrRawHeaders()
    {
        $this->assertSame('abc', erLhcoreClassMetrics::presentedToken(array('HTTP_AUTHORIZATION' => 'Bearer abc'), array()));
        $this->assertSame('abc', erLhcoreClassMetrics::presentedToken(array('REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc '), array()));
        $this->assertSame('abc', erLhcoreClassMetrics::presentedToken(array(), array(), array('authorization' => 'Bearer abc')));
        $this->assertSame('q', erLhcoreClassMetrics::presentedToken(array(), array('token' => 'q')));
        $this->assertSame('', erLhcoreClassMetrics::presentedToken(array('HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'), array()));
        $this->assertSame('', erLhcoreClassMetrics::presentedToken(array(), array('token' => array('x'))));
    }
}
