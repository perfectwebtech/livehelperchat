<?php

erLhcoreClassRestAPIHandler::setHeaders();

$modeAPI = isset($_GET['api']) && $_GET['api'] == 'true';

try {
    
    if (!isset($_GET['token']) || !is_string($_GET['token']) || trim($_GET['token']) === '') {
        throw new Exception('Token not found!');
    }

    $token = $_GET['token'];

    $uSession = erLhcoreClassModelUserSession::findOne(array(
        'filter' => array('token' => $token),
        // Never authenticate against a revoked/blank token left behind by logout
        'filternot' => array('token' => '')
    ));
        
    if ($uSession instanceof erLhcoreClassModelUserSession)
    {
        // Reject and revoke expired tokens so a leaked token can't be replayed forever
        if ($uSession->isExpired()) {
            $uSession->token = '';
            $uSession->updateThis();
            throw new Exception('Token expired');
        }

        $currentUser = erLhcoreClassUser::instance();
        $instance = erLhcoreClassSystem::instance();
               
        $userToLogin = erLhcoreClassModelUser::fetch((int)$uSession->user_id);           
        
        $r = '';
        if (isset($_GET['r'])) {
            $r = rawurldecode($_GET['r']);
        }

        if ($userToLogin instanceof erLhcoreClassModelUser) {
            // Do not report a started session when the operator cannot actually be logged in
            // (e.g. a disabled account) - setLoggedUser() returns null when already logged in
            // as that user, so compare strictly against false.
            if ($currentUser->setLoggedUser($userToLogin->id) === false) {
                throw new Exception('Session could not be started');
            }
            if ($modeAPI == false) {
                header('Location: ' .erLhcoreClassDesign::baseurldirect('site_admin').'/'.ltrim($r, '/'));
                exit;
            } else {
                echo json_encode(array('error' => false, 'msg' => 'Session started', 'url' => erLhcoreClassSystem::getHost() . erLhcoreClassDesign::baseurldirect('site_admin').'/'.ltrim($r,'/')));
                exit;
            }
        } else {
            throw new Exception('User could not be found!');
        }

    } else {
        throw new Exception('Token not found');        
    }    

} catch (Exception $e) {
    
    if ($modeAPI == false) {
        if (http_response_code() === 200) { http_response_code(400); } // keep 401/403 set by the validator
        die($e->getMessage());
    } else {
        if (http_response_code() === 200) { http_response_code(400); } // keep 401/403 set by the validator
        echo json_encode(
            array('error' => true, 'msg' => $e->getMessage())
        );
    }
}

exit;
?>