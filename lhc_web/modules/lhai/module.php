<?php

$Module = array( "name" => "AI assistant");

$ViewList = array();

$ViewList['configuration'] = array(
    'params' => array(),
    'functions' => array( 'configure' )
);

// Draft reply for an operator (POST, X-CSRFToken header), returns JSON
$ViewList['suggest'] = array(
    'params' => array('chat_id'),
    'functions' => array( 'use_suggest' )
);

$FunctionList['configure'] = array('explain' => 'Allow user to configure the AI assistant (model server, prompts, auto-reply)');
$FunctionList['use_suggest'] = array('explain' => 'Allow user to request AI reply suggestions in the chat window');

?>
