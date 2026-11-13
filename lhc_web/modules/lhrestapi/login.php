<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
header('Content-Type: application/json');

$currentUser = erLhcoreClassUser::instance();

try {

    $throttleIp = erLhcoreClassIPDetect::getIP();
    $throttleUsername = isset($_POST['username']) ? $_POST['username'] : '';
    $throttleRetryAfter = erLhcoreClassLoginThrottle::getRetryAfter($throttleIp, $throttleUsername);

    if ($throttleRetryAfter > 0) {
        http_response_code(429);
        header('Retry-After: ' . $throttleRetryAfter);
        echo json_encode(
            array('error' => true, 'msg' => erLhcoreClassLoginThrottle::getLockedMessage($throttleRetryAfter), 'retry_after' => $throttleRetryAfter)
        );
        exit;
    }

    if ($currentUser->authenticate($throttleUsername, isset($_POST['password']) ? $_POST['password'] : ''))
    {
        erLhcoreClassLoginThrottle::recordSuccess($throttleIp, $throttleUsername);

        $sessionToken = null;
        $userId = null;

        if (isset($_POST['generate_token']) && $_POST['generate_token'] == 'true') {
            
            $typeValid = array(
                "unknown" => erLhcoreClassModelUserSession::DEVICE_TYPE_UNKNOWN,
                "android" => erLhcoreClassModelUserSession::DEVICE_TYPE_ANDROID,
                "ios" => erLhcoreClassModelUserSession::DEVICE_TYPE_IOS
            );
                       
            $deviceToken = '';
            $device = erLhcoreClassModelUserSession::DEVICE_TYPE_UNKNOWN;
            
            if ((isset($_POST['device']) && key_exists($_POST['device'], $typeValid)) && $_POST['device'] != '') {
                $device = $typeValid[$_POST['device']];
                if (!isset($_POST['device_token']) || $_POST['device_token'] == '') {
                    $deviceToken = 'user_id_' . $currentUser->getUserID();
                } else {
                    $deviceToken = $_POST['device_token'];
                }
            } else {
                throw new Exception('Device not provided!');
            }
            
            $uSession = erLhcoreClassModelUserSession::findOne(array('filter' => array('device_token' => $deviceToken, 'device_type' => $device)));
            
            if (!($uSession instanceof erLhcoreClassModelUserSession)) {            
                $uSession = new erLhcoreClassModelUserSession();
            }
            
            $uSession->token = erLhcoreClassModelForgotPassword::randomPassword(40);
            $uSession->device_token = $deviceToken;
            $uSession->user_id = $currentUser->getUserID();
            
            if ($uSession->created_on == 0) {
                $uSession->created_on = time();
            }
            
            $uSession->updated_on = time();
            $uSession->device_type = $device;
            $uSession->saveThis();

            $userId = $uSession->user_id;
            $sessionToken = $uSession->token;
        }
    
        echo json_encode(
            array('error' => false, 'session_token' => $sessionToken, 'user_id' => $userId)
        );
            
    } else {
        
        // Note: the form field is `username` (lower case); the previous `Username` lookup never matched, so failed API logins were never recorded.
        if (($userAttempt = erLhcoreClassModelUser::findOne(array('filter' => array('username' => $throttleUsername)))) instanceof erLhcoreClassModelUser) {
            erLhcoreClassModelUserLogin::logUserAction(array(
                'type' => erLhcoreClassModelUserLogin::TYPE_LOGIN_ATTEMPT,
                'user_id' => $userAttempt->id,
                'msg' => erTranslationClassLhTranslation::getInstance()->getTranslation('user/login','Failed login. WEB')
            ));

            erLhcoreClassModelUserLogin::disableIfRequired($userAttempt);
        }

        $throttleLockedFor = erLhcoreClassLoginThrottle::recordFailure($throttleIp, $throttleUsername);

        if ($throttleLockedFor > 0) {
            http_response_code(429);
            header('Retry-After: ' . $throttleLockedFor);
            echo json_encode(
                array('error' => true, 'msg' => erLhcoreClassLoginThrottle::getLockedMessage($throttleLockedFor), 'retry_after' => $throttleLockedFor)
            );
            exit;
        }

        if (http_response_code() === 200) { http_response_code(400); } // keep 401/403 set by the validator
        echo json_encode(
                array('error' => true, 'msg' => 'Authentification failed')
        );
    }

} catch (Exception $e) {
    if (http_response_code() === 200) { http_response_code(400); } // keep 401/403 set by the validator
    echo json_encode(
        array('error' => true, 'msg' => $e->getMessage())
    );
}

exit;
?>