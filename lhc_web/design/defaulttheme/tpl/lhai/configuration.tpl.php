<?php $t = erTranslationClassLhTranslation::getInstance(); ?>
<h1><?php echo $t->getTranslation('ai/configuration','AI assistant')?></h1>

<?php if (isset($errors)) : ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/validation_error.tpl.php'));?>
<?php endif; ?>

<?php if (isset($updated) && $updated == 'done') : $msg = $t->getTranslation('ai/configuration','Settings updated'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<?php if (isset($test_result)) : $msg = $t->getTranslation('ai/configuration','Connection works.') . ' ' . (int)$test_result['ms'] . ' ms, ' . $t->getTranslation('ai/configuration','model replied:') . ' ' . htmlspecialchars($test_result['text']); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<p class="text-muted small"><?php echo $t->getTranslation('ai/configuration','Connects Live Helper Chat to any OpenAI compatible model server, for example a self-hosted vLLM or Ollama on your own GPU server. Operators can ask for a draft reply in the chat window, and the assistant can answer visitors while they wait for an operator. Every request is written to the audit log (without message text).');?></p>

<form ng-non-bindable action="" method="post" autocomplete="off">

    <div class="form-group">
        <label><input type="checkbox" class="me-1" value="on" <?php $settings['enabled'] == 1 ? print 'checked="checked"' : '' ?> name="enabled" /><?php echo $t->getTranslation('ai/configuration','Enable the AI assistant');?></label>
    </div>

    <div class="row">
        <div class="col-md-6">
            <h5><?php echo $t->getTranslation('ai/configuration','Model server');?></h5>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','Base URL of the OpenAI compatible API');?></label>
                <input type="text" class="form-control form-control-sm" name="base_url" placeholder="http://gpu-server:8000/v1" value="<?php echo htmlspecialchars($settings['base_url'])?>" <?php if (!empty($settings['env_base_url'])) : ?>readonly<?php endif; ?> />
                <small class="text-muted"><?php echo $t->getTranslation('ai/configuration','vLLM: http://host:8000/v1 - Ollama: http://host:11434/v1');?><?php if (!empty($settings['env_base_url'])) : ?> - <?php echo $t->getTranslation('ai/configuration','set by LHC_AI_BASE_URL');?><?php endif; ?></small>
            </div>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','Model');?></label>
                <input type="text" class="form-control form-control-sm" name="model" placeholder="Qwen/Qwen2.5-7B-Instruct" value="<?php echo htmlspecialchars($settings['model'])?>" <?php if (!empty($settings['env_model'])) : ?>readonly<?php endif; ?> />
            </div>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','API key (leave empty to keep the stored one; not needed for most self-hosted servers)');?></label>
                <input type="password" class="form-control form-control-sm" name="api_key" value="" autocomplete="new-password" <?php if (!empty($settings['env_api_key'])) : ?>disabled<?php endif; ?> />
                <?php if (!empty($settings['env_api_key'])) : ?><small class="text-muted"><?php echo $t->getTranslation('ai/configuration','Set by LHC_AI_API_KEY.');?></small><?php elseif ($settings['api_key'] != '') : ?><small class="text-muted"><?php echo $t->getTranslation('ai/configuration','A key is stored.');?></small><?php endif; ?>
            </div>
            <div class="row">
                <div class="col-4 form-group">
                    <label><?php echo $t->getTranslation('ai/configuration','Temperature');?></label>
                    <input type="text" class="form-control form-control-sm" name="temperature" value="<?php echo htmlspecialchars($settings['temperature'])?>" />
                </div>
                <div class="col-4 form-group">
                    <label><?php echo $t->getTranslation('ai/configuration','Max answer tokens');?></label>
                    <input type="number" class="form-control form-control-sm" name="max_tokens" value="<?php echo (int)$settings['max_tokens']?>" />
                </div>
                <div class="col-4 form-group">
                    <label><?php echo $t->getTranslation('ai/configuration','Timeout (seconds)');?></label>
                    <input type="number" class="form-control form-control-sm" name="timeout" value="<?php echo (int)$settings['timeout']?>" />
                </div>
            </div>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','Chat messages sent as context');?></label>
                <input type="number" class="form-control form-control-sm" name="history_messages" value="<?php echo (int)$settings['history_messages']?>" />
            </div>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php $settings['mask_pii'] == 1 ? print 'checked="checked"' : '' ?> name="mask_pii" /><?php echo $t->getTranslation('ai/configuration','Replace e-mails, phone numbers, card numbers and IBANs with placeholders before sending to the model');?></label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php $settings['verify_tls'] == 1 ? print 'checked="checked"' : '' ?> name="verify_tls" /><?php echo $t->getTranslation('ai/configuration','Verify the TLS certificate of the model server');?></label>
            </div>
        </div>
        <div class="col-md-6">
            <h5><?php echo $t->getTranslation('ai/configuration','Behaviour');?></h5>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','System prompt');?></label>
                <textarea class="form-control form-control-sm" rows="5" name="system_prompt"><?php echo htmlspecialchars($settings['system_prompt'])?></textarea>
                <small class="text-muted"><?php echo $t->getTranslation('ai/configuration','Keep the instruction to answer [HANDOFF] when unsure: it hands the chat to your team.');?></small>
            </div>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','Knowledge (facts the assistant may use: opening hours, prices, policies)');?></label>
                <textarea class="form-control form-control-sm" rows="5" name="knowledge"><?php echo htmlspecialchars($settings['knowledge'])?></textarea>
            </div>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php $settings['suggest_enabled'] == 1 ? print 'checked="checked"' : '' ?> name="suggest_enabled" /><?php echo $t->getTranslation('ai/configuration','Reply suggestions for operators (needs the ai/use_suggest permission)');?></label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" class="me-1" value="on" <?php $settings['autoreply_enabled'] == 1 ? print 'checked="checked"' : '' ?> name="autoreply_enabled" /><?php echo $t->getTranslation('ai/configuration','Answer visitors automatically while they wait for an operator');?></label>
            </div>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','Departments with automatic answers');?></label>
                <?php $selectedDepartments = erLhcoreClassAiAssistant::departmentIds($settings['autoreply_departments']); ?>
                <select multiple class="form-control form-control-sm" name="autoreply_departments[]" size="5">
                    <?php foreach ($departments as $department) : ?>
                        <option value="<?php echo (int)$department->id?>" <?php in_array((int)$department->id, $selectedDepartments, true) ? print 'selected="selected"' : '' ?>><?php echo htmlspecialchars($department->name)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <div class="col-6 form-group">
                    <label><?php echo $t->getTranslation('ai/configuration','Answers per chat before handing off');?></label>
                    <input type="number" class="form-control form-control-sm" name="autoreply_max" value="<?php echo (int)$settings['autoreply_max']?>" />
                </div>
                <div class="col-6 form-group">
                    <label><?php echo $t->getTranslation('ai/configuration','Words that hand the chat to a person');?></label>
                    <input type="text" class="form-control form-control-sm" name="handoff_keywords" value="<?php echo htmlspecialchars($settings['handoff_keywords'])?>" />
                </div>
            </div>
            <div class="form-group">
                <label><?php echo $t->getTranslation('ai/configuration','Message shown when handing off to the team');?></label>
                <input type="text" class="form-control form-control-sm" name="handoff_message" value="<?php echo htmlspecialchars($settings['handoff_message'])?>" />
            </div>
        </div>
    </div>

    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>

    <div class="btn-group" role="group">
        <input type="submit" class="btn btn-sm btn-secondary" name="StoreAiSettings" value="<?php echo $t->getTranslation('system/buttons','Save');?>" />
        <input type="submit" class="btn btn-sm btn-outline-secondary" name="TestAiConnection" value="<?php echo $t->getTranslation('ai/configuration','Test connection');?>" />
    </div>
</form>
