<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$LANG = $LANG ?? [];
$lang = $LANG['domainHub'] ?? [];
$inviteRegLogsView = $cfAdminViewModel['inviteRegistrationLogs'] ?? [];
$inviteRegItems = $inviteRegLogsView['items'] ?? [];
$inviteRegSearch = $inviteRegLogsView['search'] ?? '';
$pagination = $inviteRegLogsView['pagination'] ?? [];
$inviteRegPage = max(1, (int) ($pagination['page'] ?? 1));
$inviteRegTotalPages = max(1, (int) ($pagination['totalPages'] ?? 1));
$inviteRegTotal = max(0, (int) ($pagination['total'] ?? 0));
$perPage = max(1, (int) ($pagination['perPage'] ?? 20));

$title = $lang['invite_reg_logs_title'] ?? '邀请注册日志';
$searchPlaceholder = $lang['invite_reg_logs_search_placeholder'] ?? '用户ID/邮箱/GitHub/Telegram/邀请码';
$searchButton = $lang['invite_reg_logs_search_button'] ?? '搜索';
$resetButton = $lang['invite_reg_logs_reset_button'] ?? '重置';
$migrateButton = $lang['invite_reg_migrate_button'] ?? '为老用户自动解锁';
$migrateConfirm = $lang['invite_reg_migrate_confirm'] ?? '确定要为所有老用户自动解锁邀请注册限制吗？此操作将检测已有域名、配额等记录的用户并自动为其解锁。';
$unlockAllButton = $lang['invite_reg_unlock_all_button'] ?? '一键全量解锁';
$unlockAllConfirm = $lang['invite_reg_unlock_all_confirm'] ?? '确定提交“全量解锁”任务吗？将为所有 WHMCS 注册用户批量解锁邀请注册。';
$codeLabel = $lang['invite_reg_logs_code'] ?? '邀请码';
$inviterLabel = $lang['invite_reg_logs_inviter'] ?? '邀请人';
$inviteeLabel = $lang['invite_reg_logs_invitee'] ?? '被邀请人';
$inviteeEmailLabel = $lang['invite_reg_logs_invitee_email'] ?? '被邀请人邮箱';
$inviteeIpLabel = $lang['invite_reg_logs_invitee_ip'] ?? '注册 IP';
$verifySourceLabel = $lang['invite_reg_logs_verify_source'] ?? '准入验证来源';
$timeLabel = $lang['invite_reg_logs_time'] ?? '注册时间';
$emptyLabel = $lang['invite_reg_logs_empty'] ?? '暂无邀请注册记录';
$autoUnlockedNote = $lang['invite_reg_auto_unlocked_note'] ?? '此功能启用后，系统会自动检测老用户（已注册域名/配额记录等）并自动解锁，无需输入邀请码。';

$queryArgs = $_GET;
$queryArgs['module'] = 'domain_hub';
unset($queryArgs['invite_reg_page']);
$buildUrl = function (array $params) {
    $query = http_build_query($params);
    return '?' . $query;
};
$resetArgs = $queryArgs;
unset($resetArgs['invite_reg_search']);
$resetUrl = $buildUrl($resetArgs) . '#invite-reg-logs';
?>
<div class="card mb-4" id="invite-reg-logs">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="card-title mb-0"><i class="fas fa-user-plus"></i> <?php echo htmlspecialchars($title); ?></h5>
      <div class="d-flex flex-wrap gap-2">
        <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($migrateConfirm, ENT_QUOTES); ?>');">
          <?php if (function_exists('cfmod_csrf_hidden_field')) { echo cfmod_csrf_hidden_field(); } ?>
          <input type="hidden" name="action" value="migrate_invite_registration_existing_users">
          <button type="submit" class="btn btn-sm btn-outline-success" title="<?php echo htmlspecialchars($autoUnlockedNote, ENT_QUOTES); ?>">
            <i class="fas fa-magic"></i> <?php echo htmlspecialchars($migrateButton); ?>
          </button>
        </form>
        <form method="post" class="d-inline" onsubmit="return confirm('<?php echo htmlspecialchars($unlockAllConfirm, ENT_QUOTES); ?>');">
          <?php if (function_exists('cfmod_csrf_hidden_field')) { echo cfmod_csrf_hidden_field(); } ?>
          <input type="hidden" name="action" value="enqueue_unlock_all_invite_registration_users">
          <input type="hidden" name="unlock_all_batch_size" value="500">
          <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-unlock-alt"></i> <?php echo htmlspecialchars($unlockAllButton); ?>
          </button>
        </form>
      </div>
    </div>
    <div class="alert alert-info small mb-3">
      <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($autoUnlockedNote); ?>
    </div>
    <form method="get" class="row g-2 align-items-center mb-3">
      <input type="hidden" name="module" value="domain_hub">
      <?php
      foreach ($queryArgs as $key => $val) {
          if (in_array($key, ['module', 'invite_reg_search'], true)) { continue; }
          echo '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES) . '" value="' . htmlspecialchars(is_array($val) ? '' : (string) $val, ENT_QUOTES) . '">';
      }
      ?>
      <div class="col-sm-6 col-md-4">
        <input type="text" name="invite_reg_search" class="form-control" placeholder="<?php echo htmlspecialchars($searchPlaceholder); ?>" value="<?php echo htmlspecialchars($inviteRegSearch); ?>">
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
            <th><?php echo htmlspecialchars($codeLabel); ?></th>
            <th><?php echo htmlspecialchars($inviterLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeEmailLabel); ?></th>
            <th><?php echo htmlspecialchars($inviteeIpLabel); ?></th>
            <th>GitHub / Telegram 绑定</th>
            <th><?php echo htmlspecialchars($verifySourceLabel); ?></th>
            <th><?php echo htmlspecialchars($timeLabel); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($inviteRegItems)): ?>
            <?php foreach ($inviteRegItems as $item): ?>
              <tr>
                <td><code><?php echo htmlspecialchars($item['invite_code'] ?? ''); ?></code></td>
                <td>
                  <?php if (!empty($item['inviter_userid'])): ?>
                    <span class="text-muted">#<?php echo intval($item['inviter_userid']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($item['inviter_email'])): ?>
                    <div><?php echo htmlspecialchars($item['inviter_email']); ?></div>
                  <?php elseif (intval($item['inviter_userid'] ?? 0) === 0): ?>
                    <span class="badge bg-secondary">管理员</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($item['invitee_userid'])): ?>
                    <span class="text-muted">#<?php echo intval($item['invitee_userid']); ?></span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($item['invitee_email'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($item['invitee_ip'] ?? '-'); ?></td>
                <td>
                  <?php $ghLogin = trim((string) ($item['invitee_github_login'] ?? '')); ?>
                  <?php $ghStarLogin = trim((string) ($item['invitee_github_star_login'] ?? '')); ?>
                  <?php $tgUsername = trim((string) ($item['invitee_telegram_username'] ?? '')); ?>
                  <?php $tgUid = intval($item['invitee_telegram_user_id'] ?? 0); ?>
                  <?php if ($ghLogin !== ''): ?><div><span class="badge bg-dark">Invite GH</span> @<?php echo htmlspecialchars($ghLogin); ?></div><?php endif; ?>
                  <?php if ($ghStarLogin !== ''): ?><div><span class="badge bg-secondary">Star GH</span> @<?php echo htmlspecialchars($ghStarLogin); ?></div><?php endif; ?>
                  <?php if ($tgUsername !== '' || $tgUid > 0): ?><div><span class="badge bg-info text-dark">Telegram</span> <?php echo $tgUsername !== '' ? ('@' . htmlspecialchars($tgUsername)) : ''; ?><?php echo $tgUid > 0 ? (' #' . $tgUid) : ''; ?></div><?php endif; ?>
                  <?php if ($ghLogin === '' && $ghStarLogin === '' && $tgUsername === '' && $tgUid <= 0): ?><span class="text-muted">-</span><?php endif; ?>
                </td>
                <td>
                  <?php $source = strtolower(trim((string) ($item['verify_source'] ?? ''))); ?>
                  <?php if ($source === '') { $source = '-'; } ?>
                  <div><?php echo htmlspecialchars($source); ?></div>
                  <?php if ((int) ($item['is_group_member'] ?? 0) > 0): ?>
                    <small class="text-success">group_member</small>
                  <?php endif; ?>
                </td>
                <td><small class="text-muted"><?php echo htmlspecialchars($item['created_at'] ?? '-'); ?></small></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4"><?php echo htmlspecialchars($emptyLabel); ?></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($inviteRegTotalPages > 1): ?>
      <?php
      $prevPage = max(1, $inviteRegPage - 1);
      $nextPage = min($inviteRegTotalPages, $inviteRegPage + 1);
      $baseArgs = $queryArgs;
      ?>
      <nav aria-label="Invite Registration Logs Pagination" class="mt-3">
        <ul class="pagination pagination-sm">
          <?php $baseArgs['invite_reg_page'] = $prevPage; ?>
          <li class="page-item <?php echo $inviteRegPage <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#invite-reg-logs'); ?>">&laquo;</a>
          </li>
          <?php for ($i = 1; $i <= $inviteRegTotalPages; $i++): ?>
            <?php if ($inviteRegPage === $i): ?>
              <li class="page-item active"><span class="page-link"><?php echo $i; ?></span></li>
            <?php elseif ($i === 1 || $i === $inviteRegTotalPages || abs($i - $inviteRegPage) <= 2): ?>
              <?php $baseArgs['invite_reg_page'] = $i; ?>
              <li class="page-item">
                <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#invite-reg-logs'); ?>"><?php echo $i; ?></a>
              </li>
            <?php elseif (abs($i - $inviteRegPage) === 3): ?>
              <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
          <?php endfor; ?>
          <?php $baseArgs['invite_reg_page'] = $nextPage; ?>
          <li class="page-item <?php echo $inviteRegPage >= $inviteRegTotalPages ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($baseArgs) . '#invite-reg-logs'); ?>">&raquo;</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>
