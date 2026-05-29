# WHMCS7 域名分发插件审计报告（2026-04-30）

## 范围与方法
- 对 `domain_hub` 目录进行静态代码审计（后端 PHP、模板、配置项、任务调度逻辑）。
- 重点核查风险扫描链路：配置定义 → 保存逻辑 → cron 入队 → worker 执行 → Safe Browsing 集成。
- 说明：本次是静态审计，不包含真实 WHMCS 运行环境下的动态联调。

## 核心结论
1. **Google Safe Browsing 已接入到风险扫描链路中**，并且由 `safe_browsing_enabled` 与 `safe_browsing_api_key` 两项配置控制。
2. **“周期性风险扫描间隔（分钟）”已可配置**，通过 `risk_scan_interval` 生效在 cron 入队判定中。
3. **“风险扫描批量大小”已可配置**，通过 `risk_scan_batch_size` 生效在 worker 的每轮扫描 limit。
4. 因此你提到的“Safe Browsing 检查是否能像周期性风险扫描一样可选间隔和批量大小”，**就当前代码看实际上已具备（复用风险扫描任务参数）**。
5. 若要把 Safe Browsing 做成“独立可调度（独立间隔+独立批量）”，技术难度 **中等偏低（约 1~2 人日）**。

## 关键证据
- 配置项定义：`risk_scan_interval`、`risk_scan_batch_size`、`safe_browsing_enabled`、`safe_browsing_api_key`。见 `domain_hub.php`。
- 配置保存：`risk_scan_batch_size` 在管理端保存时有边界限制（10~1000）。
- 周期入队：`hooks.php` 使用 `risk_scan_interval` 判断是否创建 `risk_scan_all` 作业。
- 扫描执行：`worker.php` 在 `cfmod_job_risk_scan_all` 使用 `risk_scan_batch_size` limit 查询子域。
- Safe Browsing：worker 每个子域检查 `https://{subdomain}`，命中则提升为高风险并写事件。

## 发现的问题与风险点
1. **Safe Browsing API key 目前是普通 text 配置项**（非密码框），有暴露风险（后台可见明文输入过程）。
2. **Safe Browsing 与主风险扫描强绑定**：无法单独设置“仅 Safe Browsing 频率/批量”，只能跟随 `risk_scan_all` 的调度。
3. **批量参数仅在保存时做强约束**，若数据库里被手工写入异常值，运行时虽有 fallback，但建议统一在读取层再做 normalize。
4. **日志 warning 粒度较粗**：`safe_browsing_check_failed:*` 未区分 HTTP code/配额超限/网络超时，排障成本偏高。

## 若要实现“Safe Browsing 独立间隔 + 独立批量”
### 目标能力
- 新增配置：
  - `safe_browsing_scan_interval`（分钟）
  - `safe_browsing_scan_batch_size`
  - （可选）`safe_browsing_only_enabled`
- 新增独立 job type（如 `safe_browsing_scan_all`），与 `risk_scan_all` 并行存在。

### 实现改动点
1. `domain_hub.php`：新增配置项定义。
2. `AdminActionService.php`：保存/校验新增配置项。
3. `hooks.php`：新增 Safe Browsing 独立入队判定。
4. `worker.php`：新增 job handler，复用现有 `CfSafeBrowsingService`。
5. 管理端模板：风险监控区新增独立手动触发按钮与说明。
6. 文档与迁移：默认值、升级说明、配额提示（Google API QPS/QPD）。

### 难度评估
- 代码复用度高（现有 service 可直接调用），主要工作在调度拆分与配置贯通。
- **难度：中等偏低**；若不改 UI，纯后端可更快；若含 UI/文档/回归，建议按 1~2 人日评估。

## 建议优先级
- P0：把 Safe Browsing API Key 输入改为敏感字段处理（掩码显示+加密存储流程对齐）。
- P1：增加 Safe Browsing 独立调度能力（间隔/批量）。
- P2：细化 Safe Browsing 错误日志维度（HTTP 状态、响应体摘要、重试标签）。

