<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Models\Settings;

class SettingsController extends Controller
{
    private $settings;
    
    public function __construct()
    {
        parent::__construct();
        $this->settings = Settings::getInstance();
    }
    
    public function index()
    {
        $settings = $this->settings->get();
        require __DIR__ . '/../views/admin/settings/index.php';
    }
    
    public function save()
    {
        try {
            // 获取 JSON 数据
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                Response::error('无效的数据格式');
            }
            
            // 验证必填字段
            $requiredFields = [
                'proxy_host',
                'proxy_port',
                'proxy_timeout',
                'proxy_buffer_size',
                'max_error_count',
                'cache_time',
                'chunk_size',
                'redis_host',
                'redis_port',
                'monitor_refresh_interval',
                'status_check_interval',
                'check_mode'
            ];
            
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    Response::error("缺少必填字段: {$field}");
                }
            }
            
            // 验证端口范围
            if (!is_numeric($data['proxy_port']) || $data['proxy_port'] < 1 || $data['proxy_port'] > 65535) {
                Response::error('代理服务器端口号必须在 1-65535 之间');
            }
            if (!is_numeric($data['redis_port']) || $data['redis_port'] < 1 || $data['redis_port'] > 65535) {
                Response::error('Redis 端口号必须在 1-65535 之间');
            }
            
            // 验证超时时间
            if (!is_numeric($data['proxy_timeout']) || $data['proxy_timeout'] < 1 || $data['proxy_timeout'] > 300) {
                Response::error('代理服务器超时时间必须在 1-300 秒之间');
            }
            
            // 验证缓冲区大小
            if (!is_numeric($data['proxy_buffer_size']) || $data['proxy_buffer_size'] < 1024 || $data['proxy_buffer_size'] > 1048576) {
                Response::error('代理服务器缓冲区大小必须在 1KB-1MB 之间');
            }
            
            // 验证缓存时间
            if (!is_numeric($data['cache_time']) || $data['cache_time'] < 1) {
                Response::error('缓存时间必须大于 0 秒');
            }
            
            // 验证分片大小
            if (!is_numeric($data['chunk_size']) || $data['chunk_size'] < 1024 || $data['chunk_size'] > 10485760) {
                Response::error('分片大小必须在 1KB-10MB 之间');
            }
            
            // 验证监控刷新时间
            if (!is_numeric($data['monitor_refresh_interval']) || $data['monitor_refresh_interval'] < 1 || $data['monitor_refresh_interval'] > 3600) {
                Response::error('监控刷新时间必须在 1-3600 秒之间');
            }
            
            // 验证服务状态检查间隔
            if (!is_numeric($data['status_check_interval']) || $data['status_check_interval'] < 1 || $data['status_check_interval'] > 60) {
                Response::error('服务状态检查间隔必须在 1-60 秒之间');
            }
            
            // 验证检查模式相关设置
            if ($data['check_mode'] === 'daily') {
                if (empty($data['daily_check_time'])) {
                    Response::error('请设置每日检查时间');
                }
            } else if ($data['check_mode'] === 'interval') {
                if (empty($data['check_interval']) || !is_numeric($data['check_interval']) || 
                    $data['check_interval'] < 1 || $data['check_interval'] > 24) {
                    Response::error('检查间隔必须在 1-24 小时之间');
                }
            } else {
                Response::error('无效的检查模式');
            }
            
            // 保存设置
            $success = true;
            foreach ($data as $key => $value) {
                if (!$this->settings->set($key, $value)) {
                    $success = false;
                    break;
                }
            }
            
            if ($success) {
                Response::success(null, '设置已保存');
            } else {
                Response::error('保存设置失败');
            }
        } catch (\Exception $e) {
            Response::error('保存设置时发生错误: ' . $e->getMessage());
        }
    }
} 