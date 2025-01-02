<?php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/install/Installer.php';

$installer = new Installer();

// 检查是否已安装
if ($installer->checkLock()) {
    header('Location: /login.php');
    exit;
}

// 处理安装请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $installer->install($_POST);
    header('Content-Type: application/json');
    
    if ($result['success']) {
        // 创建安装锁定文件
        $installer->createLock();
        // 清理安装文件
        $installer->cleanup();
        
        // 返回成功结果，包含跳转URL
        echo json_encode([
            'success' => true,
            'redirect' => '/login.php'
        ]);
    } else {
        echo json_encode($result);
    }
    exit;
}

// 显示安装页面
$requirements = $installer->checkRequirements();
$writableChecks = $installer->checkWritable();
require BASE_PATH . '/install/templates/install.php'; 