<?php

$tpl = erLhcoreClassTemplate::getInstance( 'lhuser/passwordrequirements.tpl.php');

$pswData = erLhcoreClassModelChatConfig::fetch('password_data');
$data = (array)$pswData->data;

if ( isset($_POST['StorePasswordSettings']) ) {
    $definition = array(
        'length' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'uppercase_required' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        ),
        'lowercase_required' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        ),
        'number_required' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        ),
        'special_required' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        ),
        'expires_in' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'max_attempts' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'disable_after' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'logout_after' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'generate_manually' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'boolean'
        ),
        'throttle_enabled' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'boolean'
        ),
        'throttle_ip_attempts' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'throttle_user_attempts' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 0)
        ),
        'throttle_window' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        ),
        'throttle_lock' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        ),
        'throttle_lock_max' => new ezcInputFormDefinitionElement(
            ezcInputFormDefinitionElement::OPTIONAL, 'int', array('min_range' => 1)
        )
    );

    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('user/autologinconfig');
        exit;
    }

    $Errors = array();

    $form = new ezcInputForm( INPUT_POST, $definition );
    $Errors = array();

    if ( $form->hasValidData( 'length' ) ) {
        $data['length'] = $form->length;
    } else {
        $data['length'] = 0;
    }

    if ( $form->hasValidData( 'disable_after' ) ) {
        $data['disable_after'] = $form->disable_after;
    } else {
        $data['disable_after'] = 0;
    }

    if ( $form->hasValidData( 'generate_manually' ) && $form->generate_manually == true ) {
        $data['generate_manually'] = 1;
    } else {
        $data['generate_manually'] = 0;
    }
    
    if ( $form->hasValidData( 'logout_after' ) ) {
        $data['logout_after'] = $form->logout_after;
    } else {
        $data['logout_after'] = 0;
    }

    if ( $form->hasValidData( 'expires_in' ) ) {
        $data['expires_in'] = $form->expires_in;
    } else {
        $data['expires_in'] = 0;
    }

    if ( $form->hasValidData( 'uppercase_required' ) ) {
        $data['uppercase_required'] = $form->uppercase_required;
    } else {
        $data['uppercase_required'] = 0;
    }

    if ( $form->hasValidData( 'lowercase_required' ) ) {
        $data['lowercase_required'] = $form->lowercase_required;
    } else {
        $data['lowercase_required'] = 0;
    }

    if ($form->hasValidData( 'max_attempts' ) ) {
        $data['max_attempts'] = $form->max_attempts;
    } else {
        $data['max_attempts'] = 0;
    }

    if ( $form->hasValidData( 'number_required' )) {
        $data['number_required'] = $form->number_required;
    } else {
        $data['number_required'] = 0;
    }

    if ( $form->hasValidData( 'special_required' )) {
        $data['special_required'] = $form->special_required;
    } else {
        $data['special_required'] = 0;
    }

    $data['throttle_enabled'] = ($form->hasValidData('throttle_enabled') && $form->throttle_enabled == true) ? 1 : 0;

    foreach (array('throttle_ip_attempts', 'throttle_user_attempts', 'throttle_window', 'throttle_lock', 'throttle_lock_max') as $throttleKey) {
        if ($form->hasValidData($throttleKey)) {
            $data[$throttleKey] = $form->$throttleKey;
        } else {
            $data[$throttleKey] = erLhcoreClassLoginThrottle::$defaults[$throttleKey];
        }
    }

    $pswData->value = serialize($data);
    $pswData->saveThis();

    erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_PASSWORD_POLICY, 'update', array('settings' => $data));

    $CacheManager = erConfigClassLhCacheConfig::getInstance();
    $CacheManager->expireCache();

    $tpl->set('updated','done');
}

if (isset($_POST['UnlockThrottle'])) {
    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('user/passwordrequirements');
        exit;
    }

    $unlockRecord = erLhcoreClassModelLoginThrottle::fetch((int)$_POST['UnlockThrottle'], false);
    erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_LOGIN_THROTTLE, 'unlock', array(
        'throttle_id' => (int)$_POST['UnlockThrottle'],
        'type' => $unlockRecord instanceof erLhcoreClassModelLoginThrottle ? ($unlockRecord->type == erLhcoreClassModelLoginThrottle::TYPE_IP ? 'ip' : 'username') : 'unknown',
        'identifier' => $unlockRecord instanceof erLhcoreClassModelLoginThrottle ? $unlockRecord->identifier : '',
    ));
    erLhcoreClassLoginThrottle::unlock((int)$_POST['UnlockThrottle']);
    $tpl->set('updated', 'unlocked');
}

$tpl->set('password_data', $data);
$tpl->set('throttle_settings', erLhcoreClassLoginThrottle::normalizeSettings($data));
$tpl->set('throttle_locks', erLhcoreClassModelLoginThrottle::getList(array('filtergt' => array('locked_until' => time()), 'sort' => 'locked_until DESC', 'limit' => 200)));

$Result['content'] = $tpl->fetch();
$Result['path'] = array(array('url' => erLhcoreClassDesign::baseurl('system/configuration'),'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/htmlcode','System configuration')), array('title' => erTranslationClassLhTranslation::getInstance()->getTranslation('users/autologin','Password requirements')));

erLhcoreClassChatEventDispatcher::getInstance()->dispatch('user.autologinconfig_path', array('result' => & $Result));

?>