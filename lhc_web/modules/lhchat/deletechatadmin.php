<?php

$currentUser = erLhcoreClassUser::instance();

if (!isset($_SERVER['HTTP_X_CSRFTOKEN']) || !$currentUser->validateCSFRToken($_SERVER['HTTP_X_CSRFTOKEN'])) {
	echo json_encode(array('error' => 'true', 'result' => 'Invalid CSRF Token' ));
	exit;
}

$db = ezcDbInstance::get();
$db->beginTransaction();

try {
    $chat = erLhcoreClassModelChat::fetchAndLock($Params['user_parameters']['chat_id']);

    if ($chat->can_edit_chat && ($currentUser->hasAccessTo('lhchat','deleteglobalchat') || ($currentUser->hasAccessTo('lhchat','deletechat') && $chat->user_id == $currentUser->getUserID())))
    {
        erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.delete',array('chat' => & $chat, 'user' => $currentUser));
        $chat->removeThis();
        erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_CHAT_DELETE, 'delete', array('chat_id' => (int)$chat->id, 'department_id' => (int)$chat->dep_id, 'nick' => $chat->nick), $chat->id);
        echo json_encode(array('error' => false, 'result' => 'ok' ));
    } else {
        echo json_encode(array('error' => true, 'result' => erTranslationClassLhTranslation::getInstance()->getTranslation('chat/deletechatadmin','You do not have rights to delete a chat') ));
    }

    $db->commit();

} catch (Exception $e) {
    erLhcoreClassLog::write($e->getTraceAsString());
    echo json_encode(array('error' => true, 'result' => $e->getMessage() ));
    $db->rollback();
}

exit;

?>