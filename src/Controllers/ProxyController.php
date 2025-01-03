<?php
namespace App\Controllers;

use App\Core\Logger;
use App\Core\Config;

class ProxyController
{
    private $logger;
    private $config;
    private $pidFile;
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->config = Config::getInstance();
        $this->pidFile = dirname(dirname(__DIR__)) . '/storage/proxy.pid';
    }
    
    private function isRunning($pid = null)
    {
        if ($pid === null) {
            $pid = @file_get_contents($this->pidFile);
        }
        if (!$pid) {
            return false;
        }
        
        // 1. 使用ps命令检查进程
        exec("ps -p $pid", $psOutput, $psReturnVar);
        if ($psReturnVar !== 0) {
            return false;
        }
        
        // 2. 检查端口占用
        $port = $this->config->get('proxy_port', '9260');
        exec("netstat -tnlp 2>/dev/null | grep :$port", $netstatOutput);
        
        // 如果端口未被占用，进程可能正在停止中
        if (empty($netstatOutput)) {
            return false;
        }
        
        return true;
    }
    
    private function sendJsonResponse($data)
    {
        // 清除之前的所有输出
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 设置响应头
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        
        // 输出 JSON 数据
        echo json_encode($data);
        exit;
    }
    
    public function start()
    {
        $pid = @file_get_contents($this->pidFile);
        
        // 如果进程已经在运行，返回错误
        if ($pid && $this->isRunning($pid)) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '代理服务器已经在运行中'
            ]);
        }
        
        // 构建完整的命令路径
        $scriptPath = realpath(__DIR__ . '/../Commands/proxy.php');
        if (!$scriptPath) {
            $this->logger->error('找不到代理服务器脚本文件');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '找不到代理服务器脚本文件'
            ]);
        }
        
        // 检查文件权限
        if (!is_readable($scriptPath)) {
            $this->logger->error('代理服务器脚本文件无法读取');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '代理服务器脚本文件无法读取'
            ]);
        }
        
        // 确保日志目录存在且可写
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 确保 PID 文件目录存在且可写
        $pidDir = dirname($this->pidFile);
        if (!is_dir($pidDir)) {
            mkdir($pidDir, 0755, true);
        }
        
        // 查找可用的PHP CLI可执行文件
        $phpBinary = $this->findPhpBinary();
        if (!$phpBinary) {
            $this->logger->error('找不到可用的PHP CLI可执行文件');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '找不到可用的PHP CLI可执行文件'
            ]);
        }
        
        // 执行启动命令
        $command = sprintf('%s %s start 2>&1', $phpBinary, escapeshellarg($scriptPath));
        exec($command, $output, $returnVar);
        
        // 如果命令执行失败，直接返回错误
        if ($returnVar !== 0) {
            $this->logger->error('代理服务器启动失败');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '代理服务器启动失败: ' . implode("\n", $output)
            ]);
        }
        
        // 等待进程启动（最多等待5秒）
        $timeout = 5;
        $started = false;
        while ($timeout > 0) {
            clearstatcache(true, $this->pidFile);
            $pid = @file_get_contents($this->pidFile);
            
            if ($pid && $this->isRunning($pid)) {
                $started = true;
                break;
            }
            
            sleep(1);
            $timeout--;
        }
        
        if ($started) {
            $this->sendJsonResponse([
                'success' => true,
                'message' => '代理服务器已启动'
            ]);
        } else {
            $this->logger->error('代理服务器启动失败');
            if (file_exists($this->pidFile)) {
                @unlink($this->pidFile);
            }
            $this->sendJsonResponse([
                'success' => false,
                'message' => '代理服务器启动失败，请查看日志获取详细信息'
            ]);
        }
    }
    
    private function findPhpBinary()
    {
        // 检查是否是宝塔环境
        $btPanelPath = '/www/server/panel/class/panelSite.py';
        
        if (file_exists($btPanelPath)) {
            // 从当前 PHP 进程路径中提取版本号
            if (preg_match('/\/www\/server\/php\/(\d+)\//', PHP_BINARY, $matches)) {
                $version = $matches[1];
                
                // 构建宝塔 PHP CLI 路径
                $btPath = "/www/server/php/{$version}/bin/php";
                
                if (file_exists($btPath) && is_executable($btPath)) {
                    // 验证是否是 CLI 版本
                    $command = sprintf('"%s" -v 2>&1', $btPath);
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0) {
                        return $btPath;
                    }
                }
            } else {
                // 尝试直接使用当前PHP版本
                $version = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
                $btPath = "/www/server/php/{$version}/bin/php";
                
                if (file_exists($btPath) && is_executable($btPath)) {
                    return $btPath;
                }
            }
        }
        
        // 如果宝塔环境检测失败，尝试直接使用 php 命令
        exec('command -v php 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $phpPath = trim($output[0]);
            
            // 验证是否可用
            $command = sprintf('"%s" -v 2>&1', $phpPath);
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0) {
                return $phpPath;
            }
        }
        
        // 如果是 Windows 系统
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $possiblePaths = [
                'C:\\php\\php.exe',
                'C:\\xampp\\php\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.2.0\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.1.0\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.0.0\\php.exe',
                'C:\\wamp64\\bin\\php\\php7.4.0\\php.exe',
                'php.exe'
            ];
            
            foreach ($possiblePaths as $path) {
                if ($path === 'php.exe') {
                    $command = 'where php.exe';
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0 && !empty($output)) {
                        $path = trim($output[0]);
                    }
                }
                
                if (file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        } else {
            $possiblePaths = [
                '/usr/bin/php',
                '/usr/local/bin/php',
                '/usr/local/php/bin/php',
                'php'
            ];
            
            foreach ($possiblePaths as $path) {
                if ($path === 'php') {
                    $command = 'which php 2>/dev/null';
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0 && !empty($output)) {
                        $path = trim($output[0]);
                    }
                }
                
                if (file_exists($path) && is_executable($path)) {
                    $command = sprintf('"%s" -v 2>&1', $path);
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0) {
                        return $path;
                    }
                }
            }
        }
        
        $this->logger->error('找不到可用的 PHP CLI');
        return null;
    }
    
    public function stop()
    {
        $pid = @file_get_contents($this->pidFile);
        
        // 如果进程不在运行，返回成功
        if (!$pid || !$this->isRunning($pid)) {
            @unlink($this->pidFile);
            $this->sendJsonResponse([
                'success' => true,
                'message' => '代理服务器已停止'
            ]);
        }
        
        // 获取PHP可执行文件路径
        $phpBinary = $this->findPhpBinary();
        if (!$phpBinary) {
            $this->logger->error('找不到可用的PHP CLI可执行文件');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '找不到可用的PHP CLI可执行文件'
            ]);
        }
        
        // 构建完整的命令路径
        $scriptPath = realpath(__DIR__ . '/../Commands/proxy.php');
        if (!$scriptPath) {
            $this->logger->error('找不到代理服务器脚本文件');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '找不到代理服务器脚本文件'
            ]);
        }
        
        // 执行停止命令
        $command = sprintf('%s %s stop 2>&1', $phpBinary, escapeshellarg($scriptPath));
        exec($command, $output, $returnVar);
        
        // 发送SIGTERM信号
        if ($pid) {
            posix_kill((int)$pid, SIGTERM);
        }
        
        // 等待进程停止（最多等待10秒）
        $timeout = 10;
        $stopped = false;
        while ($timeout > 0) {
            if (!$this->isRunning($pid)) {
                $stopped = true;
                break;
            }
            
            sleep(1);
            $timeout--;
        }
        
        // 如果进程仍在运行，尝试强制终止
        if (!$stopped && $pid) {
            posix_kill((int)$pid, SIGKILL);
            sleep(1);
        }
        
        // 清理PID文件
        @unlink($this->pidFile);
        
        if ($stopped || !$this->isRunning($pid)) {
            $this->sendJsonResponse([
                'success' => true,
                'message' => '代理服务器已停止'
            ]);
        } else {
            $this->logger->error('代理服务器停止失败');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '代理服务器停止失败'
            ]);
        }
    }
    
    public function status()
    {
        $pid = @file_get_contents($this->pidFile);
        $running = $pid && $this->isRunning($pid);
        
        // 如果进程不存在但PID文件存在，清理PID文件
        if (!$running && $pid) {
            @unlink($this->pidFile);
            $pid = null;
        }
        
        // 获取进程运行时间
        $uptime = null;
        if ($running && $pid) {
            $stat = @file_get_contents("/proc/$pid/stat");
            if ($stat) {
                $stats = explode(' ', $stat);
                if (isset($stats[21])) {  // starttime
                    $btime = file_get_contents('/proc/stat');
                    if (preg_match('/btime\s+(\d+)/', $btime, $matches)) {
                        $bootTime = (int)$matches[1];
                        $startTime = $bootTime + ((int)$stats[21] / 100);
                        $uptime = time() - $startTime;
                    }
                }
            }
        }
        
        // 获取端口和连接信息
        $port = $this->config->get('proxy_port', '9260');
        $connections = 0;
        if ($running) {
            $netstat = [];
            exec("netstat -ant | grep :$port | grep ESTABLISHED", $netstat);
            $connections = count($netstat);
        }
        
        // 获取内存使用情况
        $memory = null;
        if ($running && $pid) {
            $status = @file_get_contents("/proc/$pid/status");
            if ($status && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                $memory = (int)$matches[1] * 1024;  // 转换为字节
            }
        }
        
        $this->sendJsonResponse([
            'success' => true,
            'data' => [
                'running' => $running,
                'pid' => $running ? $pid : null,
                'uptime' => $uptime,
                'memory' => $memory,
                'connections' => $connections,
                'port' => $port,
                'settings' => [
                    'host' => $this->config->get('proxy_host', '0.0.0.0'),
                    'port' => $port,
                    'timeout' => $this->config->get('proxy_timeout', '10'),
                    'buffer_size' => $this->config->get('proxy_buffer_size', '8192')
                ]
            ]
        ]);
    }
    
    public function getStatus()
    {
        $pid = @file_get_contents($this->pidFile);
        $isRunning = $this->isRunning($pid);
        
        // 获取配置
        $port = $this->config->get('proxy_port', '9260');
        $host = $this->config->get('proxy_host', '0.0.0.0');
        $timeout = $this->config->get('proxy_timeout', '10');
        $bufferSize = $this->config->get('proxy_buffer_size', '8192');
        
        // 获取进程信息
        $uptime = null;
        $memory = null;
        $connections = 0;
        
        if ($isRunning && $pid) {
            // 获取进程启动时间
            $stat = @file_get_contents("/proc/$pid/stat");
            if ($stat) {
                $stats = explode(' ', $stat);
                if (isset($stats[21])) {
                    $startTime = (int)$stats[21];
                    $uptime = time() - $startTime;
                }
            }
            
            // 获取内存使用
            $status = @file_get_contents("/proc/$pid/status");
            if ($status) {
                if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                    $memory = (int)$matches[1] * 1024; // 转换为字节
                }
            }
            
            // 获取连接数
            exec("netstat -tnp 2>/dev/null | grep :$port | grep $pid | wc -l", $output);
            if (isset($output[0])) {
                $connections = (int)$output[0];
            }
        }
        
        $this->sendJsonResponse([
            'success' => true,
            'data' => [
                'running' => $isRunning,
                'pid' => $isRunning ? $pid : null,
                'uptime' => $uptime,
                'memory' => $memory,
                'connections' => $connections,
                'port' => $port,
                'settings' => [
                    'host' => $host,
                    'port' => $port,
                    'timeout' => $timeout,
                    'buffer_size' => $bufferSize
                ]
            ]
        ]);
    }
} 