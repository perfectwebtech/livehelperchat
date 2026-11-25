<?php

$tpl = erLhcoreClassTemplate::getInstance('lhai/configuration.tpl.php');

$settings = erLhcoreClassAiAssistant::getSettings();
$t = erTranslationClassLhTranslation::getInstance();

$booleans = array('enabled', 'mask_pii', 'verify_tls', 'suggest_enabled', 'autoreply_enabled');
$integers = array('max_tokens' => 16, 'timeout' => 2, 'history_messages' => 1, 'autoreply_max' => 1);
$texts = array('base_url', 'api_key', 'model', 'system_prompt', 'knowledge', 'temperature', 'handoff_keywords', 'handoff_message');

if (isset($_POST['StoreAiSettings']) || isset($_POST['TestAiConnection'])) {

    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('ai/configuration');
        exit;
    }

    $definition = array(
        'autoreply_departments' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1), FILTER_REQUIRE_ARRAY),
    );
    foreach ($booleans as $key) {
        $definition[$key] = new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean');
    }
    foreach ($integers as $key => $min) {
        $definition[$key] = new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => $min));
    }
    foreach ($texts as $key) {
        $definition[$key] = new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw');
    }

    $form = new ezcInputForm(INPUT_POST, $definition);
    $data = array();

    foreach ($booleans as $key) {
        $data[$key] = ($form->hasValidData($key) && $form->$key == true) ? 1 : 0;
    }
    foreach (array_merge(array_keys($integers), $texts) as $key) {
        $data[$key] = $form->hasValidData($key) ? $form->$key : erLhcoreClassAiAssistant::$defaults[$key];
    }
    $data['autoreply_departments'] = $form->hasValidData('autoreply_departments') ? implode(',', $form->autoreply_departments) : '';

    // Keep the stored key when the field is submitted empty.
    $stored = (array)erLhcoreClassModelChatConfig::fetch('ai_assistant')->data;
    if (trim((string)$data['api_key']) === '') {
        $data['api_key'] = isset($stored['api_key']) ? $stored['api_key'] : '';
    }

    $errors = array();
    if (trim($data['base_url']) !== '' && !erLhcoreClassAiAssistant::isAllowedUrl(trim($data['base_url']))) {
        $errors[] = $t->getTranslation('ai/configuration', 'Model server URL has to start with http:// or https://');
    }
    if ($data['enabled'] == 1 && (trim($data['base_url']) === '' && empty($settings['env_base_url']))) {
        $errors[] = $t->getTranslation('ai/configuration', 'Model server URL is required');
    }
    if ($data['enabled'] == 1 && (trim($data['model']) === '' && empty($settings['env_model']))) {
        $errors[] = $t->getTranslation('ai/configuration', 'Model name is required');
    }

    if (!empty($errors)) {
        $settings = erLhcoreClassAiAssistant::applyEnvironment(erLhcoreClassAiAssistant::normalizeSettings($data));
        $tpl->set('errors', $errors);
    } elseif (isset($_POST['TestAiConnection'])) {
        // Test the submitted values without saving them.
        $candidate = erLhcoreClassAiAssistant::applyEnvironment(erLhcoreClassAiAssistant::normalizeSettings($data));
        try {
            $result = erLhcoreClassAiAssistant::run('test', array(
                array('role' => 'system', 'content' => 'You are a connectivity check.'),
                array('role' => 'user', 'content' => 'Reply with the single word OK.'),
            ), $candidate);
            $tpl->set('test_result', array('ms' => $result['ms'], 'text' => mb_substr($result['text'], 0, 200)));
        } catch (Exception $e) {
            $tpl->set('errors', array($t->getTranslation('ai/configuration', 'Connection test failed:') . ' ' . $e->getMessage()));
        }
        $settings = $candidate;
    } else {
        erLhcoreClassAiAssistant::saveSettings($data);
        $settings = erLhcoreClassAiAssistant::getSettings();
        erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_AI, 'settings', array(
            'enabled' => $settings['enabled'],
            'base_url' => $settings['base_url'],
            'model' => $settings['model'],
            'mask_pii' => $settings['mask_pii'],
            'suggest_enabled' => $settings['suggest_enabled'],
            'autoreply_enabled' => $settings['autoreply_enabled'],
            'autoreply_departments' => $settings['autoreply_departments'],
        ));
        $tpl->set('updated', 'done');
    }
}

$tpl->set('settings', $settings);
$tpl->set('departments', erLhcoreClassModelDepartament::getList(array('limit' => false, 'sort' => 'name ASC')));

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => $t->getTranslation('system/htmlcode', 'System configuration')),
    array('title' => $t->getTranslation('ai/configuration', 'AI assistant'))
);
