# xnx_fish 养鱼插件 2.0（鱼塘游戏化）实现计划

> 对应设计（已获用户确认）：可视化公共鱼塘 + 随机外观 + 成长解锁 + 喂食暴击 + 养鱼榜（方案 B）

**Goal:** 将养鱼插件从"单机喂食"升级为"有公共鱼塘、鱼外观差异化、有惊喜感的轻游戏"。

**Architecture:**
- 数据：`xnx_fish.appearance` 字段存外观基因 JSON（species/color/pattern/accessory），upgrade.php 幂等迁移。
- 后端：FishService 新增基因随机生成/懒生成回写、阶段进化解锁、喂食暴击、养鱼榜/鱼塘数据方法。
- 前端：新增 fish_draw.js（SVG 鱼渲染 + Canvas 鱼塘动画），fish_pond.htm 主区改造为公共鱼塘画布，侧栏新增养鱼榜。
- 设置：新增暴击概率、鱼塘展示数量、鱼塘公开可见性 3 个配置项。

**Tech Stack:** PHP 8.0+ / MySQL utf8mb4 / Bootstrap 5.3 / htmx 4 / 原生 JS + Canvas + SVG / Tabler Icons

---

## 任务分解

### Task 1: 数据层——appearance 字段

**Files:**
- Create: `plugin/xnx_fish/upgrade.php`
- Modify: `plugin/xnx_fish/install.php`（建表语句加 appearance 字段）

- [ ] 1. 新建 `upgrade.php`：`SHOW COLUMNS` + `ALTER TABLE` 幂等添加 `appearance VARCHAR(255) NOT NULL DEFAULT ''`
- [ ] 2. `install.php` 建表 SQL 同步加 appearance 字段
- [ ] 3. `php -l` 校验

### Task 2: FishService 核心扩展

**Files:**
- Modify: `plugin/xnx_fish/model/FishService.php`

- [ ] 1. 新增外观常量（species 6 种 / color 12 色 / pattern 4 级 / accessory 4 档）、`randomAppearance(int $stage)`、`getAppearance(array $fish)`（懒解析+回写）、`encodeAppearance()`
- [ ] 2. `adopt()` 写入随机 appearance；`getFishByUid/getFishById` 不解析（调用方按需）
- [ ] 3. `rollCrit(array $settings)`：暴击判定返回倍率；`feed/fastFeed` 应用暴击、返回 `crit_multiplier`，日志 detail 记 `crit:x`
- [ ] 4. `maybeEvolve(array $fish, int $newStage, array $settings)`：升级进化外观（pattern/accessory 逐级解锁），写 evolve 日志；feed/fastFeed 升级后调用
- [ ] 5. `getRanklist(int $limit)`：按 exp 倒序 + user_find_by_uids 附用户信息 + CacheHelper::remember 300s
- [ ] 6. `getPondFish(int $limit)`：鱼塘 TOP N（含 appearance 解析），缓存 60s
- [ ] 7. `php -l` 校验

### Task 3: 路由数据组装

**Files:**
- Modify: `plugin/xnx_fish/route/fish.php`、`plugin/xnx_fish/route/api_fish.php`

- [ ] 1. `fish.php`：组装 `$pondFish`、`$rankList`、`$pondMaxFish`、`$isMineInPond`，模板变量传给页面
- [ ] 2. `api_fish.php`：feed/fast_feed 返回 `crit_multiplier`；balance/页面数据不变
- [ ] 3. `php -l` 校验

### Task 4: 前端鱼渲染与动画

**Files:**
- Create: `plugin/xnx_fish/view/js/fish_draw.js`

- [ ] 1. `drawFishSVG(appearance, size)`：按基因渲染 SVG（鱼身路径按 species + color 填充 + pattern 叠加 + accessory 元素）
- [ ] 2. `FishPond` 类：Canvas 渲染水塘（渐变底/水草/气泡）、鱼游动动画（正弦波动+随机转向+边界反弹）、点击命中检测回调
- [ ] 3. 无 jQuery、无 `<?php`，纯原生 JS

### Task 5: 前台模板重构

**Files:**
- Modify: `plugin/xnx_fish/view/htm/fish_pond.htm`

- [ ] 1. 主区（col-xl-9）：未登录/无鱼分支保留；有鱼分支改为"公共鱼塘画布卡"（canvas + 我的鱼浮层 + 点击详情 modal），我的鱼卡（喂食操作）并入侧栏
- [ ] 2. 侧栏（col-xl-3）：我的鱼卡（喂食操作+进度+状态）、余额卡、养鱼榜卡、最近记录卡；未登录时显示领养入口
- [ ] 3. 数据注入：`pond_fish`、`rank_list`、`crit_i18n` 等；保留原有 `data-*` 操作钩子
- [ ] 4. 引用 fish_draw.js

### Task 6: 前端交互更新

**Files:**
- Modify: `plugin/xnx_fish/view/js/fish.js`

- [ ] 1. 处理 `crit_multiplier`：命中暴击时显示暴击动画（"暴击！经验×2"）
- [ ] 2. 鱼塘画布初始化（FishPond）、点击鱼弹出详情 modal
- [ ] 3. 保留原有喂食/冷却/状态刷新逻辑

### Task 7: 设置页新增配置

**Files:**
- Modify: `plugin/xnx_fish/setting.php`、`plugin/xnx_fish/view/htm/fish_setting.htm`

- [ ] 1. `setting.php` 接收 `crit_chance`、`pond_max_fish`、`pond_public` 并入库
- [ ] 2. `fish_setting.htm` 新增 3 个控件（暴击概率%、鱼塘展示鱼数、公开可见开关）
- [ ] 3. `FishService::$defaultSettings` 补默认值；install.php setting_set 同步

### Task 8: 语言键 + 版本 + 缓存 + 审计

**Files:**
- Modify: `plugin/xnx_fish/hook/lang_zh_cn_bbs.php`、`lang_zh_tw_bbs.php`、`lang_en_us_bbs.php`、`conf.json`

- [ ] 1. 新增约 20 个语言键（鱼塘/暴击/养鱼榜/设置项/进化日志/详情卡），三语同步
- [ ] 2. `conf.json` version 1.0.3 → 1.1.0
- [ ] 3. 清 tmp 编译缓存（lang/route/view_htm）
- [ ] 4. 审计：php -l 全部改动文件、hook 无 `return;`/`// hook xxx`、fish_draw.js 无 `<?php`
- [ ] 5. 更新根目录 update_20260807.md

---

## 验证方式（无测试框架，采用 PHP 语法 + 手动场景）

- `php -l` 所有改动 PHP/HTM 文件
- 清 `tmp/`：`rm -f tmp/lang_*_bbs.php tmp/view_htm_*.htm tmp/route_*.php`
- 前台场景：未登录看鱼塘 / 登录无鱼看鱼塘+领养 / 有鱼看自己的鱼+喂食暴击 / 点他人鱼看详情 / 侧栏养鱼榜
- 后台场景：升级按钮 → 执行 upgrade.php → 设置页新配置生效
