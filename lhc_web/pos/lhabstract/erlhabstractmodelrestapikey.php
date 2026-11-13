<?php

$def = new ezcPersistentObjectDefinition();
$def->table = "lh_abstract_rest_api_key";
$def->class = "erLhAbstractModelRestAPIKey";

$def->idProperty = new ezcPersistentObjectIdProperty();
$def->idProperty->columnName = 'id';
$def->idProperty->propertyName = 'id';
$def->idProperty->generator = new ezcPersistentGeneratorDefinition(  'ezcPersistentNativeGenerator' );

$def->properties['user_id'] = new ezcPersistentObjectProperty();
$def->properties['user_id']->columnName   = 'user_id';
$def->properties['user_id']->propertyName = 'user_id';
$def->properties['user_id']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['api_key'] = new ezcPersistentObjectProperty();
$def->properties['api_key']->columnName   = 'api_key';
$def->properties['api_key']->propertyName = 'api_key';
$def->properties['api_key']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_STRING;

$def->properties['active'] = new ezcPersistentObjectProperty();
$def->properties['active']->columnName   = 'active';
$def->properties['active']->propertyName = 'active';
$def->properties['active']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['ip_restrictions'] = new ezcPersistentObjectProperty();
$def->properties['ip_restrictions']->columnName   = 'ip_restrictions';
$def->properties['ip_restrictions']->propertyName = 'ip_restrictions';
$def->properties['ip_restrictions']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_STRING;

$def->properties['scopes'] = new ezcPersistentObjectProperty();
$def->properties['scopes']->columnName   = 'scopes';
$def->properties['scopes']->propertyName = 'scopes';
$def->properties['scopes']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_STRING;

$def->properties['expires_at'] = new ezcPersistentObjectProperty();
$def->properties['expires_at']->columnName   = 'expires_at';
$def->properties['expires_at']->propertyName = 'expires_at';
$def->properties['expires_at']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_STRING;

$def->properties['last_used'] = new ezcPersistentObjectProperty();
$def->properties['last_used']->columnName   = 'last_used';
$def->properties['last_used']->propertyName = 'last_used';
$def->properties['last_used']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

return $def;

?>