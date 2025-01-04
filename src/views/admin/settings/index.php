<?php $currentPage = 'settings'; ?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - IPTV 代理系统</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php require __DIR__ . '/../../navbar.php'; ?>

    <div class="container-fluid mx-auto" style="width: 98%;">
        <div class="row">
            <div class="col-md-12">
                <h2>系统设置</h2>
                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?> mt-3">
                        <?= $_SESSION['flash_message']['message'] ?>
                    </div>
                    <?php unset($_SESSION['flash_message']); ?>
                <?php endif; ?>

                <form id="settingsForm" method="post" action="/admin/settings/save" class="mt-4">
                    <!-- 基本设置 -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">基本设置</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">站点名称</label>
                                <input type="text" class="form-control" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">最大错误次数</label>
                                <input type="number" class="form-control" name="max_error_count" value="<?= intval($settings['max_error_count'] ?? 3) ?>">
                                <small class="text-muted">频道检测失败达到此次数后将被自动删除</small>
                            </div>
                        </div>
                    </div>

                    <!-- 代理服务器设置 -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">代理服务器设置</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">监听地址</label>
                                <input type="text" class="form-control" name="proxy_host" value="<?= htmlspecialchars($settings['proxy_host'] ?? '0.0.0.0') ?>">
                                <small class="text-muted">默认 0.0.0.0 表示监听所有网卡</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">监听端口</label>
                                <input type="number" class="form-control" name="proxy_port" value="<?= intval($settings['proxy_port'] ?? 8081) ?>">
                                <small class="text-muted">建议使用大于 1024 的端口</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">连接超时时间（秒）</label>
                                <input type="number" class="form-control" name="proxy_timeout" value="<?= intval($settings['proxy_timeout'] ?? 10) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">缓冲区大小（字节）</label>
                                <input type="number" class="form-control" name="proxy_buffer_size" value="<?= intval($settings['proxy_buffer_size'] ?? 8192) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- 缓存设置 -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">缓存设置</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">缓存时间（秒）</label>
                                <input type="number" name="cache_time" class="form-control" value="<?= $settings['cache_time'] ?? 300 ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">分片大小（字节）</label>
                                <input type="number" name="chunk_size" class="form-control" value="<?= $settings['chunk_size'] ?? 1048576 ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Redis 设置 -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Redis 设置</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Redis 主机</label>
                                <input type="text" name="redis_host" class="form-control" value="<?= $settings['redis_host'] ?? '127.0.0.1' ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Redis 端口</label>
                                <input type="number" name="redis_port" class="form-control" value="<?= $settings['redis_port'] ?? 6379 ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Redis 密码</label>
                                <input type="password" name="redis_password" class="form-control" value="<?= $settings['redis_password'] ?? '' ?>">
                            </div>
                        </div>
                    </div>

                    <!-- 监控设置 -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">监控设置</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">实时监控刷新时间（秒）</label>
                                <input type="number" name="monitor_refresh_interval" class="form-control" value="<?= $settings['monitor_refresh_interval'] ?? 5 ?>">
                                <div class="form-text">设置监控页面自动刷新的时间间隔</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">服务状态检查间隔（秒）</label>
                                <input type="number" name="status_check_interval" class="form-control" value="<?= $settings['status_check_interval'] ?? 10 ?>">
                                <div class="form-text">设置检查代理服务运行状态的时间间隔</div>
                            </div>
                        </div>
                    </div>

                    <!-- 频道检查设置 -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">频道检查设置</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="check_mode" id="daily_check" 
                                           value="daily" <?= ($settings['check_mode'] ?? 'daily') === 'daily' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="daily_check">
                                        每天定时检查
                                    </label>
                                </div>
                                <div class="ms-4 mb-3" id="daily_time_container">
                                    <label class="form-label">检查时间</label>
                                    <input type="time" name="daily_check_time" class="form-control" 
                                           value="<?= $settings['daily_check_time'] ?? '03:00' ?>">
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="check_mode" id="interval_check" 
                                           value="interval" <?= ($settings['check_mode'] ?? 'daily') === 'interval' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="interval_check">
                                        间隔检查
                                    </label>
                                </div>
                                <div class="ms-4" id="interval_time_container">
                                    <label class="form-label">检查间隔（小时）</label>
                                    <input type="number" name="check_interval" class="form-control" 
                                           value="<?= $settings['check_interval'] ?? 6 ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">保存设置</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
	<?php require __DIR__ . '/../../footer.php'; ?>
    <script src="/css/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const dailyCheck = document.getElementById('daily_check');
        const intervalCheck = document.getElementById('interval_check');
        const dailyTimeContainer = document.getElementById('daily_time_container');
        const intervalTimeContainer = document.getElementById('interval_time_container');

        function updateVisibility() {
            dailyTimeContainer.style.display = dailyCheck.checked ? 'block' : 'none';
            intervalTimeContainer.style.display = intervalCheck.checked ? 'block' : 'none';
        }

        dailyCheck.addEventListener('change', updateVisibility);
        intervalCheck.addEventListener('change', updateVisibility);

        // 初始化显示状态
        updateVisibility();
    });

    // AJAX 提交表单
    document.getElementById('settingsForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }
        
        fetch('/admin/settings/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('设置已保存');
                location.reload();
            } else {
                alert(data.message || '保存失败');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('保存失败');
        });
    });
    </script>
</body>
</html> 