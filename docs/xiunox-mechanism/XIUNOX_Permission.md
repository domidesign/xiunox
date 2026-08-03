# XIUNOX_Permission 权限系统

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

---

## 概述

Xiuno X 权限系统采用 **用户组 + 权限节点** 的两层架构。系统内置三种核心用户组（管理员、版主、普通用户），每个用户通过所属用户组继承一组权限节点。权限节点以键值对形式存在，可在后台为每个用户组独立开启或关闭。

权限检查的核心服务由 `PermissionService` 类提供，支持 **核心权限** 与 **插件扩展权限** 的统一管理。数据存储优先使用独立的 `group_permission` 表，同时兼容旧版 `group` 表字段，确保平滑升级。管理员组 ID 不再硬编码为 `[1, 2]`，改为从 `$conf['super_admin_gids']` 或 `SecurityConfigService` 动态读取，支持后台配置多个超级管理员组。

---

## 站长指南

### 配置入口

1. 登录后台 → **用户 → 用户组**，点击编辑对应用户组进行权限分配。
2. 进入 **版块管理**，可针对特定版块覆盖用户组的默认权限。
3. 安全相关的内容权限（如编辑/删除帖子）可在 **安全中心 → 内容权限** 中统一配置。

### 配置项说明

#### 用户组权限节点

| 节点键名 | 分组 | 说明 |
|---------|------|------|
| `allowread` | 普通用户 | 是否允许浏览帖子 |
| `allowthread` | 普通用户 | 是否允许创建主题 |
| `allowpost` | 普通用户 | 是否允许发表回复 |
| `allowattach` | 普通用户 | 是否允许上传附件 |
| `allowdown` | 普通用户 | 是否允许下载附件 |
| `allow_direct_post` | 审核 | 发帖是否无需人工审核 |
| `allow_direct_reply` | 审核 | 回复是否无需人工审核 |
| `allow_direct_profile` | 审核 | 资料修改是否无需人工审核 |
| `allowtop` | 版主 | 是否允许置顶主题 |
| `allowupdate` | 版主 | 是否允许编辑他人帖子 |
| `allowdelete` | 版主 | 是否允许删除他人帖子 |
| `allowmove` | 版主 | 是否允许移动主题 |
| `allowbanuser` | 版主 | 是否允许封禁用户 |
| `allowdeleteuser` | 版主 | 是否允许删除用户 |
| `allowviewip` | 版主 | 是否允许查看用户IP |

#### 内容安全配置（`SecurityConfigService`）

在后台 **安全中心** 可调整以下权限相关配置：

- `security_allow_edit` — 是否允许作者编辑自己的帖子
- `security_edit_time_limit` — 编辑有效时间（分钟，0=永久）
- `security_allow_delete` — 是否允许作者删除自己的帖子
- `security_delete_time_limit` — 删除有效时间
- `security_soft_delete` — 是否启用软删除（进回收站）
- `security_allow_delete_reply` — 是否允许删除自己的回复

### 使用场景

**场景一：开放注册但限制发帖**

将"新注册用户"用户组的 `allowthread` 和 `allowpost` 设为关闭，`allowread` 保持开启，待用户达到一定贡献后手动升级为"普通用户"组。

**场景二：细分版主权限**

为技术版块版主开启 `allowtop`、`allowmove`、`allowdelete`，但关闭 `allowbanuser` 和 `allowdeleteuser`，使其能管理内容但不可处理账号。

**场景三：紧急关闭上传**

临时将所有非管理员组的 `allowattach` 和 `allowdown` 关闭，防止恶意附件上传攻击。

### 注意事项

1. **管理员组不受限**：超级管理员组 ID 从 `$conf['super_admin_gids']` 配置读取（默认可包含 GID 1、2），配置中的所有组自动绕过所有权限检查。
2. **缓存刷新**：修改用户组权限后，`grouplist` 缓存会自动清除，无需手动操作。
3. **版块覆盖**：版块级别的权限覆盖会优先于用户组默认权限，为零时表示继承组权限。
4. **权限节点注册**：插件开发者新增的权限节点会出现在"插件"分组下，站长可直接勾选。

---

## 开发者指南

### 核心服务类

#### PermissionService

统一权限管理服务，类文件路径：`lib/PermissionService.php`。

**主要 API：**

| 方法 | 签名 | 说明 |
|------|------|------|
| `check` | `check(string $permission_key, int $uid = 0): bool` | 检查指定用户是否拥有某权限 |
| `register` | `register(string $plugin, string $key, string $label, string $group = 'plugin'): void` | 注册插件自定义权限节点 |
| `getAllRegisteredKeys` | `getAllRegisteredKeys(): array` | 获取所有已注册权限节点的定义列表 |
| `getPermissions` | `getPermissions(int $gid): array` | 获取某用户组的全部权限值 |
| `updatePermissions` | `updatePermissions(int $gid, array $permissions): bool` | 批量更新用户组权限（upsert 方式） |
| `getGroupLabel` | `getGroupLabel(string $group): string` | 获取权限分组的显示名称 |
| `getGroups` | `getGroups(): array` | 获取所有权限分组列表 |
| `isSuperAdmin` | `isSuperAdmin(int $uid = 0): bool` | 判断指定用户是否属于超级管理员组（读取 `$conf['super_admin_gids']` 配置） |

#### SecurityConfigService

安全配置服务，类文件路径：`lib/security/SecurityConfigService.php`。

**主要 API：**

| 方法 | 签名 | 说明 |
|------|------|------|
| `get` | `get(string $key, $default = null)` | 获取单个安全配置值（实时读取文件） |
| `get_config` | `get_config(): array` | 获取全部安全配置（与默认值合并） |
| `save_config` | `save_config(array $data): bool` | 保存安全配置到 `conf/conf.php` |
| `checkPasswordPolicy` | `checkPasswordPolicy(string $password): string` | 校验密码是否符合策略 |

### 权限检查流程

`PermissionService::check()` 的执行逻辑如下：

1. 若 UID 为 0，使用当前全局用户 `$GLOBALS['uid']`。
2. 获取用户所属 GID。
3. **管理员直通**：通过 `isSuperAdmin()` 判断用户 GID 是否在 `$conf['super_admin_gids']` 列表中，命中则直接返回 `TRUE`。
4. **新表查询**：从 `group_permission` 表读取 `value`，存在则直接返回。
5. **旧表回退**：从 `group` 表的旧字段读取同名权限值。
6. 以上均无记录，返回 `FALSE`。

### 扩展方式

#### 注册插件权限节点

在插件的 `init.php` 或 `model.php` 中调用 `PermissionService::register()`：

```php
// 文件：plugin/myplugin/init.php

class MyPlugin {
    public static function install() {
        PermissionService::register(
            'myplugin',           // 插件目录名
            'myplugin_can_export', // 权限键名
            '允许导出数据',        // 显示名称
            'plugin'              // 分组（默认 plugin）
        );
    }
}
```

注册后，该权限节点会出现在后台用户组编辑页面的"插件"分组中，供站长分配。

#### 权限检查代码示例

在业务逻辑中检查用户权限：

```php
// 示例 1：检查当前用户是否允许发帖
if (!PermissionService::check('allowthread')) {
    return json_encode(array('code' => 0, 'message' => '您没有发帖权限'));
}

// 示例 2：检查指定用户是否有版主权限
if (PermissionService::check('allowdelete', $uid)) {
    // 执行删除操作
    thread__delete($tid);
}
```

#### 读取和更新用户组权限

```php
// 获取 GID=3 用户组的所有权限
$permissions = PermissionService::getPermissions(3);
// 返回: ['allowread' => 1, 'allowthread' => 0, ...]

// 批量更新权限
PermissionService::updatePermissions(3, array(
    'allowthread' => 1,
    'allowpost'   => 1,
));
```

### 钩子点

权限系统相关的模型钩子（位于 `model/group.func.php`）：

- `model_group_start` — 用户组模型加载前
- `model_group__create_start` / `model_group__create_end` — 创建用户组前后
- `model_group__update_start` / `model_group__update_end` — 更新用户组前后
- `model_group__read_start` / `model_group__read_end` — 读取用户组前后
- `model_group__delete_start` / `model_group__delete_end` — 删除用户组前后
- `model_group_find_start` / `model_group_find_end` — 查询用户组列表前后
- `model_group_list_cache_start` / `model_group_list_cache_end` — 缓存读取/写入时

---

## 常见问题

### 1. 为什么管理员组可以跳过所有权限检查？

在 `PermissionService::check()` 中，当用户 GID 在 `$conf['super_admin_gids']` 配置列表中时直接返回 `TRUE`，这是系统设计的预期行为。管理员组作为系统最高权限持有者，不受任何节点限制。如需给管理员禁用某项能力，应通过业务逻辑层面的判断（如操作确认）而非权限节点本身。

### 2. 如何配置新的超级管理员组？

在 `conf/conf.php` 中设置 `super_admin_gids` 数组，例如 `'super_admin_gids' => [1, 2, 5]`，将 GID 为 5 的用户组也设为超级管理员组。系统同时支持通过 `SecurityConfigService` 动态读取该配置。

### 3. 修改了权限但前台不生效怎么办？

权限修改后 `grouplist` 缓存会自动清除，但 `group_permission` 表数据是即时读取的。若仍不生效，请检查：① 确认目标用户的 GID 是否正确；② 确认用户组是否在 `super_admin_gids` 配置中；③ 清除浏览器 Cookie 重新登录。

### 4. 插件新增的权限节点如何显示在后台？

插件需在安装时调用 `PermissionService::register()` 注册节点。注册后，节点会自动出现在 **后台 → 用户组编辑 → 插件** 分组下。若安装后未显示，确认 `register()` 调用发生在权限管理页面加载之前（建议在 `init.php` 的 `install()` 方法中注册）。

### 5. 版块权限覆盖和用户组权限如何配合？

版块级别的权限设置为 **覆盖模式**：当版块权限值为 1 时，允许所有用户组在该版块执行此操作；为 0 时则禁止；留空则继承用户组的默认值。这使得版主管理、附件上传等可按版块粒度灵活控制。

### 6. `group_permission` 表不存在时会发生什么？

`PermissionService` 会自动检测表是否存在（通过 `tableExists()` 方法）。若表不存在，系统会回退到旧版 `group` 表的字段。首次安装新版本时，建议运行数据库升级脚本以创建新表，获得更好的兼容性和扩展性。

---

*本文档基于 Xiuno X 代码库 v1.0.2+ 编写，实际功能以代码为准。*
