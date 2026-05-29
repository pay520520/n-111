<?php

// 确保CloudflareAPI类被加载
if (!class_exists('CloudflareAPI')) {
    require_once __DIR__ . '/../lib/CloudflareAPI.php';
}
require_once __DIR__ . '/../lib/ProviderResolver.php';
require_once __DIR__ . '/../lib/AdminMaintenance.php';
// 引入队列与作业处理（用于立即执行根域名替换）
if (!function_exists('run_cf_queue_once')) {
    $workerPath = __DIR__ . '/../worker.php';
    if (file_exists($workerPath)) { require_once $workerPath; }
}

if (!defined('CFMOD_SAFE_JSON_FLAGS')) {
    define('CFMOD_SAFE_JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

$LANG = $LANG ?? [];
$langFile = __DIR__ . '/admin/lang/domainHub.php';
if (file_exists($langFile)) {
    $defaultDomainHubLang = include $langFile;
    if (is_array($defaultDomainHubLang)) {
        if (!isset($LANG['domainHub']) || !is_array($LANG['domainHub'])) {
            $LANG['domainHub'] = $defaultDomainHubLang;
        } else {
            $LANG['domainHub'] = array_replace($defaultDomainHubLang, $LANG['domainHub']);
        }
    }
}
$lang = $LANG['domainHub'] ?? [];

$cfmodProviderTypeLabels = [
    'alidns' => '阿里云 DNS (AliDNS)',
    'dnspod_legacy' => 'DNSPod 国际版 Legacy API',
    'dnspod_intl' => 'DNSPod 国际版 API 3.0',
    'powerdns' => 'PowerDNS (自建)',
];

$moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
$moduleSlugAttr = htmlspecialchars($moduleSlug, ENT_QUOTES);
$moduleSlugUrl = urlencode($moduleSlug);
$moduleSlugLegacy = defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain';

if (function_exists('cf_ensure_module_settings_migrated')) {
    cf_ensure_module_settings_migrated();
}

$cfmodAdminCsrfToken = $_SESSION['cfmod_admin_csrf'] ?? '';
$cfmodAdminCsrfValid = cfmod_validate_admin_csrf();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$cfmodAdminCsrfValid) {
    $_SESSION['admin_api_error'] = '❌ 请求已过期或无效，请刷新页面后重试。';
    $redirectUrl = cfmod_admin_current_url_without_action();
    header('Location: ' . $redirectUrl);
    exit;
}

echo '<noscript><div class="alert alert-danger">' . htmlspecialchars($lang['noscript_warning'] ?? '为了防止CSRF攻击，请启用浏览器的 JavaScript 后再执行敏感操作。', ENT_QUOTES, 'UTF-8') . '</div></noscript>';

$cfAdminViewModel = $cfAdminViewModel ?? [];
$cfAdminAlerts = $cfAdminViewModel['alerts']['messages'] ?? [];
$statsView = $cfAdminViewModel['stats'] ?? [];
$total_count = $statsView['totalSubdomains'] ?? ($total_count ?? 0);
$active_count = $statsView['activeSubdomains'] ?? ($active_count ?? 0);
$total_users = $statsView['registeredUsers'] ?? ($total_users ?? 0);
$total_subdomains_created = $statsView['subdomainsCreated'] ?? ($total_subdomains_created ?? 0);
$total_dns_operations = $statsView['dnsOperations'] ?? ($total_dns_operations ?? 0);
$user_registration_trend = $statsView['registrationTrend'] ?? [];
$popular_rootdomains = $statsView['popularRootdomains'] ?? [];
$dns_record_types = $statsView['dnsRecordTypes'] ?? [];
$user_usage_patterns = $statsView['usagePatterns'] ?? [];
$userActivityView = $statsView['userActivity'] ?? [];
$user_stats = $userActivityView['items'] ?? [];
$ustPage = $userActivityView['page'] ?? 1;
$ustPerPage = $userActivityView['perPage'] ?? 20;
$ustTotal = $userActivityView['total'] ?? (is_countable($user_stats) ? count($user_stats) : 0);
$ustTotalPages = $userActivityView['totalPages'] ?? 1;
$providersView = $cfAdminViewModel['providers'] ?? [];
$providerAccounts = $providersView['accounts'] ?? [];
$providerAccountMap = $providersView['accountMap'] ?? [];
$activeProviderAccounts = $providersView['activeAccounts'] ?? [];
$providerUsageCounts = $providersView['usageCounts'] ?? [];
$providerAccountsError = $providersView['error'] ?? '';
$hasActiveProviderAccounts = !empty($providersView['hasActive']);
$defaultProviderAccountId = (int) ($providersView['defaultAccountId'] ?? 0);
$defaultProviderSelectId = (int) ($providersView['defaultSelectId'] ?? 0);

$privilegedView = $cfAdminViewModel['privileged'] ?? [];
$privilegedKeyword = $privilegedView['keyword'] ?? '';
$privilegedUsers = $privilegedView['users'] ?? [];
$privilegedUserCount = $privilegedView['userCount'] ?? (is_countable($privilegedUsers) ? count($privilegedUsers) : 0);
$privilegedIds = $privilegedView['ids'] ?? [];
$privilegedSearchView = $privilegedView['search'] ?? [];
$privilegedSearchPerformed = $privilegedSearchView['performed'] ?? ($privilegedKeyword !== '');
$privilegedSearchResults = $privilegedSearchView['results'] ?? [];
$privilegedSearchCount = $privilegedSearchView['count'] ?? (is_countable($privilegedSearchResults) ? count($privilegedSearchResults) : 0);

$quotasView = $cfAdminViewModel['quotas'] ?? [];
$quotaListView = $quotasView['list'] ?? [];
$user_quotas = $quotaListView['items'] ?? [];
$quotaPage = $quotaListView['page'] ?? 1;
$quotaPerPage = $quotaListView['perPage'] ?? 20;
$quotaTotal = $quotaListView['total'] ?? (is_countable($user_quotas) ? count($user_quotas) : 0);
$quotaTotalPages = $quotaListView['totalPages'] ?? 1;
$quotaSearchView = $quotasView['search'] ?? [];
$quotaSearch = $quotaSearchView['keyword'] ?? '';
$quotaSearchResult = $quotaSearchView['result'] ?? null;
$quotaSearchError = $quotaSearchView['error'] ?? '';
$quotaPrefill = $quotasView['prefill'] ?? [];
$quotaPrefillEmail = $quotaPrefill['email'] ?? '';
$quotaPrefillUserId = $quotaPrefill['userId'] ?? 0;
$quotaPrefillMax = $quotaPrefill['max'] ?? '';
$quotaPrefillInviteLimit = $quotaPrefill['inviteLimit'] ?? '';
$quotaPrefillUsed = array_key_exists('used', $quotaPrefill) ? $quotaPrefill['used'] : null;

$inviteView = $cfAdminViewModel['invite'] ?? [];
$rewardSection = $inviteView['rewards'] ?? [];
$periodStart = $rewardSection['periodStart'] ?? '';
$periodEnd = $rewardSection['periodEnd'] ?? '';
$reward_list = $rewardSection['items'] ?? [];
$inviteStatsView = $inviteView['stats'] ?? [];
$invite_stats = $inviteStatsView['items'] ?? [];
$invPage = $inviteStatsView['page'] ?? 1;
$invPerPage = $inviteStatsView['perPage'] ?? 20;
$invTotal = $inviteStatsView['total'] ?? (is_countable($invite_stats) ? count($invite_stats) : 0);
$invTotalPages = $inviteStatsView['totalPages'] ?? 1;
$top20 = $inviteView['top'] ?? [];
$snapshotsView = $inviteView['history'] ?? [];
$leaderboard_history = $snapshotsView['items'] ?? [];
$snapPage = $snapshotsView['page'] ?? 1;
$snapPerPage = $snapshotsView['perPage'] ?? 20;
$snapTotal = $snapshotsView['total'] ?? (is_countable($leaderboard_history) ? count($leaderboard_history) : 0);
$snapTotalPages = $snapshotsView['totalPages'] ?? 1;

$jobsView = $cfAdminViewModel['jobs'] ?? [];
$jobSummary = $jobsView['summary'] ?? [];
$jobsPagination = $jobsView['jobs'] ?? [];
$jobsPerPage = $jobsPagination['perPage'] ?? 20;
$jobsPage = $jobsPagination['page'] ?? 1;
$jobsTotal = $jobsPagination['total'] ?? 0;
$jobsTotalPages = $jobsPagination['totalPages'] ?? 1;
$recentJobs = $jobsPagination['items'] ?? [];
$diffView = $jobsView['diffs'] ?? [];
$diffPerPage = $diffView['perPage'] ?? 20;
$diffPage = $diffView['page'] ?? 1;
$diffTotal = $diffView['total'] ?? 0;
$diffTotalPages = $diffView['totalPages'] ?? 1;
$recentDiffs = $diffView['items'] ?? [];
$jobError = $jobsView['error'] ?? null;
$lastCalTime = $jobSummary['lastCalibrationTime'] ?? '-';
$lastCalStatus = $jobSummary['lastCalibrationStatus'] ?? '-';
$calibSummary = $jobSummary['calibrationSummary'] ?? ['byKind' => [], 'byAction' => []];
$backlogCount = $jobSummary['backlogCount'] ?? 0;
$jobErrorRate = $jobSummary['errorRate'] ?? '-';
$remoteCompBacklog = intval($jobSummary['remoteCompBacklog'] ?? 0);
$remoteComp24hSuccessRate = $jobSummary['remoteComp24hSuccessRate'] ?? '-';
$remoteCompFailTop = is_array($jobSummary['remoteCompFailTop'] ?? null) ? $jobSummary['remoteCompFailTop'] : [];
$typePerformance24h = is_array($jobSummary['typePerformance'] ?? null) ? $jobSummary['typePerformance'] : [];

$logsView = $cfAdminViewModel['logs'] ?? [];
$logs = $logsView['entries'] ?? [];
$logsUserFilter = $logsView['userFilter'] ?? '';
$showAllLogs = !empty($logsView['showAll']);

$bansView = $cfAdminViewModel['bans'] ?? [];
$banned_users = $bansView['items'] ?? [];



// 检查数据库表是否存在
$systemView = $cfAdminViewModel['system'] ?? [];
$tables_exist = !empty($systemView['tablesExist']);

// 如果表不存在，显示激活提示
if (!$tables_exist) {
    echo '<div class="alert alert-warning" role="alert">';
    echo '<h4>插件未激活</h4>';
    echo '<p>数据库表尚未创建，请先激活插件：</p>';
    echo '<ol>';
    echo '<li>进入 <strong>设置 → 插件模块</strong></li>';
    echo '<li>找到 "阿里云DNS 二级域名分发" 插件</li>';
    echo '<li>点击 <strong>激活</strong> 按钮</li>';
    echo '<li>激活成功后再访问此页面</li>';
    echo '</ol>';
    echo '<p><strong>注意：</strong> 激活插件会自动创建必要的数据库表。</p>';
    echo '</div>';
    exit;
}

$module_settings = $cfAdminViewModel['moduleSettings'] ?? [];
if (!is_array($module_settings) || empty($module_settings)) {
    $module_settings = [
        'cloudflare_api_key' => '',
        'cloudflare_email' => '',
        'max_subdomain_per_user' => 3,
        'root_domains' => '',
        'forbidden_prefix' => 'www,mail,ftp,admin,root,gov,pay,bank',
        'default_ip' => '192.0.2.1',
    ];
}

$rootdomainsView = $cfAdminViewModel['rootdomains'] ?? [];
$allKnownRootdomains = $rootdomainsView['allKnownRootdomains'] ?? [];
$clientPageSizeSetting = max(1, min(20, intval($module_settings['client_page_size'] ?? 20)));

$subdomainsView = $cfAdminViewModel['subdomains'] ?? [];
$subFilters = $subdomainsView['filters'] ?? [];
$search = (string) ($subFilters['search'] ?? '');
$status_filter = (string) ($subFilters['status'] ?? '');
$user_filter = (string) ($subFilters['user'] ?? '');
$resolvedUserId = isset($subFilters['resolvedUserId']) && $subFilters['resolvedUserId'] !== null
    ? (int) $subFilters['resolvedUserId']
    : null;
$expiryDaysInput = (string) ($subFilters['expiryDaysInput'] ?? '');
$expiryDaysFilter = isset($subFilters['expiryDaysFilter']) && is_numeric($subFilters['expiryDaysFilter'])
    ? (int) $subFilters['expiryDaysFilter']
    : null;
$showAllSubdomains = !empty($subFilters['showAll']);

$subListView = $subdomainsView['list'] ?? [];
$subdomains = $subListView['items'] ?? [];
$subPage = max(1, (int) ($subListView['page'] ?? 1));
$subPerPage = max(1, (int) ($subListView['perPage'] ?? 10));
$subTotal = (int) ($subListView['total'] ?? (is_countable($subdomains) ? count($subdomains) : 0));
$subTotalPages = max(1, (int) ($subListView['totalPages'] ?? 1));

$parsedView = $subdomainsView['parsed'] ?? [];
$parsed_list = $parsedView['items'] ?? [];
$parsedPage = max(1, (int) ($parsedView['page'] ?? 1));
$parsedPerPage = max(1, (int) ($parsedView['perPage'] ?? 10));
$parsedTotal = (int) ($parsedView['total'] ?? (is_countable($parsed_list) ? count($parsed_list) : 0));
$parsedTotalPages = max(1, (int) ($parsedView['totalPages'] ?? 1));


// 单独动作：对已封禁用户一键处置DNS（仅保留 A 改为指定IP，其它删除）
// 初始化邀请统计所需表（若未存在）
// 健康检查与校准摘要
$apiHealthy = false;
$apiLatencyMs = null;
$lastCalTime = $lastCalTime ?? '-';
$backlogCount = $backlogCount ?? 0;
$jobErrorRate = $jobErrorRate ?? '-';
$calibSummary = $calibSummary ?? ['byKind'=>[], 'byAction'=>[]];
$lastCalStatus = $lastCalStatus ?? '-';
// 外部扫描 API 连接信息
$riskEndpoint = trim($module_settings['risk_api_endpoint'] ?? '');
$riskConn = ['ok'=>false,'status'=>null,'latency_ms'=>null,'ip'=>null,'error'=>null];
try {
    // API 可用性与延迟
    $start = microtime(true);
    $providerContext = cfmod_acquire_default_provider_client($module_settings);
    if ($providerContext && !empty($providerContext['client'])) {
        $apiHealthy = $providerContext['client']->validateCredentials();
        $apiLatencyMs = (int) round((microtime(true) - $start) * 1000);
    } else {
        $apiHealthy = false;
    }
} catch (Exception $e) { $apiHealthy = false; }
// 外部扫描 API 连接测试
try {
    if ($riskEndpoint !== '') {
        $url = rtrim($riskEndpoint, '/');
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) { $riskConn['ip'] = gethostbyname($host); }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $t0 = microtime(true);
        curl_exec($ch);
        $riskConn['status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $riskConn['latency_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $err = curl_error($ch);
        curl_close($ch);
        $riskConn['ok'] = ($err === '' && $riskConn['status'] > 0);
        if ($err !== '') { $riskConn['error'] = $err; }
    }
} catch (Exception $e) { $riskConn['ok'] = false; $riskConn['error'] = $e->getMessage(); }

// 风险监控数据
$riskView = $cfAdminViewModel['risk'] ?? [];
$riskTop = $riskView['top'] ?? [];
$riskTrend = $riskView['trend'] ?? [];

$riskEventsMeta = $riskView['events'] ?? [];
$riskEvents = $riskEventsMeta['items'] ?? [];
$riskEventsPage = (int) ($riskEventsMeta['page'] ?? 1);
$riskEventsPerPage = (int) ($riskEventsMeta['perPage'] ?? 20);
$riskEventsTotal = (int) ($riskEventsMeta['total'] ?? 0);
$riskEventsTotalPages = (int) ($riskEventsMeta['totalPages'] ?? 1);

$riskListMeta = $riskView['list'] ?? [];
$riskList = $riskListMeta['items'] ?? [];
$riskListPage = (int) ($riskListMeta['page'] ?? 1);
$riskListPerPage = (int) ($riskListMeta['perPage'] ?? 20);
$riskListTotal = (int) ($riskListMeta['total'] ?? 0);
$riskListTotalPages = (int) ($riskListMeta['totalPages'] ?? 1);

$riskFilters = $riskView['filters'] ?? [];
$levelFilter = $riskFilters['level'] ?? '';
$kwFilter = $riskFilters['keyword'] ?? '';

$riskLogMeta = $riskView['log'] ?? [];
$viewRiskLogId = intval($riskLogMeta['subdomainId'] ?? 0);
$riskLogEvents = $riskLogMeta['entries'] ?? [];
?>
<?php if ($viewRiskLogId > 0): ?>
<div class="alert alert-primary">
  <div class="d-flex justify-content-between align-items-center">
    <strong>探测日志 - 子域ID：<?php echo $viewRiskLogId; ?></strong>
    <a href="?module=domain_hub" class="btn btn-sm btn-outline-secondary">返回</a>
  </div>
  <div class="table-responsive mt-2">
    <table class="table table-sm">
      <thead><tr><th>ID</th><th>时间</th><th>来源</th><th>分数</th><th>级别</th><th>原因</th><th>详情</th></tr></thead>
      <tbody>
        <?php if (!empty($riskLogEvents)): foreach ($riskLogEvents as $ev): ?>
        <tr>
          <td><?php echo intval($ev->id); ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($ev->created_at ?? ''); ?></small></td>
          <td><?php echo htmlspecialchars($ev->source ?? ''); ?></td>
          <td><?php echo intval($ev->score ?? 0); ?></td>
          <td><?php echo htmlspecialchars($ev->level ?? ''); ?></td>
          <td><small><?php echo htmlspecialchars($ev->reason ?? ''); ?></small></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($ev->details_json ?? ''); ?></small></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="7" class="text-center text-muted">暂无日志</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/admin/partials/announcements.tpl'; ?>


<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">阿里云DNS 二级域名管理</h2>
            <?php include __DIR__ . '/admin/partials/alerts.tpl'; ?>
            <?php include __DIR__ . '/admin/partials/stats_cards.tpl'; ?>

<?php include __DIR__ . '/admin/partials/runtime_tools.tpl'; ?>
<?php
$lazyAdminBlocks = [
    ['id' => 'ops_logs', 'title' => '操作日志'],
    ['id' => 'dns_unlock_logs', 'title' => 'DNS 解锁记录'],
    ['id' => 'invite_registration_logs', 'title' => '邀请注册日志'],
    ['id' => 'rootdomain_invite_logs', 'title' => '根域名邀请注册日志'],
    ['id' => 'domain_permanent_upgrade_logs', 'title' => '永久升级助力日志'],
];
?>
<div class="card mb-4" id="lazy-log-blocks">
    <div class="card-body">
        <h5 class="card-title mb-3"><i class="fas fa-layer-group"></i> 折叠日志中心（按需加载）</h5>
        <div class="accordion" id="cfmodLazyAccordion">
            <?php foreach ($lazyAdminBlocks as $idx => $block): ?>
            <div class="accordion-item mb-2 border rounded">
                <h2 class="accordion-header" id="heading-<?php echo $block['id']; ?>">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                            data-bs-target="#collapse-<?php echo $block['id']; ?>" aria-expanded="false"
                            aria-controls="collapse-<?php echo $block['id']; ?>"
                            data-lazy-block="<?php echo htmlspecialchars($block['id']); ?>">
                        <?php echo htmlspecialchars($block['title']); ?>
                    </button>
                </h2>
                <div id="collapse-<?php echo $block['id']; ?>" class="accordion-collapse collapse"
                     aria-labelledby="heading-<?php echo $block['id']; ?>" data-bs-parent="#cfmodLazyAccordion">
                    <div class="accordion-body" id="lazy-body-<?php echo $block['id']; ?>" data-loaded="0">
                        <div class="text-muted small">点击展开后加载数据...</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/admin/partials/job_queue.tpl'; ?>

            <!-- 子域名已解析列表 -->
            <div class="card mb-4">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-globe"></i> 子域名已解析列表</h5>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#parsed-list-collapse" aria-expanded="false" aria-controls="parsed-list-collapse">展开/收起</button>
                </div>
                <div class="collapse" id="parsed-list-collapse">
                <div class="card-body">
                    <?php
                    $parsedPaginationBaseParams = $_GET;
                    $parsedPaginationBaseParams['module'] = 'domain_hub';
                    unset($parsedPaginationBaseParams['parsed_page'], $parsedPaginationBaseParams['action']);
                    $buildParsedPaginationUrl = function($page) use ($parsedPaginationBaseParams) {
                        $params = $parsedPaginationBaseParams;
                        $params['parsed_page'] = $page;
                        return '?' . http_build_query($params) . '#parsed';
                    };
                    ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>ID</th><th>用户ID</th><th>Email</th><th>子域名</th><th>根域名</th><th>记录ID</th><th>状态</th><th>创建时间</th></tr></thead>
                            <tbody>
                                <?php if (!empty($parsed_list)): foreach($parsed_list as $s): ?>
                                <tr>
                                    <td><?php echo intval($s->id); ?></td>
                                    <td><?php echo intval($s->userid); ?></td>
                                    <td><?php echo htmlspecialchars($s->email ?? ''); ?></td>
                                    <td><code><?php echo htmlspecialchars($s->subdomain); ?></code></td>
                                    <td><?php echo htmlspecialchars($s->rootdomain); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($s->dns_record_id ?? ''); ?></small></td>
                                    <td><span class="badge bg-<?php echo $s->status==='active'?'success':'secondary'; ?>"><?php echo htmlspecialchars($s->status); ?></span></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($s->created_at ?? ''); ?></small></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted">暂无数据</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php if ($parsedTotalPages > 1): ?>
                        <nav aria-label="已解析子域分页" class="mt-2">
                        <ul class="pagination pagination-sm justify-content-center">
                            <?php if ($parsedPage > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildParsedPaginationUrl($parsedPage-1), ENT_QUOTES); ?>">上一页</a></li>
                            <?php endif; ?>
                            <?php for($i=1;$i<=$parsedTotalPages;$i++): ?>
                                <?php if ($i == $parsedPage): ?>
                                    <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                                <?php elseif ($i==1 || $i==$parsedTotalPages || abs($i-$parsedPage)<=2): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildParsedPaginationUrl($i), ENT_QUOTES); ?>"><?php echo $i; ?></a></li>
                                <?php elseif (abs($i-$parsedPage)==3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($parsedPage < $parsedTotalPages): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildParsedPaginationUrl($parsedPage+1), ENT_QUOTES); ?>">下一页</a></li>
                            <?php endif; ?>
                        </ul>
                        <div class="text-center text-muted small">第 <?php echo $parsedPage; ?> / <?php echo $parsedTotalPages; ?> 页（共 <?php echo $parsedTotal; ?> 条）</div>
                        </nav>
                        <?php endif; ?>
                        </div>
                        </div>

                        <?php
                        $privilegedUserCount = is_countable($privilegedUsers) ? count($privilegedUsers) : 0;
                        $privilegedSearchCount = is_countable($privilegedSearchResults) ? count($privilegedSearchResults) : 0;
                        ?>
                        <?php include __DIR__ . '/admin/partials/privileged_users.tpl'; ?>

<!-- 用户管理 -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <?php include __DIR__ . '/admin/partials/user_quotas.tpl'; ?>
                    <?php include __DIR__ . '/admin/partials/quota_redeem.tpl'; ?>
                </div>

                <?php include __DIR__ . '/admin/partials/banned_users.tpl'; ?>


<?php include __DIR__ . '/admin/partials/invite_rewards.tpl'; ?>

            <!-- 域名转赠记录 -->
            <?php include __DIR__ . '/admin/partials/domain_gifts.tpl'; ?>

<?php include __DIR__ . '/admin/partials/stats_extra.tpl'; ?>

            <?php include __DIR__ . '/admin/partials/provider_accounts.tpl'; ?>

                <?php include __DIR__ . '/admin/partials/rootdomains/list.tpl'; ?>

                <!-- 搜索和筛选 -->
                <div class="card mb-4">

                    <form method="get" class="row g-3">
                        <input type="hidden" name="module" value="domain_hub">

                        <div class="col-md-3">
                            <label class="form-label">搜索</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="子域名、根域名、用户名...">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">状态</label>
                            <select class="form-select" name="status">
                                <option value="">全部</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>活跃</option>
                                <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>暂停</option>
                                <option value="deleted" <?php echo $status_filter == 'deleted' ? 'selected' : ''; ?>>已删除</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">用户（ID或邮箱）</label>
                            <input type="text" class="form-control" name="user" value="<?php echo htmlspecialchars($user_filter); ?>" placeholder="例如 123 或 user@example.com">
                            <div class="form-text text-muted">支持输入用户ID、邮箱或姓名关键字</div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">到期不足（天）</label>
                            <input type="number" class="form-control" name="expiry_days" min="1" max="3650" value="<?php echo htmlspecialchars($expiryDaysInput); ?>" placeholder="如 365">
                            <div class="form-text text-muted">仅显示将在指定天数内到期的非永久域名</div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">搜索</button>
                                <a href="?module=domain_hub" class="btn btn-secondary">重置</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 批量操作 -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">子域名列表</h5>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" onclick="openBatchExpiryPanel()">
                                <i class="fas fa-clock me-1"></i> 批量调整到期
                            </button>
                            <button type="button" class="btn btn-danger" onclick="confirmBatchDelete()">批量删除</button>
                            <a href="?module=domain_hub&action=export" class="btn btn-success">导出CSV</a>
                        </div>
                    </div>

                    <div id="batchExpiryPanel" class="alert alert-info" style="display:none;">
                        <form method="post" id="batchExpiryForm" class="row g-3 align-items-end" onsubmit="return validateBatchExpiryForm();">
                            <input type="hidden" name="action" value="batch_adjust_expiry">
                            <div id="batchExpirySelectedHolder"></div>
                            <div class="col-12">
                                <strong>已选择 <span id="batchExpiryCount">0</span> 个子域名。</strong> 请设置批量调整策略。
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">操作类型</label>
                                <select class="form-select" name="batch_expiry_mode" id="batchExpiryMode">
                                    <option value="set">设置具体日期</option>
                                    <option value="extend">延长天数</option>
                                    <option value="never">设置为永久</option>
                                </select>
                            </div>
                            <div class="col-md-4 batch-expiry-field" data-mode="set">
                                <label class="form-label">新的到期时间</label>
                                <input type="datetime-local" class="form-control" name="expires_at_input" id="batchExpiryDateInput">
                            </div>
                            <div class="col-md-3 batch-expiry-field" data-mode="extend" style="display:none;">
                                <label class="form-label">延长天数</label>
                                <input type="number" class="form-control" name="extend_days" id="batchExpiryExtendInput" min="1" max="3650" placeholder="例如 90">
                            </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary me-2">执行批量调整</button>
                                <button type="button" class="btn btn-outline-secondary me-2" onclick="refreshBatchExpirySelection()">重新获取选中项</button>
                                <button type="button" class="btn btn-link text-muted" onclick="closeBatchExpiryPanel()">取消</button>
                            </div>
                        </form>
                        <div class="small text-muted mt-2">若在打开面板后修改了勾选，请点击“重新获取选中项”以同步所选列表。</div>
                    </div>

                    <?php
                        $subPaginationBaseParams = $_GET;
                        $subPaginationBaseParams['module'] = 'domain_hub';
                        unset($subPaginationBaseParams['sub_page'], $subPaginationBaseParams['action']);
                        $buildSubPaginationUrl = function($page) use ($subPaginationBaseParams) {
                            $params = $subPaginationBaseParams;
                            $params['sub_page'] = $page;
                            return '?' . http_build_query($params) . '#subdomains';
                        };
                    ?>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th><input type="checkbox" id="selectAll"></th>
                                    <th>ID</th>
                                    <th>用户</th>
                                    <th>子域名</th>
                                    <th>根域名</th>
                                    <th>状态</th>
                                    <th>注册时间</th>
                                    <th>到期时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>

                                <?php if(count($subdomains) > 0): ?>
                                    <?php $nowTsAdmin = time(); ?>
                                    <?php foreach($subdomains as $s): ?>
                                        <tr>
                                            <?php
                                                $neverExpires = intval($s->never_expires ?? 0) === 1;
                                                $expiresRaw = $s->expires_at ?? null;
                                                $expiresTs = $expiresRaw ? strtotime($expiresRaw) : null;
                                                $expiryDisplay = $neverExpires ? '永久有效' : ($expiresTs ? date('Y-m-d H:i', $expiresTs) : '未设置');
                                                $remainingLabel = $neverExpires ? '-' : '未设置';
                                                $remainingClass = 'secondary';
                                                if (!$neverExpires && $expiresTs) {
                                                    $diffSeconds = $expiresTs - $nowTsAdmin;
                                                    if ($diffSeconds >= 0) {
                                                        $diffDays = (int) ceil($diffSeconds / 86400);
                                                        $remainingLabel = $diffDays <= 0 ? '不足1天' : ($diffDays . ' 天');
                                                        $remainingClass = ($diffSeconds <= 3 * 86400) ? 'warning' : 'success';
                                                    } else {
                                                        $overDays = (int) ceil(abs($diffSeconds) / 86400);
                                                        $remainingLabel = '逾期 ' . ($overDays <= 0 ? '<1 天' : $overDays . ' 天');
                                                        $remainingClass = 'danger';
                                                    }
                                                }
                                                $remainingTextClass = 'text-muted';
                                                if ($remainingClass === 'danger') {
                                                    $remainingTextClass = 'text-danger';
                                                } elseif ($remainingClass === 'warning') {
                                                    $remainingTextClass = 'text-warning';
                                                } elseif ($remainingClass === 'success') {
                                                    $remainingTextClass = 'text-success';
                                                }
                                            ?>
                                            <td>
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo $s->id; ?>" class="record-checkbox" form="batchForm">
                                            </td>
                                                <td><?php echo $s->id; ?></td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($s->firstname . ' ' . $s->lastname); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($s->email); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($s->subdomain); ?></code>
                                                    <?php if($s->dns_record_id): ?>
                                                        <span class="badge bg-success ms-1">已解析</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning ms-1">未解析</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($s->rootdomain); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $s->status == 'active' ? 'success' : ($s->status == 'suspended' ? 'warning' : 'danger'); ?>">
                                                        <?php echo ucfirst($s->status); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($s->created_at)); ?></td>
                                                <td>
                                                    <?php if ($neverExpires): ?>
                                                        <span class="badge bg-secondary">永久有效</span>
                                                    <?php elseif ($expiresTs): ?>
                                                        <div><span class="badge bg-<?php echo $expiresTs < $nowTsAdmin ? 'danger' : 'secondary'; ?>"><?php echo htmlspecialchars($expiryDisplay); ?></span></div>
                                                        <small class="<?php echo $remainingTextClass; ?> d-block mt-1"><?php echo htmlspecialchars($remainingLabel); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">未设置</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <form method="post" class="d-inline" onsubmit="return confirm('确定重新生成解析？');">
                                                            <input type="hidden" name="action" value="admin_regen_subdomain">
                                                            <input type="hidden" name="subdomain_id" value="<?php echo $s->id; ?>">
                                                            <button type="submit" class="btn btn-sm btn-info" title="重新生成解析">
                                                                <i class="fas fa-sync"></i>
                                                            </button>
                                                        </form>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('确定切换状态？');">
                                                            <input type="hidden" name="action" value="toggle_subdomain_status">
                                                            <input type="hidden" name="id" value="<?php echo $s->id; ?>">
                                                            <button type="submit" class="btn btn-sm btn-secondary" title="启用/暂停">
                                                                <i class="fas <?php echo (strtolower((string)($s->status ?? '')) === 'suspended') ? 'fa-play' : 'fa-pause'; ?>"></i>
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" title="调整到期" onclick="toggleExpiryForm(<?php echo $s->id; ?>)">
                                                            <i class="fas fa-clock"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-info js-view-subdomain-dns" title="查看解析记录" data-subdomain-id="<?php echo intval($s->id); ?>" data-subdomain-name="<?php echo htmlspecialchars($s->subdomain, ENT_QUOTES, 'UTF-8'); ?>" data-toggle="modal" data-target="#subdomainDnsModal" data-bs-toggle="modal" data-bs-target="#subdomainDnsModal">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('确定删除该子域名？此操作不可恢复。');">
                                                            <input type="hidden" name="action" value="admin_delete_subdomain">
                                                            <input type="hidden" name="subdomain_id" value="<?php echo $s->id; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" title="删除子域名">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr id="expiry_form_<?php echo $s->id; ?>" style="display:none;">
                                                <td colspan="9">
                                                    <form method="post" class="row g-2 align-items-end">
                                                        <input type="hidden" name="action" value="admin_adjust_expiry">
                                                        <input type="hidden" name="subdomain_id" value="<?php echo $s->id; ?>">
                                                        <div class="col-md-3">
                                                            <label class="form-label mb-0">指定到期时间</label>
                                                            <input type="datetime-local" class="form-control" name="expires_at_input" value="<?php echo (!$neverExpires && $expiresTs) ? date('Y-m-d\TH:i', $expiresTs) : ''; ?>">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button type="submit" name="mode" value="set" class="btn btn-primary w-100">保存日期</button>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button type="submit" name="mode" value="extend180" class="btn btn-outline-secondary w-100"<?php echo $neverExpires ? ' disabled' : ''; ?>>+180 天</button>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button type="submit" name="mode" value="extend365" class="btn btn-outline-primary w-100"<?php echo $neverExpires ? ' disabled' : ''; ?>>+365 天</button>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button type="submit" name="mode" value="never" class="btn btn-outline-dark w-100"<?php echo $neverExpires ? ' disabled' : ''; ?>>设为永久</button>
                                                        </div>
                                                    </form>
                                                    <div class="small text-muted mt-2">
                                                        保存日期会覆盖当前到期时间；延长期限仅对非永久域名生效。
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">暂无数据</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($subTotalPages > 1): ?>
                        <nav aria-label="子域名分页" class="mt-2">
                            <ul class="pagination pagination-sm justify-content-center">
                                <?php if ($subPage > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildSubPaginationUrl($subPage-1), ENT_QUOTES); ?>">上一页</a></li>
                                <?php endif; ?>
                                <?php for($i=1;$i<=$subTotalPages;$i++): ?>
                                    <?php if ($i == $subPage): ?>
                                        <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                                    <?php elseif ($i==1 || $i==$subTotalPages || abs($i-$subPage)<=2): ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildSubPaginationUrl($i), ENT_QUOTES); ?>"><?php echo $i; ?></a></li>
                                    <?php elseif (abs($i-$subPage)==3): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($subPage < $subTotalPages): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildSubPaginationUrl($subPage+1), ENT_QUOTES); ?>">下一页</a></li>
                                <?php endif; ?>
                            </ul>
                            <div class="text-center text-muted small">第 <?php echo $subPage; ?> / <?php echo $subTotalPages; ?> 页（共 <?php echo $subTotal; ?> 条）</div>
                        </nav>
                        <?php endif; ?>
                        </div>
                </div>
                </div>
            </div>

            <div class="modal fade" id="subdomainDnsModal" tabindex="-1" aria-labelledby="subdomainDnsModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="subdomainDnsModalLabel"><i class="fas fa-list-alt me-2"></i><span id="cfmodSubdomainDnsModalTitle">解析记录</span></h5>
                            <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <div id="cfmodSubdomainDnsModalAlert" class="alert d-none" role="alert"></div>
                            <div id="cfmodSubdomainDnsModalLoading" class="text-center text-muted py-4 d-none">
                                <i class="fas fa-spinner fa-spin me-2"></i>正在读取本地已同步的解析记录...
                            </div>
                            <div id="cfmodSubdomainDnsModalSummary" class="small text-muted mb-2 d-none"></div>
                            <div id="cfmodSubdomainDnsModalRecords" class="row g-2"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">关闭</button>
                        </div>
                    </div>
                </div>
            </div>

            <form method="post" id="batchForm" style="display:none;">
                <input type="hidden" name="action" value="batch_delete">
            </form>

            <!-- 校准卡片 -->
            <div class="card mb-4">
              <div class="card-body">
                <div class="row mb-3">
                  <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                      <div class="small text-muted">最近一次校准</div>
                      <div class="mt-1">时间：<?php echo $lastCalTime; ?></div>
                      <div class="small text-muted mt-1">状态：<span class="badge bg-<?php echo ($lastCalStatus==='done'?'success':($lastCalStatus==='failed'?'danger':($lastCalStatus==='running'?'info':'secondary'))); ?>"><?php echo $lastCalStatus; ?></span></div>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                      <div class="small text-muted">最近一次差异摘要</div>
                      <div class="mt-1">
                        <?php if (!empty($calibSummary['byKind'])): ?>
                          <?php foreach ($calibSummary['byKind'] as $it): ?>
                            <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($it['k']); ?>：<?php echo intval($it['c']); ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="text-muted">暂无数据</span>
                        <?php endif; ?>
                      </div>
                      <div class="small text-muted mt-2">按动作：</div>
                      <div class="mt-1">
                        <?php if (!empty($calibSummary['byAction'])): ?>
                          <?php foreach ($calibSummary['byAction'] as $it): ?>
                            <span class="badge bg-light text-dark me-1"><?php echo htmlspecialchars($it['a']); ?>：<?php echo intval($it['c']); ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h5 class="card-title mb-0"><i class="fas fa-balance-scale"></i> 一键校准 / 迁移修复</h5>
                </div>
                <form method="post" class="row g-3">
                  <input type="hidden" name="action" value="enqueue_calibration">
                  <div class="col-md-3">
                    <label class="form-label">模式</label>
                    <select name="mode" class="form-select">
                      <option value="dry">干跑（仅比对显示差异）</option>
                      <option value="fix">自动修复（按差异执行）</option>
                    </select>
                  </div>
                  <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">提交校准作业</button>
                  </div>
                </form>
                <form method="post" class="mt-2">
                  <input type="hidden" name="action" value="run_migrations">
                  <button type="submit" class="btn btn-outline-secondary btn-sm">手动迁移/修复表</button>
                </form>
                <hr>
                <div class="row">
                  <div class="col-md-6">
                    <h6>最近作业</h6>
                    <div class="table-responsive">
                      <table class="table table-sm">
                        <thead><tr><th>ID</th><th>类型</th><th>状态</th><th>尝试</th><th>下次</th><th>时间</th></tr></thead>
                        <tbody>
                        <?php foreach($recentJobs as $j): ?>
                          <tr>
                            <td><?php echo $j->id; ?></td>
                            <td><?php echo htmlspecialchars($j->type); ?></td>
                            <td><?php echo htmlspecialchars($j->status); ?></td>
                            <td><?php echo intval($j->attempts); ?></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($j->next_run_at ?? ''); ?></small></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($j->created_at); ?></small></td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <div class="col-md-6" id="recent-diff">
                    <h6>最近差异</h6>
                    <div class="table-responsive">
                      <table class="table table-sm">
                        <thead><tr><th>ID</th><th>子域ID</th><th>类型</th><th>动作</th><th>详情</th></tr></thead>
                        <tbody>
                        <?php foreach($recentDiffs as $r): ?>
                          <tr>
                            <td><?php echo $r->id; ?></td>
                            <td><?php echo htmlspecialchars($r->subdomain_id ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($r->kind); ?></td>
                            <td><?php echo htmlspecialchars($r->action); ?></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($r->detail ?? ''); ?></small></td>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
<?php if ($diffTotalPages > 1): ?>
<?php
    $diffQueryParams = $_GET;
    $diffQueryParams['module'] = 'domain_hub';
    unset($diffQueryParams['diff_page']);
    $diffQueryString = http_build_query($diffQueryParams);
    if ($diffQueryString !== '') { $diffQueryString .= '&'; }
?>
<nav aria-label="最近差异分页" class="mt-2">
    <ul class="pagination pagination-sm justify-content-center">
        <?php if ($diffPage > 1): ?>
            <li class="page-item"><a class="page-link" href="?<?php echo $diffQueryString; ?>diff_page=<?php echo $diffPage-1; ?>#recent-diff">上一页</a></li>
        <?php endif; ?>
        <?php for($i=1;$i<=$diffTotalPages;$i++): ?>
            <?php if ($i == $diffPage): ?>
                <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
            <?php elseif ($i==1 || $i==$diffTotalPages || abs($i-$diffPage)<=2): ?>
                <li class="page-item"><a class="page-link" href="?<?php echo $diffQueryString; ?>diff_page=<?php echo $i; ?>#recent-diff"><?php echo $i; ?></a></li>
            <?php elseif (abs($i-$diffPage)==3): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($diffPage < $diffTotalPages): ?>
            <li class="page-item"><a class="page-link" href="?<?php echo $diffQueryString; ?>diff_page=<?php echo $diffPage+1; ?>#recent-diff">下一页</a></li>
        <?php endif; ?>
    </ul>
    <div class="text-center text-muted small">第 <?php echo $diffPage; ?> / <?php echo $diffTotalPages; ?> 页（共 <?php echo $diffTotal; ?> 条）</div>
</nav>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php include __DIR__ . '/admin/partials/risk_monitor.tpl'; ?>

<!-- 用户统计 -->
    <div class="row">
        <div class="col-12">
            <?php $userStatsShouldExpand = ((int) $ustPage > 1); ?>
            <div class="card mb-4" id="user-stats">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-users"></i> 用户操作统计</h5>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#user-stats-collapse" aria-expanded="<?php echo $userStatsShouldExpand ? 'true' : 'false'; ?>" aria-controls="user-stats-collapse">展开/收起</button>
                </div>
                <div class="collapse <?php echo $userStatsShouldExpand ? 'show' : ''; ?>" id="user-stats-collapse">
                <div class="card-body">
                    <div class="d-flex justify-content-end mb-2"></div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>用户ID</th>
                                    <th>二级域名创建</th>
                                    <th>DNS记录创建</th>
                                    <th>DNS记录更新</th>
                                    <th>DNS记录删除</th>
                                    <th>最后活动</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($user_stats as $stat): ?>
                                <tr>
                                    <td><?php echo $stat->userid; ?></td>
                                    <td><?php echo $stat->subdomains_created; ?></td>
                                    <td><?php echo $stat->dns_records_created; ?></td>
                                    <td><?php echo $stat->dns_records_updated; ?></td>
                                    <td><?php echo $stat->dns_records_deleted; ?></td>
                                    <td><?php echo $stat->last_activity ? date('Y-m-d H:i', strtotime($stat->last_activity)) : '从未'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php if ($ustTotalPages > 1): ?>
                        <nav aria-label="用户操作统计分页" class="mt-2">
                        <ul class="pagination pagination-sm justify-content-center">
                            <?php if ($ustPage > 1): ?>
                                <li class="page-item"><a class="page-link" href="?module=domain_hub&ust_page=<?php echo $ustPage-1; ?>#user-stats">上一页</a></li>
                            <?php endif; ?>
                            <?php for($i=1;$i<=$ustTotalPages;$i++): ?>
                                <?php if ($i == $ustPage): ?>
                                    <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
                                <?php elseif ($i==1 || $i==$ustTotalPages || abs($i-$ustPage)<=2): ?>
                                    <li class="page-item"><a class="page-link" href="?module=domain_hub&ust_page=<?php echo $i; ?>#user-stats"><?php echo $i; ?></a></li>
                                <?php elseif (abs($i-$ustPage)==3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($ustPage < $ustTotalPages): ?>
                                <li class="page-item"><a class="page-link" href="?module=domain_hub&ust_page=<?php echo $ustPage+1; ?>#user-stats">下一页</a></li>
                            <?php endif; ?>
                        </ul>
                        <div class="text-center text-muted small">第 <?php echo $ustPage; ?> / <?php echo $ustTotalPages; ?> 页（共 <?php echo $ustTotal; ?> 条）</div>
                        </nav>
                        <?php endif; ?>
                        </div>
                        </div>
                        </div>
                        </div>
                        </div>

<?php include __DIR__ . '/admin/partials/logs.tpl'; ?>

                        <!-- 健康检查 -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3"><i class="fas fa-heartbeat"></i> 健康检查</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">API 可用性</div>
                        <div class="mt-1"><span class="badge bg-<?php echo $apiHealthy?'success':'danger'; ?>"><?php echo $apiHealthy?'可用':'不可用'; ?></span></div>
                        <div class="small text-muted mt-1">延迟：<?php echo ($apiLatencyMs!==null?($apiLatencyMs.' ms'):'-'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">最近校准时间</div>
                        <div class="mt-1"><?php echo $lastCalTime; ?></div>
                        <div class="small text-muted mt-1">状态：<span class="badge bg-<?php echo ($lastCalStatus==='done'?'success':($lastCalStatus==='failed'?'danger':($lastCalStatus==='running'?'info':'secondary'))); ?>"><?php echo $lastCalStatus; ?></span></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">队列积压</div>
                        <div class="mt-1"><span class="badge bg-<?php echo ($backlogCount>0?'warning':'success'); ?>"><?php echo intval($backlogCount); ?></span></div>
                        <div class="small text-muted mt-1">pending + running</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">近期错误率（20作业）</div>
                        <div class="mt-1"><?php echo htmlspecialchars($jobErrorRate); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">远端补偿积压</div>
                        <div class="mt-1"><span class="badge bg-<?php echo ($remoteCompBacklog>0?'warning':'success'); ?>"><?php echo $remoteCompBacklog; ?></span></div>
                        <div class="small text-muted mt-1">cleanup_expired_subdomain_remote</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">远端补偿24h成功率</div>
                        <div class="mt-1"><?php echo htmlspecialchars((string) $remoteComp24hSuccessRate); ?></div>
                        <?php if (!empty($remoteCompFailTop)): ?>
                            <div class="small text-muted mt-1">失败Top:
                                <?php foreach ($remoteCompFailTop as $idx => $it): ?>
                                    <?php if ($idx > 0): ?> / <?php endif; ?>
                                    <?php echo htmlspecialchars(($it['error'] ?? 'unknown') . '(' . intval($it['count'] ?? 0) . ')'); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="border rounded p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="small text-muted">按任务类型（24h）可视化</div>
                            <span class="badge bg-light text-dark">Top <?php echo count($typePerformance24h); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>任务类型(24h)</th>
                                        <th>总数</th>
                                        <th>成功率</th>
                                        <th>平均耗时(秒)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($typePerformance24h)): ?>
                                    <?php foreach ($typePerformance24h as $perf): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars((string) ($perf['type'] ?? '')); ?></code></td>
                                            <td><?php echo intval($perf['total'] ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars(number_format((float) ($perf['success_rate'] ?? 0), 1)); ?>%</td>
                                            <td><?php echo htmlspecialchars(number_format((float) ($perf['avg_duration'] ?? 0), 1)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted">暂无24h任务类型数据</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">外部扫描 API</div>
                        <div class="mt-1"><small class="text-muted">Endpoint：</small><code><?php echo htmlspecialchars($riskEndpoint ?: '-'); ?></code></div>
                        <div class="mt-1"><small class="text-muted">解析IP：</small><code><?php echo htmlspecialchars($riskConn['ip'] ?: '-'); ?></code></div>
                        <div class="mt-1"><small class="text-muted">HTTP：</small><span class="badge bg-<?php echo ($riskConn['ok']?'success':'danger'); ?>"><?php echo ($riskConn['status']!==null?$riskConn['status']:'-'); ?></span></div>
                        <div class="small text-muted mt-1">延迟：<?php echo ($riskConn['latency_ms']!==null?($riskConn['latency_ms'].' ms'):'-'); ?></div>
                        <?php if (!empty($riskConn['error'])): ?><div class="small text-danger mt-1"><?php echo htmlspecialchars($riskConn['error']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$rootdomainsView = $cfAdminViewModel['rootdomains'] ?? [
    'hasActiveProviderAccounts' => $hasActiveProviderAccounts ?? false,
    'activeProviderAccounts' => $activeProviderAccounts ?? [],
    'defaultProviderSelectId' => $defaultProviderSelectId ?? 0,
    'rootdomains' => $rootdomains ?? [],
    'providerAccountMap' => $providerAccountMap ?? [],
    'forbiddenDomains' => $forbiddenDomains ?? [],
    'allKnownRootdomains' => $allKnownRootdomains ?? [],
];
?>

<?php
$apiView = $cfAdminViewModel['api'] ?? [];
$apiTablesExist = $apiView['tablesExist'] ?? false;
$apiSearch = $apiView['search'] ?? [];
$apiSearchKeyword = $apiSearch['keyword'] ?? '';
$apiSearchType = $apiSearch['type'] ?? 'all';
$apiPage = $apiSearch['page'] ?? 1;
$apiPerPage = $apiSearch['perPage'] ?? 20;
$apiPagination = $apiView['pagination'] ?? [];
$totalApiKeys = $apiPagination['totalKeys'] ?? 0;
$apiTotalPages = $apiPagination['totalPages'] ?? 0;
$apiStats = $apiView['stats'] ?? [];
$activeApiKeys = $apiStats['active'] ?? 0;
$totalApiRequests = $apiStats['totalRequests'] ?? 0;
$todayApiRequests = $apiStats['todayRequests'] ?? 0;
$allApiKeysArray = $apiView['keys'] ?? [];
$apiSectionShouldExpand = $apiView['expanded'] ?? false;
$apiLogs = $apiView['logs'] ?? [];
$recentLogsArray = $apiLogs['entries'] ?? [];
$recentLogsCount = is_array($recentLogsArray) ? count($recentLogsArray) : 0;
$apiLogsTotal = $apiLogs['total'] ?? 0;
$apiLogsTotalPages = $apiLogs['totalPages'] ?? 0;
$apiLogPage = $apiLogs['page'] ?? 1;
$apiLogPerPage = $apiLogs['perPage'] ?? 50;
?>
<?php include __DIR__ . '/admin/partials/api_management.tpl'; ?>

<?php
$cfAdminFooterConfig = [
    'csrfToken' => $cfmodAdminCsrfToken ?? '',
    'lang' => [
        'batchDeleteEmpty' => $lang['js_batch_delete_empty'] ?? '请选择要删除的记录',
        'batchDeleteConfirm' => $lang['js_batch_delete_confirm'] ?? '确定要删除选中的 %d 条记录吗？此操作不可恢复！',
        'batchExpirySelection' => $lang['js_batch_expiry_selection_missing'] ?? '请选择需要调整到期的子域名',
        'batchExpiryDateRequired' => $lang['js_batch_expiry_date_required'] ?? '请先填写新的到期时间',
        'batchExpiryExtendRequired' => $lang['js_batch_expiry_extend_required'] ?? '请填写延长天数（至少 1 天）',
        'numberRequired' => $lang['js_number_required'] ?? '请输入数字！',
        'numberInvalid' => $lang['js_number_invalid'] ?? '请输入有效的数字（只能包含0-9）！',
        'numberLeadingZero' => $lang['js_number_leading_zero'] ?? '数字不能以0开头！',
        'numberMin' => $lang['js_number_min'] ?? '数值不能小于 %d！',
        'numberMax' => $lang['js_number_max'] ?? '数值不能超过 %d！',
        'inviteErrorPrefix' => $lang['js_error_invite_prefix'] ?? '邀请上限错误：',
        'quotaErrorPrefix' => $lang['js_error_quota_prefix'] ?? '基础配额错误：',
        'quotaUpdateErrorPrefix' => $lang['js_error_quota_update_prefix'] ?? '配额数量错误：',
        'rootErrorPrefix' => $lang['js_error_root_prefix'] ?? '最大数量错误：',
    ],
    'announcement' => [
        'enabled' => (($cfAdminViewModel['announcements']['enabled'] ?? '0') === '1'),
    ],
    'api' => [
        'quotaEndpoint' => '?module=domain_hub&action=get_user_quota&userid=',
        'heavyStatsEndpoint' => '?module=domain_hub&action=get_admin_heavy_stats',
        'subdomainDnsEndpoint' => '?module=domain_hub&action=get_subdomain_dns_records&subdomain_id=',
    ],
];
?>
<?php include __DIR__ . '/admin/partials/admin_footer.tpl'; ?>
