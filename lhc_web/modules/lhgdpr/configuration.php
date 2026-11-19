<?php

$tpl = erLhcoreClassTemplate::getInstance('lhgdpr/configuration.tpl.php');

$settings = erLhcoreClassGdpr::getSettings();

if (isset($_POST['StoreRetentionSettings'])) {

    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('gdpr/configuration');
        exit;
    }

    $definition = array(
        'anonymize_after_days' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)),
        'delete_after_days' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)),
        'batch_size' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)),
        'anonymize_operator_messages' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean'),
    );

    $form = new ezcInputForm(INPUT_POST, $definition);
    $data = array(
        'anonymize_after_days' => $form->hasValidData('anonymize_after_days') ? $form->anonymize_after_days : 0,
        'delete_after_days' => $form->hasValidData('delete_after_days') ? $form->delete_after_days : 0,
        'batch_size' => $form->hasValidData('batch_size') ? $form->batch_size : erLhcoreClassGdpr::$defaults['batch_size'],
        'anonymize_operator_messages' => ($form->hasValidData('anonymize_operator_messages') && $form->anonymize_operator_messages == true) ? 1 : 0,
    );

    $errors = erLhcoreClassGdpr::validateSettings(erLhcoreClassGdpr::normalizeSettings($data));

    if (empty($errors)) {
        erLhcoreClassGdpr::saveSettings($data);
        $settings = erLhcoreClassGdpr::getSettings();
        erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_DATA_PROTECTION, 'settings', array('settings' => $settings));
        $tpl->set('updated', 'done');
    } else {
        $settings = erLhcoreClassGdpr::normalizeSettings($data);
        $tpl->set('errors', $errors);
    }
}

$tpl->set('settings', $settings);

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/htmlcode', 'System configuration')),
    array('title' => erTranslationClassLhTranslation::getInstance()->getTranslation('gdpr/configuration', 'Data retention'))
);
