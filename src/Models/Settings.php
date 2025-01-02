<?php
namespace App\Models;

use App\Core\Database;

class Settings
{
    private $db;
    private $configFile;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->configFile = __DIR__ . '/../../config/settings.php';
        $this->ensureTableStructure();
    }

    private function ensureTableStructure()
    {
        try {
            $query = "CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(50) PRIMARY KEY,
                `value` TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $this->db->exec($query);
        } catch (\PDOException $e) {
            error_log("Error creating settings table: " . $e->getMessage());
        }
    }

    public function getAllSettings()
    {
        try {
            $stmt = $this->db->query("SELECT `key`, `value` FROM settings");
            $dbSettings = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $dbSettings[$row['key']] = $row['value'];
            }
            
            // 合并默认设置和数据库设置
            return array_merge($this->getDefaultSettings(), $dbSettings);
        } catch (\PDOException $e) {
            error_log("Error getting settings: " . $e->getMessage());
            return $this->getDefaultSettings();
        }
    }

    private function getDefaultSettings()
    {
        return [
            'cache_time' => 300,
            'chunk_size' => 1048576,
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_password' => '',
            'monitor_refresh_interval' => 5,
            'check_mode' => 'daily',
            'daily_check_time' => '03:00',
            'check_interval' => 6
        ];
    }

    public function saveSettings($settings)
    {
        try {
            $this->db->beginTransaction();

            foreach ($settings as $key => $value) {
                $stmt = $this->db->prepare("INSERT INTO settings (`key`, `value`) 
                                          VALUES (:key, :value) 
                                          ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                $stmt->execute([
                    ':key' => $key,
                    ':value' => $value
                ]);
            }

            // 保存到配置文件
            $this->saveToConfigFile($settings);

            $this->db->commit();
            return ['success' => true, 'message' => '设置保存成功'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Error saving settings: " . $e->getMessage());
            return ['success' => false, 'message' => '保存设置失败：' . $e->getMessage()];
        }
    }

    private function saveToConfigFile($settings)
    {
        $content = "<?php\nreturn " . var_export($settings, true) . ";\n";
        file_put_contents($this->configFile, $content);
    }
} 