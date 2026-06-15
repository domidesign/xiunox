# XIUNOX 安装教程

## 目录

- [1. 环境要求](#1-环境要求)
- [2. 安装步骤](#2-安装步骤)
- [3. Web 服务器配置](#3-web-服务器配置)
- [4. 安装后配置](#4-安装后配置)
- [5. 开发模式](#5-开发模式)
- [6. 常见问题排查](#6-常见问题排查)

---

## 1. 环境要求

### 1.1 基础环境

| 项目 | 最低要求 | 推荐版本 |
|------|----------|----------|
| PHP | 8.0+ | 8.1 / 8.2 |
| MySQL | 5.7+ | 8.0 |
| MariaDB | 10.3+ | 10.6+ |
| Web 服务器 | Nginx / Apache | Nginx 1.20+ |

### 1.2 PHP 扩展

以下扩展为必需，缺少将导致安装失败或功能异常：

- **pdo_mysql** — 数据库连接（必须）
- **gd** — 图片处理，如验证码、缩略图（必须）
- **mbstring** — 多字节字符串处理（必须）
- **json** — JSON 编解码（PHP 8.0+ 已内置，无需额外安装）

可通过以下命令检查 PHP 扩展是否已安装：

```bash
php -m | grep -E "pdo_mysql|gd|mbstring|json"
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

```bash
chmod -R 0777 upload/ plugin/ tmp/ log/ conf/
```

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
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
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
