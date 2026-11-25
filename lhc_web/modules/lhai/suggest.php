<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = function ($code, array $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_CSRFTOKEN']) || !$currentUser->validateCSFRToken($_SERVER['HTTP_X_CSRFTOKEN'])) {
    $respond(403, array('error' => true, 'message' => 'Invalid request'));
}

$chat = is_numeric($Params['user_parameters']['chat_id']) ? erLhcoreClassModelChat::fetch((int)$Params['user_parameters']['chat_id']) : false;

if (!($chat instanceof erLhcoreClassModelChat) || !erLhcoreClassChat::hasAccessToRead($chat) || !erLhcoreClassChat::hasAccessToWrite($chat)) {
    $respond(404, array('error' => true, 'message' => erTranslationClassLhTranslation::getInstance()->getTranslation('ai/suggest', 'Chat not found')));
}

try {
    $suggestion = erLhcoreClassAiAssistant::suggestReply($chat);
    $respond(200, array('error' => false, 'suggestion' => $suggestion));
} catch (Exception $e) {
    $respond(502, array('error' => true, 'message' => erTranslationClassLhTranslation::getInstance()->getTranslation('ai/suggest', 'The AI assistant could not answer right now.')));
}
