<?php
$clientLanguageCode = isset($currentClientLanguage) ? strtolower((string) $currentClientLanguage) : 'english';
$isClientLanguageChinese = $clientLanguageCode === 'chinese';
$supportTicketUrl = isset($cfClientSupportTicketUrl) && trim((string) $cfClientSupportTicketUrl) !== '' ? (string) $cfClientSupportTicketUrl : 'submitticket.php';
$supportGroupUrl = isset($cfClientSupportGroupUrl) && trim((string) $cfClientSupportGroupUrl) !== '' ? (string) $cfClientSupportGroupUrl : 'https://t.me/+l9I5TNRDLP5lZDBh';
$clientPortalUrl = isset($cfClientPortalUrl) && trim((string) $cfClientPortalUrl) !== '' ? (string) $cfClientPortalUrl : 'index.php';
$helpAiEnabled = !empty($helpAiSearchEnabled);
$helpAiAssistantDisplayName = trim((string) ($helpAiAssistantName ?? 'AI 助手'));
if ($helpAiAssistantDisplayName === '') {
    $helpAiAssistantDisplayName = $isClientLanguageChinese ? 'AI 助手' : 'AI Assistant';
}
$helpAiMaxChars = max(200, min(2000, intval($helpAiMaxInputChars ?? 600)));
$whoisDefaultNsRaw = trim((string) ($module_settings['whois_default_nameservers'] ?? ($module_settings['whois_default_ns_list'] ?? '')));
$whoisDefaultNsList = array_values(array_filter(array_unique(array_map(static function ($item) {
    return strtolower(trim((string) $item));
}, preg_split('/[\r\n,;]+/', $whoisDefaultNsRaw) ?: [])), static function ($item) {
    return $item !== '';
}));
$whoisDefaultNsDisplay = !empty($whoisDefaultNsList) ? implode(' / ', $whoisDefaultNsList) : 'ns1.com / ns2.com';
$extrasTexts = [
    'tipsTitle' => cfclient_lang('cfclient.extras.tips.title', $isClientLanguageChinese ? '帮助中心知识库' : 'Help Center Knowledge Base', [], true),
    'searchPlaceholder' => cfclient_lang('cfclient.extras.search.placeholder', $isClientLanguageChinese ? '搜索关键字，例如：DNS 生效、解析报错、域名转赠' : 'Search keywords, e.g. DNS propagation, record errors, domain transfer', [], true),
    'searchEmpty' => cfclient_lang('cfclient.extras.search.empty', $isClientLanguageChinese ? '未找到匹配内容，请尝试更换关键字。' : 'No matching topics found. Try another keyword.', [], true),
    'banner' => cfclient_lang('cfclient.extras.banner', $isClientLanguageChinese ? '记录变更通常在几分钟内生效。如遇解析异常，请通过工单或 TG 社群获取实时支持。' : 'Record changes usually propagate within minutes. If DNS behaves unexpectedly, please open a ticket or contact TG community support for real-time help.', [], true),
    'coreTitle' => cfclient_lang('cfclient.extras.section.core', $isClientLanguageChinese ? '常见问题' : 'Common Questions', [], true),
    'domainTitle' => cfclient_lang('cfclient.extras.section.domain', $isClientLanguageChinese ? '域名规则与管理' : 'Domain Rules & Management', [], true),
    'dnsTitle' => cfclient_lang('cfclient.extras.section.dns', $isClientLanguageChinese ? 'DNS 记录说明' : 'DNS Record Guidance', [], true),
    'supportTitle' => cfclient_lang('cfclient.extras.support.title', $isClientLanguageChinese ? '自助支持入口' : 'Self-Service Support', [], true),
    'supportBody' => cfclient_lang('cfclient.extras.support.body', $isClientLanguageChinese ? '选择对应入口快速处理问题，建议优先提交工单以便追踪处理进度。' : 'Choose the channel that fits your issue. Opening a ticket first is recommended for better tracking.', [], true),
    'supportTicket' => cfclient_lang('cfclient.extras.support.ticket', $isClientLanguageChinese ? '提交工单' : 'Open Ticket', [], true),
    'supportAppeal' => cfclient_lang('cfclient.extras.support.appeal', $isClientLanguageChinese ? '封禁申诉工单' : 'Ban Appeal Ticket', [], true),
    'supportKb' => cfclient_lang('cfclient.extras.support.kb', $isClientLanguageChinese ? '知识库' : 'Knowledgebase', [], true),
    'supportContact' => cfclient_lang('cfclient.extras.support.contact', $isClientLanguageChinese ? 'TG 社群' : 'TG Community', [], true),
    'supportTicketDesc' => cfclient_lang('cfclient.extras.support.ticket_desc', $isClientLanguageChinese ? '反馈解析异常、记录操作失败等问题' : 'Report DNS errors, failed record operations, and account issues', [], true),
    'supportAppealDesc' => cfclient_lang('cfclient.extras.support.appeal_desc', $isClientLanguageChinese ? '账号封禁或停用后提交人工复核申请' : 'Submit manual review requests for banned or disabled accounts', [], true),
    'supportKbDesc' => cfclient_lang('cfclient.extras.support.kb_desc', $isClientLanguageChinese ? '查看官方知识库文档与教程' : 'Browse official knowledge base documentation and tutorials', [], true),
    'supportContactDesc' => cfclient_lang('cfclient.extras.support.contact_desc', $isClientLanguageChinese ? '加入社群获取实时公告与交流支持' : 'Join the community for announcements and real-time support', [], true),
    'backToPortal' => cfclient_lang('cfclient.extras.back_to_portal', $isClientLanguageChinese ? '返回客户中心' : 'Back to Client Area', [], true),
    'aiButton' => cfclient_lang('cfclient.extras.ai.button', $isClientLanguageChinese ? 'AI 搜索/问答' : 'AI Search & Chat', [], true),
    'aiModalTitle' => cfclient_lang('cfclient.extras.ai.modal_title', $isClientLanguageChinese ? '帮助中心 AI 助手' : 'Help Center AI Assistant', [], true),
    'aiModalHint' => cfclient_lang('cfclient.extras.ai.modal_hint', $isClientLanguageChinese ? '可咨询域名注册、续期、DNS 解析、API 密钥等域名相关问题。' : 'Ask about domain-related topics such as domain registration, renewal, DNS records, and API keys.', [], true),
    'aiInputPlaceholder' => cfclient_lang('cfclient.extras.ai.input_placeholder', $isClientLanguageChinese ? '请输入你的问题…' : 'Type your question…', [], true),
    'aiSend' => cfclient_lang('cfclient.extras.ai.send', $isClientLanguageChinese ? '发送' : 'Send', [], true),
    'aiThinking' => cfclient_lang('cfclient.extras.ai.thinking', $isClientLanguageChinese ? '思考中…' : 'Thinking…', [], true),
    'aiWelcome' => cfclient_lang('cfclient.extras.ai.welcome', $isClientLanguageChinese ? '你好，我是 %s。请告诉我你遇到的问题。' : 'Hi, I\'m %s. Tell me what you need help with.', [$helpAiAssistantDisplayName], true),
    'aiUserLabel' => cfclient_lang('cfclient.extras.ai.user_label', $isClientLanguageChinese ? '我' : 'You', [], true),
    'aiEmptyQuestion' => cfclient_lang('cfclient.extras.ai.empty_question', $isClientLanguageChinese ? '请输入问题后再发送。' : 'Please enter a question before sending.', [], true),
    'aiTooLong' => cfclient_lang('cfclient.extras.ai.too_long', $isClientLanguageChinese ? '问题过长，请控制在 %s 字以内。' : 'Your question is too long. Keep it within %s characters.', [$helpAiMaxChars], true),
    'aiRequestFailed' => cfclient_lang('cfclient.extras.ai.request_failed', $isClientLanguageChinese ? 'AI 请求失败，请稍后再试。' : 'AI request failed. Please try again later.', [], true),
];
$privilegedDeleteHistoryEnabled = !empty($privilegedAllowDeleteWithDnsHistory);
$deleteTipKey = !empty($clientDeleteEnabled) ? 'cfclient.extras.tips.domain.delete_enabled' : 'cfclient.extras.tips.domain.delete';
$deleteTipDefault = !empty($clientDeleteEnabled)
    ? ($privilegedDeleteHistoryEnabled
        ? ($isClientLanguageChinese ? '域名删除：您当前可提交任意域名的自助删除申请（含曾配置过解析的域名）。' : 'Domain deletion: you can submit self-service deletion requests for any domain, including domains with DNS history.')
        : ($isClientLanguageChinese ? '域名删除：可在“查看详情”中查看您的域名是否支持删除操作。' : 'Domain deletion: check in “View details” whether your domain supports deletion.'))
    : ($isClientLanguageChinese ? '域名删除：域名成功注册后不支持删除。' : 'Domain deletion: domains cannot be removed after successful registration.');
$coreTips = [
    cfclient_lang('cfclient.extras.tips.core.unparsed', $isClientLanguageChinese ? '域名显示未解析：未解析表示您未对域名进行任何解析操作（或者当前域名无 DNS 解析记录）。' : 'Domain shows unparsed: this means no DNS operation has been performed for the domain (or there are currently no DNS records for the domain).', [], true),
    cfclient_lang('cfclient.extras.tips.core.parsed', $isClientLanguageChinese ? '域名显示已解析：已解析表示您的域名进行了 DNS 解析操作（域名存在 DNS 解析记录）。' : 'Domain shows parsed: this means DNS operations have been performed for your domain (the domain has DNS records).', [], true),
    cfclient_lang('cfclient.extras.tips.core.delegated_domain_display', $isClientLanguageChinese ? '域名显示已委派：已委派表示您的域名托管到了第三方服务商，需要您到您托管到的服务商进行解析管理操作。' : 'Domain shows delegated: delegated means your domain is hosted with a third-party provider, and DNS management must be performed at that provider.', [], true),
    cfclient_lang('cfclient.extras.tips.core.dnshe_ns', $isClientLanguageChinese ? '使用 DNSHE 自带解析服务时，DNS 服务器应填写为：%s。' : 'When using DNSHE built-in DNS service, set DNS servers to: %s.', [$whoisDefaultNsDisplay], true),
    cfclient_lang('cfclient.extras.tips.core.propagation', $isClientLanguageChinese ? '生效时间：DNS 记录新增、修改或删除通常在几分钟内完成生效，个别线路可能略有延迟。' : 'Propagation: DNS add/update/delete changes usually take effect within minutes, with occasional route delays.', [], true),
    cfclient_lang('cfclient.extras.tips.core.expiry_delete', $isClientLanguageChinese ? '域名到期删除问题：域名在到期前 180 天续期时间内未进行免费续期，会再次进入 30 天的宽限免费续期；宽限期后仍未续期的域名将被删除。' : 'Domain expiry deletion: if a domain is not renewed during the 180-day free-renewal window before expiry, it will enter another 30-day grace free-renewal period; domains still not renewed after grace will be deleted.', [], true),
    cfclient_lang('cfclient.extras.tips.core.delete_quota_return', $isClientLanguageChinese ? '域名删除额度还退回吗：域名到期删除和主动提交删除后会在约 1 小时左右返回注册额度，最多不超过 2 小时。' : 'Will deletion quota be returned: after expiry deletion or manual deletion submission, registration quota is typically returned in about 1 hour, and no more than 2 hours.', [], true),
    cfclient_lang('cfclient.extras.tips.core.expiry_reminder', $isClientLanguageChinese ? '域名到期提醒：域名到期前会有邮件和 Telegram 消息提醒（Telegram 需要在 功能中心 → Telegram 到期提醒 中开启）。' : 'Domain expiry reminder: before expiration, reminders are sent by email and Telegram (Telegram reminders must be enabled in Feature Center → Telegram Expiry Reminder).', [], true),
    cfclient_lang('cfclient.extras.tips.core.error', $isClientLanguageChinese ? '异常处理：若出现无法新增、删除或更新记录，请提交工单并附上域名与错误详情。' : 'Troubleshooting: If records cannot be added, removed, or updated, open a ticket with the domain and exact error details.', [], true),
    cfclient_lang('cfclient.extras.tips.core.default_ns_switch_fallback', $isClientLanguageChinese ? '若切换为系统默认 NS 失败，请先手动删除该域名下全部 DNS 记录后，再重新执行切换。' : 'If switching to system default NS fails, manually delete all DNS records under the domain first, then try switching again.', [], true),
    cfclient_lang('cfclient.extras.tips.core.renewal', $isClientLanguageChinese ? '域名续期：系统会在域名到期前 180 天内开放免费续期，可通过控制台或 API 一键续期。' : 'Domain renewal: free renewal opens within 180 days before expiration, and supports one-click renew via panel or API.', [], true),
];
$domainTips = [
    cfclient_lang('cfclient.extras.tips.domain.transfer', $isClientLanguageChinese ? '域名转赠：转赠成功后无法撤回，请在分享前确认接收方信息。' : 'Domain transfer: transfer actions cannot be reversed once completed. Verify recipient details before sharing.', [], true),
    cfclient_lang('cfclient.extras.tips.domain.content', $isClientLanguageChinese ? '合规要求：域名禁止用于违法违规内容，违规将触发封禁处理。' : 'Compliance: domains must not be used for illegal or abusive content. Violations can trigger suspension.', [], true),
    cfclient_lang($deleteTipKey, $deleteTipDefault, [], true),
];
$dnsTips = [
    cfclient_lang('cfclient.extras.tips.dns.root', $isClientLanguageChinese ? '@ 记录：表示当前完整域名本身，例如 blog.example.com。' : '@ record: represents the full current domain itself, e.g. blog.example.com.', [], true),
    cfclient_lang('cfclient.extras.tips.dns.conflict_cleanup', $isClientLanguageChinese ? '冲突清理：域名添加 DNS 解析时若提示已存在或冲突，可通过「功能中心 → 域名记录冲突清理」功能进行清理。' : 'Conflict cleanup: if adding DNS records reports “already exists” or conflict, use Feature Center → Domain DNS Conflict Cleanup to clear conflicts.', [], true),
    cfclient_lang('cfclient.extras.tips.dns.line', $isClientLanguageChinese ? '线路限制：部分根域名不支持按运营商或地域拆分返回不同记录。' : 'Line routing: some root domains do not support geo/carrier split routing responses.', [], true),
    cfclient_lang('cfclient.extras.tips.dns.caa_srv', $isClientLanguageChinese ? '参数建议：配置 SRV、CAA 等高级记录时，请完整填写所有字段并核对格式。' : 'Advanced records: for SRV/CAA and similar types, fill all fields and verify formatting carefully.', [], true),
];
$banAppealSubject = $isClientLanguageChinese ? '封禁申诉' : 'Ban Appeal';
$banAppealMessageBase = $isClientLanguageChinese
    ? '我的账号被封禁/停用。'
    : 'My account has been banned or disabled.';
$banAppealMessageTail = $isClientLanguageChinese ? '请协助核查并解除限制。' : 'Please review and lift the restriction.';
$banAppealReason = '';
if (!empty($banReasonText)) {
    $banAppealReason = '\n' . strip_tags($banReasonText);
}
$banAppealMessage = $banAppealMessageBase . $banAppealReason . '\n' . $banAppealMessageTail;
$supportEntries = [];
if (!empty($isUserBannedOrInactive) && $isUserBannedOrInactive) {
    $supportEntries[] = [
        'href' => 'submitticket.php?step=2&deptid=1&subject=' . urlencode($banAppealSubject) . '&message=' . urlencode($banAppealMessage),
        'icon' => 'far fa-flag',
        'label' => $extrasTexts['supportAppeal'],
        'desc' => $extrasTexts['supportAppealDesc'],
        'external' => false,
    ];
} else {
    $supportEntries[] = [
        'href' => $supportTicketUrl,
        'icon' => 'far fa-life-ring',
        'label' => $extrasTexts['supportTicket'],
        'desc' => $extrasTexts['supportTicketDesc'],
        'external' => true,
    ];
}
$supportEntries[] = [
    'href' => 'knowledgebase.php',
    'icon' => 'far fa-file-alt',
    'label' => $extrasTexts['supportKb'],
    'desc' => $extrasTexts['supportKbDesc'],
    'external' => false,
];
$supportEntries[] = [
    'href' => $supportGroupUrl,
    'icon' => 'far fa-comments',
    'label' => $extrasTexts['supportContact'],
    'desc' => $extrasTexts['supportContactDesc'],
    'external' => true,
];
$helpSections = [
    [
        'id' => 'core',
        'icon' => 'far fa-compass',
        'title' => $extrasTexts['coreTitle'],
        'items' => $coreTips,
        'expanded' => true,
    ],
    [
        'id' => 'domain',
        'icon' => 'far fa-clone',
        'title' => $extrasTexts['domainTitle'],
        'items' => $domainTips,
        'expanded' => false,
    ],
    [
        'id' => 'dns',
        'icon' => 'far fa-hdd',
        'title' => $extrasTexts['dnsTitle'],
        'items' => $dnsTips,
        'expanded' => false,
    ],
];
?>
<div class="cf-help-center mt-4">
    <div class="cf-help-search-wrap mb-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="position-relative flex-grow-1">
                <i class="fas fa-search cf-help-search-icon"></i>
                <input
                    type="search"
                    class="form-control cf-help-search-input"
                    id="cfHelpSearchInput"
                    placeholder="<?php echo $extrasTexts['searchPlaceholder']; ?>"
                    autocomplete="off"
                >
            </div>
            <?php if ($helpAiEnabled): ?>
                <button type="button" class="btn btn-primary" id="cfHelpAiOpenBtn" data-cf-help-ai-open="1">
                    <i class="fas fa-robot me-1"></i><?php echo $extrasTexts['aiButton']; ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card cf-help-knowledge-card">
        <div class="card-body p-4">
            <div class="d-flex align-items-center mb-3">
                <h6 class="mb-0 fw-semibold text-dark">
                    <i class="far fa-lightbulb me-2 text-primary"></i><?php echo $extrasTexts['tipsTitle']; ?>
                </h6>
            </div>

            <div class="cf-help-banner mb-3">
                <i class="fas fa-rocket me-2"></i><?php echo $extrasTexts['banner']; ?>
            </div>

            <div class="accordion cf-help-accordion" id="cfHelpAccordion">
                <?php foreach ($helpSections as $index => $section): ?>
                    <?php
                    $headingId = 'cfHelpHeading' . $index;
                    $collapseId = 'cfHelpCollapse' . $index;
                    $isExpanded = !empty($section['expanded']);
                    ?>
                    <div class="accordion-item cf-help-accordion-item" data-default-expanded="<?php echo $isExpanded ? '1' : '0'; ?>">
                        <h2 class="accordion-header" id="<?php echo $headingId; ?>">
                            <button
                                class="accordion-button <?php echo $isExpanded ? '' : 'collapsed'; ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?php echo $collapseId; ?>"
                                aria-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>"
                                aria-controls="<?php echo $collapseId; ?>"
                            >
                                <span class="cf-help-accordion-icon"><i class="<?php echo htmlspecialchars($section['icon'], ENT_QUOTES); ?>"></i></span>
                                <span><?php echo $section['title']; ?></span>
                            </button>
                        </h2>
                        <div
                            id="<?php echo $collapseId; ?>"
                            class="accordion-collapse collapse <?php echo $isExpanded ? 'show' : ''; ?>"
                            aria-labelledby="<?php echo $headingId; ?>"
                            data-bs-parent="#cfHelpAccordion"
                        >
                            <div class="accordion-body pt-2 pb-3">
                                <ul class="cf-help-list mb-0">
                                    <?php foreach ($section['items'] as $item): ?>
                                        <li class="cf-help-item">
                                            <span class="cf-help-item-icon"><i class="far fa-circle"></i></span>
                                            <span class="cf-help-item-text"><?php echo $item; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="cf-help-search-empty d-none" id="cfHelpSearchEmpty">
                <?php echo $extrasTexts['searchEmpty']; ?>
            </div>
        </div>
    </div>

    <div class="card cf-help-support-card mt-4 mb-4">
        <div class="card-body p-4">
            <h6 class="mb-2 fw-semibold text-dark"><i class="far fa-life-ring me-2 text-primary"></i><?php echo $extrasTexts['supportTitle']; ?></h6>
            <p class="text-muted small mb-3"><?php echo $extrasTexts['supportBody']; ?></p>
            <div class="row g-3">
                <?php foreach ($supportEntries as $entry): ?>
                    <div class="col-md-4">
                        <a
                            href="<?php echo htmlspecialchars($entry['href'], ENT_QUOTES); ?>"
                            class="cf-help-support-entry"
                            <?php if (!empty($entry['external'])): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>
                        >
                            <span class="cf-help-support-icon"><i class="<?php echo htmlspecialchars($entry['icon'], ENT_QUOTES); ?>"></i></span>
                            <span class="cf-help-support-label"><?php echo $entry['label']; ?></span>
                            <span class="cf-help-support-desc"><?php echo $entry['desc']; ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
