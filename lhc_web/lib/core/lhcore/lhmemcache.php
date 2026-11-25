<?php

/**
 * Thin wrapper around the memcache extension. It deliberately does not extend
 * Memcache: the extension's method signatures changed in PHP 8 and a subclass
 * with the old signatures fails to compile wherever the extension is loaded.
 */
class erLhcoreClassLhMemcache
{
    private $memcache;
    
    public function __construct()
    {     
         $this->memcache = new Memcache();          
         $hosts = erConfigClassLhConfig::getInstance()->getSetting( 'memecache', 'servers' );
         foreach ($hosts as $server) {
                $this->memcache->addServer($server['host'],$server['port'],$server['weight']);
         }
    }  
    
    public function set($key, $value, $compress, $ttl = 0)
    {      
         $this->memcache->set($key,$value,$compress,$ttl);       
    }
    
    public function __destruct()
    {
        $this->memcache->close();
    }   
    
    public function get($var)
    {
        return $this->memcache->get($var);
    }
    
    public function increment($var,$version)
    {
        return $this->memcache->increment($var);
    }
    
}


?>