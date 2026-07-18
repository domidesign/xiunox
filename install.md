# XIUNOX 安装教程

## 文档导航

- **站长/运维**：第 1 → 2 → 3 → 4 → 6 → 8 章
- **二次开发者**：第 1 → 9 → 5 → 2 → 6 章
- **容器化部署**：第 1 → 7 → 4 → 8 章

## 目录

- [1. 环境要求](#1-环境要求)
- [2. 安装步骤](#2-安装步骤)
- [3. Web 服务器配置](#3-web-服务器配置)
- [4. 安装后配置](#4-安装后配置)
- [5. 开发模式](#5-开发模式)
- [6. 常见问题排查](#6-常见问题排查)
- [7. Docker / docker-compose 部署](#7-docker--docker-compose-部署)
- [8. 备份与恢复](#8-备份与恢复)
- [9. 开发者本地搭建指南](#9-开发者本地搭建指南)

---

## 1. 环境要求

### 1.1 基础环境

> **不推荐 Windows**：XiunoX 为 Linux/UNIX 环境设计，生产环境必须使用 Linux。Windows 可用于本地体验（如 phpStudy），但安装程序会显示警告，且部分功能可能异常。如需在 Windows 上开发，推荐使用 Docker 或 WSL2。

| 项目 | 最低要求 | 推荐版本 |
|------|----------|----------|
| 操作系统 | Linux（类 UNIX）推荐 | Ubuntu 22.04+ / Debian 12+ / CentOS 8+ / AlmaLinux 9+ |
| PHP | 8.0+ | 8.5 |
| MySQL | 5.7+ | 8.3 |
| MariaDB | 10.3+ | 11.4 |
| Web 服务器 | Nginx / Apache | Nginx 1.31+ |

#### 支持的 Linux 发行版

| 发行版 | 最低版本 | 说明 |
|--------|----------|------|
| Ubuntu | 22.04 LTS | 最广泛使用的发行版，文档和社区支持最丰富 |
| Debian | 12 (Bookworm) | 稳定可靠，与 Ubuntu 同源 |
| CentOS | 8 | 企业级，注意 CentOS 8 已 EOL，建议迁移到 AlmaLinux |
| AlmaLinux | 9 | CentOS 的替代品，完全兼容 RHEL |
| Rocky Linux | 9 | 另一个 CentOS 替代品 |
| openSUSE | Leap 15.4+ | 欧洲流行发行版 |

> **提示**：任何运行 PHP 8.0+ 和 MySQL 5.7+ 的类 UNIX 系统均可运行 XiunoX，包括 macOS（仅用于本地开发）。但**生产环境必须使用 Linux**。

> **子目录部署可用但不推荐**：XIUNOX 支持子目录部署（如 `http://domain.com/forum/`），核心已处理子目录路径。但第三方插件或主题可能硬编码绝对路径导致资源加载异常，生产环境推荐部署在根目录（如 `http://domain.com/`）。安装向导会自动检测部署路径并给出提示。

### 1.2 PHP 扩展

以下扩展按重要性分三档，安装前请确认：

#### 必需（缺一不可）

| 扩展 | 用途 |
|------|------|
| **pdo_mysql** | 数据库连接，安装向导强制检测 |

#### 强烈推荐（影响核心功能）

| 扩展 | 用途 |
|------|------|
| **gd** | 验证码生成、图片缩略图、头像处理 |
| **mbstring** | 多字节字符串处理（中文标题/内容截断、字符长度校验） |
| **json** | JSON 编解码（PHP 8.0+ 已内置，无需额外安装） |
| **zip** | 插件打包与解包、后台插件上传 |
| **intl** | 多语言字符处理（国际化、字符转换、排序） |
| **opcache** | PHP 字节码缓存，显著提升性能（PHP 8.5 推荐启用） |

#### 可选（按需启用）

| 扩展 | 用途 |
|------|------|
| **redis** | Redis 缓存驱动，中大型站点推荐 |
| **memcached** | Memcached 缓存驱动 |
| **yac** | 本地内存缓存（无锁、APCu 替代），单机部署轻量方案 |
| **curl** | 外部 HTTP 请求（Webhook、OAuth、远程附件抓取） |
| **fileinfo** | 上传文件类型检测（PHP 8.0+ 默认启用） |
| **exif** | 图片 EXIF 元数据读取（拍摄方向、相机信息） |
| **openssl** | HTTPS 请求、签名加密 |

可通过以下命令检查全部扩展是否已安装：

```bash
php -m | grep -iE "pdo_mysql|gd|mbstring|json|zip|intl|opcache|redis|memcached|yac|curl|fileinfo|exif|openssl"
```

如发现缺失，可用以下命令安装（以 Ubuntu/Debian + PHP 8.5 为例）：

```bash
# 必需 + 强烈推荐扩展
sudo apt install php8.5-fpm php8.5-mysql php8.5-gd php8.5-mbstring \
                 php8.5-zip php8.5-intl php8.5-opcache

# 可选扩展
sudo apt install php8.5-redis php8.5-memcached php8.5-curl php8.5-exif

# Yac 需通过 PECL 安装
sudo pecl install yac
echo "extension=yac.so" | sudo tee /etc/php/8.5/mods-available/yac.ini
sudo phpenmod yac
```

### 1.3 目录权限

以下目录需要可写权限，Linux 环境下建议设置为 `0777`：

```
upload/    — 文件上传目录
plugin/    — 插件目录
tmp/       — 缓存及临时文件目录
log/       — 日志目录
conf/      — 配置文件目录（安装时需要写入数据库配置）
```

设置权限示例：

#### 推荐方案：设置所有者（更安全）

优先将目录所有者设置为 Web 服务器运行用户（避免使用 0777）：

```bash
# Nginx 用户
sudo chown -R nginx:nginx upload/ plugin/ tmp/ log/ conf/

# Apache 用户
sudo chown -R www-data:www-data upload/ plugin/ tmp/ log/ conf/
```

#### 回退方案：宽松权限（仅当无法确定所有者时）

```bash
chmod -R 0777 upload/ plugin/ tmp/ log/ conf/
```

> **安全提示**：0777 允许任意用户写入，存在安全隐患。生产环境务必优先使用 chown 方案，仅在本地开发或无法确定 Web 服务器用户时才使用 0777。

---

## 2. 安装步骤

### 2.1 上传文件

将 Xiuno BBS 程序文件上传至 Web 服务器的网站根目录。例如：

- Nginx 默认路径：`/usr/share/nginx/html/` 或 `/var/www/html/`
- Apache 默认路径：`/var/www/html/`

可通过 FTP、SCP 或其他方式上传：

```bash
# 示例：使用 scp 上传
scp -r xiunobbs-master/* user@server:/var/www/html/
```

### 2.2 运行安装向导

在浏览器中访问以下地址启动安装向导：

```
http://www.domain.com/install/
```

将 `www.domain.com` 替换为你的实际域名或服务器 IP 地址。

### 2.3 选择语言

安装向导首页提供语言选择，支持以下语言：

- 简体中文
- 繁体中文
- English
- Русский（俄语）
- ไทย（泰语）
- 日本語
- 한국어（韩语）

选择语言后点击下一步。

### 2.4 阅读许可协议

Xiuno BBS 采用 MIT 开源许可协议。阅读协议内容后，勾选同意并点击下一步。

### 2.5 环境检测

安装程序会自动检测服务器环境，包括：

- PHP 版本是否满足 8.0+ 要求
- 必需的 PHP 扩展是否已安装
- 目录权限是否可写

如果检测项全部通过，可直接进入下一步。若有不通过的项目，请根据提示修复后刷新页面重新检测。

### 2.6 数据库配置

此步骤需要填写数据库连接信息和管理员账号，各字段说明如下：

| 字段 | 说明 | 示例 |
|------|------|------|
| 数据库主机 | MySQL 服务器地址 | `localhost`（默认） |
| 数据库名称 | 已创建的数据库名 | `xiunobbs` |
| 数据库用户名 | 有该数据库权限的用户 | `root` |
| 数据库密码 | 对应的密码 | `your_password` |
| 存储引擎 | InnoDB（推荐）或 MyISAM | `InnoDB` |
| 管理员邮箱 | 用于接收系统通知 | `admin@example.com` |
| 管理员用户名 | 后台登录账号 | `admin` |
| 管理员密码 | 至少 6 位字符 | `your_secure_password` |

填写前请确保已在 MySQL 中创建好数据库：

```sql
CREATE DATABASE xiunobbs DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

建议使用专用数据库用户而非 root：

```sql
CREATE USER 'xiuno'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON xiunobbs.* TO 'xiuno'@'localhost';
FLUSH PRIVILEGES;
```

确认信息无误后点击安装，程序将自动创建数据表并写入初始数据。

### 2.7 安装完成

安装成功后，系统会自动在 `install/` 目录下生成 `install.lock` 文件，防止重复执行安装程序。此时可点击链接进入首页或后台。

---

## 3. Web 服务器配置

### 3.1 Nginx 配置

#### 3.1.1 伪静态规则

在 Nginx 的 server 配置块中添加以下 rewrite 规则：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^/(.*)$ /index.php?$1 last;
    }
}
```

#### 3.1.2 完整配置示例

```nginx
server {
    listen 80;
    server_name www.domain.com;
    root /var/www/html;
    index index.php index.html;

    location / {
        if (!-e $request_filename) {
            rewrite ^/(.*)$ /index.php?$1 last;
        }
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # 传递 Authorization header（API Bearer Token 认证需要）
        fastcgi_pass_header Authorization;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    # 禁止访问敏感目录
    location ~ /(conf|log|tmp)/ {
        deny all;
    }

    # 禁止访问 install.lock 之外的 install 目录文件
    location ~ /install/.*\.php$ {
        deny all;
    }
}
```

修改配置后重载 Nginx：

```bash
nginx -t          # 检查配置语法
nginx -s reload   # 重载配置
```

#### 3.1.3 PHP 8.5 OPcache 推荐配置

在 `php.ini` 中启用并优化 OPcache，显著提升 PHP 性能：

```ini
[opcache]
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

修改后重启 PHP-FPM 使配置生效：

```bash
sudo systemctl restart php8.5-fpm
```

### 3.2 Apache 配置

项目根目录已内置 `.htaccess` 文件，支持 URL 重写。需确保 Apache 已开启 `mod_rewrite` 模块。

#### 3.2.1 启用 mod_rewrite

```bash
# Ubuntu / Debian
sudo a2enmod rewrite
sudo systemctl restart apache2

# CentOS / RHEL
# mod_rewrite 通常默认启用，检查确认：
httpd -M | grep rewrite
```

#### 3.2.2 允许 .htaccess 覆盖

确保 Apache 虚拟主机配置中 `AllowOverride` 不为 `None`：

```apache
<Directory /var/www/html>
    AllowOverride All
</Directory>
```

### 3.3 URL 伪静态

安装完成后，登录后台，进入「设置 → 固定链接」可开启 URL 伪静态：

- **关闭伪静态**：`?thread-create-1.htm`
- **开启伪静态**：`thread-create-1.htm`

开启伪静态需要 Web 服务器已正确配置 rewrite 规则（参见上方 3.1 和 3.2 节），否则会出现 404 错误。

---

## 4. 安装后配置

### 4.1 安全加固

安装完成后，建议执行以下安全措施：

#### 4.1.1 删除安装目录

删除 `install/` 目录可彻底防止安装程序被再次执行：

```bash
rm -rf install/
```

如果保留 `install/` 目录，其中的 `install.lock` 文件也会阻止重复安装，但删除目录更为安全。

#### 4.1.2 修改管理员密码

如果安装时使用了简单密码，请登录后台及时修改为强密码。

#### 4.1.3 关闭调试模式

线上环境必须将 `DEBUG` 设为 `0`，避免暴露敏感信息。详见第 5 节。

#### 4.1.4 确认 auth_key

`conf/conf.php` 中的 `auth_key` 在安装时自动生成，用于加密签名。请确认该值已正确生成且未被泄露。如需更换，可手动修改为随机字符串。

#### 4.1.5 配置 HTTPS

建议为网站配置 SSL 证书，启用 HTTPS 访问。可使用 Let's Encrypt 免费证书：

```bash
# 使用 certbot 自动配置
sudo certbot --nginx -d www.domain.com
```

### 4.2 缓存配置

在 `conf/conf.php` 中可配置缓存类型，支持以下选项：

| 缓存类型 | 说明 | 适用场景 |
|----------|------|----------|
| `mysql` | 使用数据库缓存（默认） | 小型站点，无额外缓存服务 |
| `redis` | 需要 Redis 服务 | 中大型站点，推荐 |
| `memcached` | 需要 Memcached 服务 | 中大型站点 |
| `yac` | 需要 Yac PHP 扩展 | 本地内存缓存，单机部署 |

配置示例（Redis）：

```php
'cache' => array(
    'type' => 'redis',
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => 'your_redis_password',  // Redis 6+ 启用 ACL 时必填
),
```

配置示例（Memcached）：

```php
'cache' => array(
    'type' => 'memcached',
    'host' => '127.0.0.1',
    'port' => 11211,
),
```

#### OPcache 与业务缓存的关系

OPcache 缓存的是 PHP 字节码（opcode），与上述业务缓存（mysql/redis/memcached/yac）正交，二者可同时启用：
- **OPcache**：加速 PHP 代码执行，对应用透明
- **业务缓存**：缓存数据库查询结果、模板编译产物

生产环境建议同时启用 OPcache + Redis，性能最优。

---

## 5. 开发模式

### 5.1 DEBUG 常量

在 `index.php` 中设置 `DEBUG` 常量，控制运行模式：

| 值 | 模式 | PHP 核心文件 | 缓存 | 说明 |
|----|------|-------------|------|------|
| 0 | 线上模式 | xiunophp.min.php | 开启 | 生产环境使用，性能最优 |
| 1 | 调试模式 | xiunophp.php | 开启 | 显示错误信息，便于排查问题 |
| 2 | 插件开发模式 | xiunophp.php | 关闭 | 每次重新编译模板，用于开发插件或模板 |

修改方式：

```php
// index.php 中
define('DEBUG', 0);  // 线上模式
```

### 5.2 缓存控制

在 `conf/conf.php` 中可单独控制缓存行为：

```php
'cache_disable' => 0,  // 0: 正常模式，缓存开启
'cache_disable' => 1,  // 1: 关闭模板编译缓存和模型合并缓存
```

当 `DEBUG` 设为 `2` 时，缓存会自动关闭。如果仅在 `DEBUG = 1` 模式下调试模板，可手动将 `cache_disable` 设为 `1`，修改模板后无需清理 `tmp/` 目录即可生效。

### 5.3 清理缓存

修改模板后，如果缓存未关闭，需手动清理 `tmp/` 目录：

```bash
rm -rf tmp/*
```

修改 CSS 后需在浏览器中硬刷新（Ctrl + F5 / Cmd + Shift + R）以加载最新样式。

---

## 6. 常见问题排查

### 6.1 数据库连接失败

**现象**：安装向导中提示无法连接数据库，或页面报数据库错误。

**排查步骤**：

1. 确认 MySQL 服务正在运行：`systemctl status mysql`
2. 检查数据库主机地址和端口是否正确（默认 `localhost:3306`）
3. 确认数据库用户名和密码无误
4. 确认该用户拥有目标数据库的访问权限
5. 如果数据库与 Web 服务器不在同一台机器，检查 MySQL 是否允许远程连接

### 6.2 目录权限不足

**现象**：安装向导环境检测提示目录不可写，或运行时上传文件失败、配置无法保存。

**排查步骤**：

1. 确认 Web 服务器运行用户（通常为 `www-data` 或 `nginx`）
2. 设置目录所有者：`chown -R www-data:www-data upload/ plugin/ tmp/ log/ conf/`
3. 或直接设置权限：`chmod -R 0777 upload/ plugin/ tmp/ log/ conf/`
4. 检查 SELinux 是否阻止写入（CentOS / RHEL）：`setenforce 0` 临时关闭测试

### 6.3 伪静态不生效

**现象**：开启伪静态后页面返回 404 错误。

**排查步骤**：

1. Nginx：确认 rewrite 规则已添加到 server 配置块中，且已执行 `nginx -s reload`
2. Apache：确认 `mod_rewrite` 已启用，`AllowOverride` 设为 `All`
3. 登录后台确认「设置 → 固定链接」中伪静态已开启
4. 检查 `conf/conf.php` 中 `url_rewrite_on` 的值是否为 `1`

### 6.4 页面空白

**现象**：访问页面显示空白，无任何内容输出。

**排查步骤**：

1. 将 `DEBUG` 设为 `1` 或 `2`，查看具体错误信息
2. 检查 PHP 版本是否满足 8.0+ 要求：`php -v`
3. 检查必需的 PHP 扩展是否已安装：`php -m`
4. 查看 PHP 错误日志（通常在 `/var/log/php-fpm/` 或 `/var/log/apache2/` 目录下）
5. 检查 `conf/conf.php` 配置文件是否完整、格式是否正确

### 6.5 模板修改不生效

**现象**：修改了模板文件但页面显示内容未变化。

**排查步骤**：

1. 清理 `tmp/` 目录中的编译缓存：`rm -rf tmp/*`
2. 浏览器硬刷新（Ctrl + F5）
3. 如果频繁修改模板，建议将 `DEBUG` 设为 `2`，自动关闭缓存

### 6.6 安装后跳转首页 404

**现象**：安装完成后跳转到首页，但显示 404 Not Found。

**排查步骤**：

1. 检查 Web 服务器 rewrite 规则是否正确配置（参见第 3 节）
2. 确认 `index.php` 存在于网站根目录
3. Nginx：确认 `location` 块中 `index` 包含 `index.php`
4. Apache：确认 `.htaccess` 文件存在于根目录且内容正确

### 6.7 API 认证失败（401 Unauthorized）

**现象**：调用 API 时已正确传递 `Authorization: Bearer <token>` 请求头，但返回 401 错误。

**排查步骤**：

1. **Nginx**：确认 fastcgi 配置中包含 `fastcgi_pass_header Authorization;` 和 `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`（参见 3.1.2 节）
2. **Apache**：确认 `.htaccess` 中包含 `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`（项目已内置）
3. 检查 Token 是否过期（默认 2 小时），过期后需用 Refresh Token 刷新或重新登录
4. 确认请求头格式为 `Authorization: Bearer <token>`，注意 Bearer 与 token 之间有空格

### 6.8 上传文件失败

**现象**：发帖或设置头像时上传文件失败。

**排查步骤**：

1. 确认 `upload/` 目录权限为可写：`ls -la upload/`
2. 检查 `php.ini` 中的上传限制：
   - `upload_max_filesize` — 单个文件最大尺寸
   - `post_max_size` — POST 数据最大尺寸
   - `max_execution_time` — 脚本最大执行时间
3. 修改后重启 PHP-FPM 或 Apache 使配置生效
4. 检查磁盘空间是否充足：`df -h`

---

## 7. Docker / docker-compose 部署

适合希望快速部署、不希望手动配置 PHP/Nginx/MySQL 环境的用户。以下方案通过 docker-compose 编排三个容器（PHP-FPM + Nginx + MySQL 8.3），一键启动完整论坛环境。

### 7.1 目录结构

在项目根目录创建以下文件：

```
xiunox-main/
├── Dockerfile              # PHP-FPM 镜像构建
├── docker-compose.yml      # 三容器编排
├── docker/
│   └── nginx.conf          # Nginx 容器配置
└── ...（原有项目文件）
```

### 7.2 Dockerfile

```dockerfile
FROM php:8.5-fpm-alpine

# 安装必需 + 推荐扩展
RUN apk add --no-cache \
    libpng-dev libjpeg-turbo-dev freetype-dev \
    libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql gd mbstring zip intl opcache bcmath

# 安装可选扩展（按需取消注释）
# RUN pecl install redis && docker-php-ext-enable redis
# RUN pecl install yac && docker-php-ext-enable yac

# 设置时区
ENV TZ=Asia/Shanghai
RUN apk add --no-cache tzdata && cp /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# 设置工作目录
WORKDIR /var/www/html

# 复制项目文件（.dockerignore 可排除 upload/ tmp/ log/ 等运行时目录）
COPY . .

# 设置目录权限
RUN chown -R www-data:www-data upload/ plugin/ tmp/ log/ conf/

EXPOSE 9000
```

### 7.3 docker-compose.yml

```yaml
version: '3.8'

services:
  app:
    build: .
    container_name: xiunox-app
    volumes:
      - ./:/var/www/html
      - ./tmp:/var/www/html/tmp
      - ./log:/var/www/html/log
      - ./upload:/var/www/html/upload
      - ./conf:/var/www/html/conf
    depends_on:
      - db
    networks:
      - xiunox-net

  web:
    image: nginx:1.30-alpine
    container_name: xiunox-web
    ports:
      - "80:80"
    volumes:
      - ./:/var/www/html
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - xiunox-net

  db:
    image: mysql:8.3
    container_name: xiunox-db
    environment:
      MYSQL_ROOT_PASSWORD: root_password_here
      MYSQL_DATABASE: xiunobbs
      MYSQL_USER: xiuno
      MYSQL_PASSWORD: your_password_here
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
    networks:
      - xiunox-net

volumes:
  mysql_data:

networks:
  xiunox-net:
    driver: bridge
```

### 7.4 docker/nginx.conf

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/html;
    index index.php index.html;

    location / {
        if (!-e $request_filename) {
            rewrite ^/(.*)$ /index.php?$1 last;
        }
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_pass_header Authorization;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    location ~ /(conf|log|tmp)/ {
        deny all;
    }

    location ~ /install/.*\.php$ {
        deny all;
    }
}
```

### 7.5 一键启动

```bash
# 启动全部容器
docker-compose up -d

# 查看运行状态
docker-compose ps

# 查看日志
docker-compose logs -f app
```

启动后访问 `http://localhost/install/` 进入安装向导。数据库配置填写：

| 字段 | 填写值 |
|------|--------|
| 数据库主机 | `db`（容器名） |
| 数据库名称 | `xiunobbs` |
| 数据库用户名 | `xiuno` |
| 数据库密码 | `your_password_here`（与 docker-compose.yml 一致） |

### 7.6 数据持久化

- **mysql_data** 命名卷：数据库文件持久化，删除容器后数据保留
- **./upload、./conf、./tmp、./log**：绑定挂载到宿主机当前目录，直接在宿主机可见

停止与清理：

```bash
# 停止容器（保留数据）
docker-compose down

# 停止并删除数据卷（⚠️ 会丢失数据库数据）
docker-compose down -v
```

### 7.7 生产环境注意事项

- 修改 docker-compose.yml 中的密码为强密码
- 配置 HTTPS（可在 Nginx 配置中加 443 端口与证书）
- 上线后将 `index.php` 中 `DEBUG` 设为 `0`
- 删除 `install/` 目录

---

## 8. 备份与恢复

### 8.1 数据库备份

#### 手动备份

```bash
# 完整备份（含表结构 + 数据）
mysqldump -u xiuno -p xiunobbs --default-character-set=utf8mb4 --single-transaction \
  > backup_$(date +%Y%m%d_%H%M%S).sql

# 压缩备份（推荐，节省空间）
mysqldump -u xiuno -p xiunobbs --default-character-set=utf8mb4 --single-transaction \
  | gzip > backup_$(date +%Y%m%d_%H%M%S).sql.gz
```

参数说明：
- `--default-character-set=utf8mb4`：保证中文字符正确导出
- `--single-transaction`：InnoDB 一致性快照，备份期间不锁表

#### 定时备份（crontab）

```bash
# 编辑 crontab
crontab -e

# 每天凌晨 3 点备份数据库到 /backup/db/，保留 30 天
0 3 * * * mysqldump -u xiuno -p'your_password' xiunobbs --default-character-set=utf8mb4 --single-transaction | gzip > /backup/db/xiuno_$(date +\%Y\%m\%d).sql.gz && find /backup/db/ -mtime +30 -delete
```

> **提示**：crontab 中 `%` 需转义为 `\%`，密码建议写入 `~/.my.cnf` 避免命令行暴露。

### 8.2 文件备份

以下目录包含用户数据与配置，需定期备份：

| 目录 | 内容 | 备份频率 |
|------|------|----------|
| `upload/` | 用户上传的附件、图片 | 每日（或实时同步到对象存储） |
| `conf/` | 站点配置（含 auth_key、数据库连接） | 每次修改后立即备份 |
| `plugin/` | 已安装的插件文件 | 插件变更后备份 |
| `view/htm/` | 自定义模板（若有修改） | 模板修改后备份 |

打包备份示例：

```bash
# 打包关键目录
tar -czf xiuno_files_$(date +%Y%m%d).tar.gz upload/ conf/ plugin/

# 如有自定义模板
tar -czf xiuno_files_$(date +%Y%m%d).tar.gz upload/ conf/ plugin/ view/htm/
```

### 8.3 完整恢复流程

按以下顺序恢复，避免数据不一致：

#### 步骤 1：恢复文件

```bash
# 解压文件备份到网站根目录
cd /var/www/html
tar -xzf /backup/xiuno_files_20260101.tar.gz

# 设置目录权限
chown -R www-data:www-data upload/ plugin/ tmp/ log/ conf/
```

#### 步骤 2：恢复数据库

```bash
# 解压（如为 gzip 压缩）
gunzip < /backup/db/xiuno_20260101.sql.gz | mysql -u xiuno -p xiunobbs

# 如为未压缩 SQL 文件
mysql -u xiuno -p xiunobbs < /backup/db/xiuno_20260101.sql
```

#### 步骤 3：清理缓存

```bash
# 清理 tmp/ 编译缓存，强制重新编译模板
rm -rf tmp/*
```

#### 步骤 4：验证

1. 访问首页确认站点正常加载
2. 登录后台检查「设置 → 基本」配置是否正确
3. 抽查一篇帖子确认附件可访问
4. 检查 `conf/conf.php` 中 `auth_key` 是否与备份一致

### 8.4 从旧版 Xiuno BBS 升级

XIUNOX 不提供自动升级脚本，如需从旧版 Xiuno BBS（4.0.x）升级，请按以下流程操作：

1. **自检旧站**：确保旧版网站的访问、登录、注册、发帖等核心功能正常运行，且**不再需要使用任何旧版插件**（包含插件数据）。
2. **联系官方协助**：前往 XIUNOX 官方网站发帖，说明升级需求，并在帖子中提供以下信息：
   - 旧站地址
   - 联系方式

   > 为保护隐私，请使用「隐藏内容」功能将站点信息和联系方式设置为管理员可见。

---

## 9. 开发者本地搭建指南

面向二次开发者，提供无需 Nginx 的本地启动方案，便于调试与修改代码。

### 9.1 准备本地环境

#### 9.1.1 安装 PHP 8.5

```bash
# macOS（Homebrew）
brew install php@8.5

# Ubuntu/Debian
sudo apt install php8.5-cli php8.5-mysql php8.5-gd php8.5-mbstring php8.5-zip php8.5-intl
```

确认版本：

```bash
php -v
# PHP 8.5.x (cli) ...
```

#### 9.1.2 准备数据库

**方案 A：用 Docker 启动 MySQL（推荐）**

```bash
docker run -d --name xiuno-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=xiunobbs \
  -p 3306:3306 \
  mysql:8.3 --character-set-server=utf8mb4
```

**方案 B：使用本地 MySQL**

```bash
mysql -u root -p -e "CREATE DATABASE xiunobbs DEFAULT CHARACTER SET utf8mb4;"
```

### 9.2 获取代码与初始化

```bash
# clone 仓库
git clone <仓库地址> xiunox
cd xiunox

# 设置目录权限（本地开发可直接用当前用户）
chmod -R 0777 upload/ plugin/ tmp/ log/ conf/
```

### 9.3 启动 PHP 内置服务器

无需 Nginx/Apache，直接用 PHP 内置服务器启动：

```bash
php -S localhost:8080 -t .
```

> **注意**：PHP 内置服务器不支持的 URL 重写，需将 `conf/conf.php` 中 `url_rewrite_on` 设为 `0`（兼容模式），避免 404。

访问 `http://localhost:8080/install/` 执行安装向导。

### 9.4 开启 DEBUG 模式

修改 `index.php` 第 19 行：

```php
define('DEBUG', 2);  // 0: 线上; 1: 调试; 2: 插件开发（关闭缓存）
```

DEBUG 模式说明：

| 值 | 模式 | 用途 |
|----|------|------|
| 0 | 线上模式 | 使用 `xiunophp.min.php`，开启缓存，性能最优 |
| 1 | 调试模式 | 使用 `xiunophp.php`，开启缓存，显示错误信息 |
| 2 | 插件开发模式 | 使用 `xiunophp.php`，**关闭模板编译缓存**，每次重新编译 |

修改模板后若 DEBUG 未设为 2，需手动清理 `tmp/`：

```bash
rm -rf tmp/*
```

### 9.5 开发工具配置

#### 9.5.1 VS Code 推荐扩展

- **PHP Intelephense** — 代码补全、跳转定义
- **PHP Debug** — Xdebug 调试集成
- **PHP DocBlocker** — 注释生成

#### 9.5.2 Xdebug 安装

```bash
# macOS
pecl install xdebug

# Ubuntu
sudo apt install php8.5-xdebug
```

在 `php.ini` 添加：

```ini
[xdebug]
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
```

VS Code `launch.json`：

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": {
        "/var/www/html": "${workspaceFolder}"
      }
    }
  ]
}
```

### 9.6 调试技巧

- 查看变量：`var_dump($var); exit;` 或 `xn_log($var, 'debug')`
- 查看日志：`tail -f log/php-error.php`
- 查看数据库查询：DEBUG=1 时会在日志中记录慢查询
- 修改路由后无需重启服务器（PHP 内置服务器自动加载）
- 修改 `xiunophp/` 框架文件后需重启服务器（因为 DEBUG=0 时加载 min 版本）

### 9.7 代码结构速查

详见 [docs/README.md](docs/README.md) 了解项目分层：
- `route/` — 前台路由
- `admin/route/` — 后台路由
- `api/v1/` — API 端点
- `model/` — 数据访问层（单表 CRUD）
- `service/` — 业务服务层（跨实体逻辑）
- `lib/` — 基础工具服务
- `xiunophp/` — 框架核心库
