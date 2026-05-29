# DNS Provider 行为契约（统一约束）

本文档定义 `CloudflareAPI`、`PowerDNSAPI`、`DNSPodLegacyAPI`、`DNSPodIntlAPI` 等适配层在 DNS 写操作中的统一语义，避免不同 Provider 出现“本地成功、云端未生效”的行为漂移。

## 1. updateDnsRecord* 成功语义

- `updateDnsRecord()` / `updateDnsRecordRaw()` 返回 `['success' => true]` 时，必须表示关键字段已经在 Provider 侧成功生效。
- 关键字段至少包含：
  - `name`
  - `type`
  - `content`
  - `ttl`
  - `line`（如 Provider 支持）
  - `priority`（MX/SRV）

## 2. TTL 语义（强约束）

- 当调用方显式传入 `ttl`（或 `TTL`）时，Provider 适配器不得静默忽略。
- 若 Provider 不支持目标 TTL 值，必须返回 `success=false`，并给出可识别错误码（见第 3 节）。
- 禁止“返回 success 但实际仍是旧 TTL”的行为。

## 3. 标准化失败语义

建议统一 `errors` 输出为可机器判断的错误码（可附 message）：

- `record_not_found`：记录不存在或无法定位
- `ttl_not_applied`：TTL 未按请求生效
- `field_mismatch_after_write`：写后回读关键字段不一致
- `provider_rejected`：Provider 拒绝请求（参数/权限/策略）
- `provider_unavailable`：Provider API 暂不可用

示例：

```php
['success' => false, 'errors' => [['code' => 'ttl_not_applied', 'message' => 'Provider kept old TTL']]]
```

## 4. 自愈语义（Upsert/Repair）

- 自愈不得只修本地映射而不确认云端真实状态。
- 当“本地空 + 云端已有同名记录”触发 repair/upsert 时：
  1. 若字段不一致（尤其 TTL），必须先显式 update；
  2. 必须执行写后回读校验；
  3. 仅当回读与目标值一致，才允许回填本地记录；
  4. 否则返回失败并记录 `repair_failed` 日志。

## 5. 建议实现要求

- 每个 Provider 适配器在 update 成功后，至少支持一种可用于验证的读取方法（`getDnsRecord` 或等效）。
- 业务层可复用统一比对器，避免在多处散落字段对比逻辑。

