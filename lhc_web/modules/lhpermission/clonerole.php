<?php

$role = erLhcoreClassModelRole::fetch((int)$Params['user_parameters']['role_id']);

if (!$currentUser->validateCSFRToken($Params['user_parameters_unordered']['csfr'])) {
    die('Invalid CSFR Token');
    exit;
}

$roleFunctions = erLhcoreClassModelRoleFunction::getList(array('limit' => false, 'filter' => array('role_id' => $role->id)));

// Role copy
$role->id = null;
$role->name = erTranslationClassLhTranslation::getInstance()->getTranslation('permission/roles','Copy of') . ' ' . $role->name;
$role->saveThis();

erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_ROLE, 'clone', array('role_id' => (int)$role->id, 'name' => $role->name, 'cloned_from' => (int)$Params['user_parameters']['role_id']), $role->id);

// Role functions copy
foreach ($roleFunctions as $roleFunction) {
    $roleFunction->id = null;
    $roleFunction->role_id = $role->id;
    $roleFunction->saveThis();
}

erLhcoreClassModule::redirect('permission/roles');
exit;

?>