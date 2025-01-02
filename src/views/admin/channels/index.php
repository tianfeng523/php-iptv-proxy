<?php
function buildUrl($params) 
{
    $current = array();
    if (isset($_GET)) {
        $current = $_GET;
    }
    
    if (is_array($params)) {
        foreach ($params as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
    }
    
    return '?' . http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>频道管理 - IPTV 代理系统</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
    <style>
    .btn-group-sm .btn {
        padding: 0.1rem 0.3rem;
        font-size: 0.75rem;
    }
    th {
        white-space: nowrap;
    }
    /* 表格单元格样式 */
    .table td {
        vertical-align: middle;
        white-space: nowrap;
    }
    
    /* URL列样式 */
    .url-cell {
        max-width: 250px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    /* 操作列样式 */
    .actions-cell {
        white-space: nowrap;
        width: 1%;  /* 让操作列宽度自适应内容 */
    }
    
    /* 确保表格横向滚动而不是换行 */
    .table-responsive {
        min-height: 300px;
    }
    </style>
</head>
<body>
    <?php $currentPage = 'channels'; ?>
    <?php require __DIR__ . '/../../navbar.php'; ?>

    <div class="container-fluid mx-auto" style="width: 98%;>
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2>频道管理</h2>
                    <div>
                        <select class="form-select form-select-sm d-inline-block me-2" style="width: auto;" onchange="filterByGroup(this.value)">
                            <option value="">所有分组</option>
                            <option value="0" <?php echo (isset($_GET['group_id']) && $_GET['group_id'] === '0' ? 'selected' : ''); ?>>未分组</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>" <?php echo (isset($_GET['group_id']) && $_GET['group_id'] == $group['id'] ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($group['name']); ?> (<?php echo $group['channel_count']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button onclick="checkSelected()" class="btn btn-warning btn-sm me-2">检查所选</button>
                        <button onclick="checkCurrentGroup()" class="btn btn-warning btn-sm me-2">检查当前分组</button>
                        <button onclick="checkAll()" class="btn btn-warning btn-sm me-2">检查全部</button>
                        <button onclick="deleteSelected()" class="btn btn-danger btn-sm me-2">删除所选</button>
                        <button onclick="deleteAll()" class="btn btn-danger btn-sm me-2">清空列表</button>
                        <a href="/admin/channels/import" class="btn btn-success btn-sm me-2">导入频道</a>
                        <a href="/admin/channels/add" class="btn btn-primary btn-sm">添加频道</a>
                    </div>
                </div>
                <?php
                if (isset($_SESSION['flash_message'])) {
                    $message = $_SESSION['flash_message'];
                    $alertClass = $message['type'] === 'success' ? 'alert-success' : 'alert-danger';
                    echo "<div class='alert {$alertClass} mt-3'>{$message['message']}</div>";
                    unset($_SESSION['flash_message']);
                }
                ?>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div class="d-flex align-items-center">
                        <span class="me-3">共 <?php echo $pagination['total']; ?> 条记录</span>
                        <select class="form-select form-select-sm" style="width: auto;" onchange="changePageSize(this.value)">
                            <option value="10" <?php echo (isset($_GET['per_page']) && $_GET['per_page'] == '10') ? 'selected' : ''; ?>>10条/页</option>
                            <option value="20" <?php echo (isset($_GET['per_page']) && $_GET['per_page'] == '20') ? 'selected' : ''; ?>>20条/页</option>
                            <option value="50" <?php echo (!isset($_GET['per_page']) || $_GET['per_page'] == '50') ? 'selected' : ''; ?>>50条/页</option>
                            <option value="100" <?php echo (isset($_GET['per_page']) && $_GET['per_page'] == '100') ? 'selected' : ''; ?>>100条/页</option>
                        </select>
                    </div>
                </div>
                
                <div class="table-responsive mt-4">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input" id="selectAll" onclick="toggleAll(this)">
                                </th>
                                <th style="width: 60px;">ID</th>
                                <th style="width: 150px;">名称</th>
                                <th style="width: 100px;">分组</th>
                                <th style="width: 250px;">源地址</th>
                                <th style="width: 250px;">代理地址</th>
                                <th style="width: 80px;">状态</th>
                                <th style="width: 80px;">延时</th>
                                <th style="width: 100px;">连接数</th>
                                <th style="width: 100px;">带宽</th>
                                <th style="width: 100px;">检查时间</th>
                                <th style="width: 100px;">异常次数</th>
                                <th class="actions-cell">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($channels as $channel): ?>
                            <tr id="channel-<?= $channel['id'] ?>">
                                <td>
                                    <input type="checkbox" class="channel-select" value="<?= $channel['id'] ?>">
                                </td>
                                <td><?= $channel['id'] ?></td>
                                <td><?= htmlspecialchars($channel['name']) ?></td>
                                <td><?= htmlspecialchars($channel['group_name'] ?? '未分组') ?></td>
                                <td class="url-cell" title="<?= htmlspecialchars($channel['source_url']) ?>">
                                    <?= htmlspecialchars($channel['source_url']) ?>
                                </td>
                                <td class="url-cell" title="<?= $channel['proxy_url'] ? htmlspecialchars($channel['proxy_url']) : '未生成代理地址' ?>">
                                    <?= $channel['proxy_url'] ? htmlspecialchars($channel['proxy_url']) : '<span class="text-muted">未生成代理地址</span>' ?>
                                </td>
                                <td class="status-icon">
                                    <span class="badge bg-<?php 
                                        if ($channel['status'] === 'active') echo 'success';
                                        else if ($channel['status'] === 'error') echo 'danger';
                                        else echo 'secondary';
                                    ?>">
                                        <?php 
                                        if ($channel['status'] === 'active') echo '正常';
                                        else if ($channel['status'] === 'error') echo '异常';
                                        else echo '未启用';
                                        ?>
                                    </span>
                                </td>
                                <td class="latency-cell">
                                    <?= $channel['status'] === 'active' ? $channel['latency'].'ms' : '-' ?>
                                </td>
                                <td><?= $channel['connections'] ?? 0 ?></td>
                                <td><?= isset($channel['bandwidth']) ? number_format($channel['bandwidth'] / 1024 / 1024, 2) : '0' ?> MB/s</td>
                                <td class="checked-at-cell"><?= $channel['checked_at'] ? date('Y-m-d H:i:s', strtotime($channel['checked_at'])) : '-' ?></td>
                                <td class="error-count-cell">
                                    <?php if ($channel['status'] === 'error'): ?>
                                        <span class="badge bg-warning"><?= $channel['error_count'] ?></span>
                                    <?php else: ?>
                                        <span>0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-primary" onclick="checkChannel(<?= $channel['id'] ?>)">检查</button>
                                        <a href="/admin/channels/edit/<?= $channel['id'] ?>" class="btn btn-secondary">编辑</a>
                                        <button type="button" class="btn btn-danger" onclick="deleteChannel(<?= $channel['id'] ?>)">删除</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (isset($pagination) && isset($pagination['totalPages']) && $pagination['totalPages'] > 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php
                        $current = isset($pagination['current']) ? (int)$pagination['current'] : 1;
                        $total = isset($pagination['totalPages']) ? (int)$pagination['totalPages'] : 1;
                        ?>
                        <li class="page-item <?php echo ($current == 1 ? 'disabled' : ''); ?>">
                            <a class="page-link" href="<?php echo buildUrl(array('page' => 1)); ?>">首页</a>
                        </li>
                        <li class="page-item <?php echo ($current == 1 ? 'disabled' : ''); ?>">
                            <a class="page-link" href="<?php echo buildUrl(array('page' => $current - 1)); ?>">上一页</a>
                        </li>
                        <?php
                        $start = max(1, $current - 2);
                        $end = min($total, $current + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                        <li class="page-item <?php echo ($i == $current ? 'active' : ''); ?>">
                            <a class="page-link" href="<?php echo buildUrl(array('page' => $i)); ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($current == $total ? 'disabled' : ''); ?>">
                            <a class="page-link" href="<?php echo buildUrl(array('page' => $current + 1)); ?>">下一页</a>
                        </li>
                        <li class="page-item <?php echo ($current == $total ? 'disabled' : ''); ?>">
                            <a class="page-link" href="<?php echo buildUrl(array('page' => $total)); ?>">末页</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="checkProgressModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">检查进度</h5>
                </div>
                <div class="modal-body">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <div id="checkStatus" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
    let checkModal;
    let currentCheckTask = null;

    document.addEventListener('DOMContentLoaded', function() {
        checkModal = new bootstrap.Modal(document.getElementById('checkProgressModal'));
    });

    function updateProgress(progress, status) {
        const progressBar = document.querySelector('#checkProgressModal .progress-bar');
        const statusDiv = document.getElementById('checkStatus');
        progressBar.style.width = progress + '%';
        progressBar.textContent = progress + '%';
        if (status) {
            statusDiv.textContent = status;
        }
    }

    function startCheckTask(url, ids = null) {
        checkModal.show();
        updateProgress(0, '正在开始检查...');

        const data = ids ? { ids } : {};
        
        // 开始检查任务
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.task_id) {
                currentCheckTask = data.task_id;
                pollCheckProgress(data.task_id);
            }
        });
    }

    function pollCheckProgress(taskId) {
        fetch(`/admin/channels/check-progress/${taskId}`)
        .then(response => response.json())
        .then(data => {
            updateProgress(data.progress, data.status);
            
            if (data.progress < 100 && currentCheckTask === taskId) {
                setTimeout(() => pollCheckProgress(taskId), 1000);
            } else {
                setTimeout(() => {
                    checkModal.hide();
                    location.reload();
                }, 1000);
            }
        });
    }

    function buildQueryString(params) {
        const current = new URLSearchParams(window.location.search);
        Object.keys(params).forEach(key => {
            current.delete(key);
        });

        Object.entries(params).forEach(([key, value]) => {
            if (value === null || value === '') {
                current.delete(key);
            } else {
                current.set(key, value);
            }
        });
        return current.toString();
    }

    function changePageSize(perPage) {
        window.location.href = '?' + buildQueryString({
            'per_page': perPage,
            'page': 1
        });
    }

    function filterByGroup(groupId) {
        window.location.href = '?' + buildQueryString({
            'group_id': groupId,
            'page': 1
        });
    }

    function toggleAll(checkbox) {
        document.querySelectorAll('.channel-select').forEach(item => {
            item.checked = checkbox.checked;
        });
    }

    function deleteSelected() {
        const selected = Array.from(document.querySelectorAll('.channel-select:checked'))
            .map(checkbox => checkbox.value);

        if (selected.length === 0) {
            alert('请先选择要删除的频道');
            return;
        }

        if (confirm(`确定要删除选中的 ${selected.length} 个频道吗？`)) {
            fetch('/admin/channels/delete-multiple', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ids: selected })
            }).then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '删除失败');
                }
            });
        }
    }

    function deleteAll() {
        if (confirm('确定要清空所有频道吗？此操作不可恢复！')) {
            fetch('/admin/channels/delete-all', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data && data.success) {
                    location.reload();
                } else {
                    alert(data.message || '删除失败');
                }
            })
            .catch(error => {
                console.error('删除失败:', error);
                alert('删除失败，请查看控制台了解详情');
            });
        }
    }

    function deleteChannel(id) {
        if (confirm('确定要删除这个频道吗？')) {
            fetch(`/admin/channels/delete/${id}`, {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data && data.success) {
                    location.reload();
                } else {
                    alert(data.message || '删除失败');
                }
            })
            .catch(error => {
                console.error('删除失败:', error);
                alert('删除失败，请查看控制台了解详情');
            });
        }
    }

    function checkChannel(id) {
        fetch(`/admin/channels/check/${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 更新状态图标
                    const statusIcon = document.querySelector(`#channel-${id} .status-icon`);
                    if (statusIcon) {
                        let badgeClass = 'secondary';
                        let statusText = '未启用';
                        
                        if (data.status === 'active') {
                            badgeClass = 'success';
                            statusText = '正常';
                        } else if (data.status === 'error') {
                            badgeClass = 'danger';
                            statusText = '异常';
                        }
                        
                        statusIcon.innerHTML = `<span class="badge bg-${badgeClass}">${statusText}</span>`;
                    }
                    
                    // 更新延迟
                    const latencyCell = document.querySelector(`#channel-${id} .latency-cell`);
                    if (latencyCell) {
                        latencyCell.textContent = data.status === 'active' ? `${data.latency}ms` : '-';
                    }
                    
                    // 更新错误次数
                    const errorCountCell = document.querySelector(`#channel-${id} .error-count-cell`);
                    if (errorCountCell) {
                        if (data.status === 'error') {
                            errorCountCell.innerHTML = `<span class="badge bg-warning">${data.error_count}</span>`;
                        } else {
                            errorCountCell.innerHTML = '<span>0</span>';
                        }
                    }
                    
                    // 更新检查时间
                    const checkedAtCell = document.querySelector(`#channel-${id} .checked-at-cell`);
                    if (checkedAtCell) {
                        const now = new Date();
                        checkedAtCell.textContent = now.toLocaleString('zh-CN', {
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: false
                        });
                    }

                    // 如果频道被删除，从列表中移除该行
                    if (data.deleted) {
                        const row = document.querySelector(`#channel-${id}`);
                        if (row) {
                            row.remove();
                            // 显示删除提示
                            showAlert('success', data.message);
                        }
                    }
                } else {
                    showAlert('danger', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', '检查失败，请稍后重试');
            });
    }

    function showAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.role = 'alert';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        const container = document.querySelector('.container');
        container.insertBefore(alertDiv, container.firstChild);
        
        // 5秒后自动关闭
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }

    function checkSelected() {
        const selectedCheckboxes = document.querySelectorAll('.channel-select:checked');
        const selectedIds = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
        
        if (selectedIds.length === 0) {
            showAlert('warning', '请先选择要检查的频道');
            return;
        }
        
        // 显示进度条
        const progressBar = document.querySelector('#checkProgressModal .progress-bar');
        const progressDiv = document.querySelector('#checkProgressModal');
        checkModal.show();
        let completed = 0;
        
        // 逐个检查选中的频道
        selectedIds.forEach((id, index) => {
            setTimeout(() => {
                fetch(`/admin/channels/check/${id}`)
                    .then(response => response.json())
                    .then(data => {
                        completed++;
                        // 更新进度条
                        const progress = Math.round((completed / selectedIds.length) * 100);
                        progressBar.style.width = `${progress}%`;
                        progressBar.textContent = `${progress}%`;
                        document.getElementById('checkStatus').textContent = `正在检查：${completed}/${selectedIds.length}`;
                        
                        // 更新频道状态
                        if (data.success) {
                            updateChannelRow(id, data);
                        }
                        
                        // 检查是否完成所有频道
                        if (completed === selectedIds.length) {
                            setTimeout(() => {
                                checkModal.hide();
                                showAlert('success', '所选频道检查完成');
                            }, 1000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        completed++;
                        // 即使出错也更新进度
                        const progress = Math.round((completed / selectedIds.length) * 100);
                        progressBar.style.width = `${progress}%`;
                        progressBar.textContent = `${progress}%`;
                    });
            }, index * 200); // 每个请求间隔200ms
        });
    }

    function updateChannelRow(id, data) {
        const row = document.querySelector(`#channel-${id}`);
        if (!row) return;
        
        // 更新状态
        const statusIcon = row.querySelector('.status-icon');
        if (statusIcon) {
            let badgeClass = 'secondary';
            let statusText = '未启用';
            
            if (data.status === 'active') {
                badgeClass = 'success';
                statusText = '正常';
            } else if (data.status === 'error') {
                badgeClass = 'danger';
                statusText = '异常';
            }
            
            statusIcon.innerHTML = `<span class="badge bg-${badgeClass}">${statusText}</span>`;
        }
        
        // 更新延迟
        const latencyCell = row.querySelector('.latency-cell');
        if (latencyCell) {
            latencyCell.textContent = data.status === 'active' ? `${data.latency}ms` : '-';
        }
        
        // 更新错误次数
        const errorCountCell = row.querySelector('.error-count-cell');
        if (errorCountCell) {
            if (data.status === 'error') {
                errorCountCell.innerHTML = `<span class="badge bg-warning">${data.error_count}</span>`;
            } else {
                errorCountCell.innerHTML = '<span>0</span>';
            }
        }
        
        // 更新检查时间
        const checkedAtCell = row.querySelector('.checked-at-cell');
        if (checkedAtCell) {
            const now = new Date();
            checkedAtCell.textContent = now.toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });
        }
        
        // 如果频道被删除，从列表中移除该行
        if (data.deleted) {
            row.remove();
        }
    }

    function checkCurrentGroup() {
        const urlParams = new URLSearchParams(window.location.search);
        const groupId = urlParams.get('group_id');
        
        startCheckTask('/admin/channels/check-all' + (groupId ? `?group_id=${groupId}` : ''));
    }

    function checkAll() {
        startCheckTask('/admin/channels/check-all');
    }
    </script>
</body>
</html> 