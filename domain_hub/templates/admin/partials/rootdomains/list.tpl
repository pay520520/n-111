<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$rootdomainsView = $cfAdminViewModel['rootdomains'] ?? [];
$hasActiveProviderAccounts = $rootdomainsView['hasActiveProviderAccounts'] ?? false;
$activeProviderAccounts = $rootdomainsView['activeProviderAccounts'] ?? [];
$defaultProviderSelectId = $rootdomainsView['defaultProviderSelectId'] ?? 0;
$rootdomains = $rootdomainsView['rootdomains'] ?? [];
$providerAccountMap = $rootdomainsView['providerAccountMap'] ?? [];
$forbiddenDomains = $rootdomainsView['forbiddenDomains'] ?? [];
$allKnownRootdomains = $rootdomainsView['allKnownRootdomains'] ?? [];
$pdnsLocalExportCursorStates = $rootdomainsView['pdnsLocalExportCursorStates'] ?? [];
$orderHeader = $lang['rootdomain_order_header'] ?? '排序';
$orderHint = $lang['rootdomain_order_hint'] ?? '数值越小越靠前';
$orderSaveLabel = $lang['rootdomain_order_save'] ?? '保存排序';
$cfmodAdminCsrfTokenLocal = (string) ($_SESSION['cfmod_admin_csrf'] ?? '');
?>

<!-- 根域名白名单管理 -->
<div class="card mb-4" id="rootdomainWhitelist">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0"><i class="fas fa-sitemap"></i> 根域名白名单</h5>
        </div>
        <div class="alert alert-info small">
            <i class="fas fa-lightbulb me-1"></i> 所有可注册根域名必须在此处维护，旧版 <code>root_domains</code> 配置已自动迁移并不再生效。
        </div>
        <?php if (!$hasActiveProviderAccounts): ?>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle me-1"></i> 请先在上方 “DNS 供应商账户” 中配置并启用至少一个账号后再添加根域。
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3 mb-3" onsubmit="return validateRootDomain(this)">
            <input type="hidden" name="action" value="add_rootdomain">
            <div class="col-md-3">
                <input type="text" name="domain" class="form-control" placeholder="example.com" required>
            </div>
            <div class="col-md-2">
                <input type="number" name="max_subdomains" class="form-control" placeholder="最大数量" value="1000" min="1" step="1">
                <small class="text-muted">最大99999999999</small>
            </div>
            <div class="col-md-2">
                <input type="number" name="default_term_years" class="form-control" placeholder="默认年限" value="0" min="0" step="1">
                <small class="text-muted">0 表示使用全局</small>
            </div>
            <div class="col-md-3">
                <select name="provider_account_id" class="form-select" <?php echo $hasActiveProviderAccounts ? '' : 'disabled'; ?> required>
                    <?php if ($hasActiveProviderAccounts): ?>
                        <?php foreach ($activeProviderAccounts as $provider): ?>
                            <option value="<?php echo intval($provider->id); ?>" <?php echo intval($provider->id) === $defaultProviderSelectId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($provider->name ?? ('ID ' . $provider->id)); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">暂无可用供应商</option>
                    <?php endif; ?>
                </select>
                <small class="text-muted">DNS 供应商</small>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100" <?php echo $hasActiveProviderAccounts ? '' : 'disabled'; ?>>添加根域名</button>
            </div>
            <div class="col-md-12">
                <input type="text" name="description" class="form-control" placeholder="描述（可选）">
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="disable_ns_management" value="1" id="addRootDisableNs">
                    <label class="form-check-label" for="addRootDisableNs">
                        禁止该根域名设置 DNS 服务器（NS 管理）
                    </label>
                </div>
            </div>
            <div class="col-md-12 text-muted mt-2">
                将自动尝试匹配阿里云解析域名；也可先添加，后续在阿里云中绑定。
            </div>
        </form>
        
        <form method="post" id="rootdomain-order-form" class="mb-3">
            <input type="hidden" name="action" value="update_rootdomain_order">
            <input type="hidden" name="cfmod_admin_csrf" value="<?php echo htmlspecialchars($cfmodAdminCsrfTokenLocal, ENT_QUOTES); ?>">
            <div class="d-flex align-items-center gap-2 mb-2">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?php echo htmlspecialchars($orderSaveLabel); ?></button>
                <small class="text-muted"><?php echo htmlspecialchars($orderHint); ?></small>
            </div>
            <div class="row g-2">
                <?php foreach($rootdomains as $rdOrder): ?>
                    <div class="col-sm-6 col-md-4 col-lg-3">
                        <label class="form-label mb-1" for="display_order_<?php echo intval($rdOrder->id); ?>">
                            <code><?php echo htmlspecialchars($rdOrder->domain ?? ''); ?></code>
                        </label>
                        <input
                            type="number"
                            class="form-control form-control-sm"
                            id="display_order_<?php echo intval($rdOrder->id); ?>"
                            name="display_order[<?php echo intval($rdOrder->id); ?>]"
                            value="<?php echo intval($rdOrder->display_order ?? 0); ?>"
                            min="-2147483648"
                            max="2147483647"
                            step="1"
                        >
                    </div>
                <?php endforeach; ?>
            </div>
        </form>
        
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>根域名</th>
                        <th><?php echo htmlspecialchars($orderHeader); ?></th>
                        <th>Zone ID</th>
                        <th>DNS 供应商</th>
                        <th>最大数量</th>
                        <th>单用户上限</th>
                        <th>默认年限</th>
                        <th>描述</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($rootdomains as $rd): ?>
                    <?php
                        $rdProviderId = intval($rd->provider_account_id ?? 0);
                        $rdProvider = $rdProviderId > 0 && isset($providerAccountMap[$rdProviderId]) ? $providerAccountMap[$rdProviderId] : null;
                        $rdProviderStatus = $rdProvider ? strtolower($rdProvider->status ?? '') : '';
                        $rdProviderLabel = $rdProvider ? ($rdProvider->name ?: ('ID ' . $rdProvider->id)) : null;
                        $rdMaintenance = intval($rd->maintenance ?? 0) === 1;
                        $rdNsDisabled = intval($rd->disable_ns_management ?? 0) === 1;
                        $rdDefaultTerm = intval($rd->default_term_years ?? 0);
                    ?>
                    <tr>
                        <td><?php echo $rd->id; ?></td>
                        <td><code><?php echo htmlspecialchars($rd->domain); ?></code></td>
                        <td style="width:110px;">
                            <span class="badge bg-light text-dark"><?php echo intval($rd->display_order ?? 0); ?></span>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($rd->cloudflare_zone_id ?? ''); ?></small></td>
                        <td>
                            <?php if ($rdProvider): ?>
                                <strong><?php echo htmlspecialchars($rdProviderLabel); ?></strong>
                                <div class="small text-muted">ID <?php echo intval($rdProvider->id); ?></div>
                                <?php if ($rdProviderStatus !== 'active'): ?>
                                    <span class="badge bg-warning text-dark">已停用</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">未绑定</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($rd->max_subdomains ?? 1000); ?></td>
                        <td><?php echo (intval($rd->per_user_limit ?? 0) > 0) ? intval($rd->per_user_limit) : '不限'; ?></td>
                        <td><?php echo $rdDefaultTerm > 0 ? ($rdDefaultTerm . ' 年') : '沿用全局'; ?></td>
                        <td><?php echo htmlspecialchars($rd->description ?? ''); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $rd->status==='active'?'success':'secondary'; ?>"><?php echo $rd->status==='active'?'可注册':'已停止注册'; ?></span>
                            <?php if ($rdMaintenance): ?>
                            <br><span class="badge bg-warning text-dark mt-1"><i class="fas fa-tools"></i> 维护中</span>
                            <?php else: ?>
                            <br><span class="badge bg-light text-muted mt-1">正常</span>
                            <?php endif; ?>
                            <?php if ($rdNsDisabled): ?>
                            <br><span class="badge bg-danger mt-1"><i class="fas fa-ban"></i> NS管理已禁用</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('Y-m-d H:i', strtotime($rd->created_at)); ?></td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#rootdomainEditModal<?php echo $rd->id; ?>" data-bs-toggle="modal" data-bs-target="#rootdomainEditModal<?php echo $rd->id; ?>">编辑</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('确定切换状态？');">
                                    <input type="hidden" name="action" value="toggle_rootdomain">
                                    <input type="hidden" name="id" value="<?php echo $rd->id; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-<?php echo $rd->status==='active'?'warning':'success'; ?>">
                                        <?php echo $rd->status==='active'?'停止注册':'重新开启注册'; ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?php echo $rdMaintenance ? '确定关闭维护模式？用户将可以正常操作DNS。' : '确定开启维护模式？该根域名下的所有域名将禁止DNS操作。'; ?>');">
                                    <input type="hidden" name="action" value="toggle_rootdomain_maintenance">
                                    <input type="hidden" name="id" value="<?php echo $rd->id; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-<?php echo $rdMaintenance ? 'info' : 'warning'; ?>" title="<?php echo $rdMaintenance ? '关闭维护模式' : '开启维护模式'; ?>">
                                        <i class="fas fa-<?php echo $rdMaintenance ? 'play' : 'tools'; ?>"></i> <?php echo $rdMaintenance ? '恢复' : '维护'; ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('确定删除该根域名？');">
                                    <input type="hidden" name="action" value="delete_rootdomain">
                                    <input type="hidden" name="id" value="<?php echo $rd->id; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (count($rootdomains) > 0): ?>
    <?php foreach ($rootdomains as $rd): ?>
        <?php
            $rdProviderId = intval($rd->provider_account_id ?? 0);
            $rdProvider = $rdProviderId > 0 && isset($providerAccountMap[$rdProviderId]) ? $providerAccountMap[$rdProviderId] : null;
            $rdProviderStatus = $rdProvider ? strtolower($rdProvider->status ?? '') : '';
            $rdSelectedProviderId = $rdProviderId > 0 ? $rdProviderId : ($defaultProviderSelectId > 0 ? $defaultProviderSelectId : 0);
            $rdMax = intval($rd->max_subdomains ?? 1000);
            if ($rdMax <= 0) { $rdMax = 1000; }
            $rdPerUser = intval($rd->per_user_limit ?? 0);
            if ($rdPerUser < 0) { $rdPerUser = 0; }
            $rdDefaultTerm = intval($rd->default_term_years ?? 0);
            $rdGrayEnabled = intval($rd->gray_enabled ?? 0) === 1;
            $rdGrayRatio = intval($rd->gray_ratio ?? 100);
            if ($rdGrayRatio < 0) { $rdGrayRatio = 0; }
            if ($rdGrayRatio > 100) { $rdGrayRatio = 100; }
        ?>
        <div class="modal fade" id="rootdomainEditModal<?php echo $rd->id; ?>" tabindex="-1" aria-labelledby="rootdomainEditModalLabel<?php echo $rd->id; ?>" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="action" value="admin_rootdomain_update">
                        <input type="hidden" name="rootdomain_id" value="<?php echo $rd->id; ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="rootdomainEditModalLabel<?php echo $rd->id; ?>">编辑根域名</h5>
                            <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">根域名</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($rd->domain); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Zone ID</label>
                                    <input type="text" class="form-control" name="cloudflare_zone_id" value="<?php echo htmlspecialchars($rd->cloudflare_zone_id ?? ''); ?>" placeholder="可选">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">DNS 供应商</label>
                                    <select name="provider_account_id" class="form-select" <?php echo $hasActiveProviderAccounts ? '' : 'disabled'; ?> required>
                                        <?php if ($rdProvider && $rdProviderStatus !== 'active'): ?>
                                            <option value="<?php echo $rdProviderId; ?>" selected disabled><?php echo htmlspecialchars(($rdProvider->name ?: ('ID ' . $rdProvider->id)) . '（已停用）'); ?></option>
                                        <?php endif; ?>
                                        <?php foreach ($activeProviderAccounts as $provider): ?>
                                            <option value="<?php echo intval($provider->id); ?>" <?php echo intval($provider->id) === $rdSelectedProviderId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($provider->name ?? ('ID ' . $provider->id)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$hasActiveProviderAccounts): ?>
                                        <div class="form-text text-danger">暂无可用供应商账号，无法保存。</div>
                                    <?php elseif ($rdProvider && $rdProviderStatus !== 'active'): ?>
                                        <div class="form-text text-danger">当前绑定账号已停用，请切换至其他账号。</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">描述</label>
                                    <input type="text" class="form-control" name="description" value="<?php echo htmlspecialchars($rd->description ?? ''); ?>" maxlength="255" placeholder="可选">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">最大子域数量</label>
                                    <input type="number" class="form-control" name="max_subdomains" min="1" value="<?php echo $rdMax; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">单用户上限</label>
                                    <input type="number" class="form-control" name="per_user_limit" min="0" value="<?php echo $rdPerUser; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">默认注册年限（年）</label>
                                    <input type="number" class="form-control" name="default_term_years" min="0" value="<?php echo $rdDefaultTerm; ?>">
                                    <div class="form-text">0 表示使用系统默认配置</div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="require_invite_code" value="1" id="requireInviteCode<?php echo $rd->id; ?>" <?php echo !empty($rd->require_invite_code) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="requireInviteCode<?php echo $rd->id; ?>">
                                            <strong>启用邀请码注册</strong>
                                        </label>
                                        <div class="form-text">开启后，用户注册该根域名的二级域名时必须输入邀请码</div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="disable_ns_management" value="1" id="disableNsManagement<?php echo $rd->id; ?>" <?php echo !empty($rd->disable_ns_management) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="disableNsManagement<?php echo $rd->id; ?>">
                                            <strong>禁止该根域名 NS 管理</strong>
                                        </label>
                                        <div class="form-text">开启后，该根域名下禁止设置域名 NS 服务器和新增/修改 NS 记录。</div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="gray_enabled" value="1" id="grayEnabled<?php echo $rd->id; ?>" <?php echo $rdGrayEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="grayEnabled<?php echo $rd->id; ?>">
                                            <strong>启用灰度开放</strong>
                                        </label>
                                        <div class="form-text">开启后按用户稳定分流开放该根域名，不命中灰度的用户将无法注册此根域名。</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">灰度比例（0-100）</label>
                                    <input type="number" class="form-control" name="gray_ratio" min="0" max="100" value="<?php echo $rdGrayRatio; ?>">
                                    <div class="form-text">仅在启用灰度后生效；100 表示全量开放，0 表示不对普通用户开放。</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary" <?php echo $hasActiveProviderAccounts ? '' : 'disabled'; ?>>保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- 域名平台迁移 -->
<div class="card mb-4" id="rootdomainTransfer">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="card-title mb-0"><i class="fas fa-random"></i> 域名平台迁移</h5>
        </div>
        <p class="text-muted small mb-3">将指定根域名下的所有解析批量复制到新的 DNS 供应商，可选暂停前端注册并在完成后自动恢复。</p>
        <?php if (!$hasActiveProviderAccounts || empty($allKnownRootdomains)): ?>
            <div class="alert alert-warning mb-0">
                <i class="fas fa-info-circle me-1"></i> 请先确保已添加根域名并至少启用一个供应商账号后再使用此功能。
            </div>
        <?php else: ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="transfer_rootdomain_provider">
                <div class="col-md-4">
                    <label class="form-label">选择根域名</label>
                    <select name="transfer_rootdomain" class="form-select" required>
                        <option value="">-- 请选择 --</option>
                        <?php foreach ($allKnownRootdomains as $domain): ?>
                            <option value="<?php echo htmlspecialchars($domain); ?>"><?php echo htmlspecialchars($domain); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">目标 DNS 供应商</label>
                    <select name="target_provider_account_id" class="form-select" required>
                        <?php foreach ($activeProviderAccounts as $provider): ?>
                            <option value="<?php echo intval($provider->id); ?>"><?php echo htmlspecialchars($provider->name ?? ('ID ' . $provider->id)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">批次大小</label>
                    <input type="number" class="form-control" name="transfer_batch_size" value="200" min="25" max="5000">
                    <div class="form-text">每批处理的子域数量，建议 50-200，特殊场景可提升至 5,000。</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">迁移模式</label>
                    <select name="transfer_migration_mode" class="form-select">
                        <option value="local_only">仅本地迁移（Local-only）</option>
                        <option value="mixed" selected>混合迁移（Mixed，推荐）</option>
                        <option value="cloud_only">仅云端迁移（Cloud-only）</option>
                    </select>
                    <div class="form-text">混合模式会优先结合本地记录与云端权威记录，遇到云端限流会自动回落到 Local-only。</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">本地完整性阻断阈值（%）</label>
                    <input type="number" class="form-control" name="transfer_local_missing_threshold" value="30" min="0" max="100" step="1">
                    <div class="form-text">当“本地缺失率”超过该值时阻断迁移（Cloud-only 模式不检查）。</div>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="transfer_pause_registration" name="transfer_pause_registration" value="1">
                        <label class="form-check-label" for="transfer_pause_registration">迁移期间暂停该根域当前端注册</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="transfer_auto_resume" name="transfer_auto_resume" value="1" checked>
                        <label class="form-check-label" for="transfer_auto_resume">任务完成后自动恢复原状态</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="transfer_delete_old" name="transfer_delete_old" value="1">
                        <label class="form-check-label" for="transfer_delete_old">迁移成功后删除旧平台的解析记录</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label d-block">执行方式</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="transfer_run_mode" id="transfer_run_mode_queue" value="queue" checked>
                        <label class="form-check-label" for="transfer_run_mode_queue">加入队列（推荐）</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="transfer_run_mode" id="transfer_run_mode_now" value="now">
                        <label class="form-check-label" for="transfer_run_mode_now">立即执行（大量数据可能耗时）</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-warning">开始迁移</button>
                    <small class="text-muted ms-2">系统将逐批迁移解析并在完成后更新根域名绑定。</small>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- 禁止注册域名管理 -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0"><i class="fas fa-ban"></i> 禁止注册域名</h5>
        </div>
        <form method="post" class="row g-3 mb-3">
            <input type="hidden" name="action" value="add_forbidden">
            <div class="col-md-4">
                <input type="text" name="ban_domain" class="form-control" placeholder="foo.example.com" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="ban_root" class="form-control" placeholder="根域名（可选）">
            </div>
            <div class="col-md-3">
                <input type="text" name="ban_reason" class="form-control" placeholder="原因（可选）">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">添加</button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>域名</th>
                        <th>根域</th>
                        <th>原因</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($forbiddenDomains as $fd): ?>
                    <tr>
                        <td><?php echo $fd->id; ?></td>
                        <td><code><?php echo htmlspecialchars($fd->domain); ?></code></td>
                        <td><?php echo htmlspecialchars($fd->rootdomain ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($fd->reason ?? ''); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($fd->created_at)); ?></td>
                        <td>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定移除该禁止项？');">
                                <input type="hidden" name="action" value="delete_forbidden">
                                <input type="hidden" name="id" value="<?php echo $fd->id; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 一键替换根域名（高级） -->
<div class="card mb-5">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="card-title mb-0"><i class="fas fa-exchange-alt"></i> 一键替换根域名</h5>
        </div>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="replace_rootdomain">
            <div class="col-md-4">
                <label class="form-label">选择旧根域名</label>
                <select name="from_root" class="form-select" required>
                    <option value="">-- 请选择 --</option>
                    <?php foreach ($allKnownRootdomains as $domain): ?>
                        <option value="<?php echo htmlspecialchars($domain); ?>"><?php echo htmlspecialchars($domain); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">输入新根域名</label>
                <input type="text" name="to_root" class="form-control" placeholder="new-example.com" required>
            </div>
            <div class="col-md-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="delete_old_records" name="delete_old_records" value="1">
                    <label class="form-check-label" for="delete_old_records">迁移后删除旧域名下的解析记录</label>
                </div>
                <div class="form-check mt-1">
                    <input class="form-check-input" type="radio" id="run_queue" name="run_mode" value="queue" checked>
                    <label class="form-check-label" for="run_queue">加入队列执行</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" id="run_now" name="run_mode" value="now">
                    <label class="form-check-label" for="run_now">立即执行</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-warning">开始替换</button>
                <small class="text-muted ms-2">将把所选旧根域名下的所有二级域和解析迁移至新根域名。</small>
            </div>
        </form>
    </div>
</div>

<!-- 根域名本地快照导出 / 导入 -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title mb-3"><i class="fas fa-database"></i> 根域名本地快照导出 / 导入</h5>
        <div class="row g-4">
            <div class="col-md-6">
                <form method="post">
                    <input type="hidden" name="action" value="export_rootdomain">
                    <label class="form-label">选择根域名导出</label>
                    <select name="export_rootdomain_value" class="form-select" required>
                        <option value="">-- 请选择 --</option>
                        <?php foreach ($allKnownRootdomains as $domain): ?>
                            <option value="<?php echo htmlspecialchars($domain); ?>"><?php echo htmlspecialchars($domain); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-3 d-flex align-items-start">
                        <button type="submit" class="btn btn-success me-2"><i class="fas fa-download me-1"></i> 导出本地快照</button>
                        <span class="text-muted small">导出的 JSON 文件包含本地子域、DNS 记录、风险数据及同步差异，适用于本插件的整库恢复。</span>
                    </div>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_rootdomain">
                    <label class="form-label">上传本地快照文件</label>
                    <input type="file" class="form-control" name="import_rootdomain_file" accept=".json,.json.gz" required>
                    <div class="form-text text-muted">支持 JSON 或 GZ 压缩文件；如存在同名子域，将自动覆盖为导入内容。</div>
                    <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-upload me-1"></i> 导入本地快照</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- PDNS 兼容一键导出 / 导入 -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title mb-3"><i class="fas fa-file-export"></i> PDNS 兼容一键导出 / 导入</h5>
        <div class="row g-4">
            <div class="col-md-6">
                <form method="post">
                    <input type="hidden" name="action" value="export_rootdomain_pdns">
                    <label class="form-label">选择根域名导出</label>
                    <select name="export_rootdomain_pdns_value" class="form-select" required>
                        <option value="">-- 请选择 --</option>
                        <?php foreach ($allKnownRootdomains as $domain): ?>
                            <option value="<?php echo htmlspecialchars($domain); ?>"><?php echo htmlspecialchars($domain); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted mt-2">默认导出远端当前解析（权威数据），输出为 PowerDNS RRSet 兼容 JSON，可用于跨平台迁移。</div>
                    <div class="mt-2">
                        <label class="form-label mb-1">导出来源</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" id="pdns_export_source_remote" name="pdns_export_source" value="remote" checked>
                            <label class="form-check-label" for="pdns_export_source_remote">远端平台（默认，权威）</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" id="pdns_export_source_local" name="pdns_export_source" value="local">
                            <label class="form-check-label" for="pdns_export_source_local">本地缓存（应急导出，可能非最新）</label>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label mb-1">本地应急导出限制（仅本地缓存模式生效）</label>
                        <div class="input-group input-group-sm">
                            <select class="form-select" name="pdns_local_export_limit_mode">
                                <option value="none" selected>不限制（导出全部本地缓存）</option>
                                <option value="subdomain">仅前 N 个子域名的记录</option>
                                <option value="record">仅前 N 条 DNS 记录</option>
                            </select>
                            <span class="input-group-text">N</span>
                            <input type="number" class="form-control" name="pdns_local_export_limit_value" value="1000" min="100" max="50000">
                        </div>
                        <div class="form-text text-muted">适合记录量过大时应急导出，导出文件会标记为部分导出。</div>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pdns_local_auto_continue" name="pdns_local_auto_continue" value="1">
                        <label class="form-check-label" for="pdns_local_auto_continue">自动连续导出（每段导出后间隔 10 秒继续，直到导完）</label>
                    </div>
                    <div class="form-text text-muted">仅本地缓存模式生效，且需配合“仅前 N 个子域名的记录”或“仅前 N 条 DNS 记录”使用。</div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pdns_local_resume_cursor" name="pdns_local_resume_cursor" value="1" checked>
                        <label class="form-check-label" for="pdns_local_resume_cursor">从上次游标续传（按根域名 + 限制模式 + N 自动匹配）</label>
                    </div>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" id="pdns_local_reset_cursor" name="pdns_local_reset_cursor" value="1">
                        <label class="form-check-label" for="pdns_local_reset_cursor">本次重置续传游标，从头开始导出</label>
                    </div>
                    <?php if (!empty($pdnsLocalExportCursorStates)): ?>
                    <div class="alert alert-secondary small mt-2 mb-0">
                        <div class="fw-bold mb-1">当前本地导出续传游标（未完成）</div>
                        <ul class="mb-0 ps-3">
                            <?php foreach (array_slice($pdnsLocalExportCursorStates, 0, 8) as $cursorState): ?>
                                <?php
                                    $cursorRoot = htmlspecialchars((string) ($cursorState['rootdomain'] ?? '-'));
                                    $cursorModeRaw = (string) ($cursorState['limit_mode'] ?? '');
                                    $cursorMode = $cursorModeRaw === 'record' ? '记录数' : '子域名数';
                                    $cursorLimit = intval($cursorState['limit_value'] ?? 0);
                                    $cursorNext = intval($cursorState['next_cursor'] ?? 0);
                                    $cursorUpdatedAt = htmlspecialchars((string) ($cursorState['updated_at'] ?? '-'));
                                ?>
                                <li><code><?php echo $cursorRoot; ?></code> / <?php echo htmlspecialchars($cursorMode); ?> N=<?php echo $cursorLimit; ?> / next_cursor=<?php echo $cursorNext; ?> <span class="text-muted">(更新时间 <?php echo $cursorUpdatedAt; ?>)</span></li>
                            <?php endforeach; ?>
                            <?php if (count($pdnsLocalExportCursorStates) > 8): ?>
                                <li class="text-muted">…… 其余 <?php echo intval(count($pdnsLocalExportCursorStates) - 8); ?> 条已省略</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pdns_segmented_export" name="pdns_segmented_export" value="1" checked>
                        <label class="form-check-label" for="pdns_segmented_export">分段导出（大规模记录推荐）</label>
                    </div>
                    <div class="input-group input-group-sm mt-2">
                        <span class="input-group-text">每段记录数</span>
                        <input type="number" class="form-control" name="pdns_export_segment_size" value="10000" min="1000" max="50000">
                    </div>
                    <button type="submit" class="btn btn-outline-success mt-3"><i class="fas fa-download me-1"></i> 导出 PDNS 兼容文件</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_rootdomain_pdns">
                    <label class="form-label">上传 PDNS 兼容文件</label>
                    <input type="file" class="form-control" name="import_rootdomain_pdns_file" accept=".json,.json.gz" required>
                    <label class="form-label mt-3">目标根域名</label>
                    <select name="import_rootdomain_pdns_target" class="form-select" required>
                        <option value="">-- 请选择 --</option>
                        <?php foreach ($allKnownRootdomains as $domain): ?>
                            <option value="<?php echo htmlspecialchars($domain); ?>"><?php echo htmlspecialchars($domain); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pdns_overwrite_same_name_type" name="pdns_overwrite_same_name_type" value="1" checked>
                        <label class="form-check-label" for="pdns_overwrite_same_name_type">覆盖同名同类型记录（REPLACE 模式）</label>
                    </div>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" id="pdns_segmented_import" name="pdns_segmented_import" value="1" checked>
                        <label class="form-check-label" for="pdns_segmented_import">分段导入（大规模记录推荐）</label>
                    </div>
                    <div class="input-group input-group-sm mt-2">
                        <span class="input-group-text">每段记录数</span>
                        <input type="number" class="form-control" name="pdns_import_segment_size" value="10000" min="1000" max="50000">
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pdns_enqueue_root_calibration" name="pdns_enqueue_root_calibration" value="1" checked>
                        <label class="form-check-label" for="pdns_enqueue_root_calibration">导入后自动提交根域校准任务</label>
                    </div>
                    <div class="form-text text-muted">建议在大批量迁移后开启分段导入与校准，自动同步本地与云端状态，降低前端编辑异常风险。</div>
                    <button type="submit" class="btn btn-outline-primary mt-3"><i class="fas fa-upload me-1"></i> 一键导入到目标根域</button>
                </form>
            </div>
        </div>
    </div>
</div>
