<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$statsView = $cfAdminViewModel['stats'] ?? [];
$totalSubdomains = (int) ($statsView['totalSubdomains'] ?? 0);
$activeSubdomains = (int) ($statsView['activeSubdomains'] ?? 0);
$registeredUsers = (int) ($statsView['registeredUsers'] ?? 0);
$subdomainsCreated = (int) ($statsView['subdomainsCreated'] ?? 0);
$dnsOperations = (int) ($statsView['dnsOperations'] ?? 0);
$generatedAt = (int) ($statsView['heavyStatsGeneratedAt'] ?? 0);
$statsStale = !empty($statsView['heavyStatsStale']);
$statsPending = !empty($statsView['heavyStatsPending']);
$statusText = '缓存就绪';
if ($statsPending) {
    $statusText = '统计数据后台刷新中…';
} elseif ($statsStale) {
    $statusText = '缓存已过期，等待后台刷新';
}
$generatedText = $generatedAt > 0 ? date('Y-m-d H:i:s', $generatedAt) : '-';
?>

<div class="row mb-4" id="cfmod-stats-cards">
    <div class="col-md-2">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h5 class="card-title">总子域名</h5>
                <h2 id="cfmod-stat-total-subdomains"><?php echo $totalSubdomains; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5 class="card-title">活跃子域名</h5>
                <h2 id="cfmod-stat-active-subdomains"><?php echo $activeSubdomains; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">注册用户</h5>
                <h2 id="cfmod-stat-registered-users"><?php echo $registeredUsers; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-secondary text-white">
            <div class="card-body">
                <h5 class="card-title">用户创建</h5>
                <h2 id="cfmod-stat-subdomains-created"><?php echo $subdomainsCreated; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h5 class="card-title">DNS 操作</h5>
                <h2 id="cfmod-stat-dns-operations"><?php echo $dnsOperations; ?></h2>
            </div>
        </div>
    </div>
</div>
<div class="mb-3 text-muted small" id="cfmod-heavy-stats-status"
     data-generated-at="<?php echo (int) $generatedAt; ?>"
     data-pending="<?php echo $statsPending ? '1' : '0'; ?>"
     data-stale="<?php echo $statsStale ? '1' : '0'; ?>">
    <span id="cfmod-heavy-stats-status-text"><?php echo htmlspecialchars($statusText); ?></span>（缓存时间：<span id="cfmod-heavy-stats-generated-at"><?php echo htmlspecialchars($generatedText); ?></span>）
</div>
