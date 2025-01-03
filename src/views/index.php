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
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title">总带宽使用</h5>
                                <p class="card-text">上行: - MB/s 下行: - MB/s</p>
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

    <script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html> 