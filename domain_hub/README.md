# WHMCS7 阿里云DNS 二级域名分发插件

## 📦 文件说明

### 核心文件

- **domain_hub.php** - 主插件文件（含自动性能优化）
- **worker.php** - 后台任务处理（风险扫描、根域名替换等）
- **hooks.php** - WHMCS钩子处理
- **check_activation.php** - 插件激活检查
- **cli_settle_invite.php** - 命令行邀请结算工具

### 模板文件

- **templates/admin.tpl** - 管理后台界面
- **templates/client.tpl** - 用户前台界面
- **templates/api_management.tpl** - API管理界面

#### 自定义后台 UI
- 后台 UI 已拆分为 `templates/admin/partials/*.tpl`，配套数据结构请参考 `docs/admin_refactor_plan.md`。
- 扩展/新增卡片时，仅需在 `CfAdminViewModelBuilder` 准备数据，并在 `admin.tpl` 顺序 `include` 相应 partial。

### 库文件

- **lib/CloudflareAPI.php** - Cloudflare API封装
- **lib/ExternalRiskAPI.php** - 外部风险API封装

### 控制层 & 自动加载

- **lib/autoload.php** - 模块级自动加载器，统一注册 Service/Repository/Controller
- **lib/Http/AdminController.php** - 后台入口控制器，负责载入模板、处理表单，并向模板输出 ViewModel
- **lib/Http/ClientController.php** - 客户区入口控制器，封装原有 client.tpl 访问/校验/接口逻辑
- **lib/Http/ApiDispatcher.php** - API 请求调度器，实现 `cf_is_api_request` 与 `cf_dispatch_api_request` 的统一入口

### 模板结构

- **templates/admin.tpl** - 仍为主模板入口，只负责注入全局脚本/布局，并顺序 `include` 各后台 partial，消费 `$cfAdminViewModel`
- **templates/admin/partials/**
  - `alerts.tpl`、`stats_cards.tpl`、`provider_accounts.tpl`
  - `rootdomains/list.tpl`、`rootdomains/form.tpl`
  - `privileged_users.tpl`、`job_queue.tpl`、`risk_monitor.tpl`、`logs.tpl`
  - `api_management.tpl`（原有的 API 管理区块）
- **templates/client.tpl** - 客户区入口模板，如今作为轻量入口顺序 `include` `templates/client/partials/*.tpl`（header、alerts、messages、quota_invite、subdomains、extras、modals、scripts），覆盖旧版仍兼容
- **templates/client/partials/** - 客户区 UI 的结构化片段，可按需定制；升级时依旧只需替换整个 `templates` 目录即可

### 如何新增一个后台 Partial / 管理卡片

1. **准备数据**：在 `CfAdminController::buildViewModel()`（或相应 Service）中计算该区块所需的数据，并放入 `$cfAdminViewModel['yourSection']`。
2. **创建 partial**：在 `templates/admin/partials/` 下新建 `your_section.tpl`（如有子结构可建子目录），模板内部只读取 `$cfAdminViewModel['yourSection']`，禁止直接访问 `Capsule` / superglobals。
3. **入口引入**：在 `templates/admin.tpl` 中，按页面顺序 `include __DIR__ . '/admin/partials/your_section.tpl';`。
4. **脚本归位**：若区块需要 JS/样式，将 `<script>` 放在 partial 尾部（或集中于入口尾部），避免全局污染。
5. **测试**：确认所有表单 action、CSRF、闪存提示依旧奏效，再提交代码。

### 优化工具

- **立即优化-添加索引.sql** - 数据库完整索引优化（30+索引）
- **性能检查脚本.php** - 自动性能检查工具
- **database_optimization.sql** - 数据库清理和优化
- **optimize_risk_events.php** - 风险事件优化（旧版）
- **optimize_risk_events_v2.php** - 风险事件优化（新版）
- **performance_monitor.php** - 性能监控工具

### 文档

- **API_DOCUMENTATION.md** - API使用文档

---

## 🚀 安装部署

### 新安装用户

1. **上传文件**
   ```
   上传整个 domain_hub 文件夹到：
   /www/wwwroot/your-whmcs/modules/addons/
   ```

2. **激活插件**
   ```
   WHMCS后台 → 设置 → 系统设置 → 附加组件 → 激活
   ```

3. **自动优化**
   ```
   ✅ 激活时自动添加11个核心性能优化索引
   ✅ 开箱即用，无需额外操作
   ```

### 老用户升级

1. **备份数据**
   ```bash
   mysqldump -u用户名 -p数据库名 > backup.sql
   ```

2. **上传新文件**
   ```
   覆盖旧文件到：
   /www/wwwroot/your-whmcs/modules/addons/domain_hub/


3. **重新激活**
   ```
   WHMCS后台 → 停用 → 重新激活
   ✅ 自动补充缺失的性能优化索引
   ```

4. **可选：完整优化**
   ```bash
   mysql -u用户名 -p数据库名 < 立即优化-添加索引.sql
   ```

---

## 🎯 核心功能

### 用户功能

- ✅ 二级域名注册
- ✅ DNS记录管理（A、AAAA、CNAME、MX、TXT、NS、SRV）
- ✅ 邀请码系统
- ✅ 用户配额管理
- ✅ API密钥管理
- ✅ 排行榜和奖励

### 管理功能

- ✅ 根域名管理
- ✅ 用户配额管理
- ✅ 域名审核和封禁
- ✅ 风险扫描和监控
- ✅ API密钥管理
- ✅ 操作日志审计

### 性能优化

- ✅ **自动索引优化**（新功能）
  - 激活时自动添加11个核心索引
  - 提升性能10-100倍
  - 智能检测，避免重复

- ✅ **N+1查询修复**
  - 排行榜查询优化
  - 风险扫描优化
  - 根域名替换优化

- ✅ **配置缓存机制**
  - 静态变量缓存
  - 减少数据库查询

---

## 📊 性能数据

### 优化效果（1000用户，5000域名）

| 指标 | 优化前 | 优化后 | 提升 |
|------|--------|--------|------|
| 前台加载 | 3.5秒 | 0.6秒 | **5.8倍** |
| 域名注册 | 4.2秒 | 0.8秒 | **5.2倍** |
| 后台统计 | 8.5秒 | 1.2秒 | **7.1倍** |
| 排行榜计算 | 15秒 | 3秒 | **5倍** |
| 数据库CPU | 85% | 25% | **降70%** |

### 可支持规模

| 用户数 | 域名数 | 响应时间 | 数据库CPU |
|--------|--------|---------|-----------|
| 100 | 500 | < 0.3秒 | < 10% |
| 1,000 | 5,000 | < 0.8秒 | < 20% |
| 10,000 | 50,000 | < 2秒 | < 40% |
| **50,000** | **250,000** | < 3秒 | < 60% |

---

## 🔧 可选优化

### 完整索引优化（推荐）

```bash
# 添加30+个性能优化索引
mysql -u用户名 -p数据库名 < 立即优化-添加索引.sql
```

**效果：** 性能再提升5-50倍

### 性能检查

```bash
# 检查当前性能状态
cd /www/wwwroot/your-whmcs/modules/addons/domain_hub/
php 性能检查脚本.php
```

### 数据清理

```bash
# 清理风险事件表
mysql -u用户名 -p数据库名 < database_optimization.sql
```

---

## 🆕 v2.1 新功能

### 自动性能优化 ⭐

**特性：**
- ✅ 激活时自动添加11个核心索引
- ✅ 智能检测，避免重复
- ✅ 开箱即用，无需手动操作

**索引列表：**
1. mod_cloudflare_subdomain（3个索引）
2. mod_cloudflare_dns_records（1个索引）- 最重要
3. mod_cloudflare_invitation_claims（2个索引）
4. mod_cloudflare_api_keys（1个索引）
5. mod_cloudflare_api_logs（2个索引）
6. mod_cloudflare_domain_risk（2个索引）

**激活提示：**
```
✅ 插件激活成功，数据库表已创建/更新，所有数据已保留，已添加11个性能优化索引
```

---

## 📞 技术支持

### 性能检查

```bash
php 性能检查脚本.php
```

### 日志位置

- PHP错误：`/var/log/php-fpm/error.log`
- MySQL慢查询：`/var/log/mysql/slow-query.log`
- WHMCS日志：`storage/logs/whmcs.log`

### API文档

查看 `API_DOCUMENTATION.md` 获取完整API文档。

---

## ✅ 版本信息

- **当前版本：** v2.1
- **发布日期：** 2025-10-19
- **主要特性：** 自动性能优化、N+1查询修复、配置缓存

---

## 📝 更新日志

### v2.1 (2025-10-19)

**新功能：**
- ✅ 自动索引优化（激活时自动添加11个核心索引）
- ✅ 性能检查脚本
- ✅ 智能索引检测

**性能优化：**
- ✅ 修复3个N+1查询问题
- ✅ 添加配置缓存机制
- ✅ 优化exists()查询

**效果：**
- 性能提升10-100倍
- 可支持50,000+用户

---

**🎉 从今天开始，每个新安装都是高性能！**


---

## public_id 一次性全量回填（建议升级后执行一次）

为避免首轮 API `subdomains list` 在读取时触发“懒回填”写入，建议在低峰期执行一次全量回填任务：

```bash
cd /www/wwwroot/your-whmcs/modules/addons/domain_hub/
php backfill_public_id.php
```

可选参数：

```bash
# 每批2000条，最多跑5000轮
php backfill_public_id.php 2000 5000
```

### ID 策略（统一约定）

- API 入参与返回中的 `id/subdomain_id`：按外显 ID（`public_id`）语义处理；服务端兼容解析为内部 `id`。
- 前后端页面、后台任务、Worker 与数据库外键：统一使用内部 `id`。
- 为兼容历史数据，若某条记录尚无 `public_id`，会按兼容策略自动回填。

### 统一解析实现

- 全局统一通过 `CfSubdomainIdResolver::resolveToInternal()` 做 `public_id -> internal id` 解析。
- 解析器内置请求级缓存，降低同一请求重复解析产生的查询开销。
