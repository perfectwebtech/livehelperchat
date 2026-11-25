<?php
/**
 * Minimal OpenAI compatible model server for CI (stands in for vLLM / Ollama).
 *   php -S 127.0.0.1:9500 .github/scripts/mock-llm.php
 * POST /v1/chat/completions -> canned answer; the last request body is stored in
 * $TMPDIR/lhc-mock-llm-last.json so tests can check what left the application.
 * A last visitor message containing "refund" makes the model answer [HANDOFF].
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/v1/chat/completions') {
    http_response_code(404);
    echo json_encode(array('error' => 'not found'));
    return true;
}

$body = file_get_contents('php://input');
file_put_contents(sys_get_temp_dir() . '/lhc-mock-llm-last.json', $body);
$request = json_decode($body, true);

$last = '';
foreach ((array)$request['messages'] as $message) {
    if ($message['role'] === 'user') {
        $last = $message['content'];
    }
}

$answer = stripos($last, 'refund') !== false ? '[HANDOFF]' : 'We are open from 9:00 to 17:00, Monday to Friday.';

echo json_encode(array(
    'id' => 'chatcmpl-ci',
    'object' => 'chat.completion',
    'model' => $request['model'],
    'choices' => array(array('index' => 0, 'message' => array('role' => 'assistant', 'content' => $answer), 'finish_reason' => 'stop')),
    'usage' => array('prompt_tokens' => 42, 'completion_tokens' => 12, 'total_tokens' => 54),
));
return true;
