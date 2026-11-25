<?php if (erLhcoreClassUser::instance()->hasAccessTo('lhai','use_suggest') && $chat->status != erLhcoreClassModelChat::STATUS_CLOSED_CHAT && ($aiSettings = erLhcoreClassAiAssistant::getSettings()) && $aiSettings['suggest_enabled'] == 1 && erLhcoreClassAiAssistant::isConfigured($aiSettings)) : ?>
<button type="button" class="btn btn-sm btn-outline-secondary" id="chat-ai-suggest-<?php echo $chat->id?>" title="<?php echo erTranslationClassLhTranslation::getInstance()->getTranslation('ai/suggest','Suggest a reply with AI (review before sending)')?>" onclick="(function(btn){
    var chatId = <?php echo (int)$chat->id?>, box = document.getElementById('CSChatMessage-' + chatId), icon = btn.querySelector('i');
    btn.disabled = true; icon.textContent = 'hourglass_empty';
    $.ajax({type: 'POST', url: WWW_DIR_JAVASCRIPT + 'ai/suggest/' + chatId, headers: {'X-CSRFToken': confLH.csrf_token}, dataType: 'json'})
        .done(function(data){ if (data && data.suggestion) { box.value = data.suggestion; box.focus(); box.dispatchEvent(new Event('input', {bubbles: true})); } else { alert(<?php echo json_encode(erTranslationClassLhTranslation::getInstance()->getTranslation('ai/suggest','The assistant has no suggestion for this chat.'))?>); } })
        .fail(function(xhr){ alert((xhr.responseJSON && xhr.responseJSON.message) || <?php echo json_encode(erTranslationClassLhTranslation::getInstance()->getTranslation('ai/suggest','The AI assistant could not answer right now.'))?>); })
        .always(function(){ btn.disabled = false; icon.textContent = 'auto_awesome'; });
})(this)"><i class="material-icons me-0">auto_awesome</i></button>
<?php endif; ?>
