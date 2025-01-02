<?php
namespace App\Controllers;

class AuthController
{
    public function login()
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => '无效的请求方法']);
            exit;
        }

        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        try {
            // 获取数据库配置
            $config = require BASE_PATH . '/config/config.php';
            
            // 连接数据库
            $dsn = "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['dbname']}";
            $pdo = new \PDO($dsn, $config['db']['username'], $config['db']['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 查询用户
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                echo json_encode(['success' => false, 'error' => '用户名或密码错误']);
                exit;
            }

            // 启动会话
            session_start();
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username']
            ];

            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => '登录失败：' . $e->getMessage()]);
        }
        exit;
    }

    public function logout()
    {
        session_start();
        session_destroy();
        header('Location: /login.php');
        exit;
    }
} 