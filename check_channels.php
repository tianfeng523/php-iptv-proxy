<?php
require __DIR__ . '/vendor/autoload.php';

$channel = new App\Models\Channel();
$result = $channel->getChannelList(1, 10);

echo "频道列表：\n";
echo "==================\n";
if (isset($result['channels']) && !empty($result['channels'])) {
    foreach ($result['channels'] as $ch) {
        echo "ID: " . $ch['id'] . "\n";
        echo "名称: " . $ch['name'] . "\n";
        echo "源地址: " . $ch['source_url'] . "\n";
        echo "代理地址: " . $ch['proxy_url'] . "\n";
        echo "状态: " . $ch['status'] . "\n";
        echo "==================\n";
    }
} else {
    echo "没有找到任何频道\n";
} 