<?php

$def = new ezcPersistentObjectDefinition();
$def->table = "lh_login_throttle";
$def->class = "erLhcoreClassModelLoginThrottle";

$def->idProperty = new ezcPersistentObjectIdProperty();
$def->idProperty->columnName = 'id';
$def->idProperty->propertyName = 'id';
$def->idProperty->generator = new ezcPersistentGeneratorDefinition(  'ezcPersistentNativeGenerator' );

$def->properties['type'] = new ezcPersistentObjectProperty();
$def->properties['type']->columnName   = 'type';
$def->properties['type']->propertyName = 'type';
$def->properties['type']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['identifier'] = new ezcPersistentObjectProperty();
$def->properties['identifier']->columnName   = 'identifier';
$def->properties['identifier']->propertyName = 'identifier';
$def->properties['identifier']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_STRING;

$def->properties['attempts'] = new ezcPersistentObjectProperty();
$def->properties['attempts']->columnName   = 'attempts';
$def->properties['attempts']->propertyName = 'attempts';
$def->properties['attempts']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['first_attempt'] = new ezcPersistentObjectProperty();
$def->properties['first_attempt']->columnName   = 'first_attempt';
$def->properties['first_attempt']->propertyName = 'first_attempt';
$def->properties['first_attempt']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['last_attempt'] = new ezcPersistentObjectProperty();
$def->properties['last_attempt']->columnName   = 'last_attempt';
$def->properties['last_attempt']->propertyName = 'last_attempt';
$def->properties['last_attempt']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['locked_until'] = new ezcPersistentObjectProperty();
$def->properties['locked_until']->columnName   = 'locked_until';
$def->properties['locked_until']->propertyName = 'locked_until';
$def->properties['locked_until']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

$def->properties['lock_count'] = new ezcPersistentObjectProperty();
$def->properties['lock_count']->columnName   = 'lock_count';
$def->properties['lock_count']->propertyName = 'lock_count';
$def->properties['lock_count']->propertyType = ezcPersistentObjectProperty::PHP_TYPE_INT;

return $def;

?>
