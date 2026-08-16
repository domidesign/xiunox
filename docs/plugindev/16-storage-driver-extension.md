# 存储驱动扩展机制

> 适用于 Xiuno BBS X（XIUNOX）v1.1.5+
> 相关源码：`admin/route/setting.php`、`route/attach.php`、`model/attach.func.php`
> 相关 Hook：`admin_setting_upload_driver_register.php`、`storage_save.php`、`storage_serve.php`、`storage_delete.php`

---

## 设计背景

系统核心仅提供**本地存储**（`local`），不内置任何云存储驱动。站长在后台「设置 - 上传设置」页面看到的存储驱动下拉框，默认只有"本地存储"一个选项。

云存储能力（OSS/COS/七牛/S3 等）完全由**插件提供**。插件通过专用 Hook 注册自定义存储驱动，站长选择后核心代码在附件流程的关键节点自动将控制权交给对应插件。

### 核心原则

1. **驱动注册是唯一入口**：插件必须先注册驱动，站长选择后才能接管上传/下载/删除
2. **全局后端选择**：存储驱动是全局的，不按场景（头像/帖子附件）拆分。站长选了 S3，所有附件都存到 S3
3. **核心代码消费 `upload_driver`**：附件流程在关键节点检查 `upload_driver`，非 `local` 时触发专用 storage hook
4. **安全降级**：插件未处理时（如未 exit），核心代码继续走本地逻辑作为兜底

---

## 架构总览

```
站长在后台选择存储驱动（local / mycloud_s3 / mycloud_oss ...）
         │
         ├─ admin/route/setting.php
         │    ├─ GET: 初始化 $upload_drivers，触发 admin_setting_upload_driver_register.php
         │    │        └─ 插件注册: $upload_drivers['mycloud_s3'] = 'AWS S3'
         │    └─ POST: 同一 hook 构建白名单，校验后保存到 conf.php
         │
         ├─ route/attach.php（读取/下载/fetch）
         │    └─ 检查 upload_driver != 'local' → 触发 storage_serve.php
         │         └─ 插件重定向到云端 URL 并 exit（未 exit 则降级走本地 readfile）
         │
         ├─ model/attach.func.php（attach_assoc_post）
         │    └─ 文件从 tmp 移到 attach 目录后 → 触发 storage_save.php
         │         └─ 插件将文件上传到云端
         │
         └─ model/attach.func.php（attach_delete / attach_delete_by_pid / attach_delete_by_uid）
              └─ 删除本地文件前 → 触发 storage_delete.php
                   └─ 插件删除云端文件
```

---

## 三个专用 Storage Hook

### storage_save.php — 文件保存

| 属性 | 值 |
|---|---|
| 触发位置 | `model/attach.func.php` 的 `attach_assoc_post()` 中，`xn_copy()` 成功后 |
| 触发条件 | `$conf['upload_driver'] != 'local'` |
| 可用变量 | `$destfile`（本地目标路径）、`$filename`（相对文件名如 `202606/xxx.jpg`）、`$file`（原始文件信息数组） |
| 插件职责 | 将本地文件上传到云端。上传成功后可选择删除本地文件节省空间 |
| 兜底行为 | 插件未处理时，文件仅保留在本地（不影响后续流程） |

### storage_serve.php — 文件读取/下载

| 属性 | 值 |
|---|---|
| 触发位置 | `route/attach.php` 的 `read`、`download`、`fetch` 三个动作中，`readfile()` 之前 |
| 触发条件 | `$conf['upload_driver'] != 'local'` |
| 可用变量 | `$attach`（附件完整信息含 `filename`、`aid`、`orgfilename` 等）、`$filepath` / `$attachpath`（本地文件路径） |
| 插件职责 | 重定向到云端 URL 并 `exit`。**必须 exit**，否则核心代码会继续执行本地 `readfile()` |
| 兜底行为 | 插件未 exit 时，继续走本地 `readfile()` 输出文件（即使文件不存在也安全降级） |

> **注意**：`storage_serve.php` 在 `read`、`download`、`fetch` 三个动作中都会触发。插件可以根据 `$action`（可通过 `param(1)` 获取）区分处理，也可以统一重定向。

### storage_delete.php — 文件删除

| 属性 | 值 |
|---|---|
| 触发位置 | `model/attach.func.php` 的 `attach_delete()`、`attach_delete_by_pid()`、`attach_delete_by_uid()` 中，`unlink()` 之前 |
| 触发条件 | `$conf['upload_driver'] != 'local'` |
| 可用变量 | `$attach`（附件完整信息含 `filename`）、`$path`（本地文件路径） |
| 插件职责 | 删除云端对应的文件 |
| 兜底行为 | 插件未处理时，仅删除本地文件（云端文件残留但不影响系统运行） |

> **注意**：`attach_delete_by_pid()` 和 `attach_delete_by_uid()` 在循环中逐个触发此 hook，插件每次处理一个文件。

---

## 插件开发指南

### 第一步：注册存储驱动选项

创建 hook 文件 `plugin/<你的插件>/hook/admin_setting_upload_driver_register.php`：

```php
<?php exit;
// 注册存储驱动，同时在后台页面显示和保存校验中生效
$upload_drivers['mycloud_s3'] = 'AWS S3';
```

> 驱动 key（如 `mycloud_s3`）必须带插件前缀，避免与其他插件冲突。

### 第二步：在 conf.json 中声明所有 hook

```json
{
    "name": "AWS S3存储",
    "version": "1.0.0",
    "bbs_version": "1.1",
    "type": "plugin",
    "hooks_rank": {
        "admin_setting_upload_driver_register.php": 100,
        "storage_save.php": 100,
        "storage_serve.php": 100,
        "storage_delete.php": 100
    }
}
```

### 第三步：实现 storage_save.php

创建 `plugin/<你的插件>/hook/storage_save.php`：

```php
<?php exit;
// 检查是否是自己被选中
if ($conf['upload_driver'] !== 'mycloud_s3') return; // 闭包外 return 禁止，改为 if 包裹

// 正确写法：用 if 包裹整个逻辑
if ($conf['upload_driver'] === 'mycloud_s3') {
    $s3_key = 'upload/attach/' . $filename;
    S3Service::upload($destfile, $s3_key);
    // 上传成功后删除本地文件（可选）
    @unlink($destfile);
}
```

> **重要**：hook 文件内禁止使用 `return;`（会从宿主函数返回）。必须用 `if (...) { ... }` 包裹整个逻辑。

### 第四步：实现 storage_serve.php

创建 `plugin/<你的插件>/hook/storage_serve.php`：

```php
<?php exit;
if ($conf['upload_driver'] === 'mycloud_s3') {
    $s3_url = S3Service::getSignedUrl($attach['filename']);
    // 302 重定向到云端 URL
    header("Location: $s3_url", true, 302);
    // ponytail: 重定向到云端 URL 后必须终止请求
    exit;
}
```

### 第五步：实现 storage_delete.php

创建 `plugin/<你的插件>/hook/storage_delete.php`：

```php
<?php exit;
if ($conf['upload_driver'] === 'mycloud_s3') {
    $s3_key = 'upload/attach/' . $attach['filename'];
    S3Service::delete($s3_key);
}
```

### 第六步：提供配置页面

云存储需要配置 AccessKey、Secret、Bucket 等参数。通过插件的 `setting.php` 提供独立配置页面：

```
admin/?plugin-setting-<你的插件目录名>.htm
```

---

## 完整示例：最小 S3 存储插件

### 目录结构

```
plugin/mycloud_s3/
├── conf.json
├── install.php
├── uninstall.php
├── setting.php
├── hook/
│   ├── admin_setting_upload_driver_register.php   # 注册驱动选项
│   ├── storage_save.php                            # 上传到 S3
│   ├── storage_serve.php                           # 读取/下载时重定向到 S3 URL
│   └── storage_delete.php                          # 删除 S3 文件
├── model/
│   └── S3Service.php
└── view/htm/
    └── setting.htm
```

### hook/admin_setting_upload_driver_register.php

```php
<?php exit;
$upload_drivers['mycloud_s3'] = 'AWS S3';
```

### hook/storage_save.php

```php
<?php exit;
if ($conf['upload_driver'] === 'mycloud_s3') {
    $s3_key = 'upload/attach/' . $filename;
    S3Service::upload($destfile, $s3_key);
    // 上传成功后删除本地文件，节省磁盘空间
    @unlink($destfile);
}
```

### hook/storage_serve.php

```php
<?php exit;
if ($conf['upload_driver'] === 'mycloud_s3') {
    $s3_url = S3Service::getSignedUrl($attach['filename']);
    header("Location: $s3_url", true, 302);
    // ponytail: 重定向到 S3 URL 后必须终止请求
    exit;
}
```

### hook/storage_delete.php

```php
<?php exit;
if ($conf['upload_driver'] === 'mycloud_s3') {
    $s3_key = 'upload/attach/' . $attach['filename'];
    S3Service::delete($s3_key);
}
```

---

## 注意事项

1. **驱动 key 必须带插件前缀**：如 `mycloud_s3`，禁止使用 `oss`、`cos`、`qiniu` 等通用名，避免多插件冲突
2. **同一 hook 文件在 GET 和 POST 中都会执行**：`admin_setting_upload_driver_register.php` 在设置页 GET（显示）和 POST（保存校验）中都触发，hook 内只做 `$upload_drivers` 数组赋值
3. **storage_serve.php 必须出口**：插件处理完后必须 `exit`，否则核心代码会继续执行本地 `readfile()`
4. **hook 内禁止 return**：所有 storage hook 内必须用 `if (...) { ... }` 包裹逻辑，禁止 `return;`（会从宿主函数返回导致后续逻辑被跳过）
5. **插件卸载后残留配置安全降级**：站长卸载插件前未切换回"本地存储"，`conf.php` 中残留的驱动值不在白名单中，下次保存时自动回退为 `local`
6. **多存储插件可共存**：多个插件可同时注册各自的驱动，站长在下拉框中看到所有已安装插件的存储选项
7. **清缓存**：修改 hook 文件后需清对应编译缓存：
   - `tmp/route_attach.php`（route/attach.php 的缓存）
   - `tmp/model_attach.func.php`（model/attach.func.php 的缓存）
   - `tmp/route_setting.php`（admin/route/setting.php 的缓存）
   - 批量清理：`rm -f tmp/route_attach.php tmp/model_attach.func.php tmp/route_setting.php`

---

## 为什么不按场景拆分？

存储驱动是**全局后端选择**，不按场景（头像/帖子附件/后台管理）拆分，原因：

1. **实际需求统一**：几乎没有站长会"头像存本地、帖子附件存 S3"——存储需求通常是统一的
2. **避免复杂度爆炸**：按场景拆分需要每个场景一套 hook + 注册 + 选择逻辑，维护成本极高
3. **独立上传不受影响**：插件自己的上传需求（如导出文件、缓存文件）直接用 SDK 上传即可，不需要走系统附件流程

---

## 相关文档

- [Hook 点全量目录](03-hooks-catalog.md) - 查找附件相关 Hook
- [API 速查](04-api-cheatsheet.md) - `setting_get()` / `setting_set()` 等
- [插件结构](02-plugin-structure.md) - conf.json 完整字段 / install / uninstall
- [后台 UI 规范](14-plugin-admin-ui.md) - 插件设置页面 UI 规范
