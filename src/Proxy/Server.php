<?php
namespace App\Proxy;

use App\Models\Channel;
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
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->config = Config::getInstance();
        $this->loadChannels();
    }
    
    private function loadChannels()
    {
        $channelModel = new Channel();
        $result = $channelModel->getChannelList(1, 1000); // 获取前1000个频道
        $this->channels = $result['channels'];
    }
    
    public function start()
    {
        if ($this->isRunning) {
            $this->logger->error("代理服务器已经在运行中");
            return false;
        }
        
        try {
            // 创建 TCP 服务器
            $address = $this->config->get('proxy_host', '0.0.0.0');
            $port = $this->config->get('proxy_port', 8080);
            
            $this->logger->info("正在启动代理服务器...");
            $this->logger->info("配置信息: 地址=$address, 端口=$port");
            
            // 尝试创建服务器 socket
            $this->socket = @stream_socket_server("tcp://$address:$port", $errno, $errstr);
            if (!$this->socket) {
                $this->logger->error("无法启动代理服务器: $errstr ($errno)");
                if ($errno == 98 || $errno == 10048) { // Linux 和 Windows 的端口占用错误码
                    $this->logger->error("端口 $port 已被占用，请检查是否有其他实例在运行");
                }
                return false;
            }
            
            // 设置 socket 选项
            if (function_exists('socket_set_option')) {
                $socket = socket_import_stream($this->socket);
                if ($socket) {
                    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
                    $this->logger->info("已设置 socket 选项: SO_REUSEADDR");
                }
            }
            
            // 设置非阻塞模式
            stream_set_blocking($this->socket, false);
            $this->logger->info("已设置非阻塞模式");
            
            // 加载频道信息
            $this->loadChannels();
            $this->logger->info("已加载频道列表，共 " . count($this->channels) . " 个频道");
            
            $this->isRunning = true;
            $this->logger->info("代理服务器已启动 - 监听 $address:$port");
            
            // 注册信号处理器
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGTERM, function($signo) {
                    $this->logger->info("收到 SIGTERM 信号，准备停止服务器");
                    $this->stop();
                });
                pcntl_signal(SIGINT, function($signo) {
                    $this->logger->info("收到 SIGINT 信号，准备停止服务器");
                    $this->stop();
                });
                $this->logger->info("已注册信号处理器");
            }
            
            // 主循环
            $lastCheck = time();
            $checkInterval = 60; // 每分钟检查一次
            
            $this->logger->info("进入主循环");
            while ($this->isRunning) {
                try {
                    // 处理信号
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                    
                    // 处理连接
                    $this->processConnections();
                    
                    // 定期检查和日志记录
                    $now = time();
                    if ($now - $lastCheck >= $checkInterval) {
                        $this->logger->info("服务器运行状态: " . 
                            "客户端数=" . count($this->clients) . ", " .
                            "频道数=" . count($this->channels));
                        $lastCheck = $now;
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error("主循环中发生错误: " . $e->getMessage());
                    $this->logger->error("错误堆栈: " . $e->getTraceAsString());
                }
                
                // 避免 CPU 占用过高
                usleep(10000); // 10ms 延迟
            }
            
            $this->logger->info("主循环结束");
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("启动代理服务器时发生错误: " . $e->getMessage());
            $this->logger->error("错误堆栈: " . $e->getTraceAsString());
            $this->stop();
            return false;
        }
    }
    
    private function processConnections()
    {
        try {
            // 准备 socket 数组用于 select
            $read = $this->clients;
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
                        $this->clients[] = $client;
                        $clientId = (int)$client;
                        $clientIp = stream_socket_get_name($client, true);
                        $this->logger->info("新客户端连接 (ID: $clientId, IP: $clientIp)");
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
                        $this->logger->error("处理客户端请求时发生错误 (ID: $clientId): " . $e->getMessage());
                        $this->logger->error("错误堆栈: " . $e->getTraceAsString());
                        $this->removeClient($client);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("处理连接时发生错误: " . $e->getMessage());
            $this->logger->error("错误堆栈: " . $e->getTraceAsString());
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
        
        $this->logger->info("收到请求: $method $path");
        
        // 从路径中提取频道ID和请求类型
        if (preg_match('/^\/proxy\/(ch_[^\/]+)\/([^\/]+)$/', $path, $matches)) {
            $channelId = $matches[1];
            $requestFile = $matches[2];
            $this->logger->info("提取到频道ID: $channelId, 请求文件: $requestFile");
        } else {
            $this->logger->error("无效的URL格式: $path");
            $response = "HTTP/1.1 404 Not Found\r\n";
            $response .= "Content-Type: text/plain\r\n";
            $response .= "Connection: close\r\n\r\n";
            $response .= "Invalid channel URL format";
            @fwrite($client, $response);
            $this->removeClient($client);
            return;
        }
        
        // 查找对应的频道
        $channel = $this->findChannel($channelId);
        
        if (!$channel) {
            $this->logger->error("未找到频道: $channelId");
            $response = "HTTP/1.1 404 Not Found\r\n";
            $response .= "Content-Type: text/plain\r\n";
            $response .= "Connection: close\r\n\r\n";
            $response .= "Channel not found";
            @fwrite($client, $response);
            $this->removeClient($client);
            return;
        }
        
        $this->logger->info("找到频道: {$channel['name']} (ID: {$channel['id']})");
        
        // 根据请求类型选择不同的处理方式
        if ($requestFile === 'stream.m3u8') {
            $this->proxyM3U8($client, $channel);
        } else if (preg_match('/\.ts$/', $requestFile)) {
            $this->proxyTS($client, $channel, $requestFile);
        } else {
            $this->logger->error("不支持的文件类型: $requestFile");
            $response = "HTTP/1.1 400 Bad Request\r\n";
            $response .= "Content-Type: text/plain\r\n";
            $response .= "Connection: close\r\n\r\n";
            $response .= "Unsupported file type";
            @fwrite($client, $response);
            $this->removeClient($client);
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
            $this->logger->error("无法获取m3u8内容: " . $channel['source_url']);
            $this->removeClient($client);
            return;
        }
        
        // 输出原始m3u8内容中的TS地址
        preg_match_all('/^(.*\.ts.*)$/m', $content, $matches);
        if (!empty($matches[0])) {
            $this->logger->info("原始m3u8中的TS地址: " . implode(', ', $matches[0]));
        }
        
        // 修改m3u8内容中的ts文件路径
        $baseUrl = dirname($channel['proxy_url']);
        $content = preg_replace_callback('/^(.*\.ts.*)$/m', function($matches) use ($baseUrl) {
            $tsFile = basename($matches[0]);
            $proxyPath = $baseUrl . '/' . $tsFile;
            $this->logger->info("转换后的TS地址: " . $proxyPath);
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
        $this->removeClient($client);
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
            $this->logger->error("无法获取m3u8内容: " . $channel['source_url']);
            $this->removeClient($client);
            return;
        }
        
        // 在原始m3u8内容中查找完整的ts文件URL
        $tsBaseName = pathinfo($tsFile, PATHINFO_FILENAME);
        if (preg_match('/' . preg_quote($tsBaseName, '/') . '.*\.ts[^\n]*/i', $content, $matches)) {
            $tsFullPath = $matches[0];
            $sourceUrl = dirname($channel['source_url']) . '/' . $tsFullPath;
            $this->logger->info("尝试访问的TS文件URL（带参数）: " . $sourceUrl);
            
            // 打开源流
            $source = @fopen($sourceUrl, 'r', false, $context);
            if (!$source) {
                $this->logger->error("无法打开TS文件: $sourceUrl");
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
            
            // 转发流内容
            while (!feof($source) && $this->isRunning) {
                $data = @fread($source, 8192);
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
            $this->logger->error("在m3u8中未找到对应的TS文件: $tsFile");
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
        $this->logger->info("客户端断开连接 (ID: $clientId)");
        @fclose($client);
        $index = array_search($client, $this->clients);
        if ($index !== false) {
            unset($this->clients[$index]);
        }
    }
    
    public function stop()
    {
        $this->logger->info("正在停止代理服务器...");
        
        $this->isRunning = false;
        
        // 关闭所有客户端连接
        $clientCount = count($this->clients);
        foreach ($this->clients as $client) {
            $this->removeClient($client);
        }
        $this->clients = [];
        $this->logger->info("已关闭 $clientCount 个客户端连接");
        
        // 关闭服务器 socket
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
            $this->logger->info("已关闭服务器 socket");
        }
        
        $this->logger->info("代理服务器已停止");
        return true;
    }
    
    public function isRunning()
    {
        return $this->isRunning;
    }
    
    public function getStatus()
    {
        return [
            'running' => $this->isRunning,
            'clients' => count($this->clients),
            'channels' => count($this->channels)
        ];
    }
} 