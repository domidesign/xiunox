# 12 头像组件（Avatar Component）

> 统一头像渲染入口：`lib/avatar_component.php` 的 `avatar_component_from_data()` 函数。
> 所有用户头像渲染必须走此组件，禁止原生 `<img>` 标签（确保形状配置、用户组角标、lazy loading、onerror 兜底、hook 扩展全部生效）。

---

## 为什么要用统一组件

历史问题（已通过组件整合解决）：
- 圆角不一致（`rounded-1` vs `rounded-circle` 混用）
- 尺寸碎片化（13 种自定义尺寸）
- onerror 兜底混乱（4 种写法并存）
- 用户组角标丢失（原生 img 无右下角徽章）
- 扩展点缺失（无 hook 点支持头像框/认证对勾等）

---

## 三层嵌套结构

```
L1 avatar-wrap            外层容器（承载形状 class、data 属性、头像框 hook 注入点）
└─ L2 position-relative   头像本体容器（承载 img + 角标 hook 注入点）
   ├─ <img>               头像图片
   ├─ avatar-group-icon   用户组角标（管理员/版主等，L3）
   ├─ [badges hook 注入]  插件角标（认证对勾等，L3）
   └─ ...
└─ [frame hook 注入]      插件头像框（L2 之后）
```

---

## 核心 API

### `avatar_component_from_data()`

```php
function avatar_component_from_data(
    $avatar_url,        // 头像 URL
    $size = 'md',       // 尺寸：xs/sm/md/lg/xl/xxl
    $group_icon_class = '',  // 用户组图标 class（空时按 gid 回退默认映射）
    $group_color = '',       // 用户组颜色
    $gid = 0,                // 用户组 ID
    $options = array()       // 选项数组
)
```

**`$options` 支持的 key：**

| Key | 类型 | 默认 | 说明 |
|---|---|---|---|
| `extra_class` | string | `''` | 附加到 L1 的 class（如 `border border-2 border-white`） |
| `link_uid` | int | 0 | 传 uid 自动包裹 `<a href="user-{uid}">` 链接 |
| `show_group_icon` | bool | true | false 时隐藏用户组角标 |
| `show_hooks` | bool | true | false 时跳过两个 hook 点（性能敏感场景） |
| `lazy` | bool | true | false 时关闭 lazy loading（首屏头像） |
| `badge_position` | string | `bottom-right` | 角标位置 hint，传给 badges hook（top-left/top-right/bottom-left/bottom-right） |
| `_uid` | int | 0 | 内部用，供 hook 识别头像所属用户（由 `avatar_component()` 自动传入） |

### `avatar_component()`

```php
function avatar_component($uid, $size = 'md', $gid = 0)
```

从 uid 查询用户数据后转调 `avatar_component_from_data()`，适合只有 uid 没有完整用户数据的场景。

### 尺寸档位

| Key | 像素 | CSS class | 图标字号 |
|---|---|---|---|
| `xs` | 24px | `avatar-xs` | 6px |
| `sm` | 32px | `avatar-sm` | 7px |
| `md` | 40px | `avatar-md` | 8px |
| `lg` | 52px | `avatar-lg` | 12px |
| `xl` | 96px | `avatar-xl` | 16px |
| `xxl` | 128px | `avatar-xxl` | 20px |

---

## 头像形状配置

站点级配置 `avatar_shape`，三档可选：
- `rounded`（默认）：微圆角方形（`border-radius: 6px`）
- `circle`：全圆形（`border-radius: 50%`）
- `square`：直角方形（`border-radius: 0`）

读取函数：`avatar_component_get_shape()`（带静态缓存）

管理员在后台「头像设置」页切换，配置变更立即全站生效。

**禁止在模板中硬编码 `rounded-1`/`rounded-circle`**，形状统一由外层 `avatar-shape-*` class 控制。

---

## Hook 点（扩展机制）

### `avatar_component_badges.php`（角标 hook）

- **注入位置**：L2 内，`avatar-group-icon` 之后
- **累加模式**：`$data['badges_html'] .= ...`（多插件角标全部显示）
- **典型用途**：认证对勾、勋章图标、在线状态等
- **`$data` 字段**：`uid` / `gid` / `size` / `avatar_url` / `badge_position` / `badges_html`

示例（xnx_verify 认证对勾）：
```php
<?php
// plugin/xnx_verify/hook/avatar_component_badges.php
$_verify_uid = isset($data['uid']) ? intval($data['uid']) : 0;
if ($_verify_uid > 0 && class_exists('VerifyService', false)) {
    $_verify_cfg = VerifyService::getSettings();
    if (!empty($_verify_cfg['badge_enabled'])) {
        $_verify_list = VerifyService::getUserVerifications($_verify_uid);
        if (!empty($_verify_list)) {
            $_pos = isset($_verify_cfg['badge_position']) ? $_verify_cfg['badge_position'] : 'bottom-right';
            $_icon = isset($_verify_cfg['badge_icon']) ? $_verify_cfg['badge_icon'] : 'ti ti-circle-check-filled';
            $_color = isset($_verify_cfg['badge_color']) ? $_verify_cfg['badge_color'] : 'text-primary';
            $data['badges_html'] .= '<span class="avatar-badge avatar-badge-' . esc_attr($_pos) . ' xnx-verify-badge">'
                . '<i class="' . esc_attr($_icon) . ' ' . esc_attr($_color) . '"></i>'
                . '</span>';
        }
    }
}
```

### `avatar_component_frame.php`（头像框 hook）

- **注入位置**：L1 内，L2 之后
- **覆盖模式**：`$data['frame_html'] = ...`（后注入覆盖先注入）
- **典型用途**：装饰性头像框（节日头像框、VIP 头像框等）
- **`$data` 字段**：`uid` / `gid` / `size` / `avatar_url` / `frame_html`

示例：
```php
<?php
// plugin/xxx/hook/avatar_component_frame.php
$_uid = isset($data['uid']) ? intval($data['uid']) : 0;
if ($_uid > 0 && class_exists('MyFrameService', false)) {
    $_frame = MyFrameService::getUserFrame($_uid);
    if (!empty($_frame)) {
        $data['frame_html'] = '<div class="avatar-frame avatar-frame-' . esc_attr($_frame['id']) . '"></div>';
    }
}
```

---

## Hook 规则

1. **hook 文件禁止 `return`**（会从被内联的宿主函数返回，跳过后续逻辑）—— 用 `if` 包裹整个逻辑
2. **`plugin_hook()` 第二参数 `$data` 为引用传递**，hook 中修改 `$data['badges_html']` / `$data['frame_html']` 即可回传
3. **所有 `$data` 字段用 `isset()` 兜底**（PHP 8.x 兼容）
4. **class 名用 `esc_attr()` 转义**（防止 XSS）
5. **`class_exists('XxxService', false)` 守卫**（Service 类未加载时跳过，避免 fatal）
6. **静态缓存避免 N+1**（帖子列表页会渲染大量头像，每次 hook 调用都查库会拖慢页面）

---

## 性能优化

- `show_hooks=false`：性能敏感场景（如通知下拉、首屏导航头像）跳过两个 hook 调用
- `lazy=false`：首屏头像关闭 lazy loading（避免首屏闪烁）
- `avatar_component_get_shape()` 带静态缓存，避免每次调用查 setting
- `user_read_cache()` 内部有缓存，`avatar_component()` 不会重复查库

---

## 向后兼容

- 旧 5 参数调用完全兼容（不传 `$options` 时行为不变）
- 旧 CSS class（`.avatar-sm`/`.avatar-md` 等）继续生效
- 旧 `avatar_component()` 函数签名保留并转调新函数

---

## 迁移指南

### 从原生 `<img>` 迁移

**禁止写法**：
```php
<img class="rounded-circle" src="<?php echo $user['avatar_url']; ?>" width="40" height="40">
```

**正确写法**：
```php
<?php echo avatar_component_from_data(
    $user['avatar_url'],
    'md',
    isset($user['group_icon_class']) ? $user['group_icon_class'] : '',
    isset($user['group_color']) ? $user['group_color'] : '',
    isset($user['gid']) ? $user['gid'] : 0,
    array('_uid' => isset($user['uid']) ? $user['uid'] : 0)
); ?>
```

### 例外场景（可保留原生 img）

- 非用户头像的图片（AI 生成图预览、勋章 icon 本身）
- 特殊尺寸（如 16px 排行榜头像，过小不适合三层结构）
- JS 动态生成且不在 avatar-wrap 容器内（如 toast 通知中的小头像）

---

## 参考

- 核心实现：`lib/avatar_component.php`
- CSS 样式：`view/css/bootstrap-bbs.css` 的 `.avatar-wrap` 区块
- 后台设置：`admin/route/setting.php` 的 `setting_avatar` 分支 + `admin/view/htm/setting_avatar.htm`
- 规则沉淀：`.trae/rules/bugfix_rules.md` 的「七、头像组件规范」章节
