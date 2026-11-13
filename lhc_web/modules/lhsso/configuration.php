<?php

$tpl = erLhcoreClassTemplate::getInstance('lhsso/configuration.tpl.php');

$settings = erLhcoreClassSsoOidc::getSettings();

if (isset($_POST['StoreSsoSettings'])) {

    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('sso/configuration');
        exit;
    }

    $definition = array(
        'enabled' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean'),
        'auto_provision' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean'),
        'sync_groups' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean'),
        'force_sso' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean'),
        'default_group_id' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)),
    );

    foreach (array('issuer', 'client_id', 'client_secret', 'scopes', 'button_label', 'username_claim', 'email_claim', 'name_claim', 'surname_claim', 'groups_claim', 'group_map', 'allowed_domains') as $textKey) {
        $definition[$textKey] = new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw');
    }

    $form = new ezcInputForm(INPUT_POST, $definition);
    $errors = array();
    $data = array();

    foreach (array_keys($definition) as $key) {
        if (in_array($key, array('enabled', 'auto_provision', 'sync_groups', 'force_sso'))) {
            $data[$key] = ($form->hasValidData($key) && $form->$key == true) ? 1 : 0;
        } elseif ($form->hasValidData($key)) {
            $data[$key] = $form->$key;
        } else {
            $data[$key] = erLhcoreClassSsoOidc::$defaults[$key];
        }
    }

    // Keep the stored secret when the field is submitted empty.
    if (trim((string)$data['client_secret']) === '') {
        $data['client_secret'] = $settings['client_secret'];
    }

    if ($data['enabled'] == 1) {
        if (!preg_match('#^https://#i', $data['issuer']) && strpos($data['issuer'], 'http://127.0.0.1') !== 0 && strpos($data['issuer'], 'http://localhost') !== 0) {
            $errors[] = erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration', 'Issuer URL has to start with https://');
        }
        if (trim($data['client_id']) === '') {
            $errors[] = erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration', 'Client ID is required');
        }
        if (trim($data['client_secret']) === '') {
            $errors[] = erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration', 'Client secret is required');
        }
        if ($data['force_sso'] == 1 && $data['auto_provision'] != 1 && erLhcoreClassModelUser::getCount(array('filter' => array('disabled' => 0))) == 0) {
            $errors[] = erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration', 'Cannot force single sign-on without any operator account');
        }
    }

    if (empty($errors)) {
        erLhcoreClassSsoOidc::saveSettings($data);
        $settings = erLhcoreClassSsoOidc::getSettings();
        erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_SSO, 'settings', array('enabled' => $settings['enabled'], 'issuer' => $settings['issuer'], 'client_id' => $settings['client_id'], 'force_sso' => $settings['force_sso'], 'auto_provision' => $settings['auto_provision'], 'sync_groups' => $settings['sync_groups']));
        $tpl->set('updated', 'done');
    } else {
        $settings = erLhcoreClassSsoOidc::normalizeSettings($data);
        $tpl->set('errors', $errors);
    }
}

if (isset($_POST['TestSsoDiscovery'])) {
    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('sso/configuration');
        exit;
    }
    try {
        $doc = erLhcoreClassSsoOidc::discover(isset($_POST['issuer']) ? (string)$_POST['issuer'] : $settings['issuer']);
        $tpl->set('discovery', $doc);
    } catch (Exception $e) {
        $tpl->set('errors', array($e->getMessage()));
    }
}

$tpl->set('settings', $settings);
$tpl->set('redirect_uri', erLhcoreClassSsoOidc::redirectUri());
$tpl->set('groups', erLhcoreClassModelGroup::getList(array('limit' => false, 'sort' => 'name ASC')));

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/htmlcode', 'System configuration')),
    array('title' => erTranslationClassLhTranslation::getInstance()->getTranslation('sso/configuration', 'Single sign-on (OpenID Connect)'))
);
