<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$LANG = $LANG ?? [];
$lang = $LANG['domainHub'] ?? [];
$rootdomainInviteLogs = $cfAdminViewModel['rootdomainInviteLogs'] ?? [];
$items = $rootdomainInviteLogs['items'] ?? [];
$search = $rootdomainInviteLogs['search'] ?? '';
$searchType = $rootdomainInviteLogs['searchType'] ?? 'all';
$pagination = $rootdomainInviteLogs['pagination'] ?? [];
$page = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$total = max(0, (int) ($pagination['total'] ?? 0));
$perPage = max(1, (int) ($pagination['perPage'] ?? 20));

$title = $lang['rootdomain_invite_logs_title'] ?? '根域名邀请注册日志';
$searchPlaceholder = $lang['rootdomain_invite_logs_search_placeholder'] ?? '搜索';
$searchButton = $lang['rootdomain_invite_logs_search_button'] ?? '搜索';
$resetButton = $lang['rootdomain_invite_logs_reset_button'] ?? '重置';
$rootdomainLabel = $lang['rootdomain_invite_logs_rootdomain'] ?? '根域名';
$codeLabel = $lang['rootdomain_invite_logs_code'] ?? '邀请码';
$inviterLabel = $lang['rootdomain_invite_logs_inviter'] ?? '邀请人';
$inviteeLabel = $lang['rootdomain_invite_logs_invitee'] ?? '被邀请人';
$inviteeEmailLabel = $lang['rootdomain_invite_logs_invitee_email'] ?? '邮箱';
$subdomainLabel = $lang['rootdomain_invite_logs_subdomain'] ?? '注册域名';
$inviteeIpLabel = $lang['rootdomain_invite_logs_invitee_ip'] ?? 'IP';
$timeLabel = $lang['rootdomain_invite_logs_time'] ?? '注册时间';
$emptyLabel = $lang['rootdomain_invite_logs_empty'] ?? '暂无邀请注册记录';
$descriptionNote = $lang['rootdomain_invite_logs_description'] ?? '此日志记录用户使用邀请码注册根域名二级域名的情况。';

$queryArgs = $_GET;
$queryArgs['module'] = 'domain_hub';
unset($queryArgs['rootdomain_invite_page']);
$buildUrl = function (array $params) {
    $query = http_build_query($params);
    return '?' . $query;
};
$resetArgs = $queryArgs;
unset($resetArgs['rootdomain_invite_search']);
unset($resetArgs['rootdomain_invite_search_type']);
$resetUrl = $buildUrl($resetArgs) . '#rootdomain-invite-logs';
?>
<div class="card mb-4" id="rootdomain-invite-logs">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="card-title mb-0"><i class="fas fa-list"></i> <?php echo htmlspecialchars($title); ?></h5>
    </div>
    <div class="alert alert-info small mb-3">
      <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($descriptionNote); ?>
    </div>
    <form method="get" class="row g-2 align-items-center mb-3">
      <input type="hidden" name="module" value="domain_hub">
      <?php
      foreach ($queryArgs as $key => $val) {
          if (in_array($key, ['module', 'rootdomain_invite_search', 'rootdomain_invite_search_type'], true)) { continue; }
          echo '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES) . '" value="' . htmlspecialchars(is_array($val) ? '' : (string) $val, ENT_QUOTES) . '">';
      }
      ?>
      <div class="col-sm-3 col-md-3">
        <select name="rootdomain_invite_search_type" class="form-select">
          <option value="all" <?php echo $searchType === 'all' ? 'selected' : ''; ?>>全部</option>
          <option value="rootdomain" <?php echo $searchType === 'rootdomain' ? 'selected' : ''; ?>>根域名</option>
          <option value="code" <?php echo $searchType === 'code' ? 'selected' : ''; ?>>邀请码</option>
          <option value="email" <?php echo $searchType === 'email' ? 'selected' : ''; ?>>邮箱</option>
        </select>
      </div>
      <div class="col-sm-5 col-md-4">
        <input type="text" name="rootdomain_invite_search" class="form-control" placeholder="<?php echo htmlspecialchars($searchPlaceholder); ?>" value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($searchButton); ?></button>
      </div>
      <div class="col-auto">
        <a href="<?php echo htmlspecialchars($resetUrl); ?>" class="btn btn-outline-secondary"><?php echo htmlspecialchars($resetButton); ?></a>
      </div>
    </form>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th><?php echo htmlspecialchars($rootdomainLabel); ?></th>
            <th><?php echo htmlspecialchars($codeLabel); ?></th>
            <th><?php echo htmlspecialchars($inviterLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeLabel); ?></th>
            <th><?php echo htmlspecialchars($subdomainLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeIpLabel); ?></th>
            <th><?php echo htmlspecialchars($timeLabel); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><code><?php echo htmlspecialchars($item['rootdomain'] ?? ''); ?></code></td>
                <td><code><?php echo htmlspecialchars($item['invite_code'] ?? ''); ?></code></td>
                <td>
                  <?php if (!empty($item['inviter_userid'])): ?>
                    <span class="text-muted">#<?php echo intval($item['inviter_userid']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($item['inviter_email'])): ?>
                    <div><?php echo htmlspecialchars($item['inviter_email']); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($item['invitee_userid'])): ?>
                    <span class="text-muted">#<?php echo intval($item['invitee_userid']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($item['invitee_email'])): ?>
                    <div><?php echo htmlspecialchars($item['invitee_email']); ?></div>
                  <?php endif; ?>
                </td>
                <td><small><?php echo htmlspecialchars($item['subdomain'] ?? '-'); ?></small></td>
                <td><?php echo htmlspecialchars($item['invitee_ip'] ?? '-'); ?></td>
                <td><small class="text-muted"><?php echo htmlspecialchars($item['created_at'] ?? '-'); ?></small></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-4"><?php echo htmlspecialchars($emptyLabel); ?></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <?php
      $prevPage = max(1, $page - 1);
      $nextPage = min($totalPages, $page + 1);
      $baseArgs = $queryArgs;
      if ($search !== '') {
          $baseArgs['rootdomain_invite_search'] = $search;
      }
      if ($searchType !== 'all') {
          $baseArgs['rootdomain_invite_search_type'] = $searchType;
      }
      ?>
      <nav aria-label="Rootdomain Invite Logs Pagination" class="mt-3">
        <ul class="pagination pagination-sm">
          <?php $baseArgs['rootdomain_invite_page'] = $prevPage; ?>
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#rootdomain-invite-logs'); ?>">&laquo;</a>
          </li>
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($page === $i): ?>
              <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
            <?php elseif ($i === 1 || $i === $totalPages || abs($i - $page) <= 2): ?>
              <?php $baseArgs['rootdomain_invite_page'] = $i; ?>
              <li class="page-item">
                <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#rootdomain-invite-logs'); ?>"><?php echo $i; ?></a>
              </li>
            <?php elseif (abs($i - $page) === 3): ?>
              <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
          <?php endfor; ?>
          <?php $baseArgs['rootdomain_invite_page'] = $nextPage; ?>
          <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#rootdomain-invite-logs'); ?>">&raquo;</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>
