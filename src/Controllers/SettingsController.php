<?php
namespace App\Controllers;

use App\Models\Settings;

class SettingsController
{
    private $settingsModel;

    public function __construct()
    {
        $this->settingsModel = new Settings();
    }

    public function index()
    {
        $settings = $this->settingsModel->getAllSettings();
        require __DIR__ . '/../views/admin/settings/index.php';
    }

    public function save()
    {
        $settings = [
            'cache_time' => $_POST['cache_time'] ?? 300,
            'chunk_size' => $_POST['chunk_size'] ?? 1048576,
            'redis_host' => $_POST['redis_host'] ?? '127.0.0.1',
            'redis_port' => $_POST['redis_port'] ?? 6379,
            'redis_password' => $_POST['redis_password'] ?? '',
            'monitor_refresh_interval' => $_POST['monitor_refresh_interval'] ?? 5,
            'check_mode' => $_POST['check_mode'] ?? 'daily',
            'daily_check_time' => $_POST['daily_check_time'] ?? '03:00',
            'check_interval' => $_POST['check_interval'] ?? 6
        ];

        $result = $this->settingsModel->saveSettings($settings);

        $_SESSION['flash_message'] = [
            'type' => $result['success'] ? 'success' : 'danger',
            'message' => $result['message']
        ];

        header('Location: /admin/settings');
        exit;
    }
} 