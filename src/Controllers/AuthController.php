<?php

namespace App\Controllers;

class AuthController
{
    private $basePath;

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 2);
    }

    /**
     * 显示登录页面
     */
    public function login()
    {
        // 如果已经登录，直接跳转到首页
        if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
            header('Location: /');
            exit;
        }

        // 显示登录页面
        require $this->basePath . '/src/views/login.php';
    }

    /**
     * 处理登录请求
     */
    public function handleLogin()
    {
        try {
            // 确保是 POST 请求
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new \Exception('Invalid request method');
            }

            // 设置响应头
            header('Content-Type: application/json');

            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                throw new \Exception('用户名和密码不能为空');
            }

            // 连接数据库
            $config = require $this->basePath . '/config/config.php';
            $dbConfig = $config['db'];
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 查询用户
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // 登录成功，设置session
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'login_time' => time()
                ];

                echo json_encode([
                    'success' => true,
                    'message' => '登录成功'
                ]);
                exit;
            } else {
                throw new \Exception('用户名或密码错误');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * 处理退出登录
     */
    public function logout()
    {
        // 清除所有session变量
        $_SESSION = array();

        // 如果使用了基于cookie的session，清除session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        // 销毁session
        session_destroy();

        // 重定向到登录页面
        header('Location: /login');
        exit;
    }

    /**
     * 检查是否已登录
     */
    public static function checkLogin()
    {
        if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                // AJAX 请求返回 JSON
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => '未登录或会话已过期'
                ]);
                exit;
            } else {
                // 普通请求重定向到登录页面
                header('Location: /login');
                exit;
            }
        }
    }
}