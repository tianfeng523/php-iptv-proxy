<?php $currentPage = 'index'; ?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首页 - IPTV 代理系统</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php require __DIR__ . '/../../navbar.php'; ?>

    <div class="container-fluid mx-auto" style="width: 98%;">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">代理服务器控制</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <button id="proxyControl" class="btn btn-primary" onclick="toggleProxy()">
                                    <i class="fas fa-spinner fa-spin d-none"></i>
                                    <span>正在加载...</span>
                                </button>
                                <div id="proxyStatus" class="mt-2">
                                    <span class="text-secondary">
                                        <i class="fas fa-circle-notch fa-spin"></i> 正在获取状态...
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
    let proxyStatus = false;
    
    // 显示提示信息
    function showAlert(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        // 插入到页面顶部
        document.querySelector('.container-fluid').insertBefore(alertDiv, document.querySelector('.container-fluid').firstChild);
        
        // 5秒后自动消失
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
    
    // 更新按钮和状态显示
    function updateProxyStatus(running, details = null) {
        const button = document.getElementById('proxyControl');
        const spinner = button.querySelector('.fa-spinner');
        const text = button.querySelector('span');
        const statusDiv = document.getElementById('proxyStatus');
        
        proxyStatus = running;
        
        // 更新按钮状态
        if (running) {
            button.className = 'btn btn-success';
            text.textContent = '停止服务';
            statusDiv.innerHTML = `
                <span class="text-success">
                    <i class="fas fa-circle"></i> 运行中
                </span>
            `;
            
            // 显示详细信息
            if (details) {
                let uptimeText = '';
                if (details.uptime !== null) {
                    const hours = Math.floor(details.uptime / 3600);
                    const minutes = Math.floor((details.uptime % 3600) / 60);
                    const seconds = details.uptime % 60;
                    uptimeText = `${hours}小时${minutes}分${seconds}秒`;
                }
                
                let memoryText = '';
                if (details.memory !== null) {
                    memoryText = (details.memory / (1024 * 1024)).toFixed(2) + ' MB';
                }
                
                statusDiv.innerHTML += `
                    <div class="small text-muted mt-2">
                        <div><i class="fas fa-microchip"></i> PID: ${details.pid || '未知'}</div>
                        <div><i class="fas fa-clock"></i> 运行时间: ${uptimeText || '未知'}</div>
                        <div><i class="fas fa-memory"></i> 内存使用: ${memoryText || '未知'}</div>
                        <div><i class="fas fa-network-wired"></i> 当前连接数: ${details.connections || 0}</div>
                        <div><i class="fas fa-plug"></i> 监听端口: ${details.port || '9260'}</div>
                    </div>
                `;
            }
        } else {
            button.className = 'btn btn-danger';
            text.textContent = '启动服务';
            statusDiv.innerHTML = `
                <span class="text-danger">
                    <i class="fas fa-circle"></i> 已停止
                </span>
            `;
        }
        
        button.disabled = false;
        spinner.classList.add('d-none');
    }
    
    // 获取代理服务器状态
    async function checkProxyStatus() {
        try {
            const response = await fetch('/admin/proxy/status');
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const text = await response.text(); // 先获取响应文本
            console.log('Status response:', text); // 调试输出
            
            try {
                const result = JSON.parse(text);
                console.log('Parsed status:', result); // 调试输出
                
                if (result.success && result.data) {
                    // 更新全局状态变量
                    proxyStatus = result.data.running;
                    console.log('Updated proxyStatus:', proxyStatus); // 调试输出
                    
                    // 更新界面显示
                    updateProxyStatus(result.data.running, result.data);
                } else {
                    console.error('Invalid status response:', result);
                    updateProxyStatus(false);
                    showAlert('获取状态失败: ' + (result.message || '未知错误'), 'warning');
                }
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response text:', text);
                updateProxyStatus(false);
                showAlert('解析服务器响应失败', 'error');
            }
        } catch (error) {
            console.error('Fetch error:', error);
            updateProxyStatus(false);
            showAlert('无法连接到服务器', 'error');
        }
    }
    
    // 切换代理服务器状态
    async function toggleProxy() {
        const button = document.getElementById('proxyControl');
        const spinner = button.querySelector('.fa-spinner');
        
        // 禁用按钮，显示加载动画
        button.disabled = true;
        spinner.classList.remove('d-none');
        
        try {
            // 根据当前状态决定是启动还是停止
            const action = proxyStatus ? 'stop' : 'start';
            console.log('Executing action:', action); // 调试输出
            
            const response = await fetch('/admin/proxy/' + action, {
                method: 'POST'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const text = await response.text();
            console.log('Action response:', text); // 调试输出
            
            try {
                const result = JSON.parse(text);
                console.log('Parsed result:', result); // 调试输出
                
                if (result.success) {
                    // 等待一秒后再检查状态，给服务器一些时间来启动/停止
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    await checkProxyStatus();
                    showAlert(result.message, 'success');
                } else {
                    // 如果操作失败，重新检查状态
                    await checkProxyStatus();
                    showAlert(result.message || '操作失败', 'warning');
                }
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response text:', text);
                // 发生错误时也要重新检查状态
                await checkProxyStatus();
                showAlert('解析服务器响应失败', 'error');
            }
        } catch (error) {
            console.error('Fetch error:', error);
            // 发生错误时也要重新检查状态
            await checkProxyStatus();
            showAlert('操作失败: ' + error.message, 'error');
        } finally {
            // 恢复按钮状态
            button.disabled = false;
            spinner.classList.add('d-none');
        }
    }
    
    // 页面加载时获取状态
    document.addEventListener('DOMContentLoaded', () => {
        console.log('Page loaded, checking initial status'); // 调试输出
        checkProxyStatus();
    });
    
    // 定期刷新状态（每5秒）
    setInterval(() => {
        console.log('Periodic status check'); // 调试输出
        checkProxyStatus();
    }, 5000);
    </script>
</body>
</html> 