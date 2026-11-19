<?php

$Module = array( "name" => "Data protection (GDPR)");

$ViewList = array();

$ViewList['configuration'] = array(
    'params' => array(),
    'functions' => array( 'configure' )
);

$ViewList['subject'] = array(
    'params' => array(),
    'functions' => array( 'subject_requests' )
);

$FunctionList['configure'] = array('explain' => 'Allow user to configure data retention (anonymise / delete old chats)');
$FunctionList['subject_requests'] = array('explain' => 'Allow user to export or erase all chats of a person (data subject requests)');

?>
