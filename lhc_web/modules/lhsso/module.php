<?php

$Module = array( "name" => "Single sign-on (OpenID Connect)");

$ViewList = array();

// Starts the OpenID Connect login (public)
$ViewList['login'] = array(
    'params' => array(),
    'uparams' => array('r')
);

// Provider redirect target (public)
$ViewList['callback'] = array(
    'params' => array(),
    'uparams' => array('code', 'state', 'error', 'error_description')
);

$ViewList['configuration'] = array(
    'params' => array(),
    'functions' => array( 'configure' )
);

$FunctionList['configure'] = array('explain' => 'Allow user to configure single sign-on (OpenID Connect)');

?>
