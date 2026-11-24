<?php 

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
     
     // Expire time for link
     if (isset($params['t'])){
         $dataRequest['t'] = $params['t'];
         $dataRequestAppend[] = '/(t)/'.rawurlencode($params['t']);
     }

     // Expire time is mandatory
     if (!isset($params['t'])) {
         throw new Exception('Expire time (t) has to be provided');
     }

     $hashValidation = hash_hmac('sha256', implode(',', $dataRequest), $params['secret_hash']);
     
     return "index.php/user/autologin/{$hashValidation}".implode('', $dataRequestAppend);
}

?>

<a target="_blank" href="http://dev.livehelperchat.com/<?php echo generateAutoLoginLink(array('r' => 'chat/chattabs', 'u' => 1,/* 'l' => 'admin', */ 't' => time() + 300, 'secret_hash' => '12456456456456fghfghfghfgh'))?>">Login me</a>