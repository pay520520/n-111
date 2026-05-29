# Domain Hub 日志字段契约（subdomain_id）

为避免内部 ID 与外显 ID 混用，`mod_cloudflare_logs` 约定如下：

- `subdomain_id`：**仅记录内部 ID（authoritative）**，用于与 `mod_cloudflare_subdomain.id` 直接关联。
- `details.public_id`：可选，记录对应外显 ID，便于 API 场景对照。
- `details.raw_subdomain_id`：可选，表示调用方传入但未成功映射的原始 ID（fallback）。

## 兼容策略

- API 入参继续兼容外显 ID 与内部 ID。
- API 出参继续保持外显 ID 语义，不变更接口契约。
- 日志仅做写入归一化，不改业务路径（注册/续期/DNS/Gift/限流等）。
