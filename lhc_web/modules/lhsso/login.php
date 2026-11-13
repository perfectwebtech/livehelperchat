<?php

erLhcoreClassUser::instance(); // starts the PHP session that carries state, nonce and PKCE verifier

if (!erLhcoreClassSsoOidc::isEnabled()) {
    erLhcoreClassModule::redirect('user/login');
    exit;
}

$redirectAfter = isset($Params['user_parameters_unordered']['r']) ? (string)$Params['user_parameters_unordered']['r'] : '';

try {
    $url = erLhcoreClassSsoOidc::startLogin($redirectAfter);
} catch (Exception $e) {
    erLhcoreClassLog::write('SSO start failed: ' . $e->getMessage());
    $_SESSION['lhc_sso_error'] = $e->getMessage();
    erLhcoreClassModule::redirect('user/login');
    exit;
}

header('Location: ' . $url, true, 302);
exit;
