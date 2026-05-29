<?php
$ulc = $cfAdminViewModel['unifiedLogCenter'] ?? [];
$items = $ulc['items'] ?? [];
$search = $ulc['search'] ?? '';
$source = $ulc['source'] ?? 'all';
$pg = $ulc['pagination'] ?? ['page'=>1,'totalPages'=>1,'total'=>0];
$page = max(1,(int)($pg['page']??1));
$totalPages = max(1,(int)($pg['totalPages']??1));
?>
<div class="card mb-4" id="unified-log-center">
  <div class="card-body">
    <h5 class="card-title mb-3"><i class="fas fa-stream"></i> 统一日志中心</h5>
    <form method="get" class="row g-2 mb-3">
      <input type="hidden" name="module" value="domain_hub">
      <div class="col-auto"><input type="text" class="form-control" name="ulc_q" value="<?php echo htmlspecialchars($search,ENT_QUOTES); ?>" placeholder="关键词/用户ID/邮箱"></div>
      <div class="col-auto"><select class="form-select" name="ulc_source">
        <option value="all" <?php echo $source==='all'?'selected':''; ?>>全部来源</option>
        <option value="ops" <?php echo $source==='ops'?'selected':''; ?>>操作日志</option>
        <option value="invite" <?php echo $source==='invite'?'selected':''; ?>>邀请注册</option>
        <option value="dns_unlock" <?php echo $source==='dns_unlock'?'selected':''; ?>>DNS解锁</option>
      </select></div>
      <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">检索</button></div>
    </form>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>时间</th><th>来源</th><th>用户ID</th><th>动作</th><th>详情</th></tr></thead><tbody>
    <?php if ($items): foreach($items as $it): ?>
      <tr><td><?php echo htmlspecialchars((string)($it['time']??'')); ?></td><td><?php echo htmlspecialchars((string)($it['source']??'')); ?></td><td><?php echo (int)($it['user']??0); ?></td><td><code><?php echo htmlspecialchars((string)($it['action']??'')); ?></code></td><td><small><?php echo htmlspecialchars((string)($it['details']??'')); ?></small></td></tr>
    <?php endforeach; else: ?>
      <tr><td colspan="5" class="text-center text-muted">暂无日志</td></tr>
    <?php endif; ?>
    </tbody></table></div>
  </div>
</div>
