<?php

try
{
    erLhcoreClassRestAPIHandler::validateRequest();

    $requestBody = json_decode(file_get_contents('php://input'),true);

    $data = erLhcoreClassModelChatConfig::fetch('autologin_data')->data;

    if ($data['enabled'] != 1) {
        throw new Exception('Auto login module is disabled!');
    }

    if ($data['secret_hash'] == '') {
        throw new Exception('Secret hash value is empty. Please set it in back office!');
    }

    if (!erLhcoreClassRestAPIHandler::hasAccessTo('lhuser', 'userautologin')) {
        throw new Exception('You do not have permission to generate auto login link. `lhuser`, `userautologin` is required.');
    }

    function generateAutoLoginLink($params){

        $dataRequest = array();
        $dataRequestAppend = array();

        // Destination ID
        if (isset($params['r'])){
            $dataRequest['r'] = $params['r'];
            $dataRequestAppend[] = '/(r)/'.rawurlencode(base64_encode($params['r']));
        }

        // User ID
        if (isset($params['u']) && is_numeric($params['u'])){
            $dataRequest['u'] = $params['u'];
            $dataRequestAppend[] = '/(u)/'.rawurlencode($params['u']);
        }

        // Username
        if (isset($params['l'])){
            $dataRequest['l'] = $params['l'];
            $dataRequestAppend[] = '/(l)/'.rawurlencode($params['l']);
        }

        if (!isset($params['l']) && !isset($params['u'])) {
            throw new Exception('Username or User ID has to be provided');
        }

        // One-time nonce
        $nonce = bin2hex(random_bytes(16));
        $dataRequest['n'] = $nonce;
        $dataRequestAppend[] = '/(n)/'.rawurlencode($nonce);

        // Expire time for link - mandatory, default 300 s, capped at 3600 s
        $ttl = isset($params['t']) && is_numeric($params['t']) ? (int)$params['t'] : 300;
        $ttl = max(1, min($ttl, 3600));
        $ts = time() + $ttl;
        $dataRequest['t'] = $ts;
        $dataRequestAppend[] = '/(t)/'.rawurlencode($ts);

        $hashValidation = hash_hmac('sha256', implode(',', $dataRequest), $params['secret_hash']);

        return erLhcoreClassSystem::getHost() . erLhcoreClassDesign::baseurldirect("user/autologin") . "/{$hashValidation}".implode('', $dataRequestAppend);
    }

    $requestBody['secret_hash'] = $data['secret_hash'];

    erLhcoreClassRestAPIHandler::outputResponse(array(
        'error' => false,
        'result' => generateAutoLoginLink($requestBody)
    ));

} catch (Exception $e) {
    if (http_response_code() === 200) { http_response_code(400); } // keep 401/403 set by the validator
    echo erLhcoreClassRestAPIHandler::outputResponse(array(
        'error' => true,
        'result' => $e->getMessage()
    ));
}

exit();

