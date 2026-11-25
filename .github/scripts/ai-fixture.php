<?php
/**
 * Test fixture for the AI assistant smoke test: a pending chat in department 1
 * whose first visitor message contains an e-mail address.
 *   php .github/scripts/ai-fixture.php <lhc_web dir>   prints {"id":..,"hash":".."}
 */
chdir($argv[1]);
$_SERVER['REQUEST_URI'] = '/';
require_once 'ezcomponents/Base/src/base.php';
ezcBase::addClassRepository('./', './lib/autoloads');
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
spl_autoload_register(array('erLhcoreClassSystem', 'autoload'), true, false);
erLhcoreClassSystem::init();
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');

$chat = new erLhcoreClassModelChat();
$chat->nick = 'AI Visitor';
$chat->time = time();
$chat->status = erLhcoreClassModelChat::STATUS_PENDING_CHAT;
$chat->dep_id = 1;
$chat->hash = erLhcoreClassChat::generateHash();
$chat->saveThis();

$msg = new erLhcoreClassModelmsg();
$msg->chat_id = $chat->id;
$msg->user_id = 0;
$msg->msg = 'Hello, I am ai.visitor@example.com and I have a question.';
$msg->time = time();
$msg->saveThis();

echo json_encode(array('id' => (int)$chat->id, 'hash' => $chat->hash));
