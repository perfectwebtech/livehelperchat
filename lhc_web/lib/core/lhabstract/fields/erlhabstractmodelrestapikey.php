<?php

return array(
    'user_id' => array(
        'type' => 'combobox',
        'trans' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'Username'),
        'required' => true,
        'frontend' => 'username',
        'params_call' => array(),
        'source' => 'erLhcoreClassModelUser::getUserList',
        'validation_definition' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::REQUIRED, 'int')
    ),
    'api_key' => array(
        'type' => 'text',
        'trans' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'API Key, max 50 characters'),
        'maxlength' => 50,
        'required' => true,
        'validation_definition' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw')
    ),
    'ip_restrictions' => array(
        'type' => 'text',
        'trans' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'IP restrictions. E.g 1.2.3.*,1.2.3/24,1.2.3.4/255.255.255.0,1.2.3.0-1.2.3.255'),
        'trans_sub' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'Only from defined IP/IP range request will be permitted.'),
        'required' => true,
        'maxlength' => 250,
        'validation_definition' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw')
    ),
    'active' => array(
        'type' => 'checkbox',
        'trans' => 'Active',
        'required' => false,
        'validation_definition' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'boolean')
    ),
    'expires_at' => array(
        'type' => 'text',
        'trans' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'Expires on (YYYY-MM-DD, leave empty for a key that never expires)'),
        'trans_sub' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'The key stops working at the end of that day. Rotate keys regularly: create a new one, switch the integration, delete the old one.'),
        'maxlength' => 10,
        'required' => false,
        'validation_definition' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw')
    ),
    'scopes_list' => array(
        'type' => 'checkbox_multi',
        'trans' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'Allowed endpoints (none checked = every endpoint)'),
        'required' => false,
        'col_size' => 3,
        'hidden' => true,
        'source' => 'erLhAbstractModelRestAPIKey::getScopeOptions',
        'params_call' => array(),
        'name_attr' => 'name',
        'validation_definition' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw', null, FILTER_REQUIRE_ARRAY)
    ),
    'last_used_front' => array(
        'type' => 'text_display',
        'trans' => erTranslationClassLhTranslation::getInstance()->getTranslation('abstract/restapikey', 'Last used'),
        'required' => false
    )
);
