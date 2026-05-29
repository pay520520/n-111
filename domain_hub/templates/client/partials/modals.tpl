<?php
$modalText = function (string $key, string $default, array $params = [], bool $escape = true) {
    return cfclient_lang($key, $default, $params, $escape);
};
$modalLanguage = strtolower((string) ($currentClientLanguage ?? 'english'));
$modalIsChinese = $modalLanguage === 'chinese';
$nsListLabelDefault = $modalIsChinese ? 'DNS服务器列表（使用本站解析服务,则无需填写）' : 'DNS Server List (Leave blank to use default site DNS)';
$nsAddButtonDefault = $modalIsChinese ? '[增加 DNS 服务器]' : '[Add DNS Server]';
$nsSaveButtonDefault = $modalIsChinese ? '保存设置' : 'Save Settings';
$nsForceShortDefault = $modalIsChinese ? '强制替换冲突记录' : 'Force replace conflicting records';
$nsForceTooltipDefault = $modalIsChinese
    ? '删除与 NS 冲突的同名记录，如 A/AAAA/CNAME/TXT/MX/SRV/CAA 等。'
    : 'Delete records with the same name that conflict with NS, such as A/AAAA/CNAME/TXT/MX/SRV/CAA.';

$dnsUnlockRequired = !empty($dnsUnlockRequired);
$dnsUnlockModalData = $dnsUnlock ?? [];
$dnsUnlockPurchaseEnabled = !empty($dnsUnlockPurchaseEnabled);
$dnsUnlockPurchasePrice = isset($dnsUnlockPurchasePrice) ? (float) $dnsUnlockPurchasePrice : 0.0;
$dnsUnlockPriceDisplay = number_format($dnsUnlockPurchasePrice, 2, '.', '');
$dnsUnlockShareAllowed = !empty($dnsUnlockShareAllowed);

$domainPermanentUpgradeEnabled = !empty($domainPermanentUpgradeEnabled);
$domainPermanentUpgradeState = is_array($domainPermanentUpgradeState ?? null) ? $domainPermanentUpgradeState : [];
$domainPermanentUpgradeAssistRequired = max(1, intval($domainPermanentUpgradeState['assist_required'] ?? ($domainPermanentUpgradeAssistRequired ?? 3)));
$domainPermanentUpgradeEligibleDomains = is_array($domainPermanentUpgradeState['eligible_domains'] ?? null) ? $domainPermanentUpgradeState['eligible_domains'] : [];
$domainPermanentUpgradeRequests = is_array($domainPermanentUpgradeState['requests'] ?? null) ? $domainPermanentUpgradeState['requests'] : [];
$domainPermanentUpgradeInvoicePendingOrders = is_array($domainPermanentUpgradeState['invoice_pending_orders'] ?? null) ? $domainPermanentUpgradeState['invoice_pending_orders'] : [];
$domainPermanentUpgradePagination = $domainPermanentUpgradeState['pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 5, 'total' => 0];
$domainPermanentUpgradePage = max(1, intval($domainPermanentUpgradePagination['page'] ?? 1));
$domainPermanentUpgradeTotalPages = max(1, intval($domainPermanentUpgradePagination['totalPages'] ?? 1));
$domainPermanentUpgradeAssistLimit = max(0, intval($domainPermanentUpgradeState['helper_assist_limit'] ?? ($domainPermanentUpgradeAssistLimit ?? 0)));
$domainPermanentUpgradePaidEnabled = !empty($domainPermanentUpgradePaidEnabled);
$domainPermanentUpgradePaidPrice = round(max(0, (float) ($domainPermanentUpgradePaidPrice ?? 0)), 2);
$cfCurrencyPrefix = trim((string) (($domainPermanentUpgradeCurrencyPrefix ?? '') ?: ''));
$cfCurrencySuffix = trim((string) (($domainPermanentUpgradeCurrencySuffix ?? '') ?: ''));
$dnsUnlockPriceDisplay = $cfCurrencyPrefix . number_format($dnsUnlockPurchasePrice, 2, '.', '') . $cfCurrencySuffix;
$domainPermanentUpgradePaidPriceDisplay = $cfCurrencyPrefix . number_format($domainPermanentUpgradePaidPrice, 2) . $cfCurrencySuffix;
$domainPermanentUpgradeAssistCount = max(0, intval($domainPermanentUpgradeState['helper_assist_count'] ?? 0));
$domainPermanentUpgradeHelperLimitReached = !empty($domainPermanentUpgradeState['helper_limit_reached']);
$domainPermanentUpgradeAssistLogs = is_array($domainPermanentUpgradeState['assist_logs'] ?? null) ? $domainPermanentUpgradeState['assist_logs'] : [];
$domainPermanentUpgradeAssistLogsPerPage = 5;
$domainPermanentUpgradeAssistLogsTotal = count($domainPermanentUpgradeAssistLogs);
$domainPermanentUpgradeAssistLogsTotalPages = max(1, (int) ceil($domainPermanentUpgradeAssistLogsTotal / $domainPermanentUpgradeAssistLogsPerPage));
$domainPermanentUpgradeAssistLogsPage = isset($_GET['perm_upgrade_logs_page']) ? max(1, (int) $_GET['perm_upgrade_logs_page']) : 1;
if ($domainPermanentUpgradeAssistLogsPage > $domainPermanentUpgradeAssistLogsTotalPages) {
    $domainPermanentUpgradeAssistLogsPage = $domainPermanentUpgradeAssistLogsTotalPages;
}
$domainPermanentUpgradeAssistLogsOffset = ($domainPermanentUpgradeAssistLogsPage - 1) * $domainPermanentUpgradeAssistLogsPerPage;
$domainPermanentUpgradeAssistLogsPaged = array_slice($domainPermanentUpgradeAssistLogs, $domainPermanentUpgradeAssistLogsOffset, $domainPermanentUpgradeAssistLogsPerPage);
$domainPermanentUpgradeRecentSuccessFeed = is_array($domainPermanentUpgradeState['recent_success_feed'] ?? null) ? $domainPermanentUpgradeState['recent_success_feed'] : [];
$domainPermanentUpgradeRealtimeFeedEnabled = !empty($domainPermanentUpgradeState['realtime_feed_enabled']);
$domainPermanentUpgradeAssistRemaining = isset($domainPermanentUpgradeState['helper_assist_remaining']) && $domainPermanentUpgradeState['helper_assist_remaining'] !== null
    ? max(0, (int) $domainPermanentUpgradeState['helper_assist_remaining'])
    : null;
$domainPermanentUpgradeAssistLimitNotice = $domainPermanentUpgradeAssistLimit > 0
    ? $modalText(
        'cfclient.domain_permanent_upgrade.assist.remaining_note',
        '当前还可以为好友助力 %1$s/%2$s 次。',
        [
            $domainPermanentUpgradeAssistRemaining === null ? $domainPermanentUpgradeAssistLimit : $domainPermanentUpgradeAssistRemaining,
            $domainPermanentUpgradeAssistLimit,
        ]
    )
    : $modalText('cfclient.domain_permanent_upgrade.assist.limit_unlimited', '每位用户助力次数：不限制。');
$domainPermanentUpgradeAssistDisabledNotice = $domainPermanentUpgradeHelperLimitReached
    ? $modalText('cfclient.domain_permanent_upgrade.assist.limit_reached_notice', '您已达到助力上限（%1$s/%2$s),暂时无法继续为好友助力!', [$domainPermanentUpgradeAssistCount, $domainPermanentUpgradeAssistLimit])
    : '';
$domainPermanentUpgradeBaseParams = $_GET ?? [];
unset($domainPermanentUpgradeBaseParams['perm_upgrade_page']);
$domainPermanentUpgradeInvoiceBaseParams = $domainPermanentUpgradeBaseParams;
unset($domainPermanentUpgradeInvoiceBaseParams['perm_upgrade_invoice_page']);
$domainPermanentUpgradeInvoiceBaseQuery = http_build_query($domainPermanentUpgradeInvoiceBaseParams);
$domainPermanentUpgradeInvoiceLinkPrefix = $domainPermanentUpgradeInvoiceBaseQuery !== '' ? ('?' . $domainPermanentUpgradeInvoiceBaseQuery . '&perm_upgrade_invoice_page=') : '?perm_upgrade_invoice_page=';
$domainPermanentUpgradeInvoicePerPage = 5;
$domainPermanentUpgradeInvoiceTotal = count($domainPermanentUpgradeInvoicePendingOrders);
$domainPermanentUpgradeInvoiceTotalPages = max(1, (int) ceil($domainPermanentUpgradeInvoiceTotal / $domainPermanentUpgradeInvoicePerPage));
$domainPermanentUpgradeInvoicePage = isset($_GET['perm_upgrade_invoice_page']) ? max(1, (int) $_GET['perm_upgrade_invoice_page']) : 1;
if ($domainPermanentUpgradeInvoicePage > $domainPermanentUpgradeInvoiceTotalPages) {
    $domainPermanentUpgradeInvoicePage = $domainPermanentUpgradeInvoiceTotalPages;
}
$domainPermanentUpgradeInvoiceOffset = ($domainPermanentUpgradeInvoicePage - 1) * $domainPermanentUpgradeInvoicePerPage;
$domainPermanentUpgradeInvoicePendingOrdersPaged = array_slice($domainPermanentUpgradeInvoicePendingOrders, $domainPermanentUpgradeInvoiceOffset, $domainPermanentUpgradeInvoicePerPage);
$domainPermanentUpgradeAssistLogsBaseParams = $domainPermanentUpgradeBaseParams;
unset($domainPermanentUpgradeAssistLogsBaseParams['perm_upgrade_logs_page']);
$domainPermanentUpgradeAssistLogsBaseQuery = http_build_query($domainPermanentUpgradeAssistLogsBaseParams);
$domainPermanentUpgradeAssistLogsLinkPrefix = $domainPermanentUpgradeAssistLogsBaseQuery !== '' ? ('?' . $domainPermanentUpgradeAssistLogsBaseQuery . '&perm_upgrade_logs_page=') : '?perm_upgrade_logs_page=';
$domainPermanentUpgradeBaseQuery = http_build_query($domainPermanentUpgradeBaseParams);
$domainPermanentUpgradeLinkPrefix = $domainPermanentUpgradeBaseQuery !== '' ? ('?' . $domainPermanentUpgradeBaseQuery . '&perm_upgrade_page=') : '?perm_upgrade_page=';

$expiryTelegramReminderFeatureEnabled = !empty($expiryTelegramReminderFeatureEnabled);
$expiryTelegramReminderConfigured = !empty($expiryTelegramReminderConfigured);
$expiryTelegramReminderSubscribed = !empty($expiryTelegramReminderSubscribed);
$expiryTelegramReminderTelegramBound = !empty($expiryTelegramReminderTelegramBound);
$expiryTelegramReminderTelegramUserId = (int) ($expiryTelegramReminderTelegramUserId ?? 0);
$expiryTelegramReminderTelegramUsername = trim((string) ($expiryTelegramReminderTelegramUsername ?? ''));
$expiryTelegramReminderBotUsername = trim((string) ($expiryTelegramReminderBotUsername ?? ''));
$expiryTelegramReminderDaysCsv = trim((string) ($expiryTelegramReminderDaysCsv ?? ''));
if ($expiryTelegramReminderDaysCsv === '' && is_array($expiryTelegramReminderDays ?? null)) {
    $expiryTelegramReminderDaysCsv = implode(',', array_map('intval', $expiryTelegramReminderDays));
}
$expiryTelegramReminderDaysList = [];
if ($expiryTelegramReminderDaysCsv !== '') {
    foreach (preg_split('/\s*,\s*/', $expiryTelegramReminderDaysCsv) as $dayToken) {
        if ($dayToken === '') {
            continue;
        }
        $dayValue = max(0, (int) $dayToken);
        if ($dayValue > 0) {
            $expiryTelegramReminderDaysList[] = $dayValue;
        }
    }
}
$expiryTelegramReminderDaysList = array_values(array_unique($expiryTelegramReminderDaysList));
$expiryTelegramReminderDaysZh = '-';
$expiryTelegramReminderDaysEn = '-';
if (!empty($expiryTelegramReminderDaysList)) {
    $zhParts = array_map(static function (int $day): string {
        return $day . '天';
    }, $expiryTelegramReminderDaysList);
    $expiryTelegramReminderDaysZh = count($zhParts) === 2 ? ($zhParts[0] . '及' . $zhParts[1]) : implode('、', $zhParts);

    $enParts = array_map(static function (int $day): string {
        return $day . ' day' . ($day === 1 ? '' : 's');
    }, $expiryTelegramReminderDaysList);
    $expiryTelegramReminderDaysEn = count($enParts) === 2 ? ($enParts[0] . ' and ' . $enParts[1]) : implode(', ', $enParts);
}
$expiryTelegramReminderDisplayName = $expiryTelegramReminderTelegramUsername !== ''
    ? '@' . ltrim($expiryTelegramReminderTelegramUsername, '@')
    : ($expiryTelegramReminderTelegramUserId > 0 ? ('ID: ' . $expiryTelegramReminderTelegramUserId) : '');

$dnsRecordTypes = [
    'A' => $modalText('cfclient.modals.dns.type.a', 'A记录 (IPv4地址)'),
    'AAAA' => $modalText('cfclient.modals.dns.type.aaaa', 'AAAA记录 (IPv6地址)'),
    'CNAME' => $modalText('cfclient.modals.dns.type.cname', 'CNAME记录 (别名)'),
    'MX' => $modalText('cfclient.modals.dns.type.mx', 'MX记录 (邮件服务器)'),
    'TXT' => $modalText('cfclient.modals.dns.type.txt', 'TXT记录 (文本)'),
    'SRV' => $modalText('cfclient.modals.dns.type.srv', 'SRV记录 (服务)'),
];
if (!$disableNsManagement) {
    $dnsRecordTypes['NS'] = $modalText('cfclient.modals.dns.type.ns', 'NS记录 (DNS服务器/子域授权)');
}
$dnsRecordTypes['CAA'] = $modalText('cfclient.modals.dns.type.caa', 'CAA记录 (证书颁发机构授权)');

$ttlOptions = [
    'auto' => $modalText('cfclient.modals.dns.ttl.auto', $modalIsChinese ? '自动（推荐）' : 'Auto (Recommended)'),
    '600' => $modalText('cfclient.modals.dns.ttl.600', '10分钟'),
    '1800' => $modalText('cfclient.modals.dns.ttl.1800', '30分钟'),
    '3600' => $modalText('cfclient.modals.dns.ttl.3600', '1小时'),
    '7200' => $modalText('cfclient.modals.dns.ttl.7200', '2小时'),
    '14400' => $modalText('cfclient.modals.dns.ttl.14400', '4小时'),
    '28800' => $modalText('cfclient.modals.dns.ttl.28800', '8小时'),
    '86400' => $modalText('cfclient.modals.dns.ttl.86400', '24小时'),
];

$dnsLineOptions = [
    'default' => $modalText('cfclient.modals.dns.line.default', '默认'),
    'telecom' => $modalText('cfclient.modals.dns.line.telecom', '电信'),
    'unicom' => $modalText('cfclient.modals.dns.line.unicom', '联通'),
    'mobile' => $modalText('cfclient.modals.dns.line.mobile', '移动'),
    'oversea' => $modalText('cfclient.modals.dns.line.oversea', '海外'),
    'edu' => $modalText('cfclient.modals.dns.line.edu', '教育网'),
];
$rootVerifyEnabled = !empty($module_settings) && in_array(strtolower(trim((string) ($module_settings['enable_rootdomain_verify'] ?? '0'))), ['1','on','yes','true','enabled'], true);
$rootVerifyTasks = [];
$rootVerifySubdomainMap = [];
$rootVerifyActiveTask = null;
$rootVerifyStickyTxtValue = '';
$rootVerifyStickySubdomainId = 0;
$rootVerifyStickyProvider = '';
if ($rootVerifyEnabled && class_exists('\\WHMCS\\Database\\Capsule') && !empty($userid)) {
    try {
        $rootVerifyTasks = \WHMCS\Database\Capsule::table('mod_cloudflare_root_verify_tasks')->where('client_id', intval($userid))->orderBy('id', 'desc')->limit(5)->get();
        foreach ($rootVerifyTasks as $taskRow) {
            if ($rootVerifyActiveTask === null && in_array((string) ($taskRow->status ?? ''), ['active', 'pending'], true)) {
                $rootVerifyActiveTask = $taskRow;
            }
        }
        if ($rootVerifyActiveTask) {
            $rootVerifyStickyTxtValue = trim((string) ($rootVerifyActiveTask->txt_value ?? ''));
            $rootVerifyStickySubdomainId = intval($rootVerifyActiveTask->subdomain_id ?? 0);
            $rootVerifyStickyProvider = strtolower(trim((string) ($rootVerifyActiveTask->provider ?? '')));
        }
    } catch (\Throwable $e) { $rootVerifyTasks = []; }
}
foreach (($existing ?? []) as $sd) {
    $sid = intval($sd->id ?? 0);
    if ($sid > 0) {
        $rootVerifySubdomainMap[$sid] = (string) ($sd->subdomain ?? '');
    }
}
$rootVerifyActiveLocks = [];
if ($rootVerifyEnabled && class_exists('\\WHMCS\\Database\\Capsule')) {
    try {
        $lockRows = \WHMCS\Database\Capsule::table('mod_cloudflare_root_verify_tasks')
            ->select('rootdomain', 'locked_until', 'client_id')
            ->where('status', 'active')
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', date('Y-m-d H:i:s'))
            ->get();
        foreach ($lockRows as $lr) {
            $root = strtolower(trim((string) ($lr->rootdomain ?? '')));
            if ($root === '') { continue; }
            $rootVerifyActiveLocks[$root] = [
                'locked_until' => (string) ($lr->locked_until ?? ''),
                'client_id' => intval($lr->client_id ?? 0),
            ];
        }
    } catch (\Throwable $e) {}
}
$rootVerifyAliHost = trim((string) ($module_settings['root_verify_alidns_host'] ?? 'alidnscheck'));
if ($rootVerifyAliHost === '') { $rootVerifyAliHost = 'alidnscheck'; }
$rootVerifyDnsPodHost = trim((string) ($module_settings['root_verify_dnspod_host'] ?? 'dnspodcheck'));
if ($rootVerifyDnsPodHost === '') { $rootVerifyDnsPodHost = 'dnspodcheck'; }
$rootVerifyEnableAliDns = in_array(strtolower((string) ($module_settings['root_verify_enable_alidns'] ?? 'yes')), ['1','on','yes','true'], true);
$rootVerifyEnableDnsPod = in_array(strtolower((string) ($module_settings['root_verify_enable_dnspod'] ?? 'yes')), ['1','on','yes','true'], true);
$rootVerifyBlockedRoots = [];
$rootVerifyBlockedRaw = (string) ($module_settings['root_verify_blocked_rootdomains'] ?? '');
foreach (preg_split('/[\r\n,]+/', $rootVerifyBlockedRaw) ?: [] as $blockedRoot) {
    $blockedVal = strtolower(trim((string) $blockedRoot));
    if ($blockedVal !== '') {
        $rootVerifyBlockedRoots[$blockedVal] = true;
    }
}
?>
    <!-- DNS设置模态框 -->
    <div class="modal fade" id="dnsModal" tabindex="-1">
        <div class="modal-dialog dns-modal-dialog" style="max-width:min(620px,94vw);">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus text-primary"></i> <?php echo $modalText('cfclient.modals.dns.title', '添加DNS解析记录'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="dnsForm">
                    <div class="modal-body">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" id="dns_action">
                        <input type="hidden" name="subdomain_id" id="dns_subdomain_id">
                        <input type="hidden" name="record_id" id="dns_record_id">
                        <div id="dns_external_block_alert" class="alert alert-danger d-none" style="display:none;"></div>
                        <div class="small text-muted mb-3"><?php echo $modalText('cfclient.modals.dns.label.current_domain', $modalIsChinese ? '当前域名：' : 'Current domain:'); ?><span class="ms-1 text-dark fw-semibold" id="dns_subdomain_name_text"></span></div>
                        <input type="text" class="form-control d-none" id="dns_subdomain_name" readonly>
                        <div class="row g-3">
                            <div class="col-7">
                                <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.type', '记录类型'); ?></label>
                                <select name="record_type" class="form-select" required>
                                        <?php foreach ($dnsRecordTypes as $value => $label): ?>
                                            <?php $nsLockedAttr = ($value === 'NS' && $dnsUnlockRequired) ? ' disabled data-requires-unlock="1"' : ''; ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $nsLockedAttr; ?>><?php echo $label; ?><?php echo ($value === 'NS' && $dnsUnlockRequired) ? ' (' . $modalText('cfclient.dns_unlock.title', 'DNS 解锁') . ')' : ''; ?></option>
                                        <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.ttl', $modalIsChinese ? 'TTL（分钟）' : 'TTL (Minutes)'); ?></label>
                                <select name="record_ttl" class="form-select">
                                    <?php foreach ($ttlOptions as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value === 'auto' ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.record_name', $modalIsChinese ? '主机记录' : 'Host'); ?></label>
                                <div class="input-group">
                                    <input type="text" name="record_name" id="dns_record_name" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.placeholder.record_name', '@ 或 前缀:如www、mail'); ?>">
                                    <span class="input-group-text">.<span id="dns_record_suffix"></span></span>
                                </div>
                                <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.record_name_compact', $modalIsChinese ? '@表示当前域名本身，www表示 www.当前域名' : '@ represents the current domain itself, and www represents www.current-domain'); ?></div>
                            </div>
                            <div class="col-12" id="record_content_field">
                                <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.content', $modalIsChinese ? '记录值' : 'Value'); ?></label>
                                <input type="text" name="record_content" class="form-control" required placeholder="<?php echo $modalText('cfclient.modals.dns.placeholder.content', '根据记录类型填写相应内容'); ?>">
                                <div class="form-text" id="dns_type_dynamic_hint"><?php echo $modalText('cfclient.modals.dns.hint.type', $modalIsChinese ? '请选择记录类型后按提示填写对应内容。' : 'Select a record type and follow the corresponding hints.'); ?></div>
                                <div class="form-text" id="dns_content_dynamic_hint"><?php echo $modalText('cfclient.modals.dns.hint.content_dynamic_default', '根据记录类型动态显示输入提示。'); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="mb-3" id="caa_fields" style="display: none;">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.caa', 'CAA记录参数'); ?></label>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.flag', 'Flag'); ?></label>
                                            <select name="caa_flag" class="form-select">
                                                <option value="0">0</option>
                                                <option value="128">128</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.tag', 'Tag'); ?></label>
                                            <select name="caa_tag" class="form-select">
                                                <option value="issue">issue</option>
                                                <option value="issuewild">issuewild</option>
                                                <option value="iodef">iodef</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.value', 'Value'); ?></label>
                                            <input type="text" name="caa_value" class="form-control" placeholder="letsencrypt.org">
                                        </div>
                                    </div>
                                    <div class="form-text">
                                        <?php echo $modalText('cfclient.modals.dns.hint.caa.flag', 'Flag: 0=非关键, 128=关键'); ?><br>
                                        <?php echo $modalText('cfclient.modals.dns.hint.caa.tag', 'Tag: issue=允许颁发, issuewild=允许通配符, iodef=违规报告'); ?><br>
                                        <?php echo $modalText('cfclient.modals.dns.hint.caa.value', 'Value: CA域名或邮箱地址'); ?>
                                    </div>
                                </div>
                                <div class="mb-3" id="priority_field" style="display: none;">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.priority', '优先级 (MX/SRV)'); ?></label>
                                    <input type="number" name="record_priority" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.label.priority', '优先级 (MX/SRV)', [], true); ?>" min="0" max="65535" value="10">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.priority', 'MX记录优先级，数值越小优先级越高'); ?></div>
                                </div>
                                <div class="mb-3" id="srv_fields" style="display: none;">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.weight', '权重 (SRV)'); ?></label>
                                    <input type="number" name="record_weight" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.label.weight', '权重 (SRV)', [], true); ?>" min="0" max="65535" value="0">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.weight', '范围 0-65535，数值越大权重越高'); ?></div>
                                    <label class="form-label mt-3"><?php echo $modalText('cfclient.modals.dns.label.port', '端口 (SRV)'); ?></label>
                                    <input type="number" name="record_port" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.dns.label.port', '端口 (SRV)', [], true); ?>" min="1" max="65535" value="1">
                                    <label class="form-label mt-3"><?php echo $modalText('cfclient.modals.dns.label.target', '目标地址 (SRV)'); ?></label>
                                    <input type="text" name="record_target" class="form-control" placeholder="service.example.com">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.target', '填写服务主机名，不带协议'); ?></div>
                                </div>
                            </div>
                            <div class="col-12" style="margin-top:-11px;">
                                <div class="border rounded-3 p-2">
                                    <button class="btn btn-link p-0 text-decoration-none w-100 text-start d-flex justify-content-between align-items-center" type="button" data-bs-toggle="collapse" data-bs-target="#dnsAdvancedSettings" aria-expanded="false" aria-controls="dnsAdvancedSettings">
                                        <span><?php echo $modalText('cfclient.modals.dns.advanced.toggle', $modalIsChinese ? '更多设置' : 'More settings'); ?></span>
                                        <i class="fas fa-chevron-down small" id="dnsAdvancedChevron"></i>
                                    </button>
                                    <div class="collapse mt-1" id="dnsAdvancedSettings">
                                        <div class="mb-1">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.dns.label.line', '解析请求来源（Line）'); ?></label>
                                    <select name="line" class="form-select">
                                        <?php foreach ($dnsLineOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value === 'default' ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.dns.hint.line', $modalIsChinese ? '部分域名可根据访客的运营商或地区选择解析线路。如无特殊需求，建议保持默认。' : 'Some domains support choosing DNS routes based on visitor carrier or region. If you have no special requirements, keeping the default is recommended.'); ?></div>
                                    <div class="form-text"><i class="fas fa-info-circle text-primary me-1"></i><?php echo $modalText('cfclient.modals.dns.hint.line.smart', $modalIsChinese ? '目前只有域名 us.ci 与 cn.mt 支持按线路（运营商/地域）精准解析。' : 'Currently, only the domains us.ci and cn.mt support precise line-based routing (carrier/region).'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="border rounded-2 px-3 py-2" style="background-color:#cff4fc;margin-top:10px;color:#055160;font-size:14px;line-height:20px;"><i class="fas fa-info-circle me-1" style="font-size:16px;"></i><span style="font-weight:500;"><?php echo $modalIsChinese ? '提示：' : 'Tip:'; ?></span> <?php echo $modalText('cfclient.modals.dns.alert.compact', $modalIsChinese ? 'DNS 记录的添加、修改或删除，通常在几分钟内生效。' : 'DNS record additions, updates, or deletions usually take effect within a few minutes.'); ?></div>
                    </div>
                    <div class="modal-footer" style="padding:6px 1rem;align-items:center;">
                        <button type="button" class="btn btn-secondary" style="min-height:40px;line-height:1.2;" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                        <button type="submit" class="btn btn-primary" style="min-height:40px;line-height:1.2;" id="dns_submit_btn">
                            <i class="fas fa-save"></i> <?php echo $modalText('cfclient.modals.buttons.save', '保存设置'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 注册模态框 -->
    <div class="modal fade" id="registerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle text-primary"></i> <?php echo $modalText('cfclient.modals.register.title', '注册新域名'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="registerForm">
                    <div class="modal-body">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <div id="registerErrorAlert" class="alert alert-danger d-none" style="display:none"></div>
                        <input type="hidden" name="action" value="register">
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.register.label.root', '选择根域名'); ?></label>
                            <select name="rootdomain" id="register_rootdomain" class="form-select" required>
                                <option value=""><?php echo $modalText('cfclient.modals.register.placeholder.root', '请选择根域名'); ?></option>
                                <?php if (is_array($roots) && !empty($roots)): ?>
                                    <?php foreach ($roots as $r): ?>
                                        <?php if (!empty($r)): ?>
                                            <?php $limitValue = intval($rootLimitMap[strtolower($r)] ?? 0); ?>
                                            <option value="<?php echo htmlspecialchars($r); ?>" data-limit="<?php echo $limitValue; ?>"><?php echo htmlspecialchars($r); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text text-muted" id="register_limit_hint" style="display:none;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.register.label.prefix', '域名前缀'); ?></label>
                            <div class="input-group">
                                <input type="text" name="subdomain" class="form-control" required placeholder="<?php echo $modalText('cfclient.modals.register.placeholder.prefix', '输入前缀，如: myblog'); ?>" pattern="<?php echo $subPrefixPatternHtml; ?>" minlength="<?php echo $subPrefixMinLength; ?>" maxlength="<?php echo $subPrefixMaxLength; ?>">
                                <span class="input-group-text">.<span id="register_root_suffix"><?php echo $modalText('cfclient.modals.register.label.root', '选择根域名', [], true); ?></span></span>
                            </div>
                            <div class="form-text"><?php echo $modalText('cfclient.modals.register.hint.prefix', '只能包含字母、数字和连字符，长度%1$s-%2$s字符', [$subPrefixMinLength, $subPrefixMaxLength]); ?></div>
                        </div>
                        <div class="mb-3" id="rootdomain_invite_code_container" style="display:none;">
                            <label class="form-label">
                                <i class="fas fa-ticket-alt text-warning"></i> <?php echo $modalText('cfclient.modals.register.label.invite_code', '邀请码'); ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="rootdomain_invite_code" id="rootdomain_invite_code_input" class="form-control" placeholder="<?php echo $modalText('cfclient.modals.register.placeholder.invite_code', '请输入10位邀请码'); ?>" maxlength="10" pattern="[A-Za-z0-9]{10}">
                            <div class="form-text text-info">
                                <i class="fas fa-info-circle"></i> <?php echo $modalText('cfclient.modals.register.hint.invite_code', '该根域名需要邀请码才能注册，请向已注册该根域名的用户获取邀请码'); ?>
                            </div>
                        </div>
                        <div class="alert alert-info" id="registerImportantInfo">
                            <i class="fas fa-info-circle"></i>
                            <strong><?php echo $modalText('cfclient.modals.register.alert.title', '重要说明：'); ?></strong>
                            <ul class="mb-0 mt-2">
                                <li><strong><?php echo $modalText('cfclient.modals.register.alert.1', '注册成功后，您需要手动设置DNS解析'); ?></strong></li>
                                <li><?php echo $modalText('cfclient.modals.register.alert.2', '可以设置A记录、CNAME记录等多种类型'); ?></li>
                                <li><?php echo $modalText('cfclient.modals.register.alert.3', '注册的域名严禁用于违法违规行为'); ?></li>
                                <?php if (!empty($clientDeleteEnabled)): ?>
                                    <li><?php echo $modalText('cfclient.modals.register.alert.delete_enabled', '如需删除，可在“查看详情”中提交自助删除申请。'); ?></li>
                                <?php else: ?>
                                    <li><?php echo $modalText('cfclient.modals.register.alert.delete_disabled', '注册成功的域名不支持删除。如有问题，请联系客服获取帮助'); ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding:8px 1rem;align-items:center;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                        <?php if ($pauseFreeRegistration || $maintenanceMode): ?>
                            <button type="button" class="btn btn-secondary" disabled>
                                <i class="fas fa-pause"></i> <?php echo $modalText('cfclient.modals.buttons.pause', '暂停注册'); ?>
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> <?php echo $modalText('cfclient.modals.buttons.confirm', '确认注册'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- NS 委派管理模态框 -->
    <div class="modal fade" id="nsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-server text-primary"></i> <?php echo $modalText('cfclient.modals.ns.title', 'DNS服务器（域名委派）'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="nsForm">
                    <div class="modal-body">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" value="replace_ns_group">
                        <input type="hidden" name="subdomain_id" id="ns_subdomain_id">
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.ns.label.domain', '域名'); ?></label>
                            <input type="text" class="form-control" id="ns_subdomain_name" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" id="ns_current_label"><?php echo $modalText('cfclient.modals.ns.label.current', '当前 NS'); ?></label>
                            <div id="ns_current" class="small text-muted">(<?php echo $modalText('cfclient.modals.ns.label.current', '当前 NS', [], true); ?>)</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo $modalText('cfclient.modals.ns.label.lines', $nsListLabelDefault); ?></label>
                            <textarea name="ns_lines" id="ns_lines" class="form-control d-none" rows="6"></textarea>
                            <div id="ns_inputs_container" class="ns-inputs-container"></div>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="ns_add_input_btn">
                                    <i class="fas fa-plus"></i> <?php echo $modalText('cfclient.modals.ns.button.add_server', $nsAddButtonDefault); ?>
                                </button>
                            </div>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="ns_switch_default_btn">
                                    <i class="fas fa-sync-alt"></i> <?php echo $modalText('cfclient.modals.ns.button.switch_default', '切换为系统默认DNS地址'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-0 mb-3">
                            <div class="form-check mb-0 ns-force-check">
                                <input class="form-check-input" type="checkbox" name="force_replace" id="force_replace" value="1">
                                <label class="form-check-label ns-force-label" for="force_replace"><?php echo $modalText('cfclient.modals.ns.label.force_short', $nsForceShortDefault); ?></label>
                            </div>
                            <button type="button"
                                    class="btn btn-link ns-force-help p-0"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="bottom"
                                    data-bs-container="body"
                                    data-bs-custom-class="ns-force-tooltip"
                                    data-bs-title="<?php echo htmlspecialchars($modalText('cfclient.modals.ns.label.force', $nsForceTooltipDefault), ENT_QUOTES); ?>"
                                    aria-label="<?php echo htmlspecialchars($modalText('cfclient.modals.ns.label.force', $nsForceTooltipDefault), ENT_QUOTES); ?>">
                                <i class="fas fa-exclamation-circle"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding:6px 1rem;align-items:center;">
                        <button type="button" class="btn btn-secondary" style="min-height:40px;line-height:1.2;" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                        <button type="submit" class="btn btn-primary" style="min-height:40px;line-height:1.2;">
                            <i class="fas fa-save"></i> <?php echo $modalText('cfclient.modals.buttons.save_settings', $nsSaveButtonDefault); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($quotaRedeemEnabled): ?>
    <div class="modal fade" id="quotaRedeemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-gift text-success"></i> <?php echo $modalText('cfclient.modals.redeem.title', '兑换注册额度'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="redeemAlertPlaceholder"></div>
                    <div class="row g-4">
                        <div class="col-md-5">
                            <form id="quotaRedeemForm" data-cfmod-skip-csrf="1">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $modalText('cfclient.modals.redeem.label.code', '输入兑换码'); ?></label>
                                    <input type="text" class="form-control" id="redeemCodeInput" placeholder="<?php echo $modalText('cfclient.modals.redeem.placeholder', '请输入兑换码'); ?>" autocomplete="off">
                                    <div class="form-text"><?php echo $modalText('cfclient.modals.redeem.help', '兑换成功后，将自动增加您的注册额度。'); ?></div>
                                </div>
                                <button type="submit" class="btn btn-success w-100" id="redeemSubmitButton">
                                    <i class="fas fa-check-circle"></i> <?php echo $modalText('cfclient.modals.redeem.button', '立即兑换'); ?>
                                </button>
                            </form>
                            <div class="mt-4">
                                <?php
                                    $redeemLang = isset($currentClientLanguage) ? strtolower((string) $currentClientLanguage) : 'english';
                                    $isRedeemChinese = $redeemLang === 'chinese';
                                    $faqTitle = $isRedeemChinese ? '常见问题' : 'FAQ';
                                ?>
                                <h6 class="text-muted"><i class="fas fa-question-circle me-1"></i> <?php echo htmlspecialchars($faqTitle); ?></h6>
                                <div class="small text-muted">
                                    <?php
                                        $faqQ1 = $isRedeemChinese ? '如何获取兑换码？' : 'How do I get a redeem code?';
                                        $faqA1 = $isRedeemChinese
                                            ? '兑换码通常来自官方活动、邀请排行榜奖励或管理员单独派发，请留意公告或联系支持。'
                                            : 'Redeem codes are issued via official campaigns, invite leaderboard rewards, or direct support grants—follow announcements or contact support.';
                                        $faqQ2 = $isRedeemChinese ? '兑换成功后额度会过期吗？' : 'Does the redeemed quota expire?';
                                        $faqA2 = $isRedeemChinese
                                            ? '不会过期，额度会一直保留在您的账户中，直到全部使用完毕。'
                                            : 'Redeemed quota never expires and stays in your account until it is fully consumed.';
                                        $faqQ3 = $isRedeemChinese ? '兑换失败怎么办？' : 'What if redemption fails?';
                                        $faqA3 = $isRedeemChinese
                                            ? '请检查兑换码是否输入正确或已使用，如仍无法兑换，请提交工单或联系在线客服协助处理。'
                                            : 'Verify whether the code is correct or already used. If the issue persists, please open a ticket or contact support for help.';
                                    ?>
                                    <p class="mb-2"><strong><?php echo htmlspecialchars($faqQ1); ?></strong><br><?php echo htmlspecialchars($faqA1); ?></p>
                                    <p class="mb-2"><strong><?php echo htmlspecialchars($faqQ2); ?></strong><br><?php echo htmlspecialchars($faqA2); ?></p>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($faqQ3); ?></strong><br><?php echo htmlspecialchars($faqA3); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <h6 class="mb-3"><i class="fas fa-history text-muted"></i> <?php echo $modalText('cfclient.modals.redeem.history.title', '兑换历史'); ?></h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" id="redeemHistoryTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.code', '兑换码'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.amount', '增加额度'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.time', '兑换时间'); ?></th>
                                            <th><?php echo $modalText('cfclient.modals.redeem.history.status', '状态'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="redeemHistoryBody">
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3"><?php echo $modalText('cfclient.modals.redeem.history.placeholder', '暂无兑换记录'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <nav class="mt-3">
                                <ul class="pagination pagination-sm justify-content-end mb-0" id="redeemHistoryPagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>



<?php if (!empty($dnsUnlockFeatureEnabled)): ?>
<?php
$dnsUnlockCode = $dnsUnlockModalData['code'] ?? '';
$dnsUnlockUnlocked = !empty($dnsUnlockModalData['unlocked']);
$unlockInputDisabled = $dnsUnlockUnlocked;
$dnsUnlockLastUsedCode = strtoupper(trim((string) ($dnsUnlockModalData['last_used_code'] ?? '')));
$unlockCodeUpper = strtoupper(trim((string) $dnsUnlockCode));
$unlockUsedMessage = '';
if ($dnsUnlockUnlocked) {
    $displayCode = $dnsUnlockLastUsedCode !== '' ? $dnsUnlockLastUsedCode : $unlockCodeUpper;
    if ($displayCode !== '') {
        $unlockUsedMessage = $modalText('cfclient.dns_unlock.used_code', '已使用解锁码：%s', [$displayCode]);
    }
}
$unlockInputPrefillAttr = $unlockUsedMessage !== '' ? ' value="' . htmlspecialchars($unlockUsedMessage, ENT_QUOTES) . '"' : '';
$dnsUnlockLogs = $dnsUnlockModalData['logs'] ?? [];
$dnsUnlockPagination = $dnsUnlockModalData['pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 10, 'total' => 0];
$unlockPage = max(1, intval($dnsUnlockPagination['page'] ?? 1));
$unlockTotalPages = max(1, intval($dnsUnlockPagination['totalPages'] ?? 1));
$unlockBaseParams = $_GET ?? [];
unset($unlockBaseParams['unlock_page']);
$unlockBaseQuery = http_build_query($unlockBaseParams);
$unlockLinkPrefix = $unlockBaseQuery !== '' ? ('?' . $unlockBaseQuery . '&unlock_page=') : '?unlock_page=';
?>
<div class="modal fade" id="dnsUnlockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-unlock-alt me-2"></i> <?php echo $modalText('cfclient.dns_unlock.title', 'DNS 解锁'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert <?php echo $dnsUnlockUnlocked ? 'alert-success' : 'alert-warning'; ?>">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php if ($dnsUnlockUnlocked): ?>
                        <?php echo $modalText('cfclient.dns_unlock.unlocked', 'DNS 解锁已完成，可以随时设置 NS。'); ?>
                    <?php else: ?>
                        <?php echo $modalText('cfclient.dns_unlock.locked', '首次设置 NS 服务器前需要输入解锁码，分享给协作者时可查看记录。'); ?>
                        <br>
                        <small class="text-muted d-block mt-1"><i class="fas fa-exclamation-triangle me-1"></i><?php echo $modalText('cfclient.dns_unlock.warning', 'Reminder: Sharing unlock code binds you to their behavior.'); ?></small>
                    <?php endif; ?>
                </div>
                <?php if ($dnsUnlockShareAllowed): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php echo $modalText('cfclient.dns_unlock.code_label', '我的解锁码'); ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="dnsUnlockCodeText" value="<?php echo htmlspecialchars($dnsUnlockCode, ENT_QUOTES); ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="copyDnsUnlockCode()">
                            <i class="fas fa-copy"></i> <?php echo $modalText('cfclient.dns_unlock.copy', '复制'); ?>
                        </button>
                    </div>
                    <small class="text-muted d-block mt-2"><?php echo $modalText('cfclient.dns_unlock.single_use_note', '解锁码仅限一次使用，使用后会立即失效并自动生成新的解锁码。'); ?></small>
                </div>
                <?php endif; ?>
                <?php if (!$dnsUnlockUnlocked && $dnsUnlockPurchaseEnabled && $dnsUnlockPurchasePrice > 0): ?>
                <div class="alert alert-info d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <h6 class="mb-1 text-info"><?php echo $modalText('cfclient.dns_unlock.purchase_title', '快速解锁 (余额支付)'); ?></h6>
                        <p class="mb-0 small text-muted"><?php echo $modalText('cfclient.dns_unlock.purchase_desc', '支付余额 %s 即可立即解锁，无需输入协作解锁码。', [$dnsUnlockPriceDisplay]); ?></p>
                    </div>
                    <form method="post" class="mb-0 d-flex gap-2 align-items-center">
                        <input type="hidden" name="action" value="purchase_dns_unlock">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-wallet me-1"></i> <?php echo $modalText('cfclient.dns_unlock.purchase_button', '使用余额解锁'); ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                <?php if ($dnsUnlockShareAllowed): ?>
                <form method="post" class="mb-4">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="dns_unlock">
                    <label class="form-label fw-semibold" for="dns_unlock_input"><?php echo $modalText('cfclient.dns_unlock.input_label', '输入解锁码'); ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" name="unlock_code" id="dns_unlock_input" placeholder="<?php echo $modalText('cfclient.dns_unlock.input_placeholder', '例如：AB12CDEF34'); ?>" maxlength="16"<?php echo $unlockInputPrefillAttr; ?><?php echo $unlockInputDisabled ? ' disabled' : ' required'; ?>>
                        <button type="submit" class="btn btn-primary" <?php echo $unlockInputDisabled ? 'disabled' : ''; ?>>
                            <i class="fas fa-unlock"></i> <?php echo $modalText('cfclient.dns_unlock.submit', '立即解锁'); ?>
                        </button>
                    </div>
                </form>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?php echo $modalText('cfclient.dns_unlock.logs_title', '解锁码使用记录'); ?></h6>
                    <small class="text-muted"><?php echo $modalText('cfclient.dns_unlock.logs_hint', '最多展示最近 10 条记录，邮箱已脱敏'); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.dns_unlock.logs_email', '使用者邮箱'); ?></th>
                                <th><?php echo $modalText('cfclient.dns_unlock.logs_time', '时间'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dnsUnlockLogs)): ?>
                                <?php foreach ($dnsUnlockLogs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['email_masked'] ?? '-', ENT_QUOTES); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($log['used_at'] ?? '-', ENT_QUOTES); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted py-3"><?php echo $modalText('cfclient.dns_unlock.logs_empty', '暂无使用记录'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($unlockTotalPages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm">
                            <?php $prevPage = max(1, $unlockPage - 1); ?>
                            <li class="page-item <?php echo $unlockPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($unlockLinkPrefix . $prevPage, ENT_QUOTES); ?>#dnsUnlockModal" aria-label="<?php echo $modalText('cfclient.dns_unlock.pagination.prev', '上一页'); ?>">&laquo;</a>
                            </li>
                            <?php for ($i = 1; $i <= $unlockTotalPages; $i++): ?>
                                <li class="page-item <?php echo $unlockPage == $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($unlockLinkPrefix . $i, ENT_QUOTES); ?>#dnsUnlockModal"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php $nextPage = min($unlockTotalPages, $unlockPage + 1); ?>
                            <li class="page-item <?php echo $unlockPage >= $unlockTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($unlockLinkPrefix . $nextPage, ENT_QUOTES); ?>#dnsUnlockModal" aria-label="<?php echo $modalText('cfclient.dns_unlock.pagination.next', '下一页'); ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-wallet me-1"></i> <?php echo $modalText('cfclient.dns_unlock.share_disabled', '当前仅支持余额解锁，请使用付费解锁功能或联系管理员。'); ?>
                </div>
                <div class="small text-muted mt-3 border-top pt-3">
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.liability.q', $modalIsChinese ? 'Q：邀请注册需要承担连带责任吗？' : 'Q: Do I bear joint liability when inviting registrations?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.liability.a', $modalIsChinese ? 'A：不需要承担责任，好友违规使用不会影响到您的账户，但是请您记得提醒好友遵守使用条款。' : 'A: You do not need to bear responsibility. A friend’s misuse will not affect your account, but please remind them to follow the Terms of Service.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.mode.q', $modalIsChinese ? 'Q：一次性和固定邀请码是什么意思？' : 'Q: What is the difference between one-time and fixed invite codes?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.mode.a', $modalIsChinese ? 'A：一次性邀请码只能使用一次,使用后自动作废,固定邀请码即为长效邀请码可以使用到您的邀请额度用完。' : 'A: A one-time invite code can only be used once and is automatically invalidated after use. A fixed invite code is long-lived and can be used until your invite quota is exhausted.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.reward.q', $modalIsChinese ? 'Q：邀请好友注册，我可以获得额度奖励吗？' : 'Q: If I invite a friend to register, can I get a quota bonus?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.reward.a', $modalIsChinese ? 'A：好友通过您的邀请码或专属链接成功注册成功后，您与好友均可额外获得 1 个注册额度。' : 'A: After your friend successfully registers via your invite code or exclusive link, both you and your friend will receive 1 extra registration quota.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.reward_limit.q', $modalIsChinese ? 'Q：邀请好友注册的奖励额度有上限吗？' : 'Q: Is there a limit to invite registration bonus quota?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.reward_limit.a', $modalIsChinese ? 'A：邀请方奖励的额度上限为 5 个。超出上限后您仍可继续邀请好友完成注册，但您将不再获得额外的额度奖励。' : 'A: The inviter bonus is capped at 5 quota units. You can still invite friends after reaching the cap, but you will no longer receive additional quota bonuses.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.invitee_after_cap.q', $modalIsChinese ? 'Q：邀请人的奖励额度达到上限后，被邀请的人还能拿到奖励吗？' : 'Q: If the inviter reaches the reward cap, can the invitee still get a bonus?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.invitee_after_cap.a', $modalIsChinese ? 'A：可以的，完全不受影响！邀请方的额度达到上限，并不会影响被邀请方的福利。好友通过您的链接邀请码成功注册，依然可以获得额外的注册额度。' : 'A: Yes, absolutely. The inviter reaching the cap does not affect the invitee. Friends who successfully register through your link or code can still receive extra registration quota.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.non_invite_methods.q', $modalIsChinese ? 'Q：使用其他非邀请方式注册，可以获得额度奖励吗？' : 'Q: Can I get a quota bonus if I register through non-invite methods?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.non_invite_methods.a', $modalIsChinese ? 'A：不可以。额外额度是专属的邀请奖励。通过 GitHub 认证、Telegram 机器人绑定发放的邀请码等自助解锁方式完成注册，无法获得额外的注册额度。' : 'A: No. Extra quota is exclusive to invitation rewards. Self-service unlock methods such as GitHub authentication or invite codes issued via Telegram bot binding do not grant additional registration quota.'); ?>
                    </div>
                    <div>
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.fixed_after_onetime.q', $modalIsChinese ? 'Q：如果生成了一次性还可以生成固定邀请码吗？' : 'Q: Can I generate a fixed invite code after creating one-time codes?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.fixed_after_onetime.a', $modalIsChinese ? 'A：可以生成，但是邀请码额度用完自动作废.也就是固定邀请码邀请数量达到上限一次性邀请码也会被作废。' : 'A: Yes. But once your invite quota is exhausted, codes are automatically invalidated. In other words, when fixed-code invitations reach the limit, one-time codes are also invalidated.'); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$orphanDnsCleanupEnabled = !empty($module_settings) && in_array(strtolower(trim((string) ($module_settings['client_orphan_dns_cleanup_enabled'] ?? '0'))), ['1','on','yes','true','enabled'], true);
$orphanCleanupDomains = is_array($orphanCleanupDomains ?? null) ? $orphanCleanupDomains : [];
?>
<?php if ($orphanDnsCleanupEnabled): ?>
<div class="modal fade" id="orphanDnsCleanupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" onsubmit="return confirmOrphanCleanupSubmission();">
                <input type="hidden" name="action" value="cleanup_orphan_dns_records">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-broom text-danger me-2"></i><?php echo $modalText('cfclient.orphan_cleanup.modal.title', '孤儿记录清理', [], true); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        <?php echo $modalText('cfclient.orphan_cleanup.modal.warning', '⚠️ 提交后会删除所选域名全部 DNS 记录，此功能用于处理域名添加DNS解析记录时：提示已存在或冲突看不到记录的问题，此操作不可逆请谨慎操作！！！', [], true); ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted" for="orphanCleanupDomainSearchInput"><?php echo $modalText('cfclient.orphan_cleanup.modal.search_label', '搜索域名', [], true); ?></label>
                        <input type="text" class="form-control form-control-sm mb-2" id="orphanCleanupDomainSearchInput" placeholder="<?php echo htmlspecialchars($modalText('cfclient.orphan_cleanup.modal.search_placeholder', '输入关键字过滤域名列表', [], false), ENT_QUOTES); ?>" autocomplete="off">
                        <label class="form-label small text-muted" for="orphanCleanupSubdomainSelect"><?php echo $modalText('cfclient.orphan_cleanup.modal.domain_label', '选择域名', [], true); ?></label>
                        <select class="form-select" id="orphanCleanupSubdomainSelect" name="orphan_cleanup_subdomain_id" required>
                            <option value=""><?php echo $modalText('cfclient.orphan_cleanup.modal.domain_placeholder', '请输入关键字搜索或选择要清理的域名', [], true); ?></option>
                        </select>
                        <div class="form-text" id="orphanCleanupSearchStatus"><?php echo $modalText('cfclient.orphan_cleanup.modal.search_hint', '打开弹窗后将按需加载域名，输入关键字可远程搜索。', [], true); ?></div>
                        <div class="d-flex align-items-center justify-content-between gap-2 mt-2" id="orphanCleanupPagination" style="display:none;">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="orphanCleanupPrevPage"><?php echo $modalText('cfclient.pagination.prev', '上一页', [], true); ?></button>
                            <span class="small text-muted" id="orphanCleanupPageInfo"></span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="orphanCleanupNextPage"><?php echo $modalText('cfclient.pagination.next', '下一页', [], true); ?></button>
                        </div>
                    </div>
                    <div>
                        <label class="form-label small text-muted" for="orphanCleanupConfirmDomainInput"><?php echo $modalText('cfclient.orphan_cleanup.modal.confirm_label', '手动输入域名确认', [], true); ?></label>
                        <input type="text" class="form-control" id="orphanCleanupConfirmDomainInput" name="orphan_cleanup_confirm_domain" placeholder="<?php echo htmlspecialchars($modalText('cfclient.orphan_cleanup.modal.confirm_placeholder', '请完整输入所选域名', [], false), ENT_QUOTES); ?>" autocomplete="off" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消', [], true); ?></button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i><?php echo $modalText('cfclient.orphan_cleanup.modal.submit', '提交清理', [], true); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($inviteRegistrationCenterVisible) || !empty($domainPermanentUpgradeEnabled)): ?>
<?php
$inviteRegistrationInviteEnabled = !empty($inviteRegistrationInviteEnabled);
$inviteRegistrationCenterVisible = !empty($inviteRegistrationCenterVisible);
$inviteRegGenerationLockedByGateDisabled = !empty($inviteRegistrationGenerationLockedByGateDisabled);
$inviteRegData = $inviteRegistration ?? [];
$inviteRegCode = $inviteRegData['code'] ?? '';
$inviteRegUnlocked = !empty($inviteRegData['unlocked']);
$inviteRegQuotaExhausted = !empty($inviteRegistrationQuotaExhausted);
$inviteRegAgeInsufficient = !empty($inviteRegistrationAgeInsufficient) || !empty($inviteRegData['age_insufficient']);
$inviteRegRequiredMonths = max(0, intval($inviteRegData['required_months'] ?? ($inviteRegistrationInviterMinMonths ?? 0)));
$inviteRegCopyDisabled = $inviteRegQuotaExhausted || $inviteRegAgeInsufficient;
if ($inviteRegAgeInsufficient && $inviteRegRequiredMonths > 0) {
    $inviteRegCodeDisplay = $modalText('cfclient.invite_registration.age_insufficient', '账户注册时间不足 %s 个月，无法生成邀请码。', [$inviteRegRequiredMonths]);
} elseif ($inviteRegQuotaExhausted) {
    $inviteRegCodeDisplay = $modalText('cfclient.invite_registration.quota_exhausted', '您的邀请名额已用完，暂无法生成新的邀请码。');
} else {
    $inviteRegCodeDisplay = $inviteRegCode;
}
$inviteRegLogs = $inviteRegData['logs'] ?? [];
$inviteRegPagination = $inviteRegData['pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 5, 'total' => 0];
$inviteRegPage = max(1, intval($inviteRegPagination['page'] ?? 1));
$inviteRegTotalPages = max(1, intval($inviteRegPagination['totalPages'] ?? 1));
$inviteRegBaseParams = $_GET ?? [];
unset($inviteRegBaseParams['invite_reg_page']);
$inviteRegBaseQuery = http_build_query($inviteRegBaseParams);
$inviteRegLinkPrefix = $inviteRegBaseQuery !== '' ? ('?' . $inviteRegBaseQuery . '&invite_reg_page=') : '?invite_reg_page=';
$inviteRegMaxPerUser = intval($inviteRegistrationMaxPerUser ?? 0);
$inviteRegUnusedCodes = $inviteRegData['unused_codes'] ?? [];
$inviteRegUnusedPagination = $inviteRegData['unused_pagination'] ?? ['page' => 1, 'totalPages' => 1, 'perPage' => 5, 'total' => 0];
$inviteRegCodePage = max(1, intval($inviteRegUnusedPagination['page'] ?? 1));
$inviteRegCodeTotalPages = max(1, intval($inviteRegUnusedPagination['totalPages'] ?? 1));
$inviteRegCodeBaseParams = $_GET ?? [];
unset($inviteRegCodeBaseParams['invite_code_page']);
$inviteRegCodeBaseQuery = http_build_query($inviteRegCodeBaseParams);
$inviteRegCodeLinkPrefix = $inviteRegCodeBaseQuery !== '' ? ('?' . $inviteRegCodeBaseQuery . '&invite_code_page=') : '?invite_code_page=';
$inviteRegRemainingQuotaRaw = $inviteRegistrationRemainingQuota ?? $inviteRegMaxPerUser;
$inviteRegRemainingQuota = (is_int($inviteRegRemainingQuotaRaw) || ctype_digit((string) $inviteRegRemainingQuotaRaw)) ? (int) $inviteRegRemainingQuotaRaw : 0;
$inviteRegBatchMax = max(1, (int) ($inviteRegistrationBatchMax ?? 50));
$inviteRegCanCustomCode = !empty($inviteRegistrationCanCustomCode);
$inviteRegInputMax = $inviteRegRemainingQuota >= 999999999 ? $inviteRegBatchMax : min(max(0, $inviteRegRemainingQuota), $inviteRegBatchMax);
$inviteRegGenerateDisabled = $inviteRegGenerationLockedByGateDisabled || $inviteRegCopyDisabled || ($inviteRegRemainingQuota !== PHP_INT_MAX && $inviteRegRemainingQuota <= 0);
$inviteRegBidirectionalRewardEnabled = !empty($module_settings)
    && in_array(strtolower(trim((string) ($module_settings['invite_registration_bidirectional_quota_reward_enabled'] ?? 'on'))), ['1', 'on', 'yes', 'true', 'enabled'], true);
$inviteRegPendingCode = strtoupper(trim((string) ($inviteRegistrationPendingCode ?? '')));
$inviteRegCanAutoUnlock = !$inviteRegUnlocked && $inviteRegistrationInviteEnabled && $inviteRegPendingCode !== '';
?>
<?php if ($inviteRegistrationCenterVisible): ?>
<div class="modal fade" id="inviteRegistrationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> <?php echo $modalText('cfclient.invite_registration.title', '邀请注册'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert <?php echo $inviteRegUnlocked ? 'alert-warning' : 'alert-info'; ?>">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php if ($inviteRegUnlocked): ?>
                        <?php echo $modalText(
                            $inviteRegBidirectionalRewardEnabled ? 'cfclient.invite_registration.warning_with_reward' : 'cfclient.invite_registration.warning',
                            $modalIsChinese
                                ? ($inviteRegBidirectionalRewardEnabled
                                    ? '您可以将邀请码分享至好友进行注册使用。系统将根据账户注册时长，阶梯式开放更多邀请额度。'
                                    : '您可以将邀请码分享至好友进行注册使用。系统将根据账户注册时长，阶梯式开放更多邀请额度。')
                                : ($inviteRegBidirectionalRewardEnabled
                                    ? 'You can share invite codes with friends for registration. The system will progressively unlock more invite quotas based on account registration age.'
                                    : 'You can share invite codes with friends for registration. The system will progressively unlock more invite quotas based on account registration age.')
                        ); ?>
                    <?php else: ?>
                        <?php echo $modalText('cfclient.invite_registration.locked', '新用户需要输入邀请码才能使用本系统，请向已有用户获取邀请码。'); ?>
                    <?php endif; ?>
                </div>
                <?php if ($inviteRegGenerationLockedByGateDisabled): ?>
                    <div class="alert alert-secondary">
                        <i class="fas fa-lock me-1"></i>
                        <?php echo $modalText(
                            'cfclient.invite_registration.read_only_when_gate_disabled',
                            $modalIsChinese
                                ? '由于系统限制:当前邀请注册功能仅提供邀请记录/历史邀请码查看，暂时无法生成邀请码。'
                                : 'Due to system limitations, Invite Registration currently only supports viewing invitation history/legacy codes and cannot generate new invite codes at this time.'
                        ); ?>
                    </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?php echo $modalText('cfclient.invite_registration.my_code', '我的邀请码'); ?></label>
                    <?php
                    $inviteRegHasFixedCode = false;
                    if (!empty($inviteRegUnusedCodes) && is_array($inviteRegUnusedCodes)) {
                        foreach ($inviteRegUnusedCodes as $inviteRegCodeItem) {
                            if (($inviteRegCodeItem['mode'] ?? 'one_time') === 'fixed') {
                                $inviteRegHasFixedCode = true;
                                break;
                            }
                        }
                    }
                    ?>
                    <form method="post" class="row g-2 align-items-center mb-2">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" value="invite_registration_generate_codes">
                        <div class="col-sm-4">
                            <input type="number" class="form-control" id="invite_generate_count" name="invite_generate_count" min="1" max="<?php echo max(1, $inviteRegInputMax); ?>" value="1" <?php echo $inviteRegGenerateDisabled ? 'disabled' : ''; ?>>
                        </div>
                        <div class="col-sm-4">
                            <select class="form-select" id="invite_mode_select" name="invite_mode" <?php echo $inviteRegGenerateDisabled ? 'disabled' : ''; ?>>
                                <option value="one_time"><?php echo $modalText('cfclient.invite_registration.mode.one_time', $modalIsChinese ? '一次性邀请码' : 'One-time Code'); ?></option>
                                <option value="fixed"><?php echo $modalText('cfclient.invite_registration.mode.fixed', $modalIsChinese ? '固定邀请码' : 'Fixed Code'); ?></option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <button type="submit" id="inviteGenerateSubmitBtn" class="btn btn-primary btn-sm" <?php echo $inviteRegGenerateDisabled ? 'disabled' : ''; ?>>
                                <i class="fas fa-plus-circle me-1"></i><?php echo $modalText('cfclient.invite_registration.generate_codes', $modalIsChinese ? '生成邀请码' : 'Generate Invite Codes'); ?>
                            </button>
                        </div>
                        <?php if ($inviteRegCanCustomCode): ?>
                        <div class="col-12">
                            <input type="text" class="form-control text-uppercase" id="invite_custom_code_input" name="invite_custom_code" placeholder="<?php echo $modalText('cfclient.invite_registration.custom_placeholder', $modalIsChinese ? '白名单用户可选填自定义邀请码（6-20位字母数字）；留空则随机生成' : 'Whitelist users may optionally enter a custom code (6-20 alnum); leave blank for random generation'); ?>" maxlength="20" autocomplete="off">
                        </div>
                        <?php endif; ?>
                    </form>
                    <small class="text-muted d-block mb-2">
                        <?php echo $modalText('cfclient.invite_registration.remaining_quota', $modalIsChinese ? '剩余额度' : 'Remaining Quota'); ?>:
                        <strong id="inviteRemainingQuotaText"><?php echo $inviteRegRemainingQuota >= 999999999 ? $modalText('cfclient.invite_registration.unlimited', $modalIsChinese ? '不限' : 'Unlimited') : max(0, $inviteRegRemainingQuota); ?></strong>
                        <?php if ($inviteRegMaxPerUser > 0): ?> / <?php echo $modalText('cfclient.invite_registration.total_quota', $modalIsChinese ? '总额度' : 'Total Quota'); ?> <?php echo $inviteRegMaxPerUser; ?><?php endif; ?>
                    </small>
                    <small class="text-muted d-block mb-2"><?php echo $modalText('cfclient.invite_registration.batch_max_hint', $modalIsChinese ? '单次最多可生成 %s 个邀请码。' : 'You can generate up to %s invite codes per request.', [$inviteRegBatchMax]); ?></small>
                    <?php if ($inviteRegRemainingQuota !== PHP_INT_MAX && $inviteRegRemainingQuota <= 0): ?>
                        <small class="text-warning d-block mb-2"><?php echo $modalText('cfclient.invite_registration.quota_zero_hint', $modalIsChinese ? '剩余额度为 0，暂不可生成邀请码。' : 'Remaining quota is 0, invite code generation is temporarily disabled.'); ?></small>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.invite_registration.code_column', $modalIsChinese ? '邀请码' : 'Invite Code'); ?></th>
                                <th><?php echo $modalText('cfclient.invite_registration.link_column', $modalIsChinese ? '邀请链接' : 'Invite Link'); ?></th>
                                <th><?php echo $modalText('cfclient.invite_registration.created_at_column', $modalIsChinese ? '创建时间' : 'Created At'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($inviteRegUnusedCodes)): foreach ($inviteRegUnusedCodes as $codeItem): ?>
                                <?php
                                $inviteCodeValue = strtoupper(trim((string) ($codeItem['invite_code'] ?? '')));
                                $inviteLink = '';
                                if ($inviteCodeValue !== '' && class_exists('CfClientController')) {
                                    $inviteLink = CfClientController::buildPreferredClientUrl('domain_hub', ['view' => 'tools', 'invite_code' => $inviteCodeValue]);
                                } elseif ($inviteCodeValue !== '') {
                                    $inviteLink = 'index.php?m=domain_hub&view=tools&invite_code=' . urlencode($inviteCodeValue);
                                }
                                if ($inviteLink !== '' && strpos($inviteLink, 'http://') !== 0 && strpos($inviteLink, 'https://') !== 0) {
                                    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
                                    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
                                    if ($host !== '') {
                                        $inviteLink = $scheme . '://' . $host . '/' . ltrim($inviteLink, '/');
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <code><?php echo htmlspecialchars($inviteCodeValue, ENT_QUOTES); ?></code>
                                        <?php if (($codeItem['mode'] ?? 'one_time') === 'fixed'): ?>
                                            <span class="badge bg-info"><?php echo $modalText('cfclient.invite_registration.mode.fixed', $modalIsChinese ? '固定邀请码' : 'Fixed Code'); ?></span>
                                            <?php if (!$inviteRegGenerateDisabled): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($modalText('cfclient.invite_registration.invalidate_confirm', $modalIsChinese ? '确认作废当前固定邀请码？作废后不会自动生成新邀请码。' : 'Invalidate current fixed invite code? A new code will not be generated automatically.'), ENT_QUOTES); ?>');">
                                                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES); ?>">
                                                    <input type="hidden" name="action" value="invite_registration_invalidate_fixed_code">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-link btn-sm text-danger p-0 ms-2 align-baseline"
                                                        title="<?php echo htmlspecialchars($modalText('cfclient.invite_registration.invalidate_fixed', $modalIsChinese ? '作废' : 'Invalidate'), ENT_QUOTES); ?>"
                                                        aria-label="<?php echo htmlspecialchars($modalText('cfclient.invite_registration.invalidate_fixed', $modalIsChinese ? '作废' : 'Invalidate'), ENT_QUOTES); ?>"
                                                    ><i class="fas fa-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-info"><?php echo $modalText('cfclient.invite_registration.mode.one_time', $modalIsChinese ? '一次性邀请码' : 'One-time Code'); ?></span>
                                            <?php if (!$inviteRegGenerateDisabled && (int) ($codeItem['id'] ?? 0) > 0): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($modalText('cfclient.invite_registration.invalidate_one_time_confirm', $modalIsChinese ? '确认作废该一次性邀请码？作废后不可恢复。' : 'Invalidate this one-time invite code? This cannot be undone.'), ENT_QUOTES); ?>');">
                                                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES); ?>">
                                                    <input type="hidden" name="action" value="invite_registration_invalidate_one_time_code">
                                                    <input type="hidden" name="invite_code_id" value="<?php echo (int) ($codeItem['id'] ?? 0); ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-link btn-sm text-danger p-0 ms-2 align-baseline"
                                                        title="<?php echo htmlspecialchars($modalText('cfclient.invite_registration.invalidate_one_time', $modalIsChinese ? '作废' : 'Invalidate'), ENT_QUOTES); ?>"
                                                        aria-label="<?php echo htmlspecialchars($modalText('cfclient.invite_registration.invalidate_one_time', $modalIsChinese ? '作废' : 'Invalidate'), ENT_QUOTES); ?>"
                                                    ><i class="fas fa-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($inviteLink !== ''): ?>
                                            <div class="input-group input-group-sm">
                                                <button
                                                    class="btn btn-outline-secondary"
                                                    type="button"
                                                    title="<?php echo $modalText('cfclient.common.copy', '复制'); ?>"
                                                    onclick="copyInviteRegLink(this)"
                                                    data-link="<?php echo htmlspecialchars($inviteLink, ENT_QUOTES); ?>"
                                                >
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($inviteLink, ENT_QUOTES); ?>" readonly onclick="this.select();">
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($codeItem['created_at'] ?? '-', ENT_QUOTES); ?></small></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-3"><?php echo $modalText('cfclient.invite_registration.codes_empty', $modalIsChinese ? '暂无可用邀请码' : 'No available invite codes'); ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($inviteRegCodeTotalPages > 1): ?><nav><ul class="pagination pagination-sm">
                        <?php if ($inviteRegCodePage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($inviteRegCodeLinkPrefix . ($inviteRegCodePage - 1), ENT_QUOTES); ?>#inviteRegistrationModal">&laquo;</a></li>
                        <?php endif; ?>
                        <?php for ($i=1;$i<=$inviteRegCodeTotalPages;$i++): ?>
                            <?php if ($inviteRegCodePage == $i): ?>
                                <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                            <?php elseif ($i == 1 || $i == $inviteRegCodeTotalPages || abs($i - $inviteRegCodePage) <= 2): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($inviteRegCodeLinkPrefix . $i, ENT_QUOTES); ?>#inviteRegistrationModal"><?php echo $i; ?></a></li>
                            <?php elseif (abs($i - $inviteRegCodePage) == 3): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($inviteRegCodePage < $inviteRegCodeTotalPages): ?>
                            <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($inviteRegCodeLinkPrefix . ($inviteRegCodePage + 1), ENT_QUOTES); ?>#inviteRegistrationModal">&raquo;</a></li>
                        <?php endif; ?>
                    </ul></nav><?php endif; ?>
                </div>
                <hr>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?php echo $modalText('cfclient.invite_registration.logs_title', '我的邀请记录'); ?></h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.invite_registration.logs_email', '被邀请者邮箱'); ?></th>
                                <th><?php echo $modalText('cfclient.invite_registration.logs_code', '使用的邀请码'); ?></th>
                                <th><?php echo $modalText('cfclient.invite_registration.logs_time', '时间'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inviteRegLogs)): ?>
                                <?php foreach ($inviteRegLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $logInviteeIp = trim((string) ($log['invitee_ip'] ?? ''));
                                            if ($logInviteeIp === 'user_manual_invalidate' || $logInviteeIp === 'user_manual_invalidate_one_time') {
                                                echo htmlspecialchars($modalText('cfclient.invite_registration.log_manual_invalidate', $modalIsChinese ? '用户主动作废' : 'Manually invalidated by user'), ENT_QUOTES);
                                            } elseif ($logInviteeIp === 'system_limit_reached') {
                                                echo htmlspecialchars($modalText('cfclient.invite_registration.log_system_limit_invalidate', $modalIsChinese ? '邀请已达上限自动作废' : 'Auto-invalidated after invite limit reached'), ENT_QUOTES);
                                            } else {
                                                echo htmlspecialchars($log['email_masked'] ?? '-', ENT_QUOTES);
                                            }
                                            ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($log['invite_code'] ?? '-', ENT_QUOTES); ?></code></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($log['created_at'] ?? '-', ENT_QUOTES); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3"><?php echo $modalText('cfclient.invite_registration.logs_empty', '暂无邀请记录'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($inviteRegTotalPages > 1): ?>
                    <nav>
                        <ul class="pagination pagination-sm">
                            <?php $prevPage = max(1, $inviteRegPage - 1); ?>
                            <li class="page-item <?php echo $inviteRegPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($inviteRegLinkPrefix . $prevPage, ENT_QUOTES); ?>#inviteRegistrationModal" aria-label="<?php echo $modalText('cfclient.invite_registration.pagination.prev', '上一页'); ?>">&laquo;</a>
                            </li>
                            <?php for ($i = 1; $i <= $inviteRegTotalPages; $i++): ?>
                                <?php if ($inviteRegPage == $i): ?>
                                    <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                                <?php elseif ($i == 1 || $i == $inviteRegTotalPages || abs($i - $inviteRegPage) <= 2): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($inviteRegLinkPrefix . $i, ENT_QUOTES); ?>#inviteRegistrationModal"><?php echo $i; ?></a></li>
                                <?php elseif (abs($i - $inviteRegPage) == 3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php $nextPage = min($inviteRegTotalPages, $inviteRegPage + 1); ?>
                            <li class="page-item <?php echo $inviteRegPage >= $inviteRegTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($inviteRegLinkPrefix . $nextPage, ENT_QUOTES); ?>#inviteRegistrationModal" aria-label="<?php echo $modalText('cfclient.invite_registration.pagination.next', '下一页'); ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

                <div class="small text-muted mt-3 border-top pt-3">
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.liability.q', $modalIsChinese ? 'Q：邀请注册需要承担连带责任吗？' : 'Q: Do I bear joint liability when inviting registrations?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.liability.a', $modalIsChinese ? 'A：不需要承担责任，好友违规使用不会影响到您的账户，但是请您记得提醒好友遵守使用条款。' : 'A: You do not need to bear responsibility. A friend’s misuse will not affect your account, but please remind them to follow the Terms of Service.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.mode.q', $modalIsChinese ? 'Q：一次性和固定邀请码是什么意思？' : 'Q: What is the difference between one-time and fixed invite codes?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.mode.a', $modalIsChinese ? 'A：一次性邀请码只能使用一次,使用后自动作废,固定邀请码即为长效邀请码可以使用到您的邀请额度用完。' : 'A: A one-time invite code can only be used once and is automatically invalidated after use. A fixed invite code is long-lived and can be used until your invite quota is exhausted.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.reward.q', $modalIsChinese ? 'Q：邀请好友注册，我可以获得额度奖励吗？' : 'Q: If I invite a friend to register, can I get a quota bonus?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.reward.a', $modalIsChinese ? 'A：好友通过您的邀请码或专属链接成功注册成功后，您与好友均可额外获得 1 个注册额度。' : 'A: After your friend successfully registers via your invite code or exclusive link, both you and your friend will receive 1 extra registration quota.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.reward_limit.q', $modalIsChinese ? 'Q：邀请好友注册的奖励额度有上限吗？' : 'Q: Is there a limit to invite registration bonus quota?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.reward_limit.a', $modalIsChinese ? 'A：邀请方奖励的额度上限为 5 个。超出上限后您仍可继续邀请好友完成注册，但您将不再获得额外的额度奖励。' : 'A: The inviter bonus is capped at 5 quota units. You can still invite friends after reaching the cap, but you will no longer receive additional quota bonuses.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.invitee_after_cap.q', $modalIsChinese ? 'Q：邀请人的奖励额度达到上限后，被邀请的人还能拿到奖励吗？' : 'Q: If the inviter reaches the reward cap, can the invitee still get a bonus?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.invitee_after_cap.a', $modalIsChinese ? 'A：可以的，完全不受影响！邀请方的额度达到上限，并不会影响被邀请方的福利。好友通过您的链接邀请码成功注册，依然可以获得额外的注册额度。' : 'A: Yes, absolutely. The inviter reaching the cap does not affect the invitee. Friends who successfully register through your link or code can still receive extra registration quota.'); ?>
                    </div>
                    <div class="mb-2">
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.non_invite_methods.q', $modalIsChinese ? 'Q：使用其他非邀请方式注册，可以获得额度奖励吗？' : 'Q: Can I get a quota bonus if I register through non-invite methods?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.non_invite_methods.a', $modalIsChinese ? 'A：不可以。额外额度是专属的邀请奖励。通过 GitHub 认证、Telegram 机器人绑定发放的邀请码等自助解锁方式完成注册，无法获得额外的注册额度。' : 'A: No. Extra quota is exclusive to invitation rewards. Self-service unlock methods such as GitHub authentication or invite codes issued via Telegram bot binding do not grant additional registration quota.'); ?>
                    </div>
                    <div>
                        <strong><?php echo $modalText('cfclient.invite_registration.faq.fixed_after_onetime.q', $modalIsChinese ? 'Q：如果生成了一次性还可以生成固定邀请码吗？' : 'Q: Can I generate a fixed invite code after creating one-time codes?'); ?></strong><br>
                        <?php echo $modalText('cfclient.invite_registration.faq.fixed_after_onetime.a', $modalIsChinese ? 'A：可以生成，但是邀请码额度用完自动作废.也就是固定邀请码邀请数量达到上限一次性邀请码也会被作废。' : 'A: Yes. But once your invite quota is exhausted, codes are automatically invalidated. In other words, when fixed-code invitations reach the limit, one-time codes are also invalidated.'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var input = document.getElementById('invite_generate_count');
    var modeSelect = document.getElementById('invite_mode_select');
    var customInput = document.getElementById('invite_custom_code_input');
    if (!input) return;
    var max = parseInt(input.getAttribute('max') || '0', 10);
    var exceedMessage = <?php echo json_encode($modalText('cfclient.invite_registration.exceed_alert', $modalIsChinese ? '生成数量不能超过剩余额度：%s' : 'Generation quantity cannot exceed remaining quota: %s', ['{max}'])); ?>;
    var storageKey = 'cfmod_invite_mode_selected';

    function applyInviteModeLock(modeValue) {
        var mode = String(modeValue || '').toLowerCase();
        if (mode === 'fixed') {
            input.value = 1;
            input.setAttribute('readonly', 'readonly');
            input.setAttribute('disabled', 'disabled');
            if (customInput) { customInput.removeAttribute('disabled'); customInput.removeAttribute('required'); }
        } else {
            input.removeAttribute('readonly');
            <?php if ($inviteRegGenerateDisabled): ?>
            input.setAttribute('disabled', 'disabled');
            <?php else: ?>
            input.removeAttribute('disabled');
            <?php endif; ?>
            if (customInput) { customInput.removeAttribute('disabled'); customInput.removeAttribute('required'); }
        }
    }

    if (modeSelect) {
        var cachedMode = '';
        try { cachedMode = localStorage.getItem(storageKey) || ''; } catch (e) {}
        if (cachedMode === 'fixed' || cachedMode === 'one_time') {
            modeSelect.value = cachedMode;
        }
        applyInviteModeLock(modeSelect.value);
        modeSelect.addEventListener('change', function () {
            var current = String(modeSelect.value || 'one_time').toLowerCase();
            try { localStorage.setItem(storageKey, current); } catch (e) {}
            applyInviteModeLock(current);
        });
    }

    input.addEventListener('input', function () {
        if (modeSelect && String(modeSelect.value || '').toLowerCase() === 'fixed') {
            input.value = 1;
            return;
        }
        var v = parseInt(input.value || '0', 10);
        if (isNaN(v) || v < 1) { input.value = 1; return; }
        if (max > 0 && v > max) {
            input.value = max;
            alert(String(exceedMessage || '').replace('{max}', String(max)));
        }
    });
})();

window.copyInviteRegLink = function (btn) {
    if (!btn) {
        return;
    }
    var link = btn.getAttribute('data-link') || '';
    if (!link) {
        return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () {
            alert(<?php echo json_encode($modalText('cfclient.invite_registration.link_copy_success', $modalIsChinese ? '邀请链接已复制' : 'Invite link copied')); ?>);
        }).catch(function () {
            prompt(<?php echo json_encode($modalText('cfclient.invite_registration.link_copy_fallback', $modalIsChinese ? '复制失败，请手动复制：' : 'Copy failed, please copy manually:')); ?>, link);
        });
        return;
    }
    prompt(<?php echo json_encode($modalText('cfclient.invite_registration.link_copy_fallback', $modalIsChinese ? '复制失败，请手动复制：' : 'Copy failed, please copy manually:')); ?>, link);
};

(function () {
    var modeSelect = document.getElementById('invite_mode_select');
    var submitBtn = document.getElementById('inviteGenerateSubmitBtn');
    var hasFixedCode = <?php echo $inviteRegHasFixedCode ? 'true' : 'false'; ?>;
    if (!modeSelect || !submitBtn || !hasFixedCode) {
        return;
    }
    var updateInviteGenerateState = function () {
        var isFixedSelected = (modeSelect.value || '') === 'fixed';
        submitBtn.disabled = isFixedSelected;
        submitBtn.title = isFixedSelected
            ? <?php echo json_encode($modalText('cfclient.invite_registration.fixed_mode_already_exists', $modalIsChinese ? '已生成过固定邀请码，请在邀请码中查看。' : 'A fixed invite code already exists. Please check it in the invite code list.')); ?>
            : '';
    };
    modeSelect.addEventListener('change', updateInviteGenerateState);
    updateInviteGenerateState();
})();
</script>
<?php if ($inviteRegCanAutoUnlock): ?>
<form id="inviteRegistrationAutoUnlockForm" method="post" class="d-none">
    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? '', ENT_QUOTES); ?>">
    <input type="hidden" name="action" value="invite_registration_unlock">
    <input type="hidden" name="invite_reg_code" value="<?php echo htmlspecialchars($inviteRegPendingCode, ENT_QUOTES); ?>">
</form>
<script>
(function () {
    var autoKey = 'cfmod_invite_auto_unlock_done_<?php echo md5($inviteRegPendingCode); ?>';
    if (sessionStorage.getItem(autoKey) === '1') {
        return;
    }
    sessionStorage.setItem(autoKey, '1');
    var form = document.getElementById('inviteRegistrationAutoUnlockForm');
    if (!form) {
        return;
    }
    setTimeout(function () {
        form.submit();
    }, 200);
})();
</script>
<?php endif; ?>
<?php endif; ?>


<?php if ($domainPermanentUpgradeEnabled): ?>
<style>
#domainPermanentUpgradeModal .perm-upgrade-card {
    min-height: 260px;
}
#domainPermanentUpgradeModal .perm-upgrade-modal-dialog {
    max-width: min(1024px, 90vw);
}
#domainPermanentUpgradeModal .perm-upgrade-code,
#domainPermanentUpgradeModal code {
    font-family: 'Roboto Mono', 'SFMono-Regular', Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
    letter-spacing: 0.04em;
}
#domainPermanentUpgradeModal .perm-upgrade-progress {
    display: flex;
    align-items: center;
    gap: 8px;
}
#domainPermanentUpgradeModal .perm-upgrade-progress .progress {
    flex: 1;
}
#domainPermanentUpgradeModal .perm-upgrade-progress-bar {
    background: linear-gradient(90deg, #0ea5e9 0%, #22c55e 100%);
}
#domainPermanentUpgradeModal .perm-upgrade-progress-icon {
    color: #f59e0b;
    font-size: 0.85rem;
    min-width: 16px;
    text-align: center;
}
#domainPermanentUpgradeModal .perm-upgrade-ticker {
    overflow: hidden;
    border: 1px dashed rgba(34, 197, 94, 0.35);
}
#domainPermanentUpgradeModal .perm-upgrade-ticker-track {
    display: inline-block;
    white-space: nowrap;
    padding-left: 100%;
    animation: permUpgradeTickerMove 28s linear infinite;
}
@keyframes permUpgradeTickerMove {
    0% { transform: translate3d(0, 0, 0); }
    100% { transform: translate3d(-100%, 0, 0); }
}
</style>
<div class="modal fade" id="domainPermanentUpgradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable perm-upgrade-modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-infinity text-danger me-2"></i> <?php echo $modalText('cfclient.domain_permanent_upgrade.title', '域名永久升级中心'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <div><i class="fas fa-info-circle me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.notice', '选择您的域名并创建助力任务，达到 %s 次好友助力后将自动升级为永久有效。', [$domainPermanentUpgradeAssistRequired]); ?></div>
                    <div class="small mt-1 mb-0"><?php echo htmlspecialchars($domainPermanentUpgradeAssistLimitNotice, ENT_QUOTES); ?></div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="card border-0 bg-light h-100 perm-upgrade-card">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-rocket me-2 text-danger"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.create.title', '发起永久升级任务'); ?></h6>
                                <?php if (!empty($domainPermanentUpgradeEligibleDomains)): ?>
                                    <form method="post" class="d-flex flex-column gap-2">
                                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                        <input type="hidden" name="action" value="create_domain_permanent_upgrade_request">
                                        <label class="small text-muted mb-0" for="permUpgradeSubdomainSelect"><?php echo $modalText('cfclient.domain_permanent_upgrade.create.domain_label', '选择域名'); ?></label>
                                        <select class="form-select" id="permUpgradeSubdomainSelect" name="perm_upgrade_subdomain_id" required>
                                            <?php foreach ($domainPermanentUpgradeEligibleDomains as $domainOption): ?>
                                                <?php
                                                $optionId = intval($domainOption['id'] ?? 0);
                                                $optionDomain = trim((string) ($domainOption['domain'] ?? ''));
                                                ?>
                                                <?php if ($optionId > 0 && $optionDomain !== ''): ?>
                                                    <option value="<?php echo $optionId; ?>"><?php echo htmlspecialchars($optionDomain, ENT_QUOTES); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-bolt me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.create.button', '创建助力任务'); ?>
                                        </button>
                                    </form>
                                    <?php if ($domainPermanentUpgradePaidEnabled && $domainPermanentUpgradePaidPrice > 0): ?>
                                        <hr class="my-3">
                                        <form method="post" class="d-flex flex-column gap-2">
                                            <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                            <input type="hidden" name="action" value="pay_domain_permanent_upgrade">
                                            <label class="small text-muted mb-0" for="permUpgradePaySubdomainSelect"><?php echo $modalText('cfclient.domain_permanent_upgrade.pay.domain_label', '选择域名（付费直升）'); ?></label>
                                            <select class="form-select" id="permUpgradePaySubdomainSelect" name="perm_upgrade_subdomain_id" required>
                                                <?php foreach ($domainPermanentUpgradeEligibleDomains as $domainOption): ?>
                                                    <?php $optionId = intval($domainOption['id'] ?? 0); $optionDomain = trim((string) ($domainOption['domain'] ?? '')); ?>
                                                    <?php if ($optionId > 0 && $optionDomain !== ''): ?><option value="<?php echo $optionId; ?>"><?php echo htmlspecialchars($optionDomain, ENT_QUOTES); ?></option><?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="fas fa-coins me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.pay.button', '余额/账单付费升级'); ?>（<?php echo htmlspecialchars($domainPermanentUpgradePaidPriceDisplay, ENT_QUOTES); ?>）
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0 small">
                                        <i class="fas fa-exclamation-triangle me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.create.empty', '暂无可升级域名（仅未永久有效且状态正常的域名可参与）。'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-0 bg-light h-100 perm-upgrade-card">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-handshake me-2 text-success"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.assist.title', '输入好友助力码'); ?></h6>
                                <form method="post" class="d-flex flex-column gap-2">
                                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                    <input type="hidden" name="action" value="assist_domain_permanent_upgrade">
                                    <label class="small text-muted mb-0" for="permUpgradeAssistCodeInput"><?php echo $modalText('cfclient.domain_permanent_upgrade.assist.label', '助力码'); ?></label>
                                    <input type="text" class="form-control" id="permUpgradeAssistCodeInput" name="perm_upgrade_assist_code" placeholder="<?php echo $modalText('cfclient.domain_permanent_upgrade.assist.placeholder', '请输入好友分享的助力码'); ?>" <?php echo $domainPermanentUpgradeHelperLimitReached ? 'disabled' : 'required'; ?>>
                                    <small class="text-muted"><?php echo htmlspecialchars($domainPermanentUpgradeAssistLimitNotice, ENT_QUOTES); ?></small>
                                    <?php if ($domainPermanentUpgradeHelperLimitReached): ?>
                                        <small class="text-danger"><?php echo htmlspecialchars($domainPermanentUpgradeAssistDisabledNotice, ENT_QUOTES); ?></small>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-outline-success" <?php echo $domainPermanentUpgradeHelperLimitReached ? 'disabled' : ''; ?>>
                                        <i class="fas fa-heart me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.assist.button', '立即助力'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($domainPermanentUpgradeRealtimeFeedEnabled): ?>
                    <div class="mb-3">
                        <div class="small text-muted mb-1"><?php echo $modalText('cfclient.domain_permanent_upgrade.feed.title', '实时升级动态'); ?></div>
                        <?php if (!empty($domainPermanentUpgradeRecentSuccessFeed)): ?>
                            <div class="alert alert-success py-2 px-3 mb-0 perm-upgrade-ticker">
                                <div class="perm-upgrade-ticker-track">
                                    <?php foreach ($domainPermanentUpgradeRecentSuccessFeed as $feedItem): ?>
                                        <?php
                                        $feedEmail = trim((string) ($feedItem['email_masked'] ?? '-'));
                                        $feedDomain = trim((string) ($feedItem['domain_masked'] ?? '-'));
                                        $feedText = $modalText('cfclient.domain_permanent_upgrade.feed.item', '恭喜用户 %1$s 成功将域名 %2$s 永久化！', [$feedEmail, $feedDomain]);
                                        ?>
                                        <span class="me-4"><?php echo htmlspecialchars($feedText, ENT_QUOTES); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="small text-muted"><?php echo $modalText('cfclient.domain_permanent_upgrade.feed.empty', '首个永久升级正在路上，马上发起任务冲榜！'); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-file-invoice-dollar me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.invoice_pending.title', 'Pending Orders'); ?></h6>
                    <small class="text-muted"><?php echo $modalText('cfclient.domain_permanent_upgrade.invoice_pending.hint', 'Please complete payment for the following orders. The system will auto-upgrade after successful payment.'); ?></small>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                        <tr>
                            <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.domain', '域名'); ?></th>
                            <th><?php echo $modalText('cfclient.domain_permanent_upgrade.invoice_pending.invoice', 'Invoice #'); ?></th>
                            <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.status', '状态'); ?></th>
                            <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.actions', '操作'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($domainPermanentUpgradeInvoicePendingOrdersPaged)): ?>
                            <?php foreach ($domainPermanentUpgradeInvoicePendingOrdersPaged as $invoicePendingItem): ?>
                                <?php $invoiceId = (int) ($invoicePendingItem['invoice_id'] ?? 0); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) ($invoicePendingItem['domain'] ?? '-'), ENT_QUOTES); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars((string) ($invoicePendingItem['created_at'] ?? '-'), ENT_QUOTES); ?></small>
                                    </td>
                                    <td><?php echo $invoiceId > 0 ? ('#' . $invoiceId) : '-'; ?></td>
                                    <td><span class="badge bg-info text-dark"><?php echo $modalText('cfclient.domain_permanent_upgrade.status.invoice_pending', 'Pending Payment'); ?></span></td>
                                    <td>
                                        <?php if ($invoiceId > 0): ?>
                                            <div class="d-inline-flex align-items-center gap-1">
                                                <a class="btn btn-outline-primary btn-sm px-2 py-1 d-inline-flex align-items-center" href="viewinvoice.php?id=<?php echo $invoiceId; ?>">
                                                    <i class="fas fa-credit-card me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.invoice_pending.pay_now', 'Pay'); ?>
                                                </a>
                                                <form method="post" class="d-inline-flex align-items-center m-0" onsubmit="return confirm('<?php echo htmlspecialchars($modalText('cfclient.domain_permanent_upgrade.invoice_pending.cancel_confirm', '确认取消该待支付订单并作废账单？')); ?>');">
                                                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="cancel_domain_permanent_upgrade_invoice">
                                                    <input type="hidden" name="perm_upgrade_request_id" value="<?php echo intval($invoicePendingItem['request_id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1 d-inline-flex align-items-center">
                                                        <i class="fas fa-ban me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.invoice_pending.cancel', '取消订单'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3"><?php echo $modalText('cfclient.domain_permanent_upgrade.invoice_pending.empty', 'No pending orders'); ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($domainPermanentUpgradeInvoiceTotalPages > 1): ?>
                    <nav class="mt-2" aria-label="permanent upgrade invoice pending pagination">
                        <ul class="pagination pagination-sm mb-0 justify-content-end">
                            <?php $invoicePrevPage = max(1, $domainPermanentUpgradeInvoicePage - 1); ?>
                            <li class="page-item <?php echo $domainPermanentUpgradeInvoicePage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeInvoiceLinkPrefix . $invoicePrevPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&laquo;</a>
                            </li>
                            <?php for ($invoicePageIdx = 1; $invoicePageIdx <= $domainPermanentUpgradeInvoiceTotalPages; $invoicePageIdx++): ?>
                                <?php if ($invoicePageIdx === $domainPermanentUpgradeInvoicePage): ?>
                                    <li class="page-item active"><span class="page-link"><?php echo $invoicePageIdx; ?></span></li>
                                <?php elseif ($invoicePageIdx === 1 || $invoicePageIdx === $domainPermanentUpgradeInvoiceTotalPages || abs($invoicePageIdx - $domainPermanentUpgradeInvoicePage) <= 2): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeInvoiceLinkPrefix . $invoicePageIdx, ENT_QUOTES); ?>#domainPermanentUpgradeModal"><?php echo $invoicePageIdx; ?></a></li>
                                <?php elseif (abs($invoicePageIdx - $domainPermanentUpgradeInvoicePage) === 3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php $invoiceNextPage = min($domainPermanentUpgradeInvoiceTotalPages, $domainPermanentUpgradeInvoicePage + 1); ?>
                            <li class="page-item <?php echo $domainPermanentUpgradeInvoicePage >= $domainPermanentUpgradeInvoiceTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeInvoiceLinkPrefix . $invoiceNextPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-history me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.history.title', '我的升级任务'); ?></h6>
                    <small class="text-muted"><?php echo $modalText('cfclient.domain_permanent_upgrade.history.hint', '每页展示 5 条任务，可复制助力码分享给好友。'); ?></small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.domain', '域名'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.code', '助力码'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.progress', '进度'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.helpers', '最近助力'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.status', '状态'); ?></th>
                                <th><?php echo $modalText('cfclient.domain_permanent_upgrade.history.actions', '操作'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($domainPermanentUpgradeRequests)): ?>
                                <?php foreach ($domainPermanentUpgradeRequests as $requestItem): ?>
                                    <?php
                                    $requestStatus = strtolower((string) ($requestItem['status'] ?? 'pending'));
                                    $statusClass = 'warning';
                                    $statusTextClass = 'text-dark';
                                    $statusText = $modalText('cfclient.domain_permanent_upgrade.status.pending', '进行中');
                                    if ($requestStatus === 'upgraded') {
                                        $statusClass = 'success';
                                        $statusTextClass = '';
                                        $statusText = $modalText('cfclient.domain_permanent_upgrade.status.upgraded', '已永久');
                                    } elseif ($requestStatus !== 'pending') {
                                        $statusClass = 'dark';
                                        $statusTextClass = '';
                                        $statusText = $requestStatus === 'cancelled'
                                            ? $modalText('cfclient.domain_permanent_upgrade.status.cancelled', '已取消')
                                            : strtoupper($requestStatus);
                                    }
                                    $assistCount = max(0, intval($requestItem['assist_count'] ?? 0));
                                    $targetAssists = max(1, intval($requestItem['target_assists'] ?? $domainPermanentUpgradeAssistRequired));
                                    $progressPercent = min(100, (int) round(($assistCount / $targetAssists) * 100));
                                    $remainingAssists = max(0, $targetAssists - $assistCount);
                                    $isCompletedUpgrade = $requestStatus === 'upgraded' || $remainingAssists === 0;
                                    $helpersPreview = is_array($requestItem['helpers_preview'] ?? null) ? $requestItem['helpers_preview'] : [];
                                    $assistCode = strtoupper(trim((string) ($requestItem['assist_code'] ?? '')));
                                    $canCopyCode = !empty($requestItem['can_copy']) && $assistCode !== '';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string) ($requestItem['domain'] ?? '-'), ENT_QUOTES); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars((string) ($requestItem['created_at'] ?? '-'), ENT_QUOTES); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($assistCode !== ''): ?>
                                                <div class="d-flex align-items-center gap-1">
                                                    <code class="perm-upgrade-code"><?php echo htmlspecialchars($assistCode, ENT_QUOTES); ?></code>
                                                    <?php if ($canCopyCode): ?>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyDomainPermanentAssistCode('<?php echo htmlspecialchars($assistCode, ENT_QUOTES); ?>')">
                                                            <i class="fas fa-copy"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width: 180px;">
                                            <div class="small mb-1"><?php echo $assistCount; ?>/<?php echo $targetAssists; ?></div>
                                            <div class="perm-upgrade-progress">
                                                <div class="progress" style="height: 8px;">
                                                    <div class="progress-bar perm-upgrade-progress-bar" role="progressbar" style="width: <?php echo $progressPercent; ?>%;"></div>
                                                </div>
                                                <span class="perm-upgrade-progress-icon"><i class="fas <?php echo $requestStatus === 'upgraded' ? 'fa-crown' : 'fa-infinity'; ?>"></i></span>
                                            </div>
                                            <div class="small mt-1 fw-semibold <?php echo $isCompletedUpgrade ? 'text-success' : 'text-danger'; ?>">
                                                <?php
                                                echo $isCompletedUpgrade
                                                    ? $modalText('cfclient.domain_permanent_upgrade.history.completed_hint', '已完成升级')
                                                    : $modalText('cfclient.domain_permanent_upgrade.history.remaining_hint', '还差 %s 人助力', [$remainingAssists]);
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($helpersPreview)): ?>
                                                <?php echo htmlspecialchars(implode(', ', $helpersPreview), ENT_QUOTES); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-<?php echo $statusClass; ?> <?php echo $statusTextClass; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES); ?></span></td>
                                        <td>
                                            <?php if ($requestStatus === 'pending' && !$isCompletedUpgrade): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($modalText('cfclient.domain_permanent_upgrade.history.cancel_confirm', '确认取消当前升级任务？取消后可重新发起新任务。')); ?>');">
                                                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                                    <input type="hidden" name="action" value="cancel_domain_permanent_upgrade_request">
                                                    <input type="hidden" name="perm_upgrade_request_id" value="<?php echo intval($requestItem['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        <i class="fas fa-times me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.history.cancel', '取消'); ?>
                                                    </button>
                                                </form>
                                            <?php elseif ($isCompletedUpgrade): ?>
                                                <button type="button" class="btn btn-outline-success btn-sm" disabled>
                                                    <i class="fas fa-check-circle me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.history.completed_button', '已完成'); ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3"><?php echo $modalText('cfclient.domain_permanent_upgrade.history.empty', '暂无升级任务记录'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($domainPermanentUpgradeTotalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm mb-0">
                            <?php $permPrevPage = max(1, $domainPermanentUpgradePage - 1); ?>
                            <li class="page-item <?php echo $domainPermanentUpgradePage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeLinkPrefix . $permPrevPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&laquo;</a>
                            </li>
                            <?php for ($permPage = 1; $permPage <= $domainPermanentUpgradeTotalPages; $permPage++): ?>
                                <?php if ($permPage === $domainPermanentUpgradePage): ?>
                                    <li class="page-item active"><span class="page-link"><?php echo $permPage; ?></span></li>
                                <?php elseif ($permPage === 1 || $permPage === $domainPermanentUpgradeTotalPages || abs($permPage - $domainPermanentUpgradePage) <= 2): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeLinkPrefix . $permPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal"><?php echo $permPage; ?></a></li>
                                <?php elseif (abs($permPage - $domainPermanentUpgradePage) === 3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php $permNextPage = min($domainPermanentUpgradeTotalPages, $domainPermanentUpgradePage + 1); ?>
                            <li class="page-item <?php echo $domainPermanentUpgradePage >= $domainPermanentUpgradeTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeLinkPrefix . $permNextPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

                <div class="mt-4">
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-list me-1"></i><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.title', '助力日志'); ?></h6>
                        <small class="text-muted"><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.hint', '显示最近 20 条与您相关的助力记录。'); ?></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.time', '时间'); ?></th>
                                    <th><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.domain', '域名'); ?></th>
                                    <th><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.type', '类型'); ?></th>
                                    <th><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.counterpart', '对象'); ?></th>
                                    <th><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.code', '助力码'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($domainPermanentUpgradeAssistLogsPaged)): ?>
                                    <?php foreach ($domainPermanentUpgradeAssistLogsPaged as $assistLog): ?>
                                        <?php
                                        $logRole = strtolower((string) ($assistLog['role'] ?? 'received'));
                                        $logRoleText = $logRole === 'assisted'
                                            ? $modalText('cfclient.domain_permanent_upgrade.logs.type.assisted', '我助力他人')
                                            : $modalText('cfclient.domain_permanent_upgrade.logs.type.received', '收到助力');
                                        $logDomain = trim((string) ($assistLog['domain'] ?? ''));
                                        $logCounterpart = trim((string) ($assistLog['counterpart'] ?? ''));
                                        $logCode = strtoupper(trim((string) ($assistLog['assist_code'] ?? '')));
                                        ?>
                                        <tr>
                                            <td><small class="text-muted"><?php echo htmlspecialchars((string) ($assistLog['created_at'] ?? '-'), ENT_QUOTES); ?></small></td>
                                            <td><?php echo htmlspecialchars($logDomain !== '' ? $logDomain : '-', ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($logRoleText, ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($logCounterpart !== '' ? $logCounterpart : '-', ENT_QUOTES); ?></td>
                                            <td><?php if ($logCode !== ''): ?><code class="perm-upgrade-code"><?php echo htmlspecialchars($logCode, ENT_QUOTES); ?></code><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3"><?php echo $modalText('cfclient.domain_permanent_upgrade.logs.empty', '暂无助力日志'); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($domainPermanentUpgradeAssistLogsTotalPages > 1): ?>
                        <nav class="mt-2" aria-label="permanent upgrade assist logs pagination">
                            <ul class="pagination pagination-sm mb-0 justify-content-end">
                                <?php $assistLogPrevPage = max(1, $domainPermanentUpgradeAssistLogsPage - 1); ?>
                                <li class="page-item <?php echo $domainPermanentUpgradeAssistLogsPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeAssistLogsLinkPrefix . $assistLogPrevPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&laquo;</a>
                                </li>
                                <?php for ($assistLogP = 1; $assistLogP <= $domainPermanentUpgradeAssistLogsTotalPages; $assistLogP++): ?>
                                    <?php if ($assistLogP === $domainPermanentUpgradeAssistLogsPage): ?>
                                        <li class="page-item active"><span class="page-link"><?php echo $assistLogP; ?></span></li>
                                    <?php elseif ($assistLogP === 1 || $assistLogP === $domainPermanentUpgradeAssistLogsTotalPages || abs($assistLogP - $domainPermanentUpgradeAssistLogsPage) <= 2): ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeAssistLogsLinkPrefix . $assistLogP, ENT_QUOTES); ?>#domainPermanentUpgradeModal"><?php echo $assistLogP; ?></a></li>
                                    <?php elseif (abs($assistLogP - $domainPermanentUpgradeAssistLogsPage) === 3): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php $assistLogNextPage = min($domainPermanentUpgradeAssistLogsTotalPages, $domainPermanentUpgradeAssistLogsPage + 1); ?>
                                <li class="page-item <?php echo $domainPermanentUpgradeAssistLogsPage >= $domainPermanentUpgradeAssistLogsTotalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($domainPermanentUpgradeAssistLogsLinkPrefix . $assistLogNextPage, ENT_QUOTES); ?>#domainPermanentUpgradeModal">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

                <div class="accordion mt-3" id="domainPermanentUpgradeFaq">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="domainPermanentUpgradeFaqHeadingOne">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#domainPermanentUpgradeFaqOne" aria-expanded="false" aria-controls="domainPermanentUpgradeFaqOne">
                                <?php echo $modalText('cfclient.domain_permanent_upgrade.faq.question', '“永久有效”到底是什么意思？'); ?>
                            </button>
                        </h2>
                        <div id="domainPermanentUpgradeFaqOne" class="accordion-collapse collapse" aria-labelledby="domainPermanentUpgradeFaqHeadingOne" data-bs-parent="#domainPermanentUpgradeFaq">
                            <div class="accordion-body small text-muted">
                                <?php echo $modalText('cfclient.domain_permanent_upgrade.faq.answer', '“永久有效”是指该域名将免去每年的手动续期流程。升级成功后，系统会持续保持域名有效状态（仍需遵守平台使用规则）。'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$inviteRegUnlocked && !empty($inviteRegistrationEnabled)): ?>
<?php
$inviteGateMode = (string) ($inviteRegistrationGateMode ?? 'invite_only');
$inviteGateInviteEnabled = !empty($inviteRegistrationInviteEnabled);
$inviteGateGithubEnabled = !empty($inviteRegistrationGithubEnabled);
$inviteGateGithubConfigured = !empty($inviteRegistrationGithubConfigured);
$inviteGateGithubAuthUrl = trim((string) ($inviteRegistrationGithubAuthUrl ?? ''));
$inviteGateGithubMinMonths = max(0, intval($inviteRegistrationGithubMinMonths ?? 0));
$inviteGateGithubMinRepos = max(0, intval($inviteRegistrationGithubMinRepos ?? 0));
$inviteGateTelegramEnabled = !empty($inviteRegistrationTelegramEnabled);
$inviteGateTelegramConfigured = !empty($inviteRegistrationTelegramConfigured);
$inviteGateTelegramBotUsername = trim((string) ($inviteRegistrationTelegramBotUsername ?? ''));
$inviteGateTelegramAuthMaxAge = max(60, intval($inviteRegistrationTelegramAuthMaxAge ?? 86400));
$inviteGateTelegramWidgetEnabled = !empty($inviteRegistrationTelegramWidgetEnabled);
$inviteGateTelegramBotBindUrl = trim((string) ($inviteRegistrationTelegramBotBindUrl ?? ''));
$inviteGateTelegramBotBindExpiresAt = trim((string) ($inviteRegistrationTelegramBotBindExpiresAt ?? ''));
$inviteGatePendingCode = strtoupper(trim((string) ($inviteRegistrationPendingCode ?? '')));
$inviteRegRequired = !empty($inviteRegistrationRequired);
$inviteGateTelegramRequireGroupMember = in_array(strtolower(trim((string) ($module_settings['invite_registration_telegram_require_group_member'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true);
$inviteGateTelegramGuidanceOnly = in_array(strtolower(trim((string) ($module_settings['invite_registration_telegram_group_guidance_only'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true);
$inviteGateTelegramShowGroupGuide = $inviteGateTelegramRequireGroupMember || $inviteGateTelegramGuidanceOnly;
$inviteGateTelegramGroupLink = trim((string) ($module_settings['telegram_group_link'] ?? ''));
$inviteGateTelegramGroupLinkSafe = $inviteGateTelegramGroupLink;
if ($inviteGateTelegramGroupLinkSafe !== '' && preg_match('/^\s*javascript:/i', $inviteGateTelegramGroupLinkSafe)) {
    $inviteGateTelegramGroupLinkSafe = '';
}
$inviteGateFlash = is_array($inviteRegistrationGateFlash ?? null) ? $inviteRegistrationGateFlash : null;
?>
<style>
#inviteRegistrationRequiredModal .invite-reg-required-inner {
    max-width: 560px;
    margin: 0 auto;
}
#inviteRegistrationRequiredModal .invite-reg-required-body {
    padding: 14px 16px;
}
#inviteRegistrationRequiredModal .invite-reg-github-btn {
    background: #24292e;
    border: 1px solid #24292e;
    color: #FFFFFF;
    height: 50px;
    border-radius: 6px;
}
#inviteRegistrationRequiredModal .invite-reg-github-btn:hover,
#inviteRegistrationRequiredModal .invite-reg-github-btn:focus,
#inviteRegistrationRequiredModal .invite-reg-github-btn:active {
    background: #1f2328;
    color: #FFFFFF;
    border-color: #1f2328;
}
#inviteRegistrationRequiredModal .github-auth-button .github-logo {
    height: 2.025em;
    width: 2.025em;
    margin-right: 12px;
    flex-shrink: 0;
    filter: brightness(0) invert(1);
}
#inviteRegistrationRequiredModal .invite-reg-github-hints {
    margin-top: 4px;
    margin-bottom: 12px;
}
#inviteRegistrationRequiredModal .invite-reg-github-hint {
    font-size: 13px;
    line-height: 1.4;
    color: #555555;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}
#inviteRegistrationRequiredModal .invite-reg-github-hint i {
    font-size: 11px;
    margin-right: 4px;
    color: #F59E0B;
}
#inviteRegistrationRequiredModal .invite-reg-github-hint + .invite-reg-github-hint {
    margin-top: 2px;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider {
    margin: 12px 0;
    display: flex;
    align-items: center;
    color: #9CA3AF;
    font-size: 0.875rem;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider--primary {
    margin: 10px 0 8px;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider::before,
#inviteRegistrationRequiredModal .invite-reg-or-divider::after {
    content: '';
    flex: 1;
    border-bottom: 1px solid #E5E7EB;
}
#inviteRegistrationRequiredModal .invite-reg-or-divider > span {
    padding: 0 12px;
    font-weight: 700;
}
#inviteRegistrationRequiredModal .invite-reg-required-notice {
    color: #C2410C;
    font-weight: 600;
}
</style>
<div class="modal fade" id="inviteRegistrationRequiredModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:616px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i> <?php echo $modalText('cfclient.invite_registration.required_title', '准入验证'); ?></h5>
            </div>
            <div class="modal-body invite-reg-required-body">
                <div class="invite-reg-required-inner">
                <?php if ($inviteGateFlash && !empty($inviteGateFlash['message'])): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($inviteGateFlash['type'] ?? 'danger', ENT_QUOTES); ?>">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <?php echo htmlspecialchars((string) ($inviteGateFlash['message'] ?? ''), ENT_QUOTES); ?>
                    </div>
                <?php endif; ?>

                <div class="border rounded bg-light px-3 py-2 mb-3 small invite-reg-required-notice">
                    <i class="fas fa-shield-alt text-warning me-1"></i>
                    <?php echo $modalText('cfclient.invite_registration.required_notice', '首次使用前请先完成准入验证。'); ?>
                </div>
                <?php
                $inviteBalanceUnlockEnabled = in_array(strtolower((string) ($module_settings['invite_registration_balance_unlock_enabled'] ?? '0')), ['1','on','yes','true','enabled'], true);
                $inviteBalanceUnlockPriceRaw = max(0, (float) ($module_settings['invite_registration_balance_unlock_price'] ?? 0));
                $inviteBalanceUnlockPrice = $cfCurrencyPrefix . number_format($inviteBalanceUnlockPriceRaw, 2, '.', '') . $cfCurrencySuffix;
                $inviteBalanceUnlockGrayEnabled = (string) ($module_settings['invite_registration_balance_unlock_gray_enabled'] ?? '0');
                $inviteBalanceUnlockGrayRatio = max(0, min(100, (int) ($module_settings['invite_registration_balance_unlock_gray_ratio'] ?? 100)));
                $inviteBalanceUnlockGrayHit = function_exists('cfmod_rootdomain_gray_hit')
                    ? cfmod_rootdomain_gray_hit((int) ($userid ?? 0), 'invite_registration_balance_unlock', $inviteBalanceUnlockGrayEnabled, $inviteBalanceUnlockGrayRatio, 'invite_balance_unlock_v1')
                    : true;
                ?>
                <?php if ($inviteBalanceUnlockEnabled && $inviteBalanceUnlockPriceRaw > 0 && $inviteBalanceUnlockGrayHit): ?>
                    <form method="post" class="mb-3">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" value="invite_registration_balance_unlock">
                        <button type="submit" class="btn btn-outline-success w-100">
                            <i class="fas fa-wallet me-1"></i><?php echo $modalText('cfclient.invite_registration.balance_unlock', '使用余额解锁（%s）', [$inviteBalanceUnlockPrice]); ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($inviteGateInviteEnabled): ?>
                <form method="post" id="inviteRegRequiredForm" class="mb-3">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="invite_registration_unlock">
                    <div class="border rounded bg-white p-3">
                        <label class="form-label fw-semibold small mb-2" for="invite_reg_code_input"><?php echo $modalText('cfclient.invite_registration.input_label', '输入邀请码'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-ticket-alt text-muted"></i></span>
                            <input type="text" class="form-control text-uppercase" name="invite_reg_code" id="invite_reg_code_input" placeholder="<?php echo $modalText('cfclient.invite_registration.input_placeholder', '例如：ABCD1234EFGH'); ?>" value="<?php echo htmlspecialchars($inviteGatePendingCode, ENT_QUOTES); ?>" maxlength="20" <?php echo $inviteGateInviteEnabled ? 'required' : ''; ?> autofocus>
                            <button type="submit" class="btn btn-primary px-3">
                                <i class="fas fa-check"></i> <?php echo $modalText('cfclient.invite_registration.submit', '验证邀请码'); ?>
                            </button>
                        </div>
                        <div class="form-text mb-0 mt-2"><?php echo $modalText('cfclient.invite_registration.input_hint', '邀请码不区分大小写，请仔细核对后提交。'); ?></div>
                    </div>
                </form>
                <?php endif; ?>

                <?php if ($inviteGateInviteEnabled && ($inviteGateGithubEnabled || $inviteGateTelegramEnabled)): ?>
                    <div class="invite-reg-or-divider invite-reg-or-divider--primary"><span><?php echo $modalText('cfclient.invite_registration.or_other_method', '或使用其他方式'); ?></span></div>
                <?php endif; ?>

                <?php if ($inviteGateGithubEnabled): ?>
                    <?php if ($inviteGateGithubConfigured && $inviteGateGithubAuthUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($inviteGateGithubAuthUrl, ENT_QUOTES); ?>" class="btn invite-reg-github-btn github-auth-button w-100 d-flex align-items-center justify-content-center px-3 mb-1">
                            <svg class="github-logo" aria-hidden="true" height="20" viewBox="0 0 16 16" width="20" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8a8 8 0 0 0 5.47 7.59c.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.5-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8 8 0 0 0 16 8c0-4.42-3.58-8-8-8Z"></path></svg>
                            <span><?php echo $modalText('cfclient.invite_registration.github.button_no_invite', '点击使用 GitHub 快捷认证（无需邀请码）。'); ?></span>
                        </a>
                        <?php if ($inviteGateGithubMinMonths > 0 || $inviteGateGithubMinRepos > 0): ?>
                            <div class="invite-reg-github-hints">
                                <?php if ($inviteGateGithubMinMonths > 0): ?>
                                    <div class="invite-reg-github-hint"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?php echo $modalText('cfclient.invite_registration.github.min_months', 'GitHub 账号需至少注册 %s 个月。', [$inviteGateGithubMinMonths]); ?></div>
                                <?php endif; ?>
                                <?php if ($inviteGateGithubMinRepos > 0): ?>
                                    <div class="invite-reg-github-hint"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?php echo $modalText('cfclient.invite_registration.github.min_repos', 'GitHub 账号公开仓库数需至少 %s 个。', [$inviteGateGithubMinRepos]); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary py-2 px-3 mb-2 small">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo $modalText('cfclient.invite_registration.github.not_configured', '管理员尚未配置 GitHub OAuth，请联系管理员。'); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($inviteGateGithubEnabled && $inviteGateTelegramEnabled): ?>
                    <div class="invite-reg-or-divider"><span><?php echo $modalText('cfclient.invite_registration.or', '或'); ?></span></div>
                <?php endif; ?>

                <?php if ($inviteGateTelegramEnabled): ?>
                    <?php if ($inviteGateTelegramConfigured && $inviteGateTelegramBotUsername !== ''): ?>
                        <?php if ($inviteGateTelegramWidgetEnabled || !$inviteGateTelegramRequireGroupMember): ?>
                            <div class="small text-muted text-center mb-2" id="inviteRegTelegramAuthStatus">
                                <?php
                                    if ($inviteGateTelegramWidgetEnabled) {
                                        echo $modalText('cfclient.invite_registration.telegram.auth_hint', '请点击 Telegram 授权按钮并确认授权，系统将自动完成准入验证。');
                                    } elseif (!$inviteGateTelegramGuidanceOnly) {
                                        echo $modalText('cfclient.invite_registration.telegram.bot_only_hint', '当前仅支持 Bot 对话绑定：请先前往 Telegram Bot 完成绑定，再返回本页完成准入验证。');
                                    }
                                ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($inviteGateTelegramShowGroupGuide): ?>
                            <div class="alert alert-warning py-2 px-3 mb-2 small">
                                <i class="fas fa-users me-1"></i>
                                <?php echo $modalText('cfclient.invite_registration.telegram.group_required_hint', '当前准入要求完成官方群组验证：请先加入官方群组，再在 Bot 对话中完成校验。'); ?>
                            </div>
                            <?php if ($inviteGateTelegramGroupLinkSafe !== ''): ?>
                                <a href="<?php echo htmlspecialchars($inviteGateTelegramGroupLinkSafe, ENT_QUOTES); ?>" target="_blank" rel="noopener" class="btn btn-outline-primary w-100 mb-2">
                                    <i class="fab fa-telegram-plane me-1"></i><?php echo $modalText('cfclient.invite_registration.telegram.group_join', '加入官方群组'); ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($inviteGateTelegramBotBindUrl !== ''): ?>
                            <a href="<?php echo htmlspecialchars($inviteGateTelegramBotBindUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener" class="btn btn-info mb-2 w-100" style="font-size:135%;">
                                <i class="fab fa-telegram-plane me-1"></i><?php echo $modalText('cfclient.invite_registration.telegram.bot_bind', '前往 Telegram Bot 对话绑定'); ?>
                            </a>
                            <?php if ($inviteGateTelegramBotBindExpiresAt !== ''): ?>
                                <div class="form-text text-muted text-center mb-2"><?php echo $modalText('cfclient.invite_registration.telegram.bot_bind_expire', '绑定口令有效期至：%s', [$inviteGateTelegramBotBindExpiresAt]); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($inviteGateTelegramWidgetEnabled): ?>
                            <div class="telegram-login-widget-wrap d-flex justify-content-center mb-2">
                                <script async src="https://telegram.org/js/telegram-widget.js?22"
                                    data-telegram-login="<?php echo htmlspecialchars($inviteGateTelegramBotUsername, ENT_QUOTES); ?>"
                                    data-size="large"
                                    data-userpic="false"
                                    data-request-access="write"
                                    data-onauth="cfInviteRegistrationTelegramOnAuth(user)">
                                </script>
                            </div>
                        <?php endif; ?>
                        <?php if ($inviteGateTelegramWidgetEnabled): ?>
                            <form method="post" class="mb-3" id="inviteRegTelegramForm">
                                <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                                <input type="hidden" name="action" value="invite_registration_telegram_unlock">
                                <input type="hidden" name="telegram_auth_id" id="inviteRegTelegramAuthId" value="">
                                <input type="hidden" name="telegram_auth_username" id="inviteRegTelegramAuthUsername" value="">
                                <input type="hidden" name="telegram_auth_first_name" id="inviteRegTelegramAuthFirstName" value="">
                                <input type="hidden" name="telegram_auth_last_name" id="inviteRegTelegramAuthLastName" value="">
                                <input type="hidden" name="telegram_auth_photo_url" id="inviteRegTelegramAuthPhotoUrl" value="">
                                <input type="hidden" name="telegram_auth_date" id="inviteRegTelegramAuthDate" value="">
                                <input type="hidden" name="telegram_auth_hash" id="inviteRegTelegramAuthHash" value="">
                                <button type="submit" class="btn btn-primary w-100" id="inviteRegTelegramSubmitButton" disabled>
                                    <i class="fab fa-telegram-plane me-1"></i><?php echo $modalText('cfclient.invite_registration.telegram.submit', '我已授权 Telegram，继续验证'); ?>
                                </button>
                                <div class="form-text text-muted mt-2"><?php echo $modalText('cfclient.invite_registration.telegram.ttl_hint', '授权数据最长有效 %s 秒，超时请重新授权。', [$inviteGateTelegramAuthMaxAge]); ?></div>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo $modalText('cfclient.invite_registration.telegram.not_configured', '管理员尚未完成 Telegram 准入配置，请联系管理员。'); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <a href="clientarea.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-home"></i> <?php echo $modalText('cfclient.invite_registration.back_to_portal', '返回客户中心'); ?>
                </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var inviteRegTelegramAuthSuccessText = <?php echo json_encode($modalText('cfclient.invite_registration.telegram.auth_success', 'Telegram 授权成功，请点击下方按钮完成准入验证。', [], false), CFMOD_SAFE_JSON_FLAGS); ?>;
var inviteRegTelegramAuthRequiredText = <?php echo json_encode($modalText('cfclient.invite_registration.telegram.auth_required', '请先完成 Telegram 授权后再提交验证。', [], false), CFMOD_SAFE_JSON_FLAGS); ?>;

window.cfInviteRegistrationTelegramOnAuth = function(user) {
    if (!user || typeof user !== 'object') {
        return;
    }
    var map = {
        inviteRegTelegramAuthId: user.id || '',
        inviteRegTelegramAuthUsername: user.username || '',
        inviteRegTelegramAuthFirstName: user.first_name || '',
        inviteRegTelegramAuthLastName: user.last_name || '',
        inviteRegTelegramAuthPhotoUrl: user.photo_url || '',
        inviteRegTelegramAuthDate: user.auth_date || '',
        inviteRegTelegramAuthHash: user.hash || ''
    };
    Object.keys(map).forEach(function(id) {
        var input = document.getElementById(id);
        if (input) {
            input.value = map[id];
        }
    });
    var submitBtn = document.getElementById('inviteRegTelegramSubmitButton');
    if (submitBtn) {
        submitBtn.disabled = !(map.inviteRegTelegramAuthId && map.inviteRegTelegramAuthHash && map.inviteRegTelegramAuthDate);
    }
    var status = document.getElementById('inviteRegTelegramAuthStatus');
    if (status) {
        status.textContent = inviteRegTelegramAuthSuccessText;
        status.classList.remove('text-muted');
        status.classList.add('text-success');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    var inviteRegRequiredModal = document.getElementById('inviteRegistrationRequiredModal');
    if (inviteRegRequiredModal) {
        var bsModal = new bootstrap.Modal(inviteRegRequiredModal);
        bsModal.show();
    }

    var telegramForm = document.getElementById('inviteRegTelegramForm');
    if (telegramForm) {
        telegramForm.addEventListener('submit', function(event) {
            var authId = document.getElementById('inviteRegTelegramAuthId');
            var authHash = document.getElementById('inviteRegTelegramAuthHash');
            var authDate = document.getElementById('inviteRegTelegramAuthDate');
            if (!authId || !authHash || !authDate || !authId.value || !authHash.value || !authDate.value) {
                event.preventDefault();
                var status = document.getElementById('inviteRegTelegramAuthStatus');
                if (status) {
                    status.textContent = inviteRegTelegramAuthRequiredText;
                    status.classList.remove('text-muted');
                    status.classList.add('text-danger');
                }
            }
        });
    }
});
</script>
<?php if ($inviteGatePendingCode !== '' && $inviteRegRequired): ?>
<script>
(function () {
    var form = document.getElementById('inviteRegRequiredForm');
    var input = document.getElementById('invite_reg_code_input');
    if (!form || !input) {
        return;
    }
    var onceKey = 'cfmod_invite_required_auto_submit_<?php echo md5($inviteGatePendingCode); ?>';
    if (sessionStorage.getItem(onceKey) === '1') {
        return;
    }
    input.value = '<?php echo htmlspecialchars($inviteGatePendingCode, ENT_QUOTES); ?>';
    sessionStorage.setItem(onceKey, '1');
    setTimeout(function () { form.submit(); }, 200);
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php if ($expiryTelegramReminderFeatureEnabled): ?>
<div class="modal fade" id="expiryTelegramReminderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fab fa-telegram-plane text-info me-1"></i> <?php echo $modalText('cfclient.expiry_telegram.modal.title', 'Telegram 到期提醒'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="expiryTelegramReminderForm">
                <div class="modal-body">
                    <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                    <input type="hidden" name="action" value="update_expiry_telegram_reminder">
                    <input type="hidden" name="telegram_auth_id" id="expiryTelegramAuthId" value="">
                    <input type="hidden" name="telegram_auth_username" id="expiryTelegramAuthUsername" value="">
                    <input type="hidden" name="telegram_auth_first_name" id="expiryTelegramAuthFirstName" value="">
                    <input type="hidden" name="telegram_auth_last_name" id="expiryTelegramAuthLastName" value="">
                    <input type="hidden" name="telegram_auth_photo_url" id="expiryTelegramAuthPhotoUrl" value="">
                    <input type="hidden" name="telegram_auth_date" id="expiryTelegramAuthDate" value="">
                    <input type="hidden" name="telegram_auth_hash" id="expiryTelegramAuthHash" value="">

                    <div class="alert alert-light border small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php echo $modalText('cfclient.expiry_telegram.modal.days', '提醒频率：系统会在到期前 %s 各发送一次telegram消息提醒。', [$modalIsChinese ? $expiryTelegramReminderDaysZh : $expiryTelegramReminderDaysEn]); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted" for="expiryTelegramReminderAccount"><?php echo $modalText('cfclient.expiry_telegram.modal.account', '当前绑定账号'); ?></label>
                        <input type="text" class="form-control" id="expiryTelegramReminderAccount" value="<?php echo htmlspecialchars($expiryTelegramReminderDisplayName !== '' ? $expiryTelegramReminderDisplayName : $modalText('cfclient.expiry_telegram.modal.no_account', '尚未绑定', [], false), ENT_QUOTES); ?>" readonly>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="expiry_telegram_reminder_enabled" name="expiry_telegram_reminder_enabled" value="1" <?php echo $expiryTelegramReminderSubscribed ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="expiry_telegram_reminder_enabled"><?php echo $modalText('cfclient.expiry_telegram.modal.enable', '开启 Telegram 到期提醒'); ?></label>
                    </div>

                    <div class="small text-muted mb-2" id="expiryTelegramReminderAuthStatus">
                        <?php if ($expiryTelegramReminderTelegramBound): ?>
                            <?php echo $modalText('cfclient.expiry_telegram.modal.bound_hint', '已绑定 Telegram：%s，可直接保存或重新授权。', [$expiryTelegramReminderDisplayName !== '' ? $expiryTelegramReminderDisplayName : '-']); ?>
                        <?php else: ?>
                            <?php echo $modalText('cfclient.expiry_telegram.modal.auth_hint', '请先完成 Telegram 授权后再开启提醒。'); ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($expiryTelegramReminderConfigured && $expiryTelegramReminderBotUsername !== ''): ?>
                        <div class="telegram-login-widget-wrap d-flex justify-content-center mb-2">
                            <script async src="https://telegram.org/js/telegram-widget.js?22"
                                data-telegram-login="<?php echo htmlspecialchars($expiryTelegramReminderBotUsername, ENT_QUOTES); ?>"
                                data-size="large"
                                data-userpic="false"
                                data-request-access="write"
                                data-onauth="cfExpiryTelegramReminderOnAuth(user)">
                            </script>
                        </div>

                    <?php else: ?>
                        <div class="alert alert-warning small mb-0">
                            <?php echo $modalText('cfclient.expiry_telegram.modal.not_configured', '管理员尚未完成 Telegram 提醒配置，暂无法授权。'); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.modals.buttons.cancel', '取消'); ?></button>
                    <button type="submit" class="btn btn-info text-white" id="expiryTelegramReminderSubmitButton">
                        <i class="fas fa-save me-1"></i><?php echo $modalText('cfclient.expiry_telegram.modal.submit', '保存提醒设置'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var expiryTelegramAuthRequiredText = <?php echo json_encode($modalText('cfclient.expiry_telegram.modal.auth_required', '开启提醒前请先完成 Telegram 授权。', [], false), CFMOD_SAFE_JSON_FLAGS); ?>;
    var expiryTelegramBoundTextTemplate = <?php echo json_encode($modalText('cfclient.expiry_telegram.modal.bound_hint', '已绑定 Telegram：%s，可直接保存或重新授权。', ['%s'], false), CFMOD_SAFE_JSON_FLAGS); ?>;
    var expiryTelegramHasBinding = <?php echo $expiryTelegramReminderTelegramBound ? 'true' : 'false'; ?>;

    function setAuthStatus(text, className) {
        var status = document.getElementById('expiryTelegramReminderAuthStatus');
        if (!status) {
            return;
        }
        status.textContent = text;
        status.classList.remove('text-muted', 'text-success', 'text-danger');
        status.classList.add(className || 'text-muted');
    }

    window.cfExpiryTelegramReminderOnAuth = function (user) {
        if (!user || typeof user !== 'object') {
            return;
        }
        var map = {
            expiryTelegramAuthId: user.id || '',
            expiryTelegramAuthUsername: user.username || '',
            expiryTelegramAuthFirstName: user.first_name || '',
            expiryTelegramAuthLastName: user.last_name || '',
            expiryTelegramAuthPhotoUrl: user.photo_url || '',
            expiryTelegramAuthDate: user.auth_date || '',
            expiryTelegramAuthHash: user.hash || ''
        };
        Object.keys(map).forEach(function (id) {
            var input = document.getElementById(id);
            if (input) {
                input.value = map[id] ? String(map[id]) : '';
            }
        });

        var accountInput = document.getElementById('expiryTelegramReminderAccount');
        var displayName = user.username ? ('@' + String(user.username).replace(/^@+/, '')) : ('ID: ' + String(user.id || ''));
        if (accountInput) {
            accountInput.value = displayName;
        }

        var enableSwitch = document.getElementById('expiry_telegram_reminder_enabled');
        if (enableSwitch) {
            enableSwitch.checked = true;
        }

        expiryTelegramHasBinding = true;
        setAuthStatus(expiryTelegramBoundTextTemplate.replace('%s', displayName), 'text-success');
    };

    window.showExpiryTelegramReminderModal = function () {
        var modal = document.getElementById('expiryTelegramReminderModal');
        if (!modal || typeof bootstrap === 'undefined') {
            return;
        }
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    };

    var form = document.getElementById('expiryTelegramReminderForm');
    if (form) {
        form.addEventListener('submit', function (event) {
            var enableSwitch = document.getElementById('expiry_telegram_reminder_enabled');
            var authId = document.getElementById('expiryTelegramAuthId');
            var authHash = document.getElementById('expiryTelegramAuthHash');
            var authDate = document.getElementById('expiryTelegramAuthDate');
            var hasNewAuth = !!(authId && authHash && authDate && authId.value && authHash.value && authDate.value);
            if (enableSwitch && enableSwitch.checked && !hasNewAuth && !expiryTelegramHasBinding) {
                event.preventDefault();
                setAuthStatus(expiryTelegramAuthRequiredText, 'text-danger');
            }
        });
    }
})();
</script>
<?php endif; ?>

<!-- 根域名邀请码模态框 -->
<div class="modal fade" id="rootdomainInviteCodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-friends text-primary"></i> <?php echo $modalText('cfclient.rootdomain_invite.title', '根域名邀请码'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php
                $rootdomainInviteCodes = $rootdomainInviteCodes ?? [];
                $rootInviteRequiredMap = $rootInviteRequiredMap ?? [];
                $rootdomainInviteMaxPerUser = $rootdomainInviteMaxPerUser ?? 0;
                
                // 过滤出需要邀请码的根域名
                $inviteEnabledRoots = [];
                foreach ($rootInviteRequiredMap as $root => $required) {
                    if ($required) {
                        $inviteEnabledRoots[] = $root;
                    }
                }
                ?>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <?php if ($rootdomainInviteMaxPerUser > 0): ?>
                        <?php echo $modalText('cfclient.rootdomain_invite.description_with_limit', '以下根域名需要邀请码才能注册。您可以分享您的邀请码给好友，每个根域名最多可邀请 %s 个好友。邀请码使用后会自动刷新。', [$rootdomainInviteMaxPerUser]); ?>
                    <?php else: ?>
                        <?php echo $modalText('cfclient.rootdomain_invite.description', '以下根域名需要邀请码才能注册。您可以分享您的邀请码给好友，好友使用后邀请码会自动刷新。'); ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($inviteEnabledRoots)): ?>
                    <div class="row g-3">
                        <?php foreach ($inviteEnabledRoots as $rootdomain): ?>
                            <?php
                            $inviteCodeData = $rootdomainInviteCodes[$rootdomain] ?? null;
                            $inviteCode = $inviteCodeData ? ($inviteCodeData['invite_code'] ?? '') : '';
                            
                            // 先获取该根域名已邀请人数
                            $invitedCount = 0;
                            try {
                                if (class_exists('CfRootdomainInviteService') && ($userid ?? 0) > 0) {
                                    $invitedCount = CfRootdomainInviteService::getUserInviteCount($userid, $rootdomain);
                                }
                            } catch (\Throwable $e) {
                                $invitedCount = 0;
                            }
                            
                            // 检查是否达到上限
                            $maxLimit = $rootdomainInviteMaxPerUser;
                            $hasReachedLimit = false;
                            
                            if ($maxLimit > 0) {
                                // 检查是否为特权用户
                                $isPrivileged = false;
                                try {
                                    if (function_exists('cf_is_user_privileged') && cf_is_user_privileged($userid)) {
                                        $isPrivileged = true;
                                    }
                                } catch (\Throwable $e) {
                                    // 忽略错误
                                }
                                
                                // 非特权用户且已达上限
                                if (!$isPrivileged && $invitedCount >= $maxLimit) {
                                    $hasReachedLimit = true;
                                }
                            }
                            
                            // 只有未达上限才生成邀请码
                            if (!$hasReachedLimit && $inviteCode === '' && ($userid ?? 0) > 0) {
                                try {
                                    if (class_exists('CfRootdomainInviteService')) {
                                        $generated = CfRootdomainInviteService::getOrCreateInviteCode($userid, $rootdomain);
                                        $inviteCode = $generated['invite_code'] ?? '';
                                    }
                                } catch (\Throwable $e) {
                                    $inviteCode = '';
                                }
                            }
                            
                            $remainingInvites = $rootdomainInviteMaxPerUser > 0 ? max(0, $rootdomainInviteMaxPerUser - $invitedCount) : -1;
                            ?>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="fas fa-server text-success"></i>
                                            <code><?php echo htmlspecialchars($rootdomain); ?></code>
                                        </h6>
                                        
                                        <?php if ($hasReachedLimit): ?>
                                            <!-- 达到上限：显示提示 -->
                                            <div class="alert alert-warning mb-0">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
                                                    <div class="flex-grow-1">
                                                        <strong><?php echo $modalText('cfclient.rootdomain_invite.limit_reached_title', '已达邀请上限'); ?></strong>
                                                        <p class="mb-2 mt-2 small"><?php echo $modalText('cfclient.rootdomain_invite.limit_reached_desc', '您已邀请 %s 人，已达到该根域名的邀请上限（最多 %s 人）。', [$invitedCount, $maxLimit]); ?></p>
                                                        <small class="text-muted">
                                                            <i class="fas fa-users"></i>
                                                            <?php echo $modalText('cfclient.rootdomain_invite.invited_count', '已邀请：%s 人', [$invitedCount]); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php elseif ($inviteCode !== ''): ?>
                                            <!-- 未达上限：显示邀请码 -->
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">
                                                    <?php echo $modalText('cfclient.rootdomain_invite.your_code', '您的邀请码'); ?>
                                                </label>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control font-monospace" 
                                                           value="<?php echo htmlspecialchars($inviteCode); ?>" 
                                                           id="invite_code_modal_<?php echo htmlspecialchars($rootdomain); ?>" 
                                                           readonly>
                                                    <button class="btn btn-outline-primary" type="button" 
                                                            onclick="copyRootdomainInviteCode('<?php echo htmlspecialchars($rootdomain, ENT_QUOTES); ?>')">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <i class="fas fa-users"></i>
                                                    <?php echo $modalText('cfclient.rootdomain_invite.invited_count', '已邀请：%s 人', [$invitedCount]); ?>
                                                </small>
                                                <?php if ($remainingInvites >= 0): ?>
                                                    <small class="text-success">
                                                        <i class="fas fa-check-circle"></i>
                                                        <?php echo $modalText('cfclient.rootdomain_invite.remaining', '剩余：%s', [$remainingInvites]); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <!-- 邀请码生成失败 -->
                                            <div class="alert alert-warning mb-0">
                                                <small>
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <?php echo $modalText('cfclient.rootdomain_invite.code_not_generated', '邀请码生成失败，请刷新页面重试'); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary">
                        <i class="fas fa-info-circle"></i>
                        <?php echo $modalText('cfclient.rootdomain_invite.no_roots', '当前没有需要邀请码的根域名'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <?php echo $modalText('cfclient.modals.buttons.close', '关闭'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function copyRootdomainInviteCode(rootdomain) {
    const inputId = 'invite_code_modal_' + rootdomain;
    const input = document.getElementById(inputId);
    if (!input) {
        return;
    }
    
    input.select();
    input.setSelectionRange(0, 99999);
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            const btn = input.nextElementSibling;
            const originalHTML = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-success');
            
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-primary');
            }, 2000);
        } else {
            alert(cfLang('copyFailed', '复制失败，请手动复制'));
        }
    } catch (err) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(() => {
                const btn = input.nextElementSibling;
                const originalHTML = btn.innerHTML;
                
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            }).catch(() => {
                alert(cfLang('copyFailed', '复制失败，请手动复制'));
            });
        } else {
            alert(cfLang('browserNotSupport', '您的浏览器不支持自动复制，请手动复制邀请码'));
        }
    }
}

function showRootdomainInviteCodesModal() {
    var modal = document.getElementById('rootdomainInviteCodesModal');
    if (modal) {
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
}

window.showRootdomainInviteCodesModal = showRootdomainInviteCodesModal;
window.copyRootdomainInviteCode = copyRootdomainInviteCode;
window.showRootVerifyModal = function () {
    var modal = document.getElementById('rootVerifyModal');
    if (!modal) return;
    var bsModal = new bootstrap.Modal(modal);
    bsModal.show();
};
window.updateRootVerifyHostPreview = function () {
    var provider = document.getElementById('root_verify_provider');
    var hostInput = document.getElementById('root_verify_host_preview');
    if (!provider || !hostInput) return;
    var aliHost = hostInput.getAttribute('data-ali-host') || 'alidnscheck';
    var dnspodHost = hostInput.getAttribute('data-dnspod-host') || 'dnspodcheck';
    hostInput.value = provider.value === 'dnspod' ? dnspodHost : aliHost;
};
window.updateRootVerifyLockStatus = function () {
    var select = document.querySelector('select[name=\"root_verify_subdomain_id\"]');
    var txtInput = document.querySelector('input[name=\"root_verify_txt_value\"]');
    var submitBtn = document.querySelector('#rootVerifyModal button[type=\"submit\"]');
    var lockHint = document.getElementById('rootVerifyLockHint');
    if (!select || !txtInput || !submitBtn || !lockHint) return;
    var root = (select.options[select.selectedIndex] || {}).getAttribute('data-root') || '';
    var lockMap = window.__rootVerifyLockMap || {};
    var lockInfo = root ? (lockMap[root.toLowerCase()] || null) : null;
    if (lockInfo && parseInt(lockInfo.client_id || 0, 10) === parseInt('<?php echo intval($userid ?? 0); ?>', 10)) {
        lockInfo = null;
    }
    if (!lockInfo) {
        var wasLocked = txtInput.disabled;
        txtInput.disabled = false;
        submitBtn.disabled = false;
        if (wasLocked) {
            txtInput.value = txtInput.getAttribute('data-user-typed') || '';
        }
        lockHint.textContent = '';
        return;
    }
    var expiresTs = Date.parse((lockInfo.locked_until || '').replace(' ', 'T'));
    var now = Date.now();
    var sec = isNaN(expiresTs) ? 0 : Math.max(0, Math.floor((expiresTs - now) / 1000));
    if (sec <= 0) {
        txtInput.disabled = false;
        submitBtn.disabled = false;
        lockHint.textContent = '';
        return;
    }
    txtInput.setAttribute('data-user-typed', txtInput.value);
    txtInput.value = '<?php echo addslashes($modalText('cfclient.root_verify.locked.prefix', '前方用户正在使用')); ?>';
    txtInput.disabled = true;
    submitBtn.disabled = true;
    var mm = Math.floor(sec / 60), ss = sec % 60;
    lockHint.textContent = '<?php echo addslashes($modalText('cfclient.root_verify.locked.hint', '前方用户正在使用，剩余')); ?> ' + mm + 'm ' + ss + 's';
};
</script>

<?php if ($rootVerifyEnabled): ?>
<div class="modal fade" id="rootVerifyModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><?php echo $modalText('cfclient.root_verify.modal.title', '根域名验证(测试版)'); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form method="post" class="mb-3" id="rootVerifyCreateForm">
                <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                <input type="hidden" name="action" value="root_verify_create">
                <div class="mb-2"><label class="form-label"><?php echo $modalText('cfclient.root_verify.form.subdomain', '子域名'); ?></label><select class="form-select" name="root_verify_subdomain_id" onchange="updateRootVerifyLockStatus()" <?php echo $rootVerifyStickySubdomainId > 0 ? 'disabled' : ''; ?> required><option value=""><?php echo $modalText('cfclient.root_verify.form.subdomain_placeholder', '请选择'); ?></option><?php foreach (($existing ?? []) as $sd): ?><?php $rvRoot = strtolower(trim((string) ($sd->rootdomain ?? ''))); if (isset($rootVerifyBlockedRoots[$rvRoot])) { continue; } ?><?php $sidOpt = intval($sd->id ?? 0); ?><option value="<?php echo $sidOpt; ?>" data-root="<?php echo htmlspecialchars((string) ($sd->rootdomain ?? ''), ENT_QUOTES); ?>" <?php echo $rootVerifyStickySubdomainId > 0 && $rootVerifyStickySubdomainId === $sidOpt ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($sd->subdomain ?? ''), ENT_QUOTES); ?></option><?php endforeach; ?></select><?php if ($rootVerifyStickySubdomainId > 0): ?><input type="hidden" name="root_verify_subdomain_id" value="<?php echo $rootVerifyStickySubdomainId; ?>"><?php endif; ?></div>
                <div class="mb-2"><label class="form-label"><?php echo $modalText('cfclient.root_verify.form.provider', '验证平台'); ?></label><select class="form-select" id="root_verify_provider" name="root_verify_provider" onchange="updateRootVerifyHostPreview()" <?php echo $rootVerifyStickyProvider !== '' ? 'disabled' : ''; ?>><?php if ($rootVerifyEnableAliDns): ?><option value="alidns" <?php echo $rootVerifyStickyProvider === 'alidns' ? 'selected' : ''; ?>><?php echo $modalText('cfclient.root_verify.provider.alidns', '阿里云'); ?></option><?php endif; ?><?php if ($rootVerifyEnableDnsPod): ?><option value="dnspod" <?php echo $rootVerifyStickyProvider === 'dnspod' ? 'selected' : ''; ?>><?php echo $modalText('cfclient.root_verify.provider.dnspod', 'DNSPod'); ?></option><?php endif; ?></select><?php if ($rootVerifyStickyProvider !== ''): ?><input type="hidden" name="root_verify_provider" value="<?php echo htmlspecialchars($rootVerifyStickyProvider, ENT_QUOTES); ?>"><?php endif; ?></div>
                <div class="mb-2">
                    <label class="form-label"><?php echo $modalText('cfclient.root_verify.form.host_locked', '记录名'); ?></label>
                    <?php $rootVerifyDefaultHost = $rootVerifyEnableAliDns ? $rootVerifyAliHost : ($rootVerifyEnableDnsPod ? $rootVerifyDnsPodHost : $rootVerifyAliHost); ?>
                    <input type="text" id="root_verify_host_preview" class="form-control" readonly data-ali-host="<?php echo htmlspecialchars($rootVerifyAliHost, ENT_QUOTES); ?>" data-dnspod-host="<?php echo htmlspecialchars($rootVerifyDnsPodHost, ENT_QUOTES); ?>" value="<?php echo htmlspecialchars($rootVerifyDefaultHost, ENT_QUOTES); ?>">
                    <div class="form-text"><?php echo $modalText('cfclient.root_verify.form.host_locked_help', '记录名由系统自动生成提交，不支持修改。'); ?></div>
                </div>
                <div class="mb-2"><label class="form-label"><?php echo $modalText('cfclient.root_verify.form.txt_value', 'TXT 记录值'); ?></label><input type="text" class="form-control" name="root_verify_txt_value" maxlength="255" value="<?php echo htmlspecialchars($rootVerifyStickyTxtValue, ENT_QUOTES); ?>" <?php echo $rootVerifyStickyTxtValue !== '' ? 'readonly' : ''; ?> required></div>
                <div class="form-text text-danger mb-2" id="rootVerifyLockHint"></div>
                <button type="submit" id="rootVerifySubmitBtn" class="btn btn-success w-100 <?php echo $rootVerifyActiveTask ? 'disabled' : ''; ?>" <?php echo $rootVerifyActiveTask ? 'disabled aria-disabled="true"' : ''; ?>><?php echo $rootVerifyActiveTask ? $modalText('cfclient.root_verify.form.in_progress', '验证中') : $modalText('cfclient.root_verify.form.submit', '提交验证'); ?></button>
            </form>
            <?php if ($rootVerifyActiveTask): ?>
                <?php $activeSub = $rootVerifySubdomainMap[intval($rootVerifyActiveTask->subdomain_id ?? 0)] ?? (string) ($rootVerifyActiveTask->rootdomain ?? '-'); ?>
                <div class="alert alert-warning small mb-3">
                    <?php echo $modalText('cfclient.root_verify.current_task', '当前验证任务'); ?>:
                    <?php echo $modalText('cfclient.root_verify.current_task.domain', '域名'); ?>: <?php echo htmlspecialchars($activeSub); ?> /
                    <?php echo $modalText('cfclient.root_verify.current_task.provider', '平台'); ?>: <?php echo htmlspecialchars((string) ($rootVerifyActiveTask->provider ?? '-')); ?>
                    <span class="ms-2 badge bg-warning text-dark root-verify-countdown" data-expires-at="<?php echo htmlspecialchars((string) ($rootVerifyActiveTask->expires_at ?? '')); ?>"></span>
                </div>
                <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap mb-3">
                    <form method="post" class="m-0">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>"><input type="hidden" name="action" value="root_verify_finish"><input type="hidden" name="root_verify_task_id" value="<?php echo intval($rootVerifyActiveTask->id ?? 0); ?>">
                        <button class="btn btn-lg btn-success px-4"><?php echo $modalText('cfclient.root_verify.action.finish', '我已完成验证删除记录'); ?></button>
                    </form>
                    <form method="post" class="m-0">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>"><input type="hidden" name="action" value="root_verify_cancel"><input type="hidden" name="root_verify_task_id" value="<?php echo intval($rootVerifyActiveTask->id ?? 0); ?>">
                        <button class="btn btn-lg btn-danger px-4"><?php echo $modalText('cfclient.root_verify.action.cancel', '取消验证（并删除）'); ?></button>
                    </form>
                </div>
            <?php endif; ?>
            <div class="alert alert-info small">
                <strong><?php echo $modalText('cfclient.root_verify.faq.title', '常见问题解答'); ?></strong>
                <ul class="mb-0 mt-2">
                    <li><?php echo $modalText('cfclient.root_verify.faq.q1', '不能自己输入记录名？为防滥用，记录名由系统自动根据平台生成。'); ?></li>
                    <li><?php echo $modalText('cfclient.root_verify.faq.q2', '验证有效期多久？任务创建生成记录后默认 5 分钟超时自动完成并删除 TXT记录。'); ?></li>
                    <li><?php echo $modalText('cfclient.root_verify.faq.q3', '如果验证已完成怎么办？请点击“我已完成验证并删除记录”把名额释放给下一个有需要的人。'); ?></li>
                </ul>
            </div>
        </div>
    </div></div>
</div>
<?php endif; ?>
<?php
$domainPermanentIncentiveState = isset($domainPermanentIncentiveState) && is_array($domainPermanentIncentiveState) ? $domainPermanentIncentiveState : [];
$domainPermanentIncentiveEligibleDomains = is_array($domainPermanentIncentiveState['eligible_domains'] ?? null) ? $domainPermanentIncentiveState['eligible_domains'] : [];
$domainPermanentIncentiveLogs = is_array($domainPermanentIncentiveState['logs'] ?? null) ? $domainPermanentIncentiveState['logs'] : [];
$domainPermanentIncentiveLogsPagination = is_array($domainPermanentIncentiveState['logs_pagination'] ?? null) ? $domainPermanentIncentiveState['logs_pagination'] : ['page' => 1, 'totalPages' => 1];
$domainPermanentIncentiveEndTs = !empty($domainPermanentIncentiveEndAt) ? strtotime((string) $domainPermanentIncentiveEndAt) : false;
$domainPermanentIncentiveExpired = $domainPermanentIncentiveEndTs ? ($domainPermanentIncentiveEndTs < time()) : false;
$permIncentiveIsChinese = in_array(strtolower((string)($currentLanguage ?? 'english')), ['chinese','zh','zh-cn','cn'], true);
$permReasonMap = [
    'ssl_unavailable' => $modalText('cfclient.domain_permanent_incentive.reason.ssl_unavailable', '未探测到有效 SSL 证书。建议：先部署 HTTPS 证书后重试。'),
    'site_unavailable' => $modalText('cfclient.domain_permanent_incentive.reason.site_unavailable', '网站暂不可访问。建议：检查 DNS/源站连通性后重试。'),
    'ssl_and_site_unavailable' => $modalText('cfclient.domain_permanent_incentive.reason.ssl_and_site_unavailable', 'SSL 与网站访问均未通过。建议：先确保域名可访问并部署有效 SSL。'),
];
?>
<?php if (!empty($domainPermanentIncentiveEnabled)): ?>
<div class="modal fade" id="domainPermanentIncentiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-bolt text-warning me-2"></i><?php echo $modalText('cfclient.domain_permanent_incentive.title', '域名永久激励中心（限时）'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <?php echo $modalText('cfclient.domain_permanent_incentive.notice', '部分域名申请SSL证书建站后可在此一键升级为永久有效(限时开放中）。'); ?>
                    <div class="small mt-1">
                        <?php echo $modalText('cfclient.domain_permanent_incentive.mode', '当前条件模式：%s', [($domainPermanentIncentiveConditionMode ?? 'any') === 'all' ? $modalText('cfclient.domain_permanent_incentive.mode.all', '网站可访问并且启用SSL证书。') : $modalText('cfclient.domain_permanent_incentive.mode.any', '二选一')]); ?>
                    </div>
                    <?php if (!empty($domainPermanentIncentiveEndAt)): ?>
                        <div class="small mt-1"><?php echo $modalText('cfclient.domain_permanent_incentive.ends_at', '活动结束时间：%s', [htmlspecialchars((string)$domainPermanentIncentiveEndAt, ENT_QUOTES)]); ?></div>
                        <div class="small mt-1" id="permIncentiveCountdown" data-end-at="<?php echo htmlspecialchars((string)$domainPermanentIncentiveEndAt, ENT_QUOTES); ?>" data-end-ts="<?php echo $domainPermanentIncentiveEndTs ? intval($domainPermanentIncentiveEndTs) : 0; ?>">
                            <?php
                            if ($domainPermanentIncentiveEndTs) {
                                $remain = $domainPermanentIncentiveEndTs - time();
                                if ($remain <= 0) {
                                    echo htmlspecialchars($modalText('cfclient.domain_permanent_incentive.countdown.expired', '活动状态：已结束'), ENT_QUOTES);
                                } else {
                                    $d = intdiv($remain, 86400);
                                    $h = intdiv($remain % 86400, 3600);
                                    $m = intdiv($remain % 3600, 60);
                                    $s = $remain % 60;
                                    $prefix = $modalText('cfclient.domain_permanent_incentive.countdown.remaining', '活动剩余时间');
                                    if ($permIncentiveIsChinese) {
                                        echo htmlspecialchars($prefix . ': ' . $d . '天' . $h . '小时' . $m . '分钟' . $s . '秒', ENT_QUOTES);
                                    } else {
                                        echo htmlspecialchars($prefix . ': ' . $d . 'd ' . $h . 'h ' . $m . 'm ' . $s . 's', ENT_QUOTES);
                                    }
                                }
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($domainPermanentIncentiveEligibleDomains)): ?>
                    <form method="post" class="d-flex flex-column gap-2 mb-3" id="permIncentiveSubmitForm">
                        <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                        <input type="hidden" name="action" value="check_and_upgrade_domain_permanent_incentive">
                        <label class="small text-muted mb-0"><?php echo $modalText('cfclient.domain_permanent_incentive.select_domain', '选择要升级的域名'); ?></label>
                        <select class="form-select" name="perm_incentive_subdomain_id" required>
                            <?php foreach ($domainPermanentIncentiveEligibleDomains as $option): ?>
                                <?php $optionId = intval($option['id'] ?? 0); $optionDomain = trim((string) ($option['domain'] ?? '')); ?>
                                <?php if ($optionId > 0 && $optionDomain !== ''): ?><option value="<?php echo $optionId; ?>"><?php echo htmlspecialchars($optionDomain, ENT_QUOTES); ?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-warning" id="permIncentiveSubmitButton" <?php echo $domainPermanentIncentiveExpired ? 'disabled' : ''; ?>><i class="fas fa-search me-1"></i><?php echo $modalText('cfclient.domain_permanent_incentive.submit', '提交检测升级'); ?></button>
                        <?php if ($domainPermanentIncentiveExpired): ?>
                            <small class="text-danger"><?php echo $modalText('cfclient.domain_permanent_incentive.expired', '活动已结束，当前不可提交检测升级。'); ?></small>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning small"><?php echo $modalText('cfclient.domain_permanent_incentive.empty', '暂无可检测域名（仅未永久且状态正常且在白名单内的域名可参与）。'); ?></div>
                <?php endif; ?>
                <h6 class="mb-2"><i class="fas fa-history me-1"></i><?php echo $modalText('cfclient.domain_permanent_incentive.logs.title', '升级检测历史日志'); ?></h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th><?php echo $modalText('cfclient.domain_permanent_incentive.logs.time', '时间'); ?></th><th><?php echo $modalText('cfclient.domain_permanent_incentive.logs.domain', '域名'); ?></th><th><?php echo $modalText('cfclient.domain_permanent_incentive.logs.mode', '模式'); ?></th><th>SSL</th><th><?php echo $modalText('cfclient.domain_permanent_incentive.logs.site', '可访问'); ?></th><th><?php echo $modalText('cfclient.domain_permanent_incentive.logs.result', '结果'); ?></th><th><?php echo $modalText('cfclient.domain_permanent_incentive.logs.note', '说明'); ?></th></tr></thead>
                        <tbody>
                        <?php if (!empty($domainPermanentIncentiveLogs)): foreach ($domainPermanentIncentiveLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($log['created_at'] ?? '-'), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['domain'] ?? '-'), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['condition_mode'] ?? '-'), ENT_QUOTES); ?></td>
                                <td><?php echo !empty($log['ssl_check_result']) ? $modalText('cfclient.common.yes', '是') : $modalText('cfclient.common.no', '否'); ?></td>
                                <td><?php echo !empty($log['site_check_result']) ? $modalText('cfclient.common.yes', '是') : $modalText('cfclient.common.no', '否'); ?></td>
                                <td><?php echo (($log['final_result'] ?? '') === 'success') ? ('<span class="text-success">' . $modalText('cfclient.common.success', '成功') . '</span>') : ('<span class="text-danger">' . $modalText('cfclient.common.failed', '失败') . '</span>'); ?></td>
                                <?php $rawReason = trim((string)($log['fail_reason'] ?? '')); $friendlyReason = $permReasonMap[$rawReason] ?? $rawReason; ?>
                                <td><?php echo htmlspecialchars($friendlyReason, ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted"><?php echo $modalText('cfclient.domain_permanent_incentive.logs.empty', '暂无日志'); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php $permIncentivePage = max(1, (int)($domainPermanentIncentiveLogsPagination['page'] ?? 1)); $permIncentiveTotalPages = max(1, (int)($domainPermanentIncentiveLogsPagination['totalPages'] ?? 1)); ?>
                <?php if ($permIncentiveTotalPages > 1): ?>
                    <nav class="mt-2" aria-label="permanent incentive logs pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($i = 1; $i <= $permIncentiveTotalPages; $i++): ?>
                                <?php $params = $_GET; $params['perm_incentive_logs_page'] = $i; ?>
                                <li class="page-item <?php echo $i === $permIncentivePage ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($params), ENT_QUOTES); ?>#domainPermanentIncentiveModal"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                <div class="mt-3">
                    <h6 class="mb-2"><i class="fas fa-question-circle me-1"></i><?php echo $modalText('cfclient.domain_permanent_incentive.faq.title', '常见问题解答'); ?></h6>
                    <div class="small">
                        <div class="mb-2"><strong><?php echo $modalText('cfclient.domain_permanent_incentive.faq.q1', 'Q：域名升级永久是什么意思？'); ?></strong><br><?php echo $modalText('cfclient.domain_permanent_incentive.faq.a1', 'A：升级永久后域名将保持长期有效期状态，不需要在进行任何的续期操作，但仍要遵守使用条款。'); ?></div>
                        <div class="mb-2"><strong><?php echo $modalText('cfclient.domain_permanent_incentive.faq.q2', 'Q：为什么看不到我需要升级的域名？'); ?></strong><br><?php echo $modalText('cfclient.domain_permanent_incentive.faq.a2', 'A：如果您的域名未显示在列表中说明您的域名不支持进行升级操作。'); ?></div>
                        <div><strong><?php echo $modalText('cfclient.domain_permanent_incentive.faq.q3', 'Q：活动结束后还可以升级为永久域名吗？'); ?></strong><br><?php echo $modalText('cfclient.domain_permanent_incentive.faq.a3', 'A：您可以在-域名永久升级功能中心进行需求操作或关注DNSHE官方活动。'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="renewDisplayModeModal" tabindex="-1" aria-labelledby="renewDisplayModeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="cfmod_csrf_token" value="<?php echo htmlspecialchars($_SESSION['cfmod_csrf'] ?? ''); ?>">
                <input type="hidden" name="action" value="set_renew_button_display_mode">
                <div class="modal-header">
                    <h5 class="modal-title" id="renewDisplayModeModalLabel"><?php echo $modalText('cfclient.subdomains.renew_mode.modal_title', '域名免费续期操作按钮显示设置'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php $currentRenewMode = strtolower(trim((string) ($renewButtonDisplayMode ?? 'window_only'))); ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="renew_button_display_mode" id="renewModeWindowOnly" value="window_only" <?php echo $currentRenewMode !== 'always' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="renewModeWindowOnly">
                            <?php echo $modalText('cfclient.subdomains.renew_mode.window_only', '到达可续期时间时自动显示（推荐）'); ?>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="renew_button_display_mode" id="renewModeAlways" value="always" <?php echo $currentRenewMode === 'always' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="renewModeAlways">
                            <?php echo $modalText('cfclient.subdomains.renew_mode.always', '始终显示（未到可续期时间也显示）'); ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $modalText('cfclient.subdomains.renew_mode.cancel', '取消'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo $modalText('cfclient.subdomains.renew_mode.save', '保存设置'); ?></button>
                </div>
            </form>
            <div class="px-3 pb-3">
                <div class="small text-muted border-top pt-2">
                    <div><strong><?php echo $modalText('cfclient.subdomains.renew_mode.faq.q1', 'Q：域名续期是免费的吗？'); ?></strong></div>
                    <div class="mb-2"><?php echo $modalText('cfclient.subdomains.renew_mode.faq.a1', 'A：是的，域名续期操作是完全免费的。'); ?></div>
                    <div><strong><?php echo $modalText('cfclient.subdomains.renew_mode.faq.q2', 'Q：什么时候可以对域名进行续期？'); ?></strong></div>
                    <div class="mb-2"><?php echo $modalText('cfclient.subdomains.renew_mode.faq.a2', 'A：域名到期前 180 天之内，可通过控制台或使用 API 一键续期。'); ?></div>
                    <div><strong><?php echo $modalText('cfclient.subdomains.renew_mode.faq.q3', 'Q：为什么有的域名不显示续期选项？'); ?></strong></div>
                    <div><?php echo $modalText('cfclient.subdomains.renew_mode.faq.a3', 'A：域名处于待删除状态或永久有效状态时，不会显示续期选项。'); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.__rootVerifyLockMap = <?php echo json_encode($rootVerifyActiveLocks, JSON_UNESCAPED_UNICODE); ?>;
if (typeof updateRootVerifyHostPreview === 'function') { updateRootVerifyHostPreview(); }
if (typeof updateRootVerifyLockStatus === 'function') { updateRootVerifyLockStatus(); setInterval(updateRootVerifyLockStatus, 1000); }
<?php if ($rootVerifyActiveTask): ?>
(function(){
    var form = document.getElementById('rootVerifyCreateForm');
    if (!form) return;
    form.addEventListener('submit', function(e){
        e.preventDefault();
        return false;
    });
})();
<?php endif; ?>
<?php if (($_POST['action'] ?? '') === 'root_verify_create'): ?>
setTimeout(function () {
    if (typeof window.showRootVerifyModal === 'function') {
        window.showRootVerifyModal();
    }
}, 100);
<?php endif; ?>
</script>
<script>
(function(){
    function tickRootVerifyCountdown() {
        var els = document.querySelectorAll('.root-verify-countdown');
        if (!els.length) return;
        var now = Date.now();
        els.forEach(function(el){
            var expires = el.getAttribute('data-expires-at');
            if (!expires) { el.textContent = '<?php echo addslashes($modalText('cfclient.root_verify.countdown.unknown', '剩余时间未知')); ?>'; return; }
            var ts = Date.parse(expires.replace(' ', 'T'));
            if (isNaN(ts)) { el.textContent = '<?php echo addslashes($modalText('cfclient.root_verify.countdown.unknown', '剩余时间未知')); ?>'; return; }
            var diff = Math.floor((ts - now) / 1000);
            if (diff <= 0) {
                el.textContent = '<?php echo addslashes($modalText('cfclient.root_verify.countdown.expired', '已过期')); ?>';
                if (el.getAttribute('data-autosubmitted') !== '1') {
                    var modalBody = el.closest('.modal-body') || document;
                    var finishForm = modalBody.querySelector('form input[name=\"action\"][value=\"root_verify_finish\"]');
                    if (finishForm && finishForm.form) {
                        el.setAttribute('data-autosubmitted', '1');
                        finishForm.form.submit();
                    }
                }
                return;
            }
            var m = Math.floor(diff / 60);
            var s = diff % 60;
            el.textContent = '<?php echo addslashes($modalText('cfclient.root_verify.countdown.prefix', '剩余时间')); ?>: ' + m + 'm ' + s + 's';
        });
    }
    tickRootVerifyCountdown();
    setInterval(tickRootVerifyCountdown, 1000);
})();
</script>
