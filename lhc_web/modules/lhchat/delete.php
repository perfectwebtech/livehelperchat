<?php

$chat = erLhcoreClassChat::getSession()->load('erLhcoreClassModelChat', $Params['user_parameters']['chat_id']);

$currentUser = erLhcoreClassUser::instance();

if (!isset($_SERVER['HTTP_X_CSRFTOKEN']) || !$currentUser->validateCSFRToken($_SERVER['HTTP_X_CSRFTOKEN'])) {
    die('Invalid CSRF Token');
    exit;
}

if (($chat->can_edit_chat && ($currentUser->hasAccessTo('lhchat', 'deleteglobalchat') || ($currentUser->hasAccessTo('lhchat', 'deletechat') && $chat->user_id == $currentUser->getUserID())))) {
    erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.delete', array('chat' => & $chat, 'user' => $currentUser));
    $chat->removeThis();
    erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_CHAT_DELETE, 'delete', array('chat_id' => (int)$chat->id, 'department_id' => (int)$chat->dep_id, 'nick' => $chat->nick), $chat->id);
}

echo "ok";
exit;

?>