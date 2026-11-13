<?php

try
{
    erLhcoreClassRestAPIHandler::validateRequest();

    if (!erLhcoreClassRestAPIHandler::hasAccessTo('lhgenericbot', 'use')) {
        throw new Exception('You do not have permission. `lhgenericbot`, `use` is required.');
    }

    $filterParams = erLhcoreClassSearchHandler::getParams(array('module' => 'chat', 'module_file' => 'bot_search', 'format_filter' => true, 'use_override' => true));

    $items = erLhcoreClassModelGenericBotBot::getList(array_merge($filterParams['filter'],array('limit' => false)));

    erLhcoreClassRestAPIHandler::outputResponse(array
        (
            'error' => false,
            'result' => array_values($items)
        )
    );

} catch (Exception $e) {
    if (http_response_code() === 200) { http_response_code(400); } // keep 401/403 set by the validator
    echo erLhcoreClassRestAPIHandler::outputResponse(array(
        'error' => true,
        'result' => $e->getMessage()
    ));
}

exit();

