<?php
$giftIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$giftText = static function (string $key, string $zh, string $en, array $params = []) use ($giftIsChinese): string {
    return cfclient_lang($key, $giftIsChinese ? $zh : $en, $params, true);
};
$giftTtlHours = max(1, intval($domainGiftTtlHours ?? 24));
?>

<?php if (!empty($domainGiftEnabled)): ?>
    <div class="card border-0 shadow-sm" id="giftWorkbench">
        <div class="card-body p-3 p-lg-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-2">
                <div class="alert alert-warning mb-0 py-2 px-3">
                    <i class="fas fa-shield-alt me-1"></i>
                    <?php echo $giftText('cfclient.gift_center.desc', '为保障域名安全，新注册域名需满 7 天后方可发起转赠操作。', 'To protect domain security, newly registered domains must be at least 7 days old before transfer can be initiated.'); ?>
                </div>
                <div class="gift-workbench-tip badge rounded-pill px-3 py-2">
                    <i class="fas fa-clock me-1"></i>
                    <?php echo $giftText('cfclient.gift_center.ttl', '接收码有效期 %s 小时', 'Transfer code valid for %s hours', [number_format($giftTtlHours)]); ?>
                </div>
            </div>

            <div id="giftAlertPlaceholder" class="mb-2"></div>

            <ul class="nav nav-pills nav-fill gift-workbench-tabs mb-2" id="giftTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="gift-initiate-tab" data-bs-toggle="tab" data-bs-target="#gift-initiate-pane" type="button" role="tab" aria-selected="true">
                        <i class="fas fa-paper-plane me-1"></i><?php echo $giftText('cfclient.modals.gift.tabs.initiate', '发起转赠', 'Initiate Transfer'); ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="gift-accept-tab" data-bs-toggle="tab" data-bs-target="#gift-accept-pane" type="button" role="tab" aria-selected="false">
                        <i class="fas fa-download me-1"></i><?php echo $giftText('cfclient.modals.gift.tabs.accept', '接受转赠', 'Accept Transfer'); ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="gift-history-tab" data-bs-toggle="tab" data-bs-target="#gift-history-pane" type="button" role="tab" aria-selected="false">
                        <i class="fas fa-history me-1"></i><?php echo $giftText('cfclient.modals.gift.tabs.history', '转赠历史', 'Transfer History'); ?>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="gift-initiate-pane" role="tabpanel" aria-labelledby="gift-initiate-tab">
                    <div class="row g-3 g-lg-4">
                        <div class="col-lg-8">
                            <div class="gift-panel h-100">
                                <div class="gift-panel-title mb-2">
                                    <i class="fas fa-list-check text-primary me-1"></i>
                                    <?php echo $giftText('cfclient.gift_center.select_title', '选择要转赠的域名', 'Select Domain to Transfer'); ?>
                                </div>
                                <div class="mb-2">
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="giftDomainSearchInput"
                                        placeholder="<?php echo $giftText('cfclient.gift_center.search_placeholder', '搜索域名（支持前缀/后缀）', 'Search domains by name'); ?>"
                                    >
                                </div>
                                <input type="hidden" id="giftDomainSelect" value="">
                                <div class="gift-domain-list" id="giftDomainList"></div>
                                <div class="d-flex justify-content-between align-items-center mt-2" id="giftDomainPagination"></div>
                                <div class="form-text mt-2 mb-3"><?php echo $giftText('cfclient.modals.gift.hint.domain', '仅支持转赠状态正常且未锁定的域名，转赠后域名将暂时锁定，直到完成或取消。', 'Only healthy and unlocked domains can be transferred. The domain will be temporarily locked until completed or canceled.'); ?></div>
                                <button type="button" class="btn btn-primary" id="generateGiftButton">
                                    <i class="fas fa-magic me-1"></i><?php echo $giftText('cfclient.modals.gift.button.generate', '生成接收码', 'Generate Code'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="gift-panel gift-pending-card d-none" id="giftPendingVoucherCard">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="gift-panel-title mb-0">
                                        <i class="fas fa-ticket-alt text-warning me-1"></i><?php echo $giftText('cfclient.gift_center.pending_title', '待领取凭证', 'Pending Voucher'); ?>
                                    </div>
                                    <span class="gift-countdown-pill" id="giftCodeCountdown">--:--:--</span>
                                </div>
                                <div class="small text-muted mb-2"><?php echo $giftText('cfclient.gift_center.pending_hint', '该域名正处于转赠锁定状态，请尽快分享接收码。', 'This domain is locked for transfer. Share the code soon.'); ?></div>
                                <div class="gift-code-value mb-2" id="giftCodeValue">-</div>
                                <div class="small mb-1"><?php echo $giftText('cfclient.modals.gift.result.domain', '域名：', 'Domain:'); ?><span id="giftCodeDomain">-</span></div>
                                <div class="small text-muted mb-3"><?php echo $giftText('cfclient.modals.gift.result.expire', '有效期至：', 'Valid until:'); ?><span id="giftCodeExpire">-</span></div>
                                <button type="button" class="btn btn-outline-primary btn-sm w-100" id="giftCopyButton">
                                    <i class="fas fa-copy me-1"></i><?php echo $giftText('cfclient.modals.gift.button.copy', '复制接收码', 'Copy Code'); ?>
                                </button>
                            </div>
                            <div class="gift-panel text-center" id="giftVoucherEmpty">
                                <i class="fas fa-shield-alt fa-2x text-muted mb-2"></i>
                                <div class="fw-semibold mb-1"><?php echo $giftText('cfclient.gift_center.pending_empty_title', '暂无待领取凭证', 'No Pending Voucher'); ?></div>
                                <div class="small text-muted mb-0"><?php echo $giftText('cfclient.gift_center.pending_empty_desc', '生成接收码后，此处将常驻显示并提供倒计时提醒。', 'After generating a code, this section stays visible with countdown reminders.'); ?></div>
                            </div>
                            <div class="gift-panel mt-3">
                                <div class="gift-panel-title mb-2"><i class="fas fa-stream me-1 text-info"></i><?php echo $giftText('cfclient.gift_center.recent_title', '最近 3 条转赠记录', 'Latest 3 Transfer Records'); ?></div>
                                <div id="giftRecentList" class="gift-recent-list">
                                    <div class="text-muted small"><?php echo $giftText('cfclient.gift_center.recent_loading', '正在加载记录...', 'Loading records...'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="gift-accept-pane" role="tabpanel" aria-labelledby="gift-accept-tab">
                    <div class="gift-panel gift-accept-panel">
                        <label class="form-label fw-semibold"><?php echo $giftText('cfclient.modals.gift.label.code', '输入接收码', 'Enter Transfer Code'); ?></label>
                        <div class="input-group mb-2">
                            <input type="text" id="giftAcceptCode" class="form-control text-uppercase" placeholder="<?php echo $giftText('cfclient.modals.gift.placeholder.code', '请输入18位接收码', 'Enter 18-character transfer code'); ?>">
                            <button type="button" class="btn btn-success" id="acceptGiftButton">
                                <i class="fas fa-hand-holding me-1"></i><?php echo $giftText('cfclient.modals.gift.button.accept', '立即领取', 'Accept Now'); ?>
                            </button>
                        </div>
                        <div class="form-text"><?php echo $giftText('cfclient.modals.gift.hint.code', '接收码由赠送方生成，有效期 %s 小时。', 'The transfer code is generated by sender and valid for %s hours.', [number_format($giftTtlHours)]); ?></div>
                    </div>
                </div>

                <div class="tab-pane fade" id="gift-history-pane" role="tabpanel" aria-labelledby="gift-history-tab">
                    <div class="gift-panel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th><?php echo $giftText('cfclient.modals.gift.table.domain', '域名', 'Domain'); ?></th>
                                        <th><?php echo $giftText('cfclient.modals.gift.table.code', '接收码', 'Code'); ?></th>
                                        <th><?php echo $giftText('cfclient.modals.gift.table.status', '状态', 'Status'); ?></th>
                                        <th><?php echo $giftText('cfclient.modals.gift.table.time', '时间', 'Timeline'); ?></th>
                                        <th class="text-end"><?php echo $giftText('cfclient.modals.gift.table.actions', '操作', 'Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="giftHistoryTableBody">
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3"><?php echo $giftText('cfclient.modals.gift.table.empty', '暂无转赠记录', 'No transfer records yet'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <nav class="mt-3">
                            <ul class="pagination pagination-sm justify-content-end mb-0" id="giftHistoryPagination"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <div class="gift-panel mt-3">
                <div class="gift-panel-title mb-2">
                    <i class="fas fa-question-circle text-info me-1"></i>
                    <?php echo $giftText('cfclient.gift_center.faq.title', '域名转赠常见问题解答', 'Domain Transfer FAQ'); ?>
                </div>
                <div class="small">
                    <p class="mb-2"><strong><?php echo $giftText('cfclient.gift_center.faq.q1', 'Q：域名转赠后悔了可以取消转赠吗？？', 'Q: Can I cancel a domain transfer after initiating it?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a1', 'A：默认转赠未被接收的情况下可以在转赠历史进行取消操作,如果已被接收无法撤回操作。', 'A: Yes. If the transfer has not been accepted yet, you can cancel it in Transfer History. Once accepted, it cannot be revoked.'); ?></p>
                    <p class="mb-2"><strong><?php echo $giftText('cfclient.gift_center.faq.q2', 'Q：域名转赠支持使用api调用吗？', 'Q: Does domain transfer support API calls?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a2', 'A：支持api进行域名的转赠和接收操作,您可以在api文档（V2.0版本）查看操作命令。', 'A: Yes. API supports both transfer initiation and acceptance. Please check the API documentation (v2.0) for commands.'); ?></p>
                    <p class="mb-2"><strong><?php echo $giftText('cfclient.gift_center.faq.q3', 'Q：域名转赠接收方账户有要求吗？', 'Q: Are there requirements for the recipient account?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a3', 'A：域名转赠接收方账户需要是正常状态,账户注册时间当前无限制。', 'A: The recipient account must be in normal active status. There is currently no registration-age limit.'); ?></p>
                    <p class="mb-2"><strong><?php echo $giftText('cfclient.gift_center.faq.q4', 'Q：域名转赠占用注册额度吗？', 'Q: Does domain transfer consume registration quota?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a4', 'A：域名转赠占用注册额度,转赠域名如果被成功接收,则转赠方恢复对应额度-接收方需要扣除对应的注册额度。', 'A: Yes. Domain transfer uses registration quota. If the transfer is accepted, the sender recovers the corresponding quota, and the recipient is charged the corresponding quota.'); ?></p>
                    <p class="mb-2"><strong><?php echo $giftText('cfclient.gift_center.faq.q5', 'Q：域名转赠接收方没有注册额度可以接收转赠吗？', 'Q: Can a recipient accept transfer without available registration quota?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a5', 'A：域名转赠占用注册额度,接收方需要扣除对应的注册额度，如果接收方的域名注册额度已经被注册使用完毕则无法接收域名。', 'A: No. Domain transfer consumes registration quota. The recipient must have enough available quota; if quota is already fully used, the domain cannot be accepted.'); ?></p>
                    <p class="mb-2"><strong><?php echo $giftText('cfclient.gift_center.faq.q6', 'Q：域名转赠给好友后会影响域名的DNS解析吗？', 'Q: Will transferring a domain affect its DNS records?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a6', 'A：域名转赠只转移域名所有人,不影响域名的DNS解析,转赠后对方仍然会显示域名所有的DNS记录。', 'A: No. Transfer only changes ownership and does not affect DNS resolution. The recipient will still see all DNS records of the domain.'); ?></p>
                    <p class="mb-0"><strong><?php echo $giftText('cfclient.gift_center.faq.q7', 'Q：域名转赠给好友后会影响域名的到期时间吗？', 'Q: Will transferring a domain affect its expiration date?'); ?></strong><br><?php echo $giftText('cfclient.gift_center.faq.a7', 'A：域名转赠只转移域名所有人,不会对域名的到期时间等进行任何改变。', 'A: No. Transfer only changes ownership and does not change expiration date or related lifecycle settings.'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <style>
    #giftWorkbench .gift-workbench-tip {
        background: #fff4df;
        border: 1px solid #f7c46d;
        color: #c66a00;
        font-weight: 700;
    }

    #giftWorkbench .gift-workbench-tip i {
        color: #f59e0b;
    }

    #giftWorkbench .gift-workbench-tabs .nav-link {
        border-radius: 999px;
        border: 1px solid #dce4f1;
        font-weight: 600;
        color: #425166;
        padding: 0.62rem 0.9rem;
        margin: 0.1rem;
        background: #f8fafc;
    }

    #giftWorkbench .gift-workbench-tabs .nav-link.active {
        background: linear-gradient(135deg, #4f7df7 0%, #6aa2ff 100%);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 6px 18px rgba(79, 125, 247, 0.28);
    }

    #giftWorkbench .gift-panel {
        border: 1px solid #e8edf5;
        border-radius: 14px;
        background: #fff;
        padding: 0.9rem 1rem;
    }

    #giftWorkbench .gift-panel-title {
        font-size: 0.94rem;
        font-weight: 700;
        color: #334155;
    }

    #giftWorkbench .gift-domain-list {
        border: 1px solid #e5eaf2;
        border-radius: 12px;
        padding: 0.4rem;
        background: #fbfcfe;
        max-height: 280px;
        overflow-y: auto;
    }

    #giftWorkbench .gift-domain-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 0.52rem 0.6rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    #giftWorkbench .gift-domain-item + .gift-domain-item {
        margin-top: 0.32rem;
    }

    #giftWorkbench .gift-domain-item:hover {
        border-color: #d4e0ff;
        background: #f1f6ff;
    }

    #giftWorkbench .gift-domain-item.is-selected {
        border-color: #9db7ff;
        background: #eaf1ff;
    }

    #giftWorkbench .gift-domain-item.is-locked {
        opacity: 1;
        cursor: not-allowed;
        border-color: #f4d49b;
        background: #fff9ed;
    }

    #giftWorkbench .gift-domain-item.is-locked:hover {
        border-color: #f4d49b;
        background: #fff9ed;
    }

    #giftWorkbench .gift-domain-item .form-check-input {
        margin-top: 0;
    }

    #giftWorkbench .gift-domain-main {
        min-width: 0;
        flex: 1;
    }

    #giftWorkbench .gift-domain-name {
        font-weight: 600;
        color: #334155;
        word-break: break-all;
    }

    #giftWorkbench .gift-domain-lock-text {
        display: inline-flex;
        align-items: center;
        margin-left: 0.45rem;
        font-size: 0.75rem;
        font-weight: 700;
        color: #d97706;
    }

    #giftWorkbench .gift-code-value {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        font-weight: 700;
        background: #f3f7ff;
        border: 1px dashed #9cb6ff;
        border-radius: 10px;
        padding: 0.5rem 0.6rem;
        text-align: center;
    }

    #giftWorkbench .gift-pending-card {
        border-color: #cfe0ff;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%);
    }

    #giftWorkbench .gift-countdown-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 94px;
        padding: 0.22rem 0.52rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(90deg, #fb923c 0%, #f97316 100%);
        animation: giftPulse 1.25s ease-in-out infinite;
    }

    #giftWorkbench .gift-countdown-pill.is-expired {
        background: #dc3545;
        animation: none;
    }

    #giftWorkbench .gift-recent-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    #giftWorkbench .gift-recent-item {
        border: 1px solid #eef2f7;
        border-radius: 10px;
        padding: 0.45rem 0.55rem;
        background: #fbfdff;
    }

    #giftWorkbench .gift-recent-domain {
        font-weight: 600;
        font-size: 0.86rem;
        word-break: break-all;
    }

    #giftWorkbench .gift-accept-panel {
        max-width: 720px;
    }

    @keyframes giftPulse {
        0% { box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.45); }
        70% { box-shadow: 0 0 0 7px rgba(249, 115, 22, 0); }
        100% { box-shadow: 0 0 0 0 rgba(249, 115, 22, 0); }
    }

    @media (max-width: 991px) {
        #giftWorkbench .gift-domain-list {
            max-height: 220px;
        }
    }
    </style>
<?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-1"></i><?php echo $giftText('cfclient.gift_center.disabled', '当前未启用域名转赠功能。', 'Domain transfer feature is currently disabled.'); ?>
    </div>
<?php endif; ?>
