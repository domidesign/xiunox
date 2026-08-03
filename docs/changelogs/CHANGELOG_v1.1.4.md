# v1.1.4 - 2026-07-25

> **版本说明**: ⚠️ 破坏性更新 —— 完全移除 jQuery（全站原生 JS + fetch）、头像组件重构、编辑器按钮 hook 化、帖子页 N+1 查询优化、发帖草稿自动保存

## ⚠️ 破坏性变更

- **完全移除 jQuery**（`6d14d03`）：删除 `jquery-3.7.1.min.js` / `bootstrap-plugin.js`，后台与前台全部 htm/js 改造为原生 JS + fetch；`api_debug.htm` 内置 xpost/ajax shim 兼容旧调用
  - **插件兼容提示**：依赖 jQuery 的插件需迁移，详见新增文档 `10-jquery-removal-guide.md`

## 🆕 新功能

- **草稿自动保存系统**（`view/js/auto-save.js` 新增）：发帖页通过 `data-autosave` 属性集成，支持 `thread_create` / `post_create` / `post_update` 三种上下文，编辑器内容定时自动保存，防止误关页面丢稿
- **后台头像设置页**（`setting_avatar.htm` 新增）：头像显示形状/尺寸等全局配置项

## 🔧 重构与优化

### 头像组件重构（`lib/avatar_component.php`）
- 三层嵌套结构（avatar-wrap / position-relative / avatar-group-icon）
- 形状配置（rounded / circle / square）+ 新增 xs / xxl 尺寸档位
- 新增 `avatar_component_frame` / `avatar_component_badges` 两个 hook 注入点

### 编辑器按钮 hook 化（EditorService）
- 核心不再硬编码插件名，新增 `editor_custom_btns_end` hook，插件自定义按钮统一走 hook
- 禁用 AIEditor 内置 contentRetentionKey 避免与草稿系统冲突
- tippy / aie-container z-index 降至 1020，不再遮挡 Bootstrap Modal

### 帖子页 N+1 查询优化
- 批量预加载用户 / 点赞状态 / 隐藏内容 / 勋章数据
- 首帖作者与勋章预加载；移除 `post_format` 重复调用
- `thread_read` 改 `thread_read_cache`、`user_read` 改 `user_read_cache` 命中静态缓存

### 表单健壮性
- `post.htm` 表单提交：htmx 未加载时阻止提交（避免跳转错误页）+ 30s 超时恢复按钮 + toast/alert 双兜底

## 📚 文档

- 新增插件开发文档 5 篇：`09-model-loading-refactor.md` / `10-jquery-removal-guide.md` / `11-editor-toolbar-integration.md` / `12-avatar-component.md` / `plugin-mutex-guide.md`
- 同步更新 `docs/xiunox-plugin-dev/*` 参考文档

## 📊 统计
- 文件总数：160（新增 7，修改 150，删除 3）
- 代码量：+8,550 行 / -3,541 行
- 提交范围：`8100e15` → `6d14d03`（2 个提交）
