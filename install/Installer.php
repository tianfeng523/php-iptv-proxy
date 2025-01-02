<?php
class Installer
{
    private $requirements = [
        'php' => '7.4.0',
        'extensions' => [
            'pdo',
            'pdo_mysql',
            'redis',
            'curl',
            'json',
            'mbstring'
        ]
    ];

    // 检查是否已安装
    public function checkLock()
    {
        return file_exists(BASE_PATH . '/storage/installed.lock');
    }

    // 创建安装锁定文件
    public function createLock()
    {
        if (!is_dir(BASE_PATH . '/storage')) {
            mkdir(BASE_PATH . '/storage', 0755, true);
        }
        return file_put_contents(
            BASE_PATH . '/storage/installed.lock',
            date('Y-m-d H:i:s')
        );
    }

    // 清理安装文件
    public function cleanup()
    {
        $files = [
            BASE_PATH . '/install/install.php',
            BASE_PATH . '/install/database.sql',
            BASE_PATH . '/install/templates/install.php'
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // 删除安装目录
        @rmdir(BASE_PATH . '/install/templates');
        @rmdir(BASE_PATH . '/install');

        return true;
    }

    public function checkRequirements()
    {
        $results = [
            'php' => [
                'required' => $this->requirements['php'],
                'current' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, $this->requirements['php'], '>=')
            ],
            'extensions' => []
        ];

        foreach ($this->requirements['extensions'] as $ext) {
            $results['extensions'][$ext] = [
                'status' => extension_loaded($ext),
                'current' => extension_loaded($ext) ? phpversion($ext) : '未安装'
            ];
        }

        return $results;
    }

    public function checkWritable()
    {
        $paths = [
            BASE_PATH . '/config',
            BASE_PATH . '/storage/logs',
            BASE_PATH . '/storage/cache'
        ];

        $results = [];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                @mkdir($path, 0755, true);
            }
            $results[$path] = [
                'path' => $path,
                'writable' => is_writable($path)
            ];
        }

        return $results;
    }

    public function install($data)
    {
        try {
            // 1. 测试数据库连接
            $dsn = "mysql:host={$data['db_host']};port={$data['db_port']}";
            $pdo = new PDO($dsn, $data['db_user'], $data['db_pass']);
            
            // 2. 创建数据库
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$data['db_name']}` 
                       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // 3. 导入数据库结构
            $pdo->exec("USE `{$data['db_name']}`");
            $sql = file_get_contents(BASE_PATH . '/install/database.sql');
            $pdo->exec($sql);
            
            // 4. 创建管理员账号
            $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $stmt->execute([$data['admin_user'], password_hash($data['admin_pass'], PASSWORD_DEFAULT)]);
            
            // 5. 生成配置文件
            $config = [
                'db' => [
                    'host' => $data['db_host'],
                    'port' => $data['db_port'],
                    'dbname' => $data['db_name'],
                    'username' => $data['db_user'],
                    'password' => $data['db_pass']
                ],
                'redis' => [
                    'host' => $data['redis_host'],
                    'port' => $data['redis_port'],
                    'password' => $data['redis_pass'] ?: null
                ],
                'stream' => [
                    'chunk_size' => 1048576,
                    'cache_time' => 300
                ]
            ];
            
            if (!is_dir(BASE_PATH . '/config')) {
                mkdir(BASE_PATH . '/config', 0755, true);
            }
            
            file_put_contents(
                BASE_PATH . '/config/config.php',
                "<?php\nreturn " . var_export($config, true) . ";\n"
            );
            
            return ['success' => true];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 