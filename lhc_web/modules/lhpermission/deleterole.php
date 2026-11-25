<?php

if (!$currentUser->validateCSFRToken($Params['user_parameters_unordered']['csfr'])) {
	die('Invalid CSFR Token');
	exit;
}

$roleId = (int)$Params['user_parameters']['role_id'];

$role = erLhcoreClassRole::getSession()->load( 'erLhcoreClassModelRole', $roleId);

if ($role->id != 1 && erLhcoreClassRole::canDeleteRole($role->id) === true) {
	erLhcoreClassRole::getSession()->delete($role);

	erLhcoreClassAuditTrail::record(erLhcoreClassAuditTrail::CATEGORY_ROLE, 'delete', array('role_id' => (int)$roleId, 'name' => $role->name), $roleId);
	
	// Delete user assigned departaments
	$q = ezcDbInstance::get()->createDeleteQuery();
	$q->deleteFrom( 'lh_rolefunction' )->where( $q->expr->eq( 'role_id', $roleId ) );
	$stmt = $q->prepare();
	$stmt->execute();
	
	$q = ezcDbInstance::get()->createDeleteQuery();
	$q->deleteFrom( 'lh_grouprole' )->where( $q->expr->eq( 'role_id', $roleId ) );
	$stmt = $q->prepare();
	$stmt->execute();
}

erLhcoreClassAdminChatValidatorHelper::clearUsersCache();

erLhcoreClassModule::redirect('permission/roles');
exit;

?>