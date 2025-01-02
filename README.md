# IPTV 代理系统

一个功能强大的 IPTV 代理系统，支持频道管理、实时监控、日志记录等功能。

## 功能特点

### 1. 频道管理
- 添加、编辑、删除频道
- 批量导入频道（支持 m3u/m3u8/txt 格式）
- 频道分组管理
- 频道状态监控
- 批量检查频道可用性
- 自定义每页显示记录数（10/20/50/100条）

### 2. 系统监控
- 实时监控频道状态
- 显示频道延迟
- 显示频道带宽使用情况
- 显示频道连接数
- Redis 缓存监控
- 系统性能监控

### 3. 日志管理
- 操作日志记录
  - 创建频道
  - 导入频道
  - 修改频道
  - 删除频道
  - 其他操作
- 错误日志记录
  - 连接错误
  - 超时错误
  - HTTP错误
  - 其他错误

### 4. 系统设置
- 缓存设置
  - 缓存时间
  - 分片大小
- Redis 设置
  - 服务器配置
  - 密码配置
- 监控设置
  - 刷新间隔
- 频道检查设置
  - 定时检查
  - 间隔检查

## 目录结构

```
php-iptv-proxy/
├── public/                 # 公共访问目录
│   ├── index.php          # 入口文件
│   └── proxy/             # 代理访问目录
├── src/                   # 源代码目录
│   ├── Controllers/       # 控制器目录
│   │   ├── ChannelController.php    # 频道管理
│   │   ├── MonitorController.php    # 系统监控
│   │   ├── LogController.php        # 日志管理
│   │   └── SettingsController.php   # 系统设置
│   ├── Models/            # 模型目录
│   │   ├── Channel.php             # 频道模型
│   │   ├── ChannelGroup.php        # 频道分组
│   │   ├── Log.php                 # 日志模型
│   │   └── Settings.php            # 设置模型
│   ├── Core/              # 核心类目录
│   │   ├── Database.php           # 数据库连接
│   │   └── Router.php             # 路由处理
│   └── views/             # 视图目录
│       ├── admin/                 # 管理界面
│       │   ├── channels/         # 频道管理
│       │   ├── monitor/          # 系统监控
│       │   ├── logs/            # 日志管理
│       │   └── settings/        # 系统设置
│       └── navbar.php           # 导航栏
└── vendor/                # 依赖库目录

```

## 数据库表结构

### channels（频道表）
- id: 自增主键
- name: 频道名称
- source_url: 源地址
- proxy_url: 代理地址
- group_id: 分组ID
- status: 状态（active/error/inactive）
- latency: 延迟（毫秒）
- connections: 当前连接数
- bandwidth: 带宽使用
- error_count: 错误次数
- checked_at: 最后检查时间
- created_at: 创建时间
- updated_at: 更新时间

### channel_groups（频道分组表）
- id: 自增主键
- name: 分组名称
- sort_order: 排序
- created_at: 创建时间

### channel_logs（操作日志表）
- id: 自增主键
- type: 操作类型
- action: 操作动作
- channel_id: 频道ID
- channel_name: 频道名称
- group_id: 分组ID
- group_name: 分组名称
- details: 详细信息
- created_at: 创建时间

### settings（系统设置表）
- id: 自增主键
- key: 设置键名
- value: 设置值
- created_at: 创建时间
- updated_at: 更新时间

## 安装说明

1. 克隆项目
```bash
git clone https://github.com/yourusername/php-iptv-proxy.git
```

2. 安装依赖
```bash
composer install
```

3. 配置数据库
- 创建数据库
- 修改数据库配置

4. 配置 Redis
- 安装 Redis 服务器
- 修改 Redis 配置

5. 配置 Web 服务器
- 设置 public 目录为网站根目录
- 配置 URL 重写规则

6. 初始化数据库
```bash
php init-db.php
```

## 使用说明

1. 频道管理
- 添加频道：填写频道名称和源地址
- 导入频道：支持上传文件或输入在线地址
- 检查频道：可单个检查或批量检查
- 编辑频道：修改频道信息
- 删除频道：可单个删除或批量删除

2. 系统监控
- 查看频道状态
- 监控系统性能
- 查看缓存状态

3. 日志管理
- 查看操作日志
- 查看错误日志
- 支持按类型和日期筛选

4. 系统设置
- 配置缓存参数
- 配置 Redis 连接
- 设置监控参数
- 配置检查策略

## 技术栈

- PHP 7.4+
- MySQL 5.7+
- Redis 6.0+
- Bootstrap 5.1
- Font Awesome 5.15
- jQuery 3.6

## 注意事项

1. 性能优化
- 建议使用 Redis 缓存
- 适当配置检查间隔
- 合理设置缓存时间

2. 安全建议
- 修改默认密码
- 限制管理员访问IP
- 定期备份数据

3. 维护建议
- 定期清理日志
- 监控系统资源
- 及时更新系统

## 许可证

MIT License 