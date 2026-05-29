<?php
$activityState = isset($clientActivityLogs) && is_array($clientActivityLogs) ? $clientActivityLogs : [];
$activityItems = isset($activityState['items']) && is_array($activityState['items']) ? $activityState['items'] : [];
$activityPagination = isset($activityState['pagination']) && is_array($activityState['pagination']) ? $activityState['pagination'] : [];
$activityPage = max(1, (int) ($activityPagination['page'] ?? 1));
$activityTotalPages = max(1, (int) ($activityPagination['totalPages'] ?? 1));
$activityWindowDays = max(1, (int) ($activityState['windowDays'] ?? 7));
$isZh = strtolower((string)($currentLanguage ?? 'chinese')) !== 'english';
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h6 class="mb-3"><i class="fas fa-history text-primary me-2"></i><?php echo cfclient_lang('cfclient.activity.title', $isZh ? '最近操作记录' : 'Recent Activity Logs', [], true); ?></h6>
        <div class="text-muted small mb-3"><?php echo cfclient_lang('cfclient.activity.window_hint', $isZh ? '仅展示最近 %d 天内的账户注册域名、解析等部分操作记录。' : 'Only selected actions from the last %d days are shown, such as domain registration and DNS operations.', [$activityWindowDays], true); ?></div>

        <?php if (empty($activityItems)): ?>
            <div class="alert alert-light border mb-0"><?php echo cfclient_lang('cfclient.activity.empty', '暂无操作记录。', [], true); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?php echo cfclient_lang('cfclient.activity.col_time', $isZh ? '时间' : 'Time', [], true); ?></th>
                            <th><?php echo cfclient_lang('cfclient.activity.col_action', $isZh ? '动作' : 'Action', [], true); ?></th>
                            <th><?php echo cfclient_lang('cfclient.activity.col_detail', $isZh ? '详情' : 'Details', [], true); ?></th>
                            <th><?php echo cfclient_lang('cfclient.activity.col_ip', 'IP', [], true); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activityItems as $item): ?>
                            <tr>
                                <td class="text-nowrap"><?php echo htmlspecialchars((string) ($item['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($item['action_label'] ?? ($item['action'] ?? '')), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string) ($item['details'] ?? ''), ENT_QUOTES); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars((string) ($item['ip'] ?? '-'), ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($activityTotalPages > 1): ?>
            <nav aria-label="activity logs pagination" class="mt-3">
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $activityTotalPages; $p++): ?>
                        <?php
                        $pageQuery = $cfClientBaseEntryQuery;
                        $pageQuery['view'] = 'activity_logs';
                        $pageQuery['activity_page'] = $p;
                        $pageUrl = $cfClientEntryScript . '?' . http_build_query($pageQuery);
                        ?>
                        <li class="page-item <?php echo $p === $activityPage ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>
