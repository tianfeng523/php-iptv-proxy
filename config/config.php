<?php
return array (
  'db' => 
  array (
    'host' => 'localhost',
    'port' => '3306',
    'dbname' => 'iptv_proxy',
    'username' => 'iptv_proxy',
    'password' => 'Pc2ccw30',
  ),
  'redis' => 
  array (
    'host' => '127.0.0.1',
    'port' => '6379',
    'password' => NULL,
  ),
  'stream' => 
  array (
    'chunk_size' => 1048576,
    'cache_time' => 300,
  ),
);
