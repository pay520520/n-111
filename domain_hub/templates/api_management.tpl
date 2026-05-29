<?php
// 获取用户API密钥
$userId = $_SESSION['uid'];
$apiKeys = \WHMCS\Database\Capsule::table('mod_cloudflare_api_keys')
    ->where('userid', $userId)
    ->orderBy('created_at', 'desc')
    ->get();

$apiKeyIds = [];
foreach ($apiKeys as $apiKeyRow) {
    $apiKeyIds[] = intval($apiKeyRow->id ?? 0);
}
$apiKeyIds = array_values(array_filter(array_unique($apiKeyIds)));
if (!empty($apiKeyIds)) {
    try {
        $usageRows = \WHMCS\Database\Capsule::table('mod_cloudflare_api_logs')
            ->select('api_key_id', \WHMCS\Database\Capsule::raw('COUNT(*) as request_count'), \WHMCS\Database\Capsule::raw('MAX(created_at) as last_used_at'))
            ->whereIn('api_key_id', $apiKeyIds)
            ->groupBy('api_key_id')
            ->get();

        $usageMap = [];
        foreach ($usageRows as $usageRow) {
            $usageKeyId = intval($usageRow->api_key_id ?? 0);
            if ($usageKeyId <= 0) {
                continue;
            }
            $usageMap[$usageKeyId] = [
                'request_count' => intval($usageRow->request_count ?? 0),
                'last_used_at' => $usageRow->last_used_at ?? null,
            ];
        }

        foreach ($apiKeys as $apiKeyRow) {
            $usage = $usageMap[intval($apiKeyRow->id ?? 0)] ?? null;
            if ($usage === null) {
                continue;
            }
            $apiKeyRow->request_count = intval($usage['request_count'] ?? $apiKeyRow->request_count ?? 0);
            if (!empty($usage['last_used_at'])) {
                $apiKeyRow->last_used_at = $usage['last_used_at'];
            }
        }
    } catch (\Throwable $e) {
    }
}

$apiDailySeriesDays = 7;
$apiDailyCallStats = [];
$apiToday = new \DateTimeImmutable('today');
for ($offset = $apiDailySeriesDays - 1; $offset >= 0; $offset--) {
    $day = $apiToday->sub(new \DateInterval('P' . $offset . 'D'));
    $dayKey = $day->format('Y-m-d');
    $apiDailyCallStats[$dayKey] = [
        'date' => $dayKey,
        'label' => $day->format('m-d'),
        'count' => 0,
        'success_count' => 0,
        'success_rate' => 100.0,
    ];
}
if (!empty($apiKeyIds)) {
    try {
        $startAt = $apiToday->sub(new \DateInterval('P' . ($apiDailySeriesDays - 1) . 'D'))->format('Y-m-d 00:00:00');
        $dailyCallRows = \WHMCS\Database\Capsule::table('mod_cloudflare_api_logs')
            ->select(
                \WHMCS\Database\Capsule::raw('DATE(created_at) as day'),
                \WHMCS\Database\Capsule::raw('COUNT(*) as total_calls'),
                \WHMCS\Database\Capsule::raw('SUM(CASE WHEN response_code < 400 THEN 1 ELSE 0 END) as success_calls')
            )
            ->whereIn('api_key_id', $apiKeyIds)
            ->where('created_at', '>=', $startAt)
            ->groupBy(\WHMCS\Database\Capsule::raw('DATE(created_at)'))
            ->orderBy('day', 'asc')
            ->get();

        foreach ($dailyCallRows as $dailyCallRow) {
            $dayKey = (string) ($dailyCallRow->day ?? '');
            if ($dayKey === '' || !isset($apiDailyCallStats[$dayKey])) {
                continue;
            }
            $totalCalls = intval($dailyCallRow->total_calls ?? 0);
            $successCalls = intval($dailyCallRow->success_calls ?? 0);
            $successRate = $totalCalls > 0 ? round(($successCalls / $totalCalls) * 100, 1) : 100.0;

            $apiDailyCallStats[$dayKey]['count'] = $totalCalls;
            $apiDailyCallStats[$dayKey]['success_count'] = $successCalls;
            $apiDailyCallStats[$dayKey]['success_rate'] = $successRate;
        }
    } catch (\Throwable $e) {
    }
}
$apiDailyCallStats = array_values($apiDailyCallStats);
$apiDailyTrendPayload = [
    'labels' => array_values(array_map(static function (array $row): string {
        return (string) ($row['label'] ?? '');
    }, $apiDailyCallStats)),
    'dates' => array_values(array_map(static function (array $row): string {
        return (string) ($row['date'] ?? '');
    }, $apiDailyCallStats)),
    'values' => array_values(array_map(static function (array $row): int {
        return intval($row['count'] ?? 0);
    }, $apiDailyCallStats)),
    'successRates' => array_values(array_map(static function (array $row): float {
        return round((float) ($row['success_rate'] ?? 100), 1);
    }, $apiDailyCallStats)),
];

$moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
$moduleSlugAttr = htmlspecialchars($moduleSlug, ENT_QUOTES);
$moduleSlugUrl = urlencode($moduleSlug);

// 获取模块设置
$settings = [];
$rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
    ->where('module', $moduleSlug)
    ->get();
if (count($rows) === 0) {
    $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain')
        ->get();
}
foreach ($rows as $r) {
    $settings[$r->setting] = $r->value;
}

$apiEnabled = ($settings['enable_user_api'] ?? 'on') === 'on';
$maxApiKeys = intval($settings['api_keys_per_user'] ?? 3);
$maxApiKeys = $maxApiKeys > 0 ? $maxApiKeys : 1;
$requireQuota = intval($settings['api_require_quota'] ?? 1);
$ipWhitelistEnabled = ($settings['api_enable_ip_whitelist'] ?? 'no') === 'on';

// 获取用户配额
$quota = \WHMCS\Database\Capsule::table('mod_cloudflare_subdomain_quotas')
    ->where('userid', $userId)
    ->first();
$totalQuota = intval($quota->max_count ?? 0);
$canCreateApi = $totalQuota >= $requireQuota;

if (!$apiEnabled) {
    return;
}

$apiSectionShouldExpand = true;
$cfApiText = static function (string $key, string $default, array $params = [], bool $escape = true): string {
    return cfclient_lang($key, $default, $params, $escape);
};
$apiLocaleIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$apiTrendTitleDefault = $apiLocaleIsChinese
    ? sprintf('近 %d 天每日总调用次数', $apiDailySeriesDays)
    : sprintf('Total Daily Calls (Last %d Days)', $apiDailySeriesDays);
$apiTrendHintDefault = $apiLocaleIsChinese
    ? '将鼠标悬停在折线点上可查看明细；成功率低于 90% 会显示红色预警点。'
    : 'Hover over points to see details. Red points indicate success rate below 90%.';
$apiTrendCountLabel = $apiLocaleIsChinese ? '调用次数' : 'Calls';
$apiTrendCountUnit = $apiLocaleIsChinese ? '次' : '';
$apiTrendSuccessLabel = $apiLocaleIsChinese ? '成功率' : 'Success Rate';
$apiTrendSeparator = $apiLocaleIsChinese ? '：' : ': ';
$apiKeyCount = count($apiKeys);
$apiLimitReached = $apiKeyCount >= $maxApiKeys;
$apiCreateButtonDisabled = !$canCreateApi || $apiLimitReached;
$apiUsagePercent = min(100, max(0, ($apiKeyCount / $maxApiKeys) * 100));
$apiMaskKey = static function (string $plainKey): string {
    $plainKey = trim($plainKey);
    $length = strlen($plainKey);
    if ($length <= 12) {
        return $plainKey;
    }

    return substr($plainKey, 0, 8) . '******' . substr($plainKey, -4);
};
?>

<div class="card mt-2" id="api-management-card">
    <div class="card-body" id="apiManagementBody">
        
        <!-- API说明 -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            <strong><?php echo $cfApiText('cfclient.api.alert.title', 'API功能：', [], true); ?></strong><?php echo $cfApiText('cfclient.api.alert.body', '通过API密钥，您可以在程序中自动管理域名和DNS记录，无需手动操作。', [], true); ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#apiDocModal" class="alert-link"><?php echo $cfApiText('cfclient.api.alert.docs', '查看API文档', [], true); ?></a>
        </div>

        <?php if (!$canCreateApi): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $cfApiText('cfclient.api.warning.requirement', '您的配额不足，需要至少有 %1$s 个域名注册配额才能创建API密钥。', [sprintf('<strong>%s</strong>', $requireQuota)], false); ?>
            <br>
            <?php echo $cfApiText('cfclient.api.warning.current_quota', '当前注册额度：%s', [sprintf('<strong>%s</strong>', $totalQuota)], false); ?>
        </div>
        <?php endif; ?>

        <div class="api-create-toolbar mb-3">
            <div class="api-create-summary">
                <div class="api-create-text">
                    <?php echo $cfApiText('cfclient.api.stats.created', '已创建 %1$s / %2$s 个', [number_format($apiKeyCount), number_format($maxApiKeys)], true); ?>
                </div>
                <div class="api-create-progress" aria-hidden="true">
                    <span class="api-create-progress-bar <?php echo $apiLimitReached ? 'is-limit' : ''; ?>" style="width: <?php echo round($apiUsagePercent, 2); ?>%;"></span>
                </div>
            </div>
            <div class="api-create-actions">
                <button
                    type="button"
                    class="btn btn-success"
                    data-bs-toggle="modal"
                    data-bs-target="#createApiKeyModal"
                    <?php echo $apiCreateButtonDisabled ? 'disabled aria-disabled="true"' : ''; ?>
                    title="<?php echo htmlspecialchars($apiLimitReached ? $cfApiText('cfclient.api.limit_reached', '已达到可创建上限', [], true) : ($canCreateApi ? $cfApiText('cfclient.api.button.create', '创建API密钥', [], true) : $cfApiText('cfclient.api.button.disabled_quota', '当前配额不足，无法创建', [], true))); ?>"
                >
                    <i class="fas fa-plus"></i> <?php echo $cfApiText('cfclient.api.button.create', '创建API密钥', [], true); ?>
                </button>
                <?php if ($apiLimitReached): ?>
                    <span class="api-create-limit-note text-muted"><?php echo $cfApiText('cfclient.api.limit_reached', '已达到可创建上限', [], true); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- API密钥列表 -->
        <?php if ($apiKeyCount > 0): ?>
        <div class="api-key-panel-list">
            <?php foreach ($apiKeys as $key): ?>
                <?php
                $apiKeyValue = (string) ($key->api_key ?? '');
                $maskedApiKey = $apiMaskKey($apiKeyValue);
                $apiKeyDomId = 'apiKeyDisplay' . intval($key->id ?? 0);
                $isKeyActive = ($key->status === 'active');
                ?>
                <article class="api-key-panel-row <?php echo !$isKeyActive ? 'is-disabled' : ''; ?>">
                    <div class="api-key-panel-main">
                        <div class="api-key-panel-head">
                            <div class="api-key-name-wrap">
                                <strong class="api-key-name" id="apiKeyNameLabel<?php echo intval($key->id); ?>"><?php echo htmlspecialchars($key->key_name); ?></strong>
                                <div class="api-key-name-editor d-none" id="apiKeyNameEditor<?php echo intval($key->id); ?>">
                                    <input
                                        type="text"
                                        class="form-control form-control-sm api-key-name-input"
                                        id="apiKeyNameInput<?php echo intval($key->id); ?>"
                                        maxlength="100"
                                        value="<?php echo htmlspecialchars($key->key_name, ENT_QUOTES); ?>"
                                    >
                                    <button type="button" class="btn btn-sm api-inline-icon-btn" onclick="saveApiKeyName(<?php echo intval($key->id); ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.save_name', '保存名称', [], true)); ?>">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm api-inline-icon-btn" onclick="cancelApiKeyNameEdit(<?php echo intval($key->id); ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.cancel_edit', '取消编辑', [], true)); ?>">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-sm api-key-name-edit-btn" id="apiKeyNameEditBtn<?php echo intval($key->id); ?>" onclick="startApiKeyNameEdit(<?php echo intval($key->id); ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.edit_name', '修改密钥名称', [], true)); ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                            </div>
                            <span class="api-status-pill <?php echo $isKeyActive ? 'is-active' : 'is-disabled'; ?>">
                                <span class="api-status-dot"></span>
                                <span><?php echo $isKeyActive ? $cfApiText('cfclient.api.status.active', '启用', [], true) : $cfApiText('cfclient.api.status.disabled', '禁用', [], true); ?></span>
                            </span>
                        </div>

                        <div class="api-key-secret-wrap">
                            <code
                                id="<?php echo htmlspecialchars($apiKeyDomId, ENT_QUOTES); ?>"
                                class="api-key-code"
                                data-masked="<?php echo htmlspecialchars($maskedApiKey, ENT_QUOTES); ?>"
                                data-full="<?php echo htmlspecialchars($apiKeyValue, ENT_QUOTES); ?>"
                                data-visible="0"
                            ><?php echo htmlspecialchars($maskedApiKey); ?></code>
                            <div class="api-key-inline-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm api-inline-icon-btn"
                                    onclick="toggleApiKeyVisibility('<?php echo htmlspecialchars($apiKeyDomId, ENT_QUOTES); ?>', this)"
                                    title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.toggle_key', '显示或隐藏完整密钥', [], true)); ?>"
                                >
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm api-inline-icon-btn"
                                    onclick="copyApiKeyValue('<?php echo htmlspecialchars($apiKeyDomId, ENT_QUOTES); ?>')"
                                    title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.copy_key', '复制完整密钥', [], true)); ?>"
                                >
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <div class="api-key-meta-grid">
                            <div class="api-key-meta-item">
                                <span class="api-key-meta-label"><?php echo $cfApiText('cfclient.api.table.requests', '请求次数', [], true); ?></span>
                                <span class="api-key-meta-value"><?php echo number_format($key->request_count); ?></span>
                            </div>
                            <div class="api-key-meta-item">
                                <span class="api-key-meta-label"><?php echo $cfApiText('cfclient.api.table.last_used', '最后使用', [], true); ?></span>
                                <span class="api-key-meta-value"><?php echo $key->last_used_at ? date('Y-m-d H:i', strtotime($key->last_used_at)) : $cfApiText('cfclient.api.table.never_used', '从未使用', [], true); ?></span>
                            </div>
                            <div class="api-key-meta-item">
                                <span class="api-key-meta-label"><?php echo $cfApiText('cfclient.api.table.created_at', '创建时间', [], true); ?></span>
                                <span class="api-key-meta-value"><?php echo date('Y-m-d H:i', strtotime($key->created_at)); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="api-row-actions">
                        <?php if ($isKeyActive): ?>
                            <button type="button" class="btn btn-sm api-action-btn api-action-regenerate" onclick="regenerateApiKey(<?php echo intval($key->id); ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.regenerate', '重新生成', [], true)); ?>">
                                <i class="fas fa-sync"></i>
                            </button>
                            <button type="button" class="btn btn-sm api-action-btn api-action-disable" onclick="toggleApiKeyStatus(<?php echo intval($key->id); ?>, false)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.disable', (isset($cfClientIsChineseLocale) && $cfClientIsChineseLocale) ? '暂停' : 'Disable', [], true)); ?>">
                                <i class="fas fa-pause"></i>
                            </button>
                            <button type="button" class="btn btn-sm api-action-btn api-action-delete" onclick="deleteApiKey(<?php echo intval($key->id); ?>)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.delete', '删除', [], true)); ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm api-action-btn api-action-enable" onclick="toggleApiKeyStatus(<?php echo intval($key->id); ?>, true)" title="<?php echo htmlspecialchars($cfApiText('cfclient.api.actions.enable', (isset($cfClientIsChineseLocale) && $cfClientIsChineseLocale) ? '启用' : 'Enable', [], true)); ?>">
                                <i class="fas fa-play"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary text-center api-key-empty-state">
            <i class="fas fa-key fa-3x mb-3 text-muted"></i>
            <p class="mb-0"><?php echo $cfApiText('cfclient.api.empty.message', '您还没有创建任何API密钥', [], true); ?></p>
        </div>
        <?php endif; ?>

        <div class="mt-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><?php echo $cfApiText('cfclient.api.trend.title', $apiTrendTitleDefault, [intval($apiDailySeriesDays)], true); ?></h6>
                <span class="badge bg-light text-secondary border"><?php echo intval($apiDailySeriesDays); ?>D</span>
            </div>
            <div class="api-usage-trend-chart-wrap">
                <canvas id="apiUsageTrendCanvas" class="api-usage-trend-canvas" height="240"></canvas>
                <div id="apiUsageTrendTooltip" class="api-usage-trend-tooltip" role="status" aria-live="polite"></div>
            </div>
            <div class="small text-muted mt-2"><?php echo $cfApiText('cfclient.api.trend.hint', $apiTrendHintDefault, [], true); ?></div>
        </div>

        <!-- API端点信息 -->
        <div class="mt-3">
            <h6><?php echo $cfApiText('cfclient.api.endpoint.title', 'API端点链接地址：', [], true); ?></h6>
<div class="input-group">
    <input type="text" class="form-control" id="apiEndpoint" readonly
        value="https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>">
    <button class="btn btn-outline-secondary" type="button"
        onclick="copyToClipboard(document.getElementById('apiEndpoint').value)">
        <i class="fas fa-copy"></i> <?php echo $cfApiText('cfclient.api.actions.copy', '复制', [], true); ?>
 
                </button>
            </div>
        </div>

        <div class="mt-4">
            <h6><?php echo $cfApiText('cfclient.api.faq.title', (isset($cfClientIsChineseLocale) && $cfClientIsChineseLocale) ? 'API 使用常见问题（FAQ）' : 'API FAQ', [], true); ?></h6>
            <div class="small text-muted">
                <p class="mb-2"><?php echo $cfApiText('cfclient.api.faq.rate_limit', (isset($cfClientIsChineseLocale) && $cfClientIsChineseLocale)
                    ? 'Q：API 的速率限制是多少？A：默认情况下，API 的频率限制为 30 次请求/分钟。如果您的业务需求超过此限制，请联系技术支持申请提升速率限制。'
                    : 'Q: What is the API rate limit? A: By default, the API rate limit is 30 requests per minute. If your business needs exceed this limit, please contact technical support to request a higher rate limit.', [], true); ?></p>
                <p class="mb-2"><?php echo $cfApiText('cfclient.api.faq.secret_recovery', (isset($cfClientIsChineseLocale) && $cfClientIsChineseLocale)
                    ? 'Q：API Secret 丢失后还能找回吗？A：不能找回。出于安全考虑，API Secret 仅在创建时显示一次，系统不存储明文密钥。如果您不慎丢失，您可以重新生成 API Secret，或者删除后重新创建 API 密钥。'
                    : 'Q: Can I recover a lost API Secret? A: No. For security reasons, the API Secret is shown only once at creation, and the system does not store the plaintext secret. If lost, you can regenerate the API Secret or delete and recreate the API key.', [], true); ?></p>
                <p class="mb-0"><?php echo $cfApiText('cfclient.api.faq.features', (isset($cfClientIsChineseLocale) && $cfClientIsChineseLocale)
                    ? 'Q：API 密钥调用可以使用哪些功能？A：当前 API 支持账户注册域名和管理 DNS 解析、续期域名、域名 Whois 查询等功能。具体请在 API 文档中查看。'
                    : 'Q: What features can be used via API keys? A: The current API supports account domain registration, DNS record management, domain renewal, and domain WHOIS lookup. Please refer to the API documentation for details.', [], true); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- 创建API密钥模态框 -->
<div class="modal fade" id="createApiKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $cfApiText('cfclient.api.modal.create.title', '创建API密钥', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createApiKeyForm">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $cfApiText('cfclient.api.modal.create.name_label', '密钥名称 *', [], true); ?></label>
                        <input type="text" class="form-control" name="key_name" required 
                            placeholder="<?php echo htmlspecialchars($cfApiText('cfclient.api.modal.create.name_placeholder', '例如：生产环境、测试环境', [], true)); ?>">
                        <small class="form-text text-muted"><?php echo $cfApiText('cfclient.api.modal.create.name_hint', '用于识别此密钥的用途', [], true); ?></small>
                    </div>
                    <?php if ($ipWhitelistEnabled): ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $cfApiText('cfclient.api.modal.create.ip_label', 'IP白名单（可选）', [], true); ?></label>
                        <textarea class="form-control" name="ip_whitelist" rows="3" 
                            placeholder="<?php echo htmlspecialchars($cfApiText('cfclient.api.modal.create.ip_placeholder', '192.168.1.1\n192.168.1.2\n留空则允许所有IP', [], true)); ?>"></textarea>
                        <small class="form-text text-muted"><?php echo $cfApiText('cfclient.api.modal.create.ip_hint', '每行一个IP地址，只有这些IP可以使用此密钥', [], true); ?></small>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $cfApiText('cfclient.api.modal.button.cancel', '取消', [], true); ?></button>
                <button type="button" class="btn btn-primary" onclick="createApiKey()"><?php echo $cfApiText('cfclient.api.modal.button.create', '创建', [], true); ?></button>
            </div>
        </div>
    </div>
</div>


<!-- API新密钥显示模态框 -->
<div class="modal fade" id="newApiKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle"></i> <?php echo $cfApiText('cfclient.api.modal.secret.title', 'API密钥创建成功', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?php echo $cfApiText('cfclient.api.modal.secret.important', '重要：', [], true); ?></strong><?php echo $cfApiText('cfclient.api.modal.secret.notice', 'API Secret只会显示一次，请立即保存！', [], true); ?>
                </div>
                <div class="mb-3">
                    <label class="form-label"><strong>API Key：</strong></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="newApiKey" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('newApiKey').value)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><strong>API Secret：</strong></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="newApiSecret" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('newApiSecret').value)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="alert alert-info">
                  <strong><?php echo $cfApiText('cfclient.api.modal.secret.examples', '使用方法示例：', [], true); ?></strong>
<pre class="mb-0"><code>curl -X GET "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=subdomains&action=list" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "X-API-Secret: YOUR_API_SECRET"</code></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?php echo $cfApiText('cfclient.api.modal.secret.button_saved', '我已保存密钥', [], true); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- API文档模态框 -->
<div class="modal fade" id="apiDocModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-book"></i> <?php echo $cfApiText('cfclient.api.docs.modal.title', 'API使用文档', [], true); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section1.title', '1. 认证方式', [], true); ?></h6>
                    <p><?php echo $cfApiText('cfclient.api.docs.section1.body', '所有API请求需要携带API Key和API Secret进行认证。', [], true); ?></p>
                    <p><strong><?php echo $cfApiText('cfclient.api.docs.section1.method_header', '方式1：HTTP Header（推荐使用）', [], true); ?></strong></p>
                    <pre><code>X-API-Key: cfsd_xxxxxxxxxx
X-API-Secret: yyyyyyyyyyyy</code></pre>
                    <p class="text-danger mb-0"><strong><?php echo $cfApiText('cfclient.api.docs.section1.method_query', '方式2：URL/Body 参数（已禁用）', [], true); ?></strong></p>
                    <p class="text-muted small"><?php echo $cfApiText('cfclient.api.docs.section1.method_query_notice', '出于安全原因，api_key/api_secret 仅支持通过 X-API-Key / X-API-Secret 请求头传递。', [], true); ?></p>
                </div>

                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section2.title', '2. 可用端点', [], true); ?></h6>
                    <ul>
                        <li><code>subdomains</code> - <?php echo $cfApiText('cfclient.api.docs.section2.subdomains', '子域名管理', [], true); ?></li>
                        <li><code>dns_records</code> - <?php echo $cfApiText('cfclient.api.docs.section2.records', 'DNS记录管理', [], true); ?></li>
                        <li><code>keys</code> - <?php echo $cfApiText('cfclient.api.docs.section2.keys', 'API密钥管理', [], true); ?></li>
                        <li><code>quota</code> - <?php echo $cfApiText('cfclient.api.docs.section2.quota', '配额查询', [], true); ?></li>
                    </ul>
                </div>

                <div class="mb-4">
                 <h6><?php echo $cfApiText('cfclient.api.docs.section3.title', '3. 示例：列出子域名', [], true); ?></h6>
<pre><code>curl -X GET "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=subdomains&action=list" \
  -H "X-API-Key: cfsd_xxxxxxxxxx" \
  -H "X-API-Secret: yyyyyyyyyyyy"
</code></pre>
                </div>

                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section4.title', '4. 示例：注册子域名', [], true); ?></h6>
<pre><code>curl -X POST "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=subdomains&action=register" \
  -H "X-API-Key: cfsd_xxxxxxxxxx" \
  -H "X-API-Secret: yyyyyyyyyyyy" \
  -H "Content-Type: application/json" \
  -d '{
    "subdomain": "myapp",
    "rootdomain": "example.com"
  }'
</code></pre>

                </div>

                <div class="mb-4">
                    <h6><?php echo $cfApiText('cfclient.api.docs.section5.title', '5. 示例：创建DNS记录', [], true); ?></h6>
                 <pre><code>curl -X POST "https://api005.dnshe.com/index.php?m=<?php echo $moduleSlug; ?>&endpoint=dns_records&action=create" \
  -H "X-API-Key: cfsd_xxxxxxxxxx" \
  -H "X-API-Secret: yyyyyyyyyyyy" \
  -H "Content-Type: application/json" \
  -d '{
    "subdomain_id": 1,
    "type": "A",
    "content": "192.168.1.1",
    "ttl": 600
  }'
</code></pre>

                </div>

              <div class="alert alert-info">
    <i class="fas fa-download"></i>
    <strong><?php echo $cfApiText('cfclient.api.docs.full.title', '完整API文档：', [], true); ?></strong>
    <a href="https://my.dnshe.com/knowledgebase/13/DNSHE-Free-Domain-API-User-Guide-V2.0.html"
       target="_blank"
       class="alert-link">
        <?php echo $cfApiText('cfclient.api.docs.full.link', '点击查看完整文档', [], true); ?>
    </a>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 将当前模板内的模态框移动到 body，避免被父级容器层叠上下文/overflow 影响
(function ensureApiModalsMountedToBody() {
    var modalIds = ['createApiKeyModal', 'newApiKeyModal', 'apiDocModal'];
    var mount = function () {
        modalIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || !document.body || el.parentNode === document.body) {
                return;
            }
            document.body.appendChild(el);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();

(function normalizeApiModalLayering() {
    var modalIds = ['createApiKeyModal', 'newApiKeyModal', 'apiDocModal'];
    var modalSet = {};
    modalIds.forEach(function (id) { modalSet[id] = true; });

    function getMaxZIndex() {
        var elements = document.querySelectorAll('body *');
        var maxZ = 1050;
        for (var i = 0; i < elements.length; i++) {
            var z = window.getComputedStyle(elements[i]).zIndex;
            if (!z || z === 'auto') {
                continue;
            }
            var num = parseInt(z, 10);
            if (!isNaN(num) && num > maxZ) {
                maxZ = num;
            }
        }
        return maxZ;
    }

    document.addEventListener('show.bs.modal', function (event) {
        var modalEl = event && event.target ? event.target : null;
        if (!modalEl || !modalEl.id || !modalSet[modalEl.id]) {
            return;
        }
        var zBase = getMaxZIndex() + 20;
        modalEl.style.zIndex = String(zBase + 5);

        setTimeout(function () {
            var backdrops = document.querySelectorAll('.modal-backdrop:not(.api-modal-z-ready)');
            if (!backdrops || !backdrops.length) {
                return;
            }
            var backdrop = backdrops[backdrops.length - 1];
            backdrop.style.zIndex = String(zBase);
            backdrop.classList.add('api-modal-z-ready');
        }, 0);
    });
})();

// 复制到剪贴板
function copyToClipboard(text) {
    var content = (text || '').toString();
    if (!content) {
        return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(content).then(function() {
            alert(cfLang('api.copySuccess', '已复制到剪贴板'));
        }, function(err) {
            console.error(cfLang('api.copyFailed', '复制失败：'), err);
        });
        return;
    }

    var textarea = document.createElement('textarea');
    textarea.value = content;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        alert(cfLang('api.copySuccess', '已复制到剪贴板'));
    } catch (err) {
        console.error(cfLang('api.copyFailed', '复制失败：'), err);
    }
    document.body.removeChild(textarea);
}

function buildApiAjaxUrl(actionName, extraParams) {
    var current = new URL(window.location.href);
    var currentParams = new URLSearchParams(current.search || '');
    var params = new URLSearchParams();

    if (currentParams.get('action') === 'addon' && currentParams.get('module')) {
        params.set('action', 'addon');
        params.set('module', currentParams.get('module'));
        params.set('ajax_action', actionName);
    } else {
        params.set('m', <?php echo json_encode($moduleSlug, CFMOD_SAFE_JSON_FLAGS); ?>);
        params.set('action', actionName);
    }

    if (extraParams && typeof extraParams === 'object') {
        Object.keys(extraParams).forEach(function (key) {
            var value = extraParams[key];
            if (value === undefined || value === null || value === '') {
                return;
            }
            params.set(key, String(value));
        });
    }

    return (current.pathname || 'index.php') + '?' + params.toString();
}

function parseJsonResponse(response) {
    return response.text().then(function (text) {
        if (!response.ok) {
            throw new Error('http_' + response.status);
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(cfLang('api.responseInvalid', '服务返回异常，请稍后重试'));
        }
    });
}

function toggleApiKeyVisibility(domId, triggerBtn) {
    var target = document.getElementById(domId);
    if (!target) {
        return;
    }

    var isVisible = target.getAttribute('data-visible') === '1';
    var nextVisible = !isVisible;
    var masked = target.getAttribute('data-masked') || '';
    var full = target.getAttribute('data-full') || '';

    target.textContent = nextVisible ? full : masked;
    target.setAttribute('data-visible', nextVisible ? '1' : '0');

    if (triggerBtn) {
        var icon = triggerBtn.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-eye', !nextVisible);
            icon.classList.toggle('fa-eye-slash', nextVisible);
        }
    }
}

function copyApiKeyValue(domId) {
    var target = document.getElementById(domId);
    if (!target) {
        return;
    }
    var full = target.getAttribute('data-full') || target.textContent || '';
    copyToClipboard(full);
}

function startApiKeyNameEdit(keyId) {
    var labelEl = document.getElementById('apiKeyNameLabel' + keyId);
    var editorEl = document.getElementById('apiKeyNameEditor' + keyId);
    var inputEl = document.getElementById('apiKeyNameInput' + keyId);
    var editBtn = document.getElementById('apiKeyNameEditBtn' + keyId);
    if (!labelEl || !editorEl || !inputEl) {
        return;
    }

    labelEl.classList.add('d-none');
    editorEl.classList.remove('d-none');
    if (editBtn) {
        editBtn.classList.add('d-none');
    }
    inputEl.focus();
    inputEl.select();
}

function cancelApiKeyNameEdit(keyId) {
    var labelEl = document.getElementById('apiKeyNameLabel' + keyId);
    var editorEl = document.getElementById('apiKeyNameEditor' + keyId);
    var inputEl = document.getElementById('apiKeyNameInput' + keyId);
    var editBtn = document.getElementById('apiKeyNameEditBtn' + keyId);
    if (!labelEl || !editorEl || !inputEl) {
        return;
    }

    inputEl.value = labelEl.textContent || '';
    editorEl.classList.add('d-none');
    labelEl.classList.remove('d-none');
    if (editBtn) {
        editBtn.classList.remove('d-none');
    }
}

function saveApiKeyName(keyId) {
    var labelEl = document.getElementById('apiKeyNameLabel' + keyId);
    var editorEl = document.getElementById('apiKeyNameEditor' + keyId);
    var inputEl = document.getElementById('apiKeyNameInput' + keyId);
    var editBtn = document.getElementById('apiKeyNameEditBtn' + keyId);
    if (!labelEl || !editorEl || !inputEl) {
        return;
    }

    var nextName = (inputEl.value || '').trim();
    if (!nextName) {
        alert(cfLang('api.nameEmpty', '密钥名称不能为空'));
        inputEl.focus();
        return;
    }

    if (nextName.length > 100) {
        alert(cfLang('api.nameTooLong', '密钥名称最多100个字符'));
        inputEl.focus();
        return;
    }

    fetch(buildApiAjaxUrl('ajax_update_api_key_name'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify({
            key_id: keyId,
            key_name: nextName
        })
    })
    .then(parseJsonResponse)
    .then(function(result) {
        if (!result || !result.success) {
            throw new Error((result && result.error) ? result.error : cfLang('api.renameFailed', '保存失败，请稍后重试'));
        }
        labelEl.textContent = result.key_name || nextName;
        inputEl.value = labelEl.textContent;
        editorEl.classList.add('d-none');
        labelEl.classList.remove('d-none');
        if (editBtn) {
            editBtn.classList.remove('d-none');
        }
    })
    .catch(function(error) {
        var message = error && error.message ? error.message : cfLang('api.renameFailed', '保存失败，请稍后重试');
        alert(cfLang('api.renameFailedWithReason', '保存失败：') + message);
    });
}

// 创建API密钥
function createApiKey() {
    const form = document.getElementById('createApiKeyForm');
    const formData = new FormData(form);
    
    // 转换为JSON
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    
    // 如果有IP白名单，转换为逗号分隔
    if (data.ip_whitelist) {
        data.ip_whitelist = data.ip_whitelist.split('\n').map(ip => ip.trim()).filter(ip => ip).join(',');
    }
    
    fetch(buildApiAjaxUrl('ajax_create_api_key'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify(data)
    })
    .then(parseJsonResponse)
    .then(result => {
        if (result.success) {
            // 关闭创建模态框
            const createModal = bootstrap.Modal.getInstance(document.getElementById('createApiKeyModal'));
            createModal.hide();
            
            // 显示新密钥
            document.getElementById('newApiKey').value = result.api_key;
            document.getElementById('newApiSecret').value = result.api_secret;
            const newKeyModal = new bootstrap.Modal(document.getElementById('newApiKeyModal'));
            newKeyModal.show();
            
            // 刷新页面
            newKeyModal._element.addEventListener('hidden.bs.modal', function() {
                location.reload();
            });
        } else {
            alert(cfLang('api.createFailedWithReason', '创建失败：') + result.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert(cfLang('api.createFailedGeneric', '创建失败，请重试'));
    });
}


// 重新生成API密钥
function regenerateApiKey(keyId) {
    if (!confirm(cfLang('api.regenerateConfirm', '重新生成后，旧的API Secret将立即失效，确定继续吗？'))) {
        return;
    }
    
    fetch(buildApiAjaxUrl('ajax_regenerate_api_key'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify({ key_id: keyId })
    })
    .then(parseJsonResponse)
    .then(result => {
        if (result.success) {
            document.getElementById('newApiKey').value = result.api_key;
            document.getElementById('newApiSecret').value = result.api_secret;
            const modal = new bootstrap.Modal(document.getElementById('newApiKeyModal'));
            modal.show();
        } else {
            alert(cfLang('api.regenerateFailed', '重新生成失败：') + result.error);
        }
    });
}

// 删除API密钥
function deleteApiKey(keyId) {
    if (!confirm(cfLang('api.deleteConfirm', '确定要删除此API密钥吗？删除后无法恢复！'))) {
        return;
    }
    
    fetch(buildApiAjaxUrl('ajax_delete_api_key'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify({ key_id: keyId })
    })
    .then(parseJsonResponse)
    .then(result => {
        if (result.success) {
            alert(cfLang('api.deleteSuccess', '删除成功'));
            location.reload();
        } else {
            alert(cfLang('api.deleteFailed', '删除失败：') + result.error);
        }
    });
}

function toggleApiKeyStatus(keyId, enable) {
    var actionText = enable ? cfLang('api.enable', '启用') : cfLang('api.disable', '暂停');
    var confirmText = enable
        ? cfLang('api.enableConfirm', '确定要启用此API密钥吗？')
        : cfLang('api.disableConfirm', '确定要暂停此API密钥吗？暂停后请求将被拒绝。');
    if (!confirm(confirmText)) {
        return;
    }
    fetch(buildApiAjaxUrl(enable ? 'ajax_enable_api_key' : 'ajax_disable_api_key'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (window.CF_MOD_CSRF || '')
        },
        body: JSON.stringify({ key_id: keyId })
    })
    .then(parseJsonResponse)
    .then(function(result) {
        if (result.success) {
            alert(actionText + cfLang('api.actionSuccess', '成功'));
            location.reload();
        } else {
            alert(actionText + cfLang('api.actionFailed', '失败：') + (result.error || 'unknown'));
        }
    });
}

const apiUsageTrendPayload = <?php echo json_encode($apiDailyTrendPayload, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendCountLabel = <?php echo json_encode($apiTrendCountLabel, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendCountUnit = <?php echo json_encode($apiTrendCountUnit, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendSuccessLabel = <?php echo json_encode($apiTrendSuccessLabel, CFMOD_SAFE_JSON_FLAGS); ?>;
const apiUsageTrendSeparator = <?php echo json_encode($apiTrendSeparator, CFMOD_SAFE_JSON_FLAGS); ?>;

function renderApiUsageTrendChart() {
    const canvas = document.getElementById('apiUsageTrendCanvas');
    const tooltip = document.getElementById('apiUsageTrendTooltip');
    if (!canvas || !canvas.getContext) {
        return;
    }

    const ctx = canvas.getContext('2d');
    const labels = Array.isArray(apiUsageTrendPayload.labels) ? apiUsageTrendPayload.labels : [];
    const dates = Array.isArray(apiUsageTrendPayload.dates) ? apiUsageTrendPayload.dates : [];
    const values = Array.isArray(apiUsageTrendPayload.values)
        ? apiUsageTrendPayload.values.map(function(item){ return Number(item) || 0; })
        : [];
    const successRates = Array.isArray(apiUsageTrendPayload.successRates)
        ? apiUsageTrendPayload.successRates.map(function(item){ return Number(item) || 100; })
        : values.map(function(){ return 100; });

    const formatAxisValue = function(value) {
        if (value >= 1000) {
            const k = value / 1000;
            if (Math.abs(k - Math.round(k)) < 0.01) {
                return Math.round(k) + 'k';
            }
            return k.toFixed(1) + 'k';
        }
        return String(Math.round(value));
    };

    const formatTooltipValue = function(value) {
        return (Number(value) || 0).toLocaleString('en-US');
    };

    const calcNiceMax = function(rawMax) {
        const safeMax = Math.max(1, Number(rawMax) || 0);
        const exponent = Math.floor(Math.log10(safeMax));
        const magnitude = Math.pow(10, exponent);
        const normalized = safeMax / magnitude;
        let niceBase = 10;
        if (normalized <= 1) {
            niceBase = 1;
        } else if (normalized <= 2) {
            niceBase = 2;
        } else if (normalized <= 5) {
            niceBase = 5;
        }
        return niceBase * magnitude;
    };

    let points = [];

    const draw = function() {
        const dpr = window.devicePixelRatio || 1;
        const cssWidth = Math.max(320, canvas.clientWidth || 320);
        const cssHeight = Math.max(220, canvas.clientHeight || 220);
        canvas.width = Math.floor(cssWidth * dpr);
        canvas.height = Math.floor(cssHeight * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssWidth, cssHeight);

        const padding = { left: 48, right: 16, top: 16, bottom: 34 };
        const plotWidth = cssWidth - padding.left - padding.right;
        const plotHeight = cssHeight - padding.top - padding.bottom;
        const maxValue = calcNiceMax(Math.max.apply(null, values.concat([0])));
        const tickCount = 4;

        ctx.strokeStyle = '#e9ecef';
        ctx.fillStyle = '#6c757d';
        ctx.lineWidth = 1;
        ctx.font = '12px Arial, sans-serif';

        for (let i = 0; i <= tickCount; i++) {
            const ratio = i / tickCount;
            const y = padding.top + plotHeight * ratio;
            const value = maxValue * (1 - ratio);
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(cssWidth - padding.right, y);
            ctx.stroke();

            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(formatAxisValue(value), padding.left - 8, y);
        }

        ctx.beginPath();
        ctx.moveTo(padding.left, padding.top);
        ctx.lineTo(padding.left, cssHeight - padding.bottom);
        ctx.lineTo(cssWidth - padding.right, cssHeight - padding.bottom);
        ctx.strokeStyle = '#cfd4da';
        ctx.stroke();

        points = [];
        const len = values.length;
        const stepX = len > 1 ? plotWidth / (len - 1) : 0;

        for (let i = 0; i < len; i++) {
            const x = len > 1 ? (padding.left + stepX * i) : (padding.left + plotWidth / 2);
            const ratio = maxValue > 0 ? values[i] / maxValue : 0;
            const y = padding.top + plotHeight * (1 - ratio);
            points.push({
                x: x,
                y: y,
                value: values[i],
                date: dates[i] || labels[i] || '',
                successRate: Number(successRates[i] ?? 100)
            });
        }

        if (points.length > 1) {
            ctx.beginPath();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#0d6efd';
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                ctx.lineTo(points[i].x, points[i].y);
            }
            ctx.stroke();
        }

        points.forEach(function(point) {
            const warningPoint = Number(point.value || 0) > 0 && Number(point.successRate || 0) < 90;
            ctx.beginPath();
            ctx.fillStyle = warningPoint ? '#fff5f5' : '#ffffff';
            ctx.strokeStyle = warningPoint ? '#dc3545' : '#0d6efd';
            ctx.lineWidth = 2;
            ctx.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        });

        ctx.fillStyle = '#6c757d';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        ctx.font = '11px Arial, sans-serif';
        for (let i = 0; i < labels.length; i++) {
            const x = labels.length > 1 ? (padding.left + stepX * i) : (padding.left + plotWidth / 2);
            ctx.fillText(labels[i], x, cssHeight - padding.bottom + 8);
        }
    };

    const hideTooltip = function() {
        if (!tooltip) {
            return;
        }
        tooltip.style.opacity = '0';
        tooltip.style.visibility = 'hidden';
    };

    const showTooltip = function(point) {
        if (!tooltip || !point) {
            return;
        }
        const countSuffix = apiUsageTrendCountUnit ? (' ' + apiUsageTrendCountUnit) : '';
        const successRateText = (Number(point.successRate) || 0).toFixed(1) + '%';
        tooltip.innerHTML = point.date + '<br>'
            + apiUsageTrendCountLabel + apiUsageTrendSeparator + formatTooltipValue(point.value) + countSuffix + '<br>'
            + apiUsageTrendSuccessLabel + apiUsageTrendSeparator + successRateText;
        tooltip.style.opacity = '1';
        tooltip.style.visibility = 'visible';

        const tipWidth = tooltip.offsetWidth || 0;
        const tipHeight = tooltip.offsetHeight || 0;
        let left = point.x + 12;
        let top = point.y - tipHeight - 10;

        const maxLeft = (canvas.clientWidth || 0) - tipWidth - 6;
        if (left > maxLeft) {
            left = point.x - tipWidth - 12;
        }
        if (left < 6) {
            left = 6;
        }
        if (top < 6) {
            top = point.y + 10;
        }

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    };

    canvas.addEventListener('mousemove', function(event) {
        const rect = canvas.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        let hovered = null;
        for (let i = 0; i < points.length; i++) {
            const point = points[i];
            const dx = x - point.x;
            const dy = y - point.y;
            if ((dx * dx + dy * dy) <= 64) {
                hovered = point;
                break;
            }
        }

        if (hovered) {
            showTooltip(hovered);
        } else {
            hideTooltip();
        }
    });

    canvas.addEventListener('mouseleave', hideTooltip);

    draw();
    window.addEventListener('resize', draw);
}

document.addEventListener('DOMContentLoaded', function () {
    renderApiUsageTrendChart();

    var nameInputs = document.querySelectorAll('.api-key-name-input');
    nameInputs.forEach(function (input) {
        input.addEventListener('keydown', function (event) {
            var idMatch = (input.id || '').match(/(\d+)$/);
            if (!idMatch) {
                return;
            }
            var keyId = parseInt(idMatch[1], 10);
            if (!Number.isFinite(keyId) || keyId <= 0) {
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                saveApiKeyName(keyId);
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                cancelApiKeyNameEdit(keyId);
            }
        });
    });
});
</script>

<style>
#api-management-card code {
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.9em;
}

#api-management-card pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}

#api-management-card .api-management-header {
    background: var(--bs-info-bg-subtle, #cff4fc);
    border-bottom: 1px solid var(--bs-info-border-subtle, #9eeaf9);
}

#api-management-card #apiManagementToggleBtn {
    color: var(--bs-info-text-emphasis, #055160);
}

#api-management-card #apiManagementToggleBtn:hover,
#api-management-card #apiManagementToggleBtn:focus {
    color: var(--bs-info-text-emphasis, #055160);
    opacity: 0.95;
}

#api-management-card .api-create-toolbar {
    border: 1px solid #e8ecf3;
    border-radius: 12px;
    background: #fbfcfe;
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.9rem;
    flex-wrap: wrap;
}

#api-management-card .api-create-summary {
    min-width: 240px;
    flex: 1;
}

#api-management-card .api-create-text {
    font-size: 0.92rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.45rem;
}

#api-management-card .api-create-progress {
    width: min(280px, 100%);
    height: 8px;
    border-radius: 999px;
    background: #e8edf5;
    overflow: hidden;
}

#api-management-card .api-create-progress-bar {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #4578f8 0%, #62a2ff 100%);
    transition: width 0.2s ease;
}

#api-management-card .api-create-progress-bar.is-limit {
    background: linear-gradient(90deg, #f2994a 0%, #eb5757 100%);
}

#api-management-card .api-create-actions {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

#api-management-card .api-create-actions .btn[disabled] {
    cursor: not-allowed;
    opacity: 0.65;
    box-shadow: none;
}

#api-management-card .api-create-limit-note {
    font-size: 0.82rem;
}

#api-management-card .api-key-panel-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

#api-management-card .api-key-panel-row {
    border: 1px solid #e7ebf1;
    border-radius: 12px;
    background: #fff;
    padding: 12px;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

#api-management-card .api-key-panel-row:hover {
    border-color: #d8e2ff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

#api-management-card .api-key-panel-main {
    flex: 1;
    min-width: 0;
}

#api-management-card .api-key-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
    margin-bottom: 0.65rem;
}

#api-management-card .api-key-name-wrap {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    min-width: 0;
}

#api-management-card .api-key-name {
    font-size: 0.96rem;
    color: #1f2937;
    line-height: 1.25;
}

#api-management-card .api-key-name-edit-btn {
    width: 28px;
    height: 28px;
    border: 1px solid transparent;
    border-radius: 8px;
    color: #7b8798;
    background: transparent;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

#api-management-card .api-key-name-edit-btn:hover,
#api-management-card .api-key-name-edit-btn:focus {
    color: #1d4ed8;
    border-color: #c8d7ff;
    background: #eef4ff;
}

#api-management-card .api-key-name-editor {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}

#api-management-card .api-key-name-input {
    width: 200px;
    min-width: 140px;
    border-radius: 8px;
}

#api-management-card .api-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.21rem 0.6rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}

#api-management-card .api-status-pill .api-status-dot {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    display: inline-block;
}

#api-management-card .api-status-pill.is-active {
    background: #E6F4EA;
    color: #1E8E3E;
}

#api-management-card .api-status-pill.is-active .api-status-dot {
    background: #1E8E3E;
}

#api-management-card .api-status-pill.is-disabled {
    background: #fdecea;
    color: #c5221f;
}

#api-management-card .api-status-pill.is-disabled .api-status-dot {
    background: #c5221f;
}

#api-management-card .api-key-secret-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 38px;
}

#api-management-card .api-key-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    letter-spacing: 0.25px;
    color: #2d3748;
    background: #f7f9fc;
    border: 1px solid #e4e9f1;
    border-radius: 8px;
    padding: 0.42rem 0.58rem;
    display: inline-block;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

#api-management-card .api-key-inline-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.28rem;
}

#api-management-card .api-inline-icon-btn {
    width: 30px;
    height: 30px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    color: #6b7280;
    background: #fff;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

#api-management-card .api-inline-icon-btn:hover,
#api-management-card .api-inline-icon-btn:focus {
    border-color: #c8d7ff;
    color: #1d4ed8;
    background: #eef4ff;
}

#api-management-card .api-key-meta-grid {
    margin-top: 0.68rem;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.65rem;
}

#api-management-card .api-key-meta-item {
    border: 1px solid #edf1f6;
    border-radius: 9px;
    padding: 0.48rem 0.6rem;
    background: #fbfcff;
}

#api-management-card .api-key-meta-label {
    display: block;
    font-size: 0.74rem;
    color: #8a94a3;
    margin-bottom: 0.22rem;
}

#api-management-card .api-key-meta-value {
    display: block;
    font-size: 0.86rem;
    color: #2d3748;
    line-height: 1.35;
}

#api-management-card .api-row-actions {
    display: flex;
    align-items: flex-start;
    gap: 0.34rem;
    flex-shrink: 0;
}

#api-management-card .api-action-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid transparent;
    background: transparent;
    color: #9aa4b2;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}


#api-management-card .api-key-panel-row:hover .api-action-regenerate,
#api-management-card .api-action-regenerate:focus {
    background: #fff6e5;
    color: #c68400;
    border-color: #ffe4aa;
}

#api-management-card .api-key-panel-row:hover .api-action-delete,
#api-management-card .api-action-delete:focus {
    background: #fdecea;
    color: #c5221f;
    border-color: #f6c8c6;
}

#api-management-card .api-row-disabled-note {
    font-size: 0.75rem;
    color: #c5221f;
    display: inline-flex;
    align-items: center;
    gap: 0.28rem;
    padding-top: 0.4rem;
}

#api-management-card .api-key-panel-row.is-disabled {
    background: #fefefe;
}

#api-management-card .api-key-empty-state {
    border: 1px dashed #d8dee9;
    border-radius: 12px;
}

#api-management-card .api-usage-trend-chart-wrap {
    position: relative;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    background: #ffffff;
    padding: 8px 8px 4px;
}

#api-management-card .api-usage-trend-canvas {
    display: block;
    width: 100%;
    height: 240px;
}

#api-management-card .api-usage-trend-tooltip {
    position: absolute;
    left: 0;
    top: 0;
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    background: rgba(33, 37, 41, 0.92);
    color: #fff;
    font-size: 12px;
    border-radius: 6px;
    padding: 6px 8px;
    white-space: normal;
    line-height: 1.45;
    min-width: 136px;
    transition: opacity 0.15s ease;
    z-index: 6;
}

@media (max-width: 992px) {
    #api-management-card .api-key-meta-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    #api-management-card .api-key-panel-row {
        flex-direction: column;
    }

    #api-management-card .api-row-actions {
        align-items: center;
    }

    #api-management-card .api-key-name-input {
        width: 150px;
    }

    #api-management-card .api-create-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    #api-management-card .api-create-actions {
        justify-content: space-between;
    }
}
</style>
