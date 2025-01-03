<?php
namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

class Settings
{
    private $db;
    private static $instance = null;
    
    // 默认设置
    private $defaults = [
        'proxy_host' => '0.0.0.0',
        'proxy_port' => '9260',
        'proxy_timeout' => '10',
        'proxy_buffer_size' => '8192',
        'check_mode' => 'manual',
        'check_interval' => '1',
        'daily_check_time' => '00:00',
        'monitor_refresh_interval' => '5',
        'max_error_count' => '3',
        'status_check_interval' => '10'  // 添加默认的服务状态检查间隔
    ];
    
    private function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get($key = null)
    {
        try {
            $settings = [];
            $query = "SELECT * FROM settings";
            $stmt = $this->db->query($query);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
            
            // 合并默认值
            $settings = array_merge($this->defaults, $settings);
            
            if ($key !== null) {
                return $settings[$key] ?? null;
            }
            
            return $settings;
        } catch (PDOException $e) {
            error_log("Error getting settings: " . $e->getMessage());
            return $key !== null ? ($this->defaults[$key] ?? null) : $this->defaults;
        }
    }
    
    public function set($key, $value)
    {
        try {
            $query = "INSERT INTO settings (`key`, `value`) 
                     VALUES (:key, :value1) 
                     ON DUPLICATE KEY UPDATE `value` = :value2";
            
            $stmt = $this->db->prepare($query);
            return $stmt->execute([
                ':key' => $key,
                ':value1' => $value,
                ':value2' => $value
            ]);
        } catch (PDOException $e) {
            error_log("Error setting setting: " . $e->getMessage());
            return false;
        }
    }
    
    // 防止对象被复制
    public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed.');
    }
    
    // 防止反序列化创建对象
    public function __wakeup()
    {
        throw new \RuntimeException('Unserialize is not allowed.');
    }
} 