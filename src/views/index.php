<?php
$currentPage = 'home';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV 代理系统</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .stats-card {
            height: 100%;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .stats-card .card-body {
            padding: 1rem;
        }
        .stats-card .card-title {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .stats-card .card-text {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0;
        }
        .system-status {
            font-size: 0.85rem;
        }
        .system-status .progress {
            height: 8px;
        }
        /* 代理开关样式 */
        .proxy-switch {
            transition: all 0.3s ease;
        }
        .proxy-switch:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .proxy-switch .proxy-status {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .proxy-switch .fa-power-off {
            transition: all 0.3s ease;
            margin-bottom: 0.5rem;
        }
        .proxy-switch.active {
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .proxy-switch.active .fa-power-off {
            color: #28a745;
        }
        .proxy-switch.inactive {
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .proxy-switch.inactive .fa-power-off {
            color: #dc3545;
        }
        .proxy-switch.checking {
            background-color: #fff3cd;
            border-color: #ffeeba;
        }
        .proxy-switch.checking .fa-power-off {
            color: #ffc107;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <!-- 频道统计和系统状态 -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">总频道数</h5>
                                <p class="card-text"><?= $channelStats['total_channels'] ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">活跃频道</h5>
                                <p class="card-text"><?= $channelStats['active_channels'] ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">错误频道</h5>
                                <p class="card-text"><?= $channelStats['error_channels'] ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">总连接数</h5>
                                <p class="card-text">-</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">总带宽</h5>
                                <p class="card-text"> - MB/s</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card proxy-switch" id="proxySwitch" style="cursor: pointer;" onclick="toggleProxy()">
                            <div class="card-body text-center">
                                <h5 class="card-title">代理服务</h5>
                                <div class="proxy-status">
                                    <i class="fas fa-power-off fa-2x"></i>
                                    <p class="card-text mt-2" id="proxyStatusText">检查中...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 系统状态 -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card system-status">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-2">
                                            <span>CPU使用率: <?= $systemStatus['cpu'] ?>%</span>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" style="width: <?= $systemStatus['cpu'] ?>%" aria-valuenow="<?= $systemStatus['cpu'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-2">
                                            <span>内存使用: <?= $systemStatus['memory']['percentage'] ?>%</span>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" style="width: <?= $systemStatus['memory']['percentage'] ?>%" aria-valuenow="<?= $systemStatus['memory']['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-2">
                                            <span>磁盘使用: <?= $systemStatus['disk']['percentage'] ?>%</span>
                                            <div class="progress">
                                                <div class="progress-bar" role="progressbar" style="width: <?= $systemStatus['disk']['percentage'] ?>%" aria-valuenow="<?= $systemStatus['disk']['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-2">
                                            <span>负载: <?= $systemStatus['load']['1min'] ?> / <?= $systemStatus['load']['5min'] ?> / <?= $systemStatus['load']['15min'] ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 快捷操作卡片 -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">频道管理</h5>
                                <p class="card-text">添加、编辑、删除频道，管理频道分组。</p>
                                <a href="/admin/channels" class="btn btn-primary">进入管理</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">导入频道</h5>
                                <p class="card-text">从 M3U/M3U8 文件批量导入频道。</p>
                                <a href="/admin/import" class="btn btn-success">开始导入</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">实时监控</h5>
                                <p class="card-text">监控频道状态、带宽和连接数。</p>
                                <a href="/admin/monitor" class="btn btn-info">查看监控</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
	<?php require __DIR__ . '/footer.php'; ?>
    <script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
    // 添加全局状态变量
    let isProxyRunning = false;
    let statusCheckInterval = null; // 用于存储定时检查的interval ID
    let stoppedConfirmCount = 0;    // 用于记录确认停止的次数
    let checkIntervalTime = <?= intval($settings['status_check_interval'] ?? 10) ?> * 1000; // 从PHP设置中获取检查间隔
    
    // 检查代理状态
    function checkProxyStatus() {
        const proxySwitch = document.getElementById('proxySwitch');
        const statusText = document.getElementById('proxyStatusText');
        
        proxySwitch.className = 'card stats-card proxy-switch checking';
        statusText.textContent = '检查中...';
        
        fetch('/admin/proxy/status')
            .then(response => response.json())
            .then(result => {
                console.log('Status response:', result); // 调试输出
                if (result.success && result.data && result.data.running) {
                    isProxyRunning = true; // 更新全局状态
                    stoppedConfirmCount = 0; // 重置停止确认计数
                    proxySwitch.className = 'card stats-card proxy-switch active';
                    statusText.textContent = '运行中';
                } else {
                    isProxyRunning = false; // 更新全局状态
                    proxySwitch.className = 'card stats-card proxy-switch inactive';
                    statusText.textContent = '已停止';
                    
                    // 如果确认是停止状态，增加计数
                    stoppedConfirmCount++;
                    console.log('停止状态确认次数:', stoppedConfirmCount);
                    
                    // 如果连续3次确认是停止状态，清除定时检查
                    if (stoppedConfirmCount >= 3 && statusCheckInterval) {
                        console.log('已确认停止状态，停止定时检查');
                        clearInterval(statusCheckInterval);
                        statusCheckInterval = null;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                isProxyRunning = false; // 更新全局状态
                proxySwitch.className = 'card stats-card proxy-switch inactive';
                statusText.textContent = '检查失败';
            });
    }

    // 切换代理状态
    function toggleProxy() {
        const proxySwitch = document.getElementById('proxySwitch');
        const statusText = document.getElementById('proxyStatusText');
        
        proxySwitch.className = 'card stats-card proxy-switch checking';
        statusText.textContent = '处理中...';
        
        // 使用全局状态变量来判断当前状态
        const action = isProxyRunning ? 'stop' : 'start';
        console.log('Current proxy status:', isProxyRunning ? 'running' : 'stopped');
        console.log('Executing action:', action);
        
        fetch(`/admin/proxy/${action}`, {
            method: 'POST'
        })
        .then(response => response.json())
        .then(result => {
            console.log('Action response:', result);
            if (result.success) {
                // 重置停止确认计数
                stoppedConfirmCount = 0;
                
                // 清除现有的定时检查
                if (statusCheckInterval) {
                    clearInterval(statusCheckInterval);
                    statusCheckInterval = null;
                }
                
                // 等待更长时间后开始检查状态
                setTimeout(() => {
                    // 开始定时检查状态
                    statusCheckInterval = setInterval(() => {
                        checkProxyStatus();
                    }, checkIntervalTime); // 使用设置的检查间隔
                    // 立即执行一次检查
                    checkProxyStatus();
                }, 2000); // 等待2秒后开始检查
            } else {
                throw new Error(result.message || '操作失败');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            checkProxyStatus(); // 发生错误时重新检查状态
            alert(error.message || '操作失败，请重试');
        });
    }

    // 页面加载时检查状态
    document.addEventListener('DOMContentLoaded', function() {
        checkProxyStatus();
        
        // 开始定时检查
        if (!statusCheckInterval) {
            statusCheckInterval = setInterval(checkProxyStatus, checkIntervalTime);
        }
    });
    </script>
</body>
</html> 