<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$logsView = $cfAdminViewModel['domainPermanentUpgradeAssistLogs'] ?? [];
$items = $logsView['items'] ?? [];
$search = $logsView['search'] ?? '';
$pagination = $logsView['pagination'] ?? [];
$page = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));

$queryArgs = $_GET;
$queryArgs['module'] = 'domain_hub';
unset($queryArgs['perm_upgrade_log_page']);
$buildUrl = function (array $params): string {
    $query = http_build_query($params);
    return '?' . $query;
};
$resetArgs = $queryArgs;
unset($resetArgs['perm_upgrade_log_search']);
?>
<div class="card mb-4" id="perm-upgrade-assist-logs">
  <div class="card-body">
    <h5 class="card-title mb-3"><i class="fas fa-infinity"></i> 永久升级助力日志</h5>
    <form method="get" class="row g-2 align-items-center mb-3">
      <input type="hidden" name="module" value="domain_hub">
      <?php foreach ($queryArgs as $key => $val): ?>
        <?php if (in_array($key, ['module', 'perm_upgrade_log_search'], true)) { continue; } ?>
        <input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>" value="<?php echo htmlspecialchars(is_array($val) ? '' : (string) $val, ENT_QUOTES); ?>">
      <?php endforeach; ?>
      <div class="col-sm-6 col-md-4">
        <input type="text" name="perm_upgrade_log_search" class="form-control" placeholder="助力码 / 邮箱 / 域名 / UID" value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <div class="col-auto"><button type="submit" class="btn btn-primary">搜索</button></div>
      <div class="col-auto"><a href="<?php echo htmlspecialchars($buildUrl($resetArgs) . '#perm-upgrade-assist-logs'); ?>" class="btn btn-outline-secondary">重置</a></div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th>时间</th>
            <th>域名</th>
            <th>助力码</th>
            <th>发起人</th>
            <th>助力人</th>
            <th>助力IP</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><small class="text-muted"><?php echo htmlspecialchars((string) ($item['created_at'] ?? '-')); ?></small></td>
                <td><?php echo htmlspecialchars((string) ($item['domain'] ?? '-')); ?></td>
                <td><code><?php echo htmlspecialchars((string) ($item['assist_code'] ?? '-')); ?></code></td>
                <td>
                  <div class="small text-muted">UID: <?php echo intval($item['owner_userid'] ?? 0); ?></div>
                  <div><?php echo htmlspecialchars((string) ($item['owner_email'] ?? '-')); ?></div>
                </td>
                <td>
                  <div class="small text-muted">UID: <?php echo intval($item['helper_userid'] ?? 0); ?></div>
                  <div><?php echo htmlspecialchars((string) (($item['helper_email'] ?? '') !== '' ? $item['helper_email'] : ($item['helper_client_email'] ?? '-'))); ?></div>
                </td>
                <td><?php echo htmlspecialchars((string) ($item['helper_ip'] ?? '-')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" class="text-center text-muted py-3">暂无助力日志</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="mt-3" aria-label="永久升级助力日志分页">
        <ul class="pagination pagination-sm mb-0">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
              <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
            <?php elseif ($i === 1 || $i === $totalPages || abs($i - $page) <= 2): ?>
              <?php $pageArgs = $queryArgs; $pageArgs['perm_upgrade_log_page'] = $i; ?>
              <li class="page-item">
                <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($pageArgs) . '#perm-upgrade-assist-logs'); ?>"><?php echo $i; ?></a>
              </li>
            <?php elseif (abs($i - $page) === 3): ?>
              <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>
