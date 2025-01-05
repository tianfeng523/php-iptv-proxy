<?php
namespace App\Proxy;

use App\Models\Channel;
use App\Models\ErrorLog;
use App\Core\Logger;
use App\Core\Config;
use App\Core\Database;
use App\Core\Redis;

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
    private $redis;
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->config = Config::getInstance();
        $this->loadChannels();
        $this->db = \App\Core\Database::getInstance()->getConnection();
        $this->errorLog = new ErrorLog();
        $this->redis = new Redis();
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
            
            // 获取 User-Agent
            $userAgent = '-';
            $request = $this->clients[$clientId]['request'] ?? '';
            if (preg_match('/User-Agent: (.*?)[\r\n]/i', $request, $matches)) {
                $userAgent = trim($matches[1]);
            }
            
            // 获取客户端 IP，去除端口号
            $clientIp = stream_socket_get_name($client, true);
            $clientIp = preg_replace('/:\d+$/', '', $clientIp); // 移除端口号
            
            // 使用 IP（不含端口）、User-Agent 和频道 ID 生成唯一会话 ID
            $sessionId = md5($clientIp . $userAgent . $channelId);
            
            // 先检查是否存在活跃连接
            $stmt = $this->db->prepare("SELECT session_id FROM channel_connections 
                WHERE client_ip LIKE ? 
                AND user_agent = ? 
                AND channel_id = ? 
                AND status = 'active' 
                AND last_active_time > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
            $stmt->execute([$clientIp . '%', $userAgent, $channelId]);
            $existingSession = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($existingSession) {
                // 使用现有会话
                if (!isset($this->clients[$clientId])) {
                    $this->clients[$clientId] = ['socket' => $client];
                }
                $this->clients[$clientId]['session_id'] = $existingSession['session_id'];
                $this->clients[$clientId]['channel_id'] = $channelId;
                
                // 更新最后活跃时间
                $this->updateConnectionStatus($client);
                return;
            }
            
            // 如果没有活跃连接，清理旧连接并创建新连接
            $stmt = $this->db->prepare("UPDATE channel_connections 
                SET status = 'disconnected', 
                    disconnect_time = NOW() 
                WHERE client_ip LIKE ? 
                AND user_agent = ? 
                AND channel_id = ? 
                AND status = 'active'");
            $stmt->execute([$clientIp . '%', $userAgent, $channelId]);
            
            // 记录新连接
            $stmt = $this->db->prepare("INSERT INTO channel_connections 
                (channel_id, client_ip, user_agent, session_id, connect_time, last_active_time, status) 
                VALUES (?, ?, ?, ?, NOW(), NOW(), 'active')");
            $stmt->execute([$channelId, $clientIp, $userAgent, $sessionId]);
            
            // 存储 session_id 到 clients 数组中
            if (!isset($this->clients[$clientId])) {
                $this->clients[$clientId] = ['socket' => $client];
            }
            $this->clients[$clientId]['session_id'] = $sessionId;
            $this->clients[$clientId]['channel_id'] = $channelId;
            
            // Redis 连接计数只在新会话时增加
            $key = "proxy:connections:{$channelId}";
            $count = $this->redis->get($key) ?: 0;
            $this->redis->set($key, $count + 1);
            
            // 记录会话活跃时间
            $activeKey = "proxy:connection:active:{$channelId}:{$sessionId}";
            $this->redis->set($activeKey, time());
            
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
                $channelId = $this->clients[$clientId]['channel_id'];

                // 更新数据库中的连接状态
                $stmt = $this->db->prepare("UPDATE channel_connections 
                    SET last_active_time = NOW(), status = 'active' 
                    WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                
                // 更新最后活跃时间
                $activeKey = "proxy:connection:active:{$channelId}:{$sessionId}";
                $this->redis->set($activeKey, time());
            }
        } catch (\Exception $e) {
            $this->logError("更新连接状态时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
        }
    }
        
    private function closeConnection($client)
    {
        try {
            $clientId = (int)$client;
            if (isset($this->clients[$clientId]['session_id'])) {
                $sessionId = $this->clients[$clientId]['session_id'];
                $channelId = $this->clients[$clientId]['channel_id'];
                
                // 更新数据库状态
                $stmt = $this->db->prepare("UPDATE channel_connections 
                    SET status = 'disconnected', 
                        disconnect_time = NOW() 
                    WHERE session_id = ? 
                    AND status = 'active'");
                $stmt->execute([$sessionId]);
                
                // 减少 Redis 中的连接计数
                $key = "proxy:connections:{$channelId}";
                $count = $this->redis->get($key) ?: 0;
                if ($count > 0) {
                    $this->redis->set($key, $count - 1);
                }
                
                // 删除活跃会话记录
                $activeKey = "proxy:connection:active:{$channelId}:{$sessionId}";
                $this->redis->del($activeKey);
            }
        } catch (\Exception $e) {
            $this->logError("关闭连接时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
        }
    }
    
    // 清理不活跃连接时也要更新计数
private function cleanInactiveConnections()
    {
        try {
            // 获取所有活跃会话
            $keys = $this->redis->keys('proxy:connection:active:*');
            $now = time();
            
            foreach ($keys as $key) {
                $lastActive = $this->redis->get($key);
                if ($now - $lastActive > 180) { // 60秒无活动视为断开
                    // 解析频道ID和会话ID
                    $parts = explode(':', $key);
                    $channelId = $parts[3];
                    $sessionId = $parts[4];
                    
                    // 更新数据库状态
                    $stmt = $this->db->prepare("UPDATE channel_connections 
                        SET status = 'disconnected',
                            disconnect_time = NOW() 
                        WHERE session_id = ? 
                        AND status = 'active'");
                    $stmt->execute([$sessionId]);
                    
                    // 减少连接计数
                    $countKey = "proxy:connections:{$channelId}";
                    $count = $this->redis->get($countKey) ?: 0;
                    if ($count > 0) {
                        $this->redis->set($countKey, $count - 1);
                    }
                    
                    // 删除活跃会话记录
                    $this->redis->del($key);
                }
            }
        } catch (\Exception $e) {
            $this->logError("清理不活跃连接时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
        }
    }
    
    private function handleRequest($client, $data)
    {
        $clientId = (int)$client;
        // 存储请求数据供后续使用
        if (!isset($this->clients[$clientId])) {
            $this->clients[$clientId] = [];
        }
        $this->clients[$clientId]['request'] = $data;

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
        
        // 从路径中提取频道ID和请求类型
        if (preg_match('/^\/proxy\/(ch_[^\/]+)\/([^\/]+)$/', $path, $matches)) {
            $channelId = $matches[1];
            $requestFile = $matches[2];
            
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
            
            // 获取客户端IP
            $clientIp = stream_socket_get_name($client, true);
            
            // 检查是否已存在活跃连接
            if ($requestFile === 'stream.m3u8') {
                try {
                    $stmt = $this->db->prepare("SELECT session_id FROM channel_connections 
                        WHERE client_ip = ? 
                        AND user_agent = ? 
                        AND channel_id = ? 
                        AND status = 'active' 
                        AND last_active_time > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
                    $stmt->execute([$clientIp, $userAgent, $channel['id']]);
                    $existingSession = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if (!$existingSession) {
                        // 只有在没有活跃连接时才记录新连接
                        $this->recordConnection($client, $channel['id']);
                    } else {
                        // 如果存在活跃连接，使用现有会话ID
                        if (!isset($this->clients[$clientId])) {
                            $this->clients[$clientId] = ['socket' => $client];
                        }
                        $this->clients[$clientId]['session_id'] = $existingSession['session_id'];
                        $this->clients[$clientId]['channel_id'] = $channel['id'];
                        
                        // 更新最后活跃时间
                        $this->updateConnectionStatus($client);
                    }
                } catch (\Exception $e) {
                    $this->logError("检查活跃连接时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
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
        //$response .= "Connection: keep-alive\r\n"; 
        $response .= $content;
        
        @fwrite($client, $response);
    }
    
    private function proxyTS($client, $channel, $tsFile)
    {
        try {
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
            if (!preg_match('/' . preg_quote($tsBaseName, '/') . '.*\.ts[^\n]*/i', $content, $matches)) {
                $this->logError("在m3u8中未找到对应的TS文件: $tsFile", 'error', __FILE__, __LINE__);
                $this->removeClient($client);
                return;
            }

            $tsFullPath = $matches[0];
            $sourceUrl = dirname($channel['source_url']) . '/' . $tsFullPath;
            
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
            
            if (@fwrite($client, $response) === false) {
                @fclose($source);
                $this->removeClient($client);
                return;
            }
            
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
                
                // 更新带宽统计
                $this->updateBandwidthStats($channel['id'], strlen($data), $written);
                
                // 定期更新连接状态
                $clientId = (int)$client;
                if (isset($this->clients[$clientId]['session_id'])) {
                    $this->updateConnectionStatus($client);
                }
                
                usleep(1000);
            }
            
            // 清理
            @fclose($source);
            
            // 最后一次更新状态并移除客户端
            $clientId = (int)$client;
            if (isset($this->clients[$clientId]['session_id'])) {
                $this->updateConnectionStatus($client);
            }
            $this->removeClient($client);
            
        } catch (\Exception $e) {
            $this->logError("处理TS文件时发生错误: " . $e->getMessage(), 'error', __FILE__, __LINE__);
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
            @fclose($source);
            $this->removeClient($client);
        }
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
        
        // 在移除客户端之前，记录最后一次状态
        if (isset($this->clients[$clientId]['session_id'])) {
            $this->updateConnectionStatus($client);
        }
        
        // 移除客户端
        unset($this->clients[$clientId]);
        @fclose($client);
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
            $this->logError("错误堆栈: " . $e->getTraceAsString(), 'error', __FILE__, __LINE__);
            return false;
        }
    }

    private function updateBandwidthStats($channelId, $bytesReceived = 0, $bytesSent = 0)
    {
        try {
            $key = "proxy:stats:{$channelId}";
            $now = time();
            
            // 获取当前统计数据
            $stats = $this->redis->hGetAll($key) ?: [
                'bytes_received' => 0,
                'bytes_sent' => 0,
                'last_update' => $now,
                'last_reset' => $now
            ];
            
            // 将字符串转换为整数
            $currentReceived = intval($stats['bytes_received']);
            $currentSent = intval($stats['bytes_sent']);
            $lastUpdate = intval($stats['last_update'] ?? $now);
            $lastReset = intval($stats['last_reset'] ?? $now);
            
            // 累加新的字节数
            $currentReceived += $bytesReceived;
            $currentSent += $bytesSent;
            
            // 每秒重置一次计数
            if ($now - $lastReset >= 1) {
                // 计算每秒的带宽（转换为MB/s）
                $bandwidthReceived = $currentReceived / (1024 * 1024);  // 转换为MB
                $bandwidthSent = $currentSent / (1024 * 1024);         // 转换为MB
                
                // 更新统计数据
                $this->redis->hMSet($key, [
                    'bytes_received' => $bandwidthReceived,
                    'bytes_sent' => $bandwidthSent,
                    'last_update' => $now,
                    'last_reset' => $now
                ]);
                
                // 重置计数器
                $currentReceived = $bytesReceived;
                $currentSent = $bytesSent;
            } else {
                // 更新累计数据
                $this->redis->hMSet($key, [
                    'bytes_received' => $currentReceived,
                    'bytes_sent' => $currentSent,
                    'last_update' => $now
                ]);
            }
            
            // 设置过期时间
            $this->redis->expire($key, 60); // 1分钟过期
            
        } catch (\Exception $e) {
            // 记录错误但不中断程序
            $this->logError("更新带宽统计失败: " . $e->getMessage(), 'warning', __FILE__, __LINE__);
        }
    }

} 