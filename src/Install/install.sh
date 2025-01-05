#!/bin/bash

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo "IPTV代理系统环境安装脚本"
echo "========================="

# 检查是否为root用户
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}请使用root权限运行此脚本${NC}"
    exit 1
fi

# 更新系统包
echo "正在更新系统..."
apt update && apt upgrade -y

# 安装基础依赖
echo "安装基础依赖..."
apt install -y curl wget git unzip

# 安装PHP及扩展
echo "安装PHP及扩展..."
apt install -y php8.1-fpm php8.1-cli php8.1-common php8.1-mysql \
    php8.1-zip php8.1-gd php8.1-mbstring php8.1-curl \
    php8.1-xml php8.1-bcmath php8.1-json php8.1-redis

# 安装Redis
echo "安装Redis..."
apt install -y redis-server

# 安装MySQL
echo "安装MySQL..."
apt install -y mysql-server

# 安装Nginx
echo "安装Nginx..."
apt install -y nginx

# 创建项目目录
echo "创建项目目录..."
mkdir -p /var/www/html
chown -R www-data:www-data /var/www/html

# 复制项目文件
echo "部署项目文件..."
cp -r ./* /var/www/html/
cp -r ./.* /var/www/html/ 2>/dev/null || true
chown -R www-data:www-data /var/www/html

# 配置PHP
echo "配置PHP..."
sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 100M/' /etc/php/8.1/fpm/php.ini
sed -i 's/post_max_size = 8M/post_max_size = 100M/' /etc/php/8.1/fpm/php.ini
sed -i 's/memory_limit = 128M/memory_limit = 256M/' /etc/php/8.1/fpm/php.ini
sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/' /etc/php/8.1/fpm/php.ini

# 配置Nginx
echo "配置Nginx..."
cp nginx.conf /etc/nginx/sites-available/iptv-proxy
ln -s /etc/nginx/sites-available/iptv-proxy /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# 重启服务
echo "重启服务..."
systemctl restart php8.1-fpm
systemctl restart nginx
systemctl restart mysql
systemctl restart redis-server

# 设置服务开机自启
echo "设置服务开机自启..."
systemctl enable php8.1-fpm
systemctl enable nginx
systemctl enable mysql
systemctl enable redis-server

echo -e "${GREEN}环境安装完成！${NC}"
echo "请访问 http://您的服务器IP/install.php 继续安装程序" 