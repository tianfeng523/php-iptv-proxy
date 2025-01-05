<?php
namespace App\Core;

class Redis
{
    private $redis;
    
    public function __construct()
    {
        $this->redis = new \Redis();
        $config = Config::getInstance();
        
        try {
            $this->redis->connect(
                $config->get('redis.host', '127.0.0.1'),
                $config->get('redis.port', 6379)
            );
            
            if ($password = $config->get('redis.password')) {
                $this->redis->auth($password);
            }
        } catch (\Exception $e) {
            throw new \Exception('Redis连接失败: ' . $e->getMessage());
        }
    }
    // 添加 get 方法
    public function get($key)
    {
        return $this->redis->get($key);
    }
    
    // 添加 set 方法
    public function set($key, $value)
    {
        return $this->redis->set($key, $value);
    }
    
    // 添加 keys 方法（如果需要）
    public function keys($pattern)
    {
        return $this->redis->keys($pattern);
    }

    
    public function hGetAll($key)
    {
        return $this->redis->hGetAll($key);
    }
    
    public function hSet($key, $field, $value)
    {
        return $this->redis->hSet($key, $field, $value);
    }
    
    public function hMSet($key, $data)
    {
        return $this->redis->hMSet($key, $data);
    }
    
    public function exists($key)
    {
        return $this->redis->exists($key);
    }
    
    public function expire($key, $seconds)
    {
        return $this->redis->expire($key, $seconds);
    }
    
    public function del($key)
    {
        return $this->redis->del($key);
    }
} 