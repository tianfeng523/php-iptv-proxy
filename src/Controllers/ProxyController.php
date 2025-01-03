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
            $this->logger->info('没有PID信息');
            return false;
        }
        
        $this->logger->info('检查进程状态，PID: ' . $pid);
        
        // 1. 使用ps命令检查进程
        exec("ps -p $pid", $psOutput, $psReturnVar);
        if ($psReturnVar !== 0) {
            $this->logger->info('进程不存在');
            return false;
        }
        
        // 2. 检查端口占用
        $port = $this->config->get('proxy_port', '9260');
        exec("netstat -tnlp 2>/dev/null | grep :$port", $netstatOutput);
        
        // 如果端口未被占用，进程可能正在停止中
        if (empty($netstatOutput)) {
            $this->logger->info('端口未被占用，进程可能正在停止中');
            return false;
        }
        
        $this->logger->info('进程正在运行');
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
        $this->logger->info('执行启动命令: ' . $command);
        
        // 记录当前工作目录
        $this->logger->info('当前工作目录: ' . getcwd());
        
        // 记录PHP版本和操作系统信息
        $this->logger->info('PHP版本: ' . PHP_VERSION);
        $this->logger->info('操作系统: ' . PHP_OS);
        
        exec($command, $output, $returnVar);
        
        // 记录命令输出
        if ($output) {
            $this->logger->info('命令输出: ' . implode("\n", $output));
        }
        
        // 记录返回值
        $this->logger->info('命令返回值: ' . $returnVar);
        
        // 如果命令执行失败，直接返回错误
        if ($returnVar !== 0) {
            $this->logger->error('命令执行失败，返回值: ' . $returnVar);
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
            
            if ($pid) {
                $this->logger->info('检测到 PID 文件，PID: ' . $pid);
                if ($this->isRunning($pid)) {
                    $started = true;
                    $this->logger->info('进程已启动并运行中');
                    break;
                } else {
                    $this->logger->info('PID 文件存在但进程未运行');
                }
            } else {
                $this->logger->info('等待 PID 文件创建...');
            }
            
            sleep(1);
            $timeout--;
        }
        
        if ($started) {
            $this->logger->info('代理服务器启动成功');
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
        $this->logger->info('开始查找 PHP CLI 可执行文件');
        $this->logger->info('当前 PHP 版本: ' . PHP_VERSION);
        $this->logger->info('当前 PHP 二进制文件: ' . PHP_BINARY);
        $this->logger->info('操作系统: ' . PHP_OS);
        
        // 检查是否是宝塔环境
        $btPanelPath = '/www/server/panel/class/panelSite.py';
        $this->logger->info('检查宝塔面板文件: ' . $btPanelPath);
        $this->logger->info('文件是否存在: ' . (file_exists($btPanelPath) ? '是' : '否'));
        
        if (file_exists($btPanelPath)) {
            $this->logger->info('检测到宝塔环境');
            
            // 从当前 PHP 进程路径中提取版本号
            $this->logger->info('PHP进程路径: ' . PHP_BINARY);
            
            if (preg_match('/\/www\/server\/php\/(\d+)\//', PHP_BINARY, $matches)) {
                $version = $matches[1];
                $this->logger->info('提取到PHP版本号: ' . $version);
                
                // 构建宝塔 PHP CLI 路径
                $btPath = "/www/server/php/{$version}/bin/php";
                $this->logger->info('尝试宝塔 PHP 路径: ' . $btPath);
                
                if (file_exists($btPath)) {
                    $this->logger->info('文件存在: ' . $btPath);
                    if (is_executable($btPath)) {
                        $this->logger->info('文件可执行');
                        
                        // 验证是否是 CLI 版本
                        $command = sprintf('"%s" -v 2>&1', $btPath);
                        $this->logger->info('执行验证命令: ' . $command);
                        exec($command, $output, $returnVar);
                        
                        if ($output) {
                            $this->logger->info('命令输出: ' . implode("\n", $output));
                        }
                        
                        if ($returnVar === 0) {
                            $this->logger->info('找到可用的 PHP CLI: ' . $btPath);
                            return $btPath;
                        } else {
                            $this->logger->info('命令执行失败，返回值: ' . $returnVar);
                        }
                    } else {
                        $this->logger->info('文件不可执行，尝试修复权限');
                        @chmod($btPath, 0755);
                        if (is_executable($btPath)) {
                            $this->logger->info('权限修复成功');
                            return $btPath;
                        } else {
                            $this->logger->info('权限修复失败');
                        }
                    }
                } else {
                    $this->logger->info('文件不存在: ' . $btPath);
                }
            } else {
                $this->logger->info('无法从PHP进程路径提取版本号');
                
                // 尝试直接使用当前PHP版本
                $version = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
                $this->logger->info('使用当前PHP版本: ' . $version);
                
                $btPath = "/www/server/php/{$version}/bin/php";
                $this->logger->info('尝试宝塔 PHP 路径: ' . $btPath);
                
                if (file_exists($btPath) && is_executable($btPath)) {
                    $this->logger->info('找到可用的 PHP CLI: ' . $btPath);
                    return $btPath;
                }
            }
        }
        
        // 如果宝塔环境检测失败，尝试直接使用 php 命令
        $this->logger->info('尝试直接使用 php 命令');
        exec('command -v php 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $phpPath = trim($output[0]);
            $this->logger->info('找到 PHP 命令: ' . $phpPath);
            
            // 验证是否可用
            $command = sprintf('"%s" -v 2>&1', $phpPath);
            $this->logger->info('执行验证命令: ' . $command);
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->logger->info('PHP 命令可用');
                return $phpPath;
            }
        }
        
        // 如果是 Windows 系统
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows 系统的可能路径
            $possiblePaths = [
                'C:\\php\\php.exe',
                'C:\\xampp\\php\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.2.0\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.1.0\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.0.0\\php.exe',
                'C:\\wamp64\\bin\\php\\php7.4.0\\php.exe',
                'php.exe' // 如果 PHP 在系统 PATH 中
            ];
            
            foreach ($possiblePaths as $path) {
                $this->logger->info('检查 Windows 路径: ' . $path);
                
                // 对于 php.exe，使用 where 命令查找实际路径
                if ($path === 'php.exe') {
                    $command = 'where php.exe';
                    $this->logger->info('执行命令: ' . $command);
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0 && !empty($output)) {
                        $path = trim($output[0]);
                        $this->logger->info('在 PATH 中找到 PHP: ' . $path);
                    }
                }
                
                if (file_exists($path)) {
                    $this->logger->info('文件存在: ' . $path);
                    if (is_executable($path)) {
                        $this->logger->info('找到可用的 PHP: ' . $path);
                        return $path;
                    }
                }
            }
        } else {
            // Linux/Unix 系统的可能路径
            $possiblePaths = [
                '/usr/bin/php',                   // 系统默认 PHP
                '/usr/local/bin/php',             // 自定义安装的 PHP
                '/usr/local/php/bin/php',         // 源码编译安装的 PHP
                'php'                             // 如果 PHP 在系统 PATH 中
            ];
            
            foreach ($possiblePaths as $path) {
                $this->logger->info('检查 Linux 路径: ' . $path);
                
                // 对于不带路径的 php，使用 which 命令查找实际路径
                if ($path === 'php') {
                    $command = 'which php 2>/dev/null';
                    $this->logger->info('执行命令: ' . $command);
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0 && !empty($output)) {
                        $path = trim($output[0]);
                        $this->logger->info('在 PATH 中找到 PHP: ' . $path);
                    }
                }
                
                if (file_exists($path)) {
                    $this->logger->info('文件存在: ' . $path);
                    if (is_executable($path)) {
                        // 验证是否是 CLI 版本
                        $command = sprintf('"%s" -v 2>&1', $path);
                        $this->logger->info('执行验证命令: ' . $command);
                        exec($command, $output, $returnVar);
                        
                        if ($returnVar === 0) {
                            $this->logger->info('找到可用的 PHP CLI: ' . $path);
                            return $path;
                        }
                    } else {
                        $this->logger->info('尝试修复权限');
                        @chmod($path, 0755);
                        if (is_executable($path)) {
                            $this->logger->info('权限修复成功');
                            return $path;
                        }
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
        $this->logger->info('执行停止命令: ' . $command);
        
        exec($command, $output, $returnVar);
        
        // 记录命令输出
        if ($output) {
            $this->logger->info('命令输出: ' . implode("\n", $output));
        }
        
        // 记录返回值
        $this->logger->info('命令返回值: ' . $returnVar);
        
        // 发送SIGTERM信号
        if ($pid) {
            posix_kill((int)$pid, SIGTERM);
            $this->logger->info('已发送SIGTERM信号到进程: ' . $pid);
        }
        
        // 等待进程停止（最多等待10秒）
        $timeout = 10;
        $stopped = false;
        while ($timeout > 0) {
            $this->logger->info('等待进程停止...');
            
            // 检查进程是否还在运行
            $isStillRunning = false;
            
            // 使用ps命令检查
            exec("ps -p $pid", $psOutput, $psReturnVar);
            if ($psReturnVar !== 0) {
                $isStillRunning = false;
            } else {
                // 检查端口是否还在监听
                $port = $this->config->get('proxy_port', '9260');
                exec("netstat -tnlp 2>/dev/null | grep :$port | grep $pid", $netstatOutput);
                $isStillRunning = !empty($netstatOutput);
            }
            
            if (!$isStillRunning) {
                $stopped = true;
                break;
            }
            
            sleep(1);
            $timeout--;
            
            // 如果等待超过5秒，尝试发送SIGKILL信号
            if ($timeout == 5) {
                $this->logger->info('进程仍在运行，尝试发送SIGKILL信号');
                posix_kill((int)$pid, SIGKILL);
            }
        }
        
        // 清理PID文件
        @unlink($this->pidFile);
        
        if ($stopped) {
            $this->logger->info('代理服务器已成功停止');
            $this->sendJsonResponse([
                'success' => true,
                'message' => '代理服务器已停止'
            ]);
        } else {
            $this->logger->error('代理服务器停止失败');
            $this->sendJsonResponse([
                'success' => false,
                'message' => '代理服务器停止失败，请手动检查进程状态'
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