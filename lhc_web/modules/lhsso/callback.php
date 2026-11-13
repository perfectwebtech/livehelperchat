<?php

$currentUser = erLhcoreClassUser::instance();

$fail = function ($message) {
    erLhcoreClassLog::write('SSO callback failed: ' . $message);
    $_SESSION['lhc_sso_error'] = $message;
    erLhcoreClassModule::redirect('user/login');
    exit;
};

if (!erLhcoreClassSsoOidc::isEnabled()) {
    $fail('Single sign-on is not enabled');
}

// Providers return the result as a query string (?code=...&state=...); the
// framework's own /(code)/... path style is accepted as well.
$param = function ($name) use ($Params) {
    if (isset($_GET[$name]) && is_string($_GET[$name])) {
        return $_GET[$name];
    }
    return isset($Params['user_parameters_unordered'][$name]) ? (string)$Params['user_parameters_unordered'][$name] : '';
};

if ($param('error') !== '') {
    $fail('Identity provider error: ' . $param('error') . ($param('error_description') !== '' ? ' - ' . $param('error_description') : ''));
}

$code = $param('code');
$state = $param('state');

if ($code === '' || $state === '') {
    $fail('Identity provider response is incomplete');
}

$redirectAfter = isset($_SESSION[erLhcoreClassSsoOidc::SESSION_KEY]['redirect']) ? (string)$_SESSION[erLhcoreClassSsoOidc::SESSION_KEY]['redirect'] : '';

try {
    $user = erLhcoreClassSsoOidc::completeLogin($code, $state);
} catch (Exception $e) {
    $fail($e->getMessage());
}

if (isset($_SESSION['logout_reason'])) {
    unset($_SESSION['logout_reason']);
}

if (!$currentUser->setLoggedUser($user->id)) {
    $fail('Could not start a session for this account');
}

erLhcoreClassModelUserLogin::logUserAction(array(
    'type' => erLhcoreClassModelUserLogin::TYPE_LOGGED,
    'user_id' => $user->id,
    'msg' => erTranslationClassLhTranslation::getInstance()->getTranslation('user/login', 'Logged in successfully. SSO')
));

$user->llogin = time();
$user->force_logout = 0;
$user->updateThis(array('update' => array('llogin', 'force_logout')));

erLhcoreClassChatEventDispatcher::getInstance()->dispatch('user.sso_login', array('current_user' => & $currentUser, 'user' => & $user));

if ($redirectAfter !== '' && preg_match('#^[A-Za-z0-9+/=_-]+$#', $redirectAfter)) {
    $target = base64_decode($redirectAfter, true);
    if (is_string($target) && $target !== '' && $target[0] === '/' && substr($target, 0, 2) !== '//') {
        erLhcoreClassModule::redirect($target);
        exit;
    }
}

erLhcoreClassModule::redirect();
exit;
