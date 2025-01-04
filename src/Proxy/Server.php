<?php
namespace App\Proxy;

use App\Models\Channel;
use App\Models\ErrorLog;
use App\Core\Logger;
use App\Core\Config;

class Server 
{
    private $logger;
    private $config;
    private $isRunning = false;
    private $socket;
    private $clients = [];
    private $channels = [];
    private $db;
    private $errorLog;
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->config = Config::getInstance();
        $this->loadChannels();
        $this->db = \App\Core\Database::getInstance()->getConnection();
        $this->errorLog = new ErrorLog();
    }
    
    private function logError($message, $level = 'error', $file = null, $line = null)
    {
        // 获取调用堆栈
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        array_shift($trace); // 移除当前方法的堆栈
        
        // 如果没有提供文件和行号，使用调用者的信息
        if ($file === null && !empty($trace[0]['file'])) {
            $file = $trace[0]['file'];
        }
        if ($line === null && !empty($trace[0]['line'])) {
            $line = $trace[0]['line'];
        }
        
        // 记录到错误日志数据库
        try {
            $this->errorLog->add([
                'level' => $level,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'trace' => json_encode($trace, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (\Exception $e) {
            // 如果记录错误日志失败，至少要记录到系统日志
            error_log("Error logging to database: " . $e->getMessage());
        }
        
        // 同时记录到系统日志
        $this->logger->$level($message);
    }
    
    private function loadChannels()
    {
        try {
            $channelModel = new Channel();
            $result = $channelModel->getChannelList(1, 1000); // 获取前1000个频道
            $this->channels = $result['channels'];
        } catch (\Exception $e) {
            $this->logError("加载频道列表失败: " . $e->getMessage(), 'error', __FILE__, __LINE__);
        }
    }
    
    public function start()
    {
        if ($this->isRunning) {
            //$this->logError("代理服务器已经在运行中", 'warning');
            return false;
        }
        
        try {
            // 创建 TCP 服务器
            $address = $this->config->get('proxy_host', '0.0.0.0');
            $port = $this->config->get('proxy_port', 8080);
            
            $this->logError("正在启动代理服务器...", 'info', __FILE__, __LINE__);
            
            $this->socket = stream_socket_server("tcp://{$address}:{$port}", $errno, $errstr);
            if (!$this->socket) {
                $this->logError("创建服务器失败: {$errstr} ({$errno})", 'error', __FILE__, __LINE__);
                return false;
            }
            
            $this->isRunning = true;
            $this->logError("代理服务器已启动: {$address}:{$port}", 'info', __FILE__, __LINE__);
            
            return true;
        } catch (\Exception $e) {
            $this->logError("启动代理服务器失败: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            return false;
        }
    }
    
    private function handleClientError($client, $message, $level = 'error')
    {
        $clientId = (int)$client;
        $clientIp = stream_socket_get_name($client, true);
        $channelId = $this->clients[$clientId]['channel_id'] ?? null;
        
        $errorMessage = sprintf(
            "客户端错误 [IP: %s, Channel: %s]: %s",
            $clientIp,
            $channelId ? "#{$channelId}" : 'Unknown',
            $message
        );
        
        $this->logError($errorMessage, $level, __FILE__, __LINE__);
    }
    
    private function processConnections()
    {
        try {
            // 准备 socket 数组用于 select
            $read = [];
            foreach ($this->clients as $clientInfo) {
                $read[] = $clientInfo['socket'];
            }
            $read[] = $this->socket;
            $write = null;
            $except = null;
            
            // 使用 select 监听连接，设置超时时间为 0.2 秒
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                // 检查新连接
                if (in_array($this->socket, $read)) {
                    $client = @stream_socket_accept($this->socket);
                    if ($client) {
                        stream_set_blocking($client, false);
                        $clientId = (int)$client;
                        $this->clients[$clientId] = [
                            'socket' => $client
                        ];
                        $clientIp = stream_socket_get_name($client, true);
                        //$this->logError("新客户端连接 (ID: $clientId, IP: $clientIp)", 'info', __FILE__, __LINE__);
                    }
                    unset($read[array_search($this->socket, $read)]);
                }
                
                // 处理现有连接
                foreach ($read as $client) {
                    $data = @fread($client, 4096);
                    if ($data === false || $data === '') {
                        $this->removeClient($client);
                        continue;
                    }
                    
                    try {
                        // 处理 HTTP 请求
                        $this->handleRequest($client, $data);
                    } catch (\Exception $e) {
                        $clientId = (int)$client;
                        $this->logError("处理客户端请求时发生错误 (ID: $clientId): " . $e->getMessage(), 'error', __FILE__, __LINE__);
                        $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
                        $this->removeClient($client);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logError("处理连接时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
        }
    }
    
    private function recordConnection($client, $channelId)
    {
        try {
            $clientId = (int)$client;
            $clientIp = stream_socket_get_name($client, true);
            $userAgent = '-';
            $sessionId = md5($clientId . $clientIp . microtime(true));
            
            // 先清理可能存在的旧连接记录
            $stmt = $this->db->prepare("UPDATE channel_connections 
                SET status = 'disconnected', 
                    disconnect_time = NOW() 
                WHERE client_ip = ? 
                AND channel_id = ? 
                AND status = 'active'");
            $stmt->execute([$clientIp, $channelId]);
            
            // 等待一小段时间确保更新完成
            usleep(100000); // 100ms
            
            // 记录新连接
            $stmt = $this->db->prepare("INSERT INTO channel_connections 
                (channel_id, client_ip, user_agent, session_id, connect_time, last_active_time, status) 
                VALUES (?, ?, ?, ?, NOW(), NOW(), 'active')");
            $stmt->execute([$channelId, $clientIp, $userAgent, $sessionId]);
            
            // 存储session_id到clients数组中
            if (!isset($this->clients[$clientId])) {
                $this->clients[$clientId] = ['socket' => $client];
            }
            $this->clients[$clientId]['session_id'] = $sessionId;
            $this->clients[$clientId]['channel_id'] = $channelId;
            
            //$this->logError("新连接已记录: IP=$clientIp, Channel=$channelId, Session=$sessionId", 'info', __FILE__, __LINE__);
            
            // 更新连接状态
            $this->updateConnectionStatus($client);
        } catch (\Exception $e) {
            $this->logError("记录连接信息时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
            // 继续处理请求，不要因为记录失败而中断服务
        }
    }
    
    private function updateConnectionStatus($client)
    {
        try {
            $clientId = (int)$client;
            if (isset($this->clients[$clientId]['session_id'])) {
                $sessionId = $this->clients[$clientId]['session_id'];
                
                // 更新最后活跃时间
                $stmt = $this->db->prepare("UPDATE channel_connections 
                    SET last_active_time = NOW(), status = 'active' 
                    WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                
                //$this->logError("更新连接状态: Session=$sessionId", 'info', __FILE__, __LINE__);
            }
        } catch (\Exception $e) {
            $this->logError("更新连接状态时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
        }
    }
    
    private function closeConnection($client)
    {
        try {
            $clientId = (int)$client;
            if (isset($this->clients[$clientId]['session_id'])) {
                $sessionId = $this->clients[$clientId]['session_id'];
                
                // 标记连接为断开，并记录断开时间
                $stmt = $this->db->prepare("UPDATE channel_connections 
                    SET status = 'disconnected', 
                        disconnect_time = NOW() 
                    WHERE session_id = ? 
                    AND status = 'active'");
                $stmt->execute([$sessionId]);
                
                //$this->logError("连接已断开: Session=$sessionId", 'info', __FILE__, __LINE__);
            }
        } catch (\Exception $e) {
            $this->logError("关闭连接时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
        }
    }
    
    private function cleanInactiveConnections()
    {
        try {
            // 将超过1分钟未活跃的连接标记为断开
            $stmt = $this->db->prepare("UPDATE channel_connections 
                SET status = 'disconnected',
                    disconnect_time = NOW() 
                WHERE status = 'active' 
                AND last_active_time < DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
            $stmt->execute();
            
            //$this->logError("已清理不活跃连接", 'info', __FILE__, __LINE__);
        } catch (\Exception $e) {
            $this->logError("清理不活跃连接时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
        }
    }
    
    private function handleRequest($client, $data)
    {
        // 解析 HTTP 请求
        $lines = explode("\r\n", $data);
        $firstLine = explode(' ', $lines[0]);
        
        if (count($firstLine) < 2) {
            $this->removeClient($client);
            return;
        }
        
        $method = $firstLine[0];
        $path = parse_url($firstLine[1], PHP_URL_PATH);
        
        // 获取User-Agent
        $userAgent = '-';
        foreach ($lines as $line) {
            if (stripos($line, 'User-Agent:') === 0) {
                $userAgent = trim(substr($line, 11));
                break;
            }
        }
        
        //$this->logError("收到请求: $method $path", 'info', __FILE__, __LINE__);
        
        // 从路径中提取频道ID和请求类型
        if (preg_match('/^\/proxy\/(ch_[^\/]+)\/([^\/]+)$/', $path, $matches)) {
            $channelId = $matches[1];
            $requestFile = $matches[2];
            //$this->logError("提取到频道ID: $channelId, 请求文件: $requestFile", 'info', __FILE__, __LINE__);
            
            // 查找对应的频道
            $channel = $this->findChannel($channelId);
            
            if (!$channel) {
                $this->logError("未找到频道: $channelId", 'error', __FILE__, __LINE__);
                $response = "HTTP/1.1 404 Not Found\r\n";
                $response .= "Content-Type: text/plain\r\n";
                $response .= "Connection: close\r\n\r\n";
                $response .= "Channel not found";
                @fwrite($client, $response);
                $this->removeClient($client);
                return;
            }
            
            //$this->logError("找到频道: {$channel['name']} (ID: {$channel['id']})", 'info', __FILE__, __LINE__);
            
            // 只在请求m3u8文件时记录连接
            if ($requestFile === 'stream.m3u8') {
                $this->recordConnection($client, $channel['id']);
                
                // 更新客户端的User-Agent
                $clientId = (int)$client;
                if (isset($this->clients[$clientId]['session_id'])) {
                    $stmt = $this->db->prepare("UPDATE channel_connections 
                        SET user_agent = ? 
                        WHERE session_id = ? AND status = 'active'");
                    $stmt->execute([$userAgent, $this->clients[$clientId]['session_id']]);
                }
            } else if (preg_match('/\.ts$/', $requestFile)) {
                // 对于ts文件请求，只更新最后活跃时间
                $clientId = (int)$client;
                if (isset($this->clients[$clientId]['session_id'])) {
                    $this->updateConnectionStatus($client);
                }
            }
            
            // 根据请求类型选择不同的处理方式
            if ($requestFile === 'stream.m3u8') {
                $this->proxyM3U8($client, $channel);
            } else if (preg_match('/\.ts$/', $requestFile)) {
                $this->proxyTS($client, $channel, $requestFile);
            } else {
                $this->logError("不支持的文件类型: $requestFile", 'error', __FILE__, __LINE__);
                $response = "HTTP/1.1 400 Bad Request\r\n";
                $response .= "Content-Type: text/plain\r\n";
                $response .= "Connection: close\r\n\r\n";
                $response .= "Unsupported file type";
                @fwrite($client, $response);
                $this->removeClient($client);
            }
        } else {
            $this->logError("无效的URL格式: $path", 'error', __FILE__, __LINE__);
            $response = "HTTP/1.1 404 Not Found\r\n";
            $response .= "Content-Type: text/plain\r\n";
            $response .= "Connection: close\r\n\r\n";
            $response .= "Invalid channel URL format";
            @fwrite($client, $response);
            $this->removeClient($client);
            return;
        }
    }
    
    private function proxyM3U8($client, $channel)
    {
        // 获取原始m3u8内容
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->config->get('proxy_timeout', 10),
                'user_agent' => 'PHP IPTV Proxy'
            ]
        ]);
        
        $content = @file_get_contents($channel['source_url'], false, $context);
        if ($content === false) {
            $this->logError("无法获取m3u8内容: " . $channel['source_url'], 'error', __FILE__, __LINE__);
            $this->removeClient($client);
            return;
        }
        
        // 修改m3u8内容中的ts文件路径
        $baseUrl = dirname($channel['proxy_url']);
        $content = preg_replace_callback('/^(.*\.ts.*)$/m', function($matches) use ($baseUrl) {
            $tsFile = basename($matches[0]);
            $proxyPath = $baseUrl . '/' . $tsFile;
            return $proxyPath;
        }, $content);
        
        // 发送响应
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: application/vnd.apple.mpegurl\r\n";
        $response .= "Access-Control-Allow-Origin: *\r\n";
        $response .= "Cache-Control: no-cache\r\n";
        $response .= "Content-Length: " . strlen($content) . "\r\n";
        $response .= "Connection: close\r\n\r\n";
        $response .= $content;
        
        @fwrite($client, $response);
    }
    
    private function proxyTS($client, $channel, $tsFile)
    {
        // 从原始m3u8中获取完整的ts文件路径
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->config->get('proxy_timeout', 10),
                'user_agent' => 'PHP IPTV Proxy'
            ]
        ]);
        
        // 获取原始m3u8内容
        $content = @file_get_contents($channel['source_url'], false, $context);
        if ($content === false) {
            $this->logError("无法获取m3u8内容: " . $channel['source_url'], 'error', __FILE__, __LINE__);
            $this->removeClient($client);
            return;
        }
        
        // 在原始m3u8内容中查找完整的ts文件URL
        $tsBaseName = pathinfo($tsFile, PATHINFO_FILENAME);
        if (preg_match('/' . preg_quote($tsBaseName, '/') . '.*\.ts[^\n]*/i', $content, $matches)) {
            $tsFullPath = $matches[0];
            $sourceUrl = dirname($channel['source_url']) . '/' . $tsFullPath;
            //$this->logError("尝试访问的TS文件URL（带参数）: " . $sourceUrl, 'info', __FILE__, __LINE__);
            
            // 打开源流
            $source = @fopen($sourceUrl, 'r', false, $context);
            if (!$source) {
                $this->logError("无法打开TS文件: $sourceUrl", 'error', __FILE__, __LINE__);
                $this->removeClient($client);
                return;
            }
            
            // 发送 HTTP 头
            $response = "HTTP/1.1 200 OK\r\n";
            $response .= "Content-Type: video/mp2t\r\n";
            $response .= "Access-Control-Allow-Origin: *\r\n";
            $response .= "Cache-Control: no-cache\r\n";
            $response .= "Connection: close\r\n\r\n";
            @fwrite($client, $response);
            
            // 设置流为非阻塞模式
            stream_set_blocking($source, false);
            
            // 获取缓冲区大小配置并转换为字节
            $bufferSize = $this->config->get('proxy_buffer_size', 8192) * 1024;
            
            // 转发流内容
            while (!feof($source) && $this->isRunning) {
                $data = @fread($source, $bufferSize);
                if ($data === false) {
                    break;
                }
                
                $written = @fwrite($client, $data);
                if ($written === false) {
                    break;
                }
                
                // 避免 CPU 占用过高
                usleep(1000);
            }
            
            // 清理
            @fclose($source);
        } else {
            $this->logError("在m3u8中未找到对应的TS文件: $tsFile", 'error', __FILE__, __LINE__);
        }
        
        // 更新连接状态
        $clientId = (int)$client;
        if (isset($this->clients[$clientId]['session_id'])) {
            $this->updateConnectionStatus($client);
        }
        
        $this->removeClient($client);
    }
    
    private function findChannel($channelId)
    {
        foreach ($this->channels as $channel) {
            // 从代理URL中提取频道ID
            if (preg_match('/^\/proxy\/(ch_[^\/]+)\//', $channel['proxy_url'], $matches)) {
                if ($matches[1] === $channelId) {
                    return $channel;
                }
            }
        }
        return null;
    }
    
    private function removeClient($client)
    {
        $clientId = (int)$client;
        //$this->logError("客户端断开连接 (ID: $clientId)", 'info', __FILE__, __LINE__);
        
        // 标记连接为断开
        $this->closeConnection($client);
        
        @fclose($client);
        unset($this->clients[$clientId]);
    }
    
    public function stop()
    {
        $this->logError("正在停止代理服务器...", 'info', __FILE__, __LINE__);
        
        $this->isRunning = false;
        
        // 关闭所有客户端连接
        $clientCount = count($this->clients);
        foreach ($this->clients as $clientInfo) {
            $this->removeClient($clientInfo['socket']);
        }
        $this->clients = [];
        //$this->logError("已关闭 $clientCount 个客户端连接", 'info', __FILE__, __LINE__);
        
        // 关闭服务器 socket
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
            $this->logError("已关闭服务器 socket", 'info', __FILE__, __LINE__);
        }
        
        $this->logError("代理服务器已停止", 'info', __FILE__, __LINE__);
        return true;
    }
    
    public function isRunning()
    {
        return $this->isRunning;
    }
    
    public function getStatus()
    {
        try {
            // 清理不活跃连接
            $this->cleanInactiveConnections();
            
            // 获取活跃连接数
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM channel_connections WHERE status = 'active'");
            $totalConnections = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];
            
            return [
                'running' => $this->isRunning,
                'clients' => count($this->clients),
                'channels' => count($this->channels),
                'connections' => $totalConnections
            ];
        } catch (\Exception $e) {
            $this->logError("获取状态时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            return [
                'running' => $this->isRunning,
                'clients' => count($this->clients),
                'channels' => count($this->channels),
                'connections' => 0
            ];
        }
    }
    
    public function run()
    {
        try {
            while ($this->isRunning) {
                $this->processConnections();
                usleep(10000); // 10ms，避免CPU占用过高
            }
            return true;
        } catch (\Exception $e) {
            $this->logError("代理服务器运行时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            return false;
        }
    }
} 