<?php if (!empty($helpAiSearchEnabled)): ?>
<?php
$clientLanguageCode = isset($currentClientLanguage) ? strtolower((string) $currentClientLanguage) : 'english';
$isClientLanguageChinese = $clientLanguageCode === 'chinese';
$helpAiEnabled = !empty($helpAiSearchEnabled);
$helpAiAssistantDisplayName = trim((string) ($helpAiAssistantName ?? 'AI 助手'));
if ($helpAiAssistantDisplayName === '') {
    $helpAiAssistantDisplayName = $isClientLanguageChinese ? 'AI 助手' : 'AI Assistant';
}
$helpAiMaxChars = max(200, min(2000, intval($helpAiMaxInputChars ?? 600)));
$helpAiTexts = [
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
    'aiEscalateTicket' => cfclient_lang('cfclient.extras.ai.escalate_ticket', $isClientLanguageChinese ? '升级为工单处理' : 'Escalate to Ticket', [], true),
    'aiConfidenceLabel' => cfclient_lang('cfclient.extras.ai.confidence', $isClientLanguageChinese ? '置信度' : 'Confidence', [], true),
    'aiCopyDiagPack' => cfclient_lang('cfclient.extras.ai.copy_diag', $isClientLanguageChinese ? '复制全部 dig 命令' : 'Copy dig diagnostics', [], true),
    'aiChipDelegatedMeaning' => cfclient_lang('cfclient.extras.ai.chip_delegated_meaning', $isClientLanguageChinese ? '域名显示已委派是什么意思？' : 'What does “delegated” mean for my domain?', [], true),
    'aiChipQuotaIncrease' => cfclient_lang('cfclient.extras.ai.chip_quota_increase', $isClientLanguageChinese ? '怎么增加账户的域名注册额度？' : 'How can I increase my domain registration quota?', [], true),
    'aiCopied' => cfclient_lang('cfclient.extras.ai.copied', $isClientLanguageChinese ? '已复制诊断命令' : 'Diagnostics copied', [], true),
    'aiCopyFailed' => cfclient_lang('cfclient.extras.ai.copy_failed', $isClientLanguageChinese ? '复制失败，请手动复制。' : 'Copy failed, please copy manually.', [], true),
    'aiChipPromptDelegatedMeaning' => cfclient_lang('cfclient.extras.ai.chip_prompt_delegated_meaning', $isClientLanguageChinese ? '域名显示已委派是什么意思？' : 'What does “delegated” mean for my domain?', [], true),
    'aiChipPromptQuotaIncrease' => cfclient_lang('cfclient.extras.ai.chip_prompt_quota_increase', $isClientLanguageChinese ? '怎么增加账户的域名注册额度？' : 'How can I increase my domain registration quota?', [], true),
    'aiChipAccountQuota' => cfclient_lang('cfclient.extras.ai.chip_account_quota', $isClientLanguageChinese ? '查询我的注册额度' : 'Check my registration quota', [], true),
    'aiChipAccountExpiry' => cfclient_lang('cfclient.extras.ai.chip_account_expiry', $isClientLanguageChinese ? '查询我最近到期域名' : 'Check my upcoming expiring domains', [], true),
    'aiChipPromptAccountQuota' => cfclient_lang('cfclient.extras.ai.chip_prompt_account_quota', $isClientLanguageChinese ? '请查询我的注册额度（已使用、总额度、剩余额度）。' : 'Please show my registration quota (used, total, and remaining).', [], true),
    'aiChipPromptAccountExpiry' => cfclient_lang('cfclient.extras.ai.chip_prompt_account_expiry', $isClientLanguageChinese ? '请查询我最近到期的域名列表。' : 'Please show my recently expiring domain list.', [], true),
    'aiChipDnsHealth' => cfclient_lang('cfclient.extras.ai.chip_dns_health', $isClientLanguageChinese ? '查询域名DNS健康摘要' : 'Check domain DNS health', [], true),
    'aiChipApiSummary' => cfclient_lang('cfclient.extras.ai.chip_api_summary', $isClientLanguageChinese ? '查询我的API密钥状态' : 'Check my API key status', [], true),
    'aiChipPromptDnsHealth' => cfclient_lang('cfclient.extras.ai.chip_prompt_dns_health', $isClientLanguageChinese ? '请帮我检查 www.example.com 的 DNS 健康摘要（A/AAAA/CNAME、最近修改时间、冲突风险和下一步建议）。' : 'Please check DNS health summary for www.example.com (A/AAAA/CNAME, last update time, conflict risk, and next steps).', [], true),
    'aiChipPromptDnsHealthWithDomain' => cfclient_lang('cfclient.extras.ai.chip_prompt_dns_health_domain', $isClientLanguageChinese ? '请帮我检查 %s 的 DNS 健康摘要（A/AAAA/CNAME、最近修改时间、冲突风险和下一步建议）。' : 'Please check DNS health summary for %s (A/AAAA/CNAME, last update time, conflict risk, and next steps).', [], true),
    'aiChipPromptApiSummary' => cfclient_lang('cfclient.extras.ai.chip_prompt_api_summary', $isClientLanguageChinese ? '请查询我的 API 密钥状态摘要（总数、启停、最近操作、是否达到创建上限、权限元数据）。' : 'Please show my API key status summary (total, active/disabled, latest operation, create limit reached, and permission metadata).', [], true),
    'aiPromptDomainInputTitle' => cfclient_lang('cfclient.extras.ai.prompt_domain_title', $isClientLanguageChinese ? '请输入要查询的完整域名（例如：www.example.com）' : 'Please enter the full domain to check (e.g. www.example.com)', [], true),
    'aiPromptDomainInvalid' => cfclient_lang('cfclient.extras.ai.prompt_domain_invalid', $isClientLanguageChinese ? '域名格式不正确，请输入完整域名后重试。' : 'Invalid domain format. Please enter a full domain and try again.', [], true),
    'aiDisclaimer' => cfclient_lang('cfclient.extras.ai.disclaimer', $isClientLanguageChinese ? 'AI 助手的回复仅供参考，如遇无法解决问题请提交工单获取帮助。' : 'AI assistant responses are for reference only. If your issue is not resolved, please submit a support ticket for help.', [], true),
];
?>
<div class="modal fade" id="cfHelpAiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-robot text-primary me-2"></i><?php echo $helpAiTexts['aiModalTitle']; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3"><?php echo $helpAiTexts['aiModalHint']; ?></p>
                <div class="border rounded p-2 bg-light" id="cfHelpAiMessages" style="max-height: 360px; overflow: auto;"></div>
                <div class="text-danger small mt-2 d-none" id="cfHelpAiError"></div>
                <div class="d-flex flex-wrap gap-2 mt-2" id="cfHelpAiChips">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ai-chip="delegated_meaning"><?php echo $helpAiTexts['aiChipDelegatedMeaning']; ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ai-chip="quota_increase"><?php echo $helpAiTexts['aiChipQuotaIncrease']; ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ai-chip="account_quota"><?php echo $helpAiTexts['aiChipAccountQuota']; ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ai-chip="account_expiry"><?php echo $helpAiTexts['aiChipAccountExpiry']; ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ai-chip="dns_health"><?php echo $helpAiTexts['aiChipDnsHealth']; ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-ai-chip="api_summary"><?php echo $helpAiTexts['aiChipApiSummary']; ?></button>
                </div>
            </div>
            <div class="modal-footer">
                <div class="w-100">
                    <div class="input-group">
                        <input type="text" class="form-control" id="cfHelpAiInput" maxlength="<?php echo intval($helpAiMaxChars); ?>" placeholder="<?php echo $helpAiTexts['aiInputPlaceholder']; ?>">
                        <button type="button" class="btn btn-primary" id="cfHelpAiSendBtn"><?php echo $helpAiTexts['aiSend']; ?></button>
                    </div>
                    <div class="small fw-semibold mt-2" style="color:#b45309;"><?php echo $helpAiTexts['aiDisclaimer']; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var aiEnabled = <?php echo $helpAiEnabled ? 'true' : 'false'; ?>;
    if (!aiEnabled) { return; }

    var modalEl = document.getElementById('cfHelpAiModal');
    var messagesEl = document.getElementById('cfHelpAiMessages');
    var errorEl = document.getElementById('cfHelpAiError');
    var inputEl = document.getElementById('cfHelpAiInput');
    var sendBtn = document.getElementById('cfHelpAiSendBtn');
    var chipsEl = document.getElementById('cfHelpAiChips');
    var triggerSelector = '[data-cf-help-ai-open="1"]';
    var modal = null, history = [], busy = false;
    var maxChars = <?php echo intval($helpAiMaxChars); ?>;
    if (!modalEl || !messagesEl || !inputEl || !sendBtn) { return; }

    if (modalEl.parentNode !== document.body) { document.body.appendChild(modalEl); }

    var text = {
        assistantName: <?php echo json_encode($helpAiAssistantDisplayName, JSON_UNESCAPED_UNICODE); ?>,
        userName: <?php echo json_encode($helpAiTexts['aiUserLabel'], JSON_UNESCAPED_UNICODE); ?>,
        welcome: <?php echo json_encode($helpAiTexts['aiWelcome'], JSON_UNESCAPED_UNICODE); ?>,
        emptyQuestion: <?php echo json_encode($helpAiTexts['aiEmptyQuestion'], JSON_UNESCAPED_UNICODE); ?>,
        tooLong: <?php echo json_encode($helpAiTexts['aiTooLong'], JSON_UNESCAPED_UNICODE); ?>,
        requestFailed: <?php echo json_encode($helpAiTexts['aiRequestFailed'], JSON_UNESCAPED_UNICODE); ?>,
        send: <?php echo json_encode($helpAiTexts['aiSend'], JSON_UNESCAPED_UNICODE); ?>,
        thinking: <?php echo json_encode($helpAiTexts['aiThinking'], JSON_UNESCAPED_UNICODE); ?>,
        copyDiag: <?php echo json_encode($helpAiTexts['aiCopyDiagPack'], JSON_UNESCAPED_UNICODE); ?>,
        copied: <?php echo json_encode($helpAiTexts['aiCopied'], JSON_UNESCAPED_UNICODE); ?>,
        copyFailed: <?php echo json_encode($helpAiTexts['aiCopyFailed'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptDelegatedMeaning: <?php echo json_encode($helpAiTexts['aiChipPromptDelegatedMeaning'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptQuotaIncrease: <?php echo json_encode($helpAiTexts['aiChipPromptQuotaIncrease'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptAccountQuota: <?php echo json_encode($helpAiTexts['aiChipPromptAccountQuota'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptAccountExpiry: <?php echo json_encode($helpAiTexts['aiChipPromptAccountExpiry'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptDnsHealthWithDomain: <?php echo json_encode($helpAiTexts['aiChipPromptDnsHealthWithDomain'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptDnsHealth: <?php echo json_encode($helpAiTexts['aiChipPromptDnsHealth'], JSON_UNESCAPED_UNICODE); ?>,
        chipPromptApiSummary: <?php echo json_encode($helpAiTexts['aiChipPromptApiSummary'], JSON_UNESCAPED_UNICODE); ?>,
        promptDomainInputTitle: <?php echo json_encode($helpAiTexts['aiPromptDomainInputTitle'], JSON_UNESCAPED_UNICODE); ?>,
        promptDomainInvalid: <?php echo json_encode($helpAiTexts['aiPromptDomainInvalid'], JSON_UNESCAPED_UNICODE); ?>
    };
    var moduleSlug = <?php echo json_encode($moduleSlug ?? (defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub'), JSON_UNESCAPED_UNICODE); ?>;

    function escapeHtml(v){return String(v||'').replace(/[&<>"']/g,function(ch){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];});}
    function showError(msg){var m=String(msg||'').trim(); if(!errorEl)return; if(!m){errorEl.classList.add('d-none');errorEl.textContent='';return;} errorEl.textContent=m;errorEl.classList.remove('d-none');}
    function setBusy(state){busy=!!state; sendBtn.disabled=busy; inputEl.disabled=busy; sendBtn.textContent=busy?text.thinking:text.send;}
    function ensureModal(){ if(!window.bootstrap||!bootstrap.Modal){return null;} if(!modal){modal=bootstrap.Modal.getOrCreateInstance(modalEl);} return modal; }
    function buildUrl(){ return (typeof cfClientBuildModuleUrl==='function') ? cfClientBuildModuleUrl('ajax_help_ai_search') : ('index.php?m='+encodeURIComponent(moduleSlug)+'&module_action=ajax_help_ai_search'); }

    function renderMessage(role, content, meta){
        meta = meta || {};
        var row=document.createElement('div'); row.className='mb-2';
        var badgeClass=role==='assistant'?'bg-primary':'bg-secondary';
        var roleName=role==='assistant'?text.assistantName:text.userName;
        var metaHtml='';
        if(role==='assistant' && typeof meta.confidence==='number'){
            var pct=Math.max(0,Math.min(99,Math.round(meta.confidence*100)));
            metaHtml += '<div class="small text-muted mt-1">'+escapeHtml(<?php echo json_encode($helpAiTexts['aiConfidenceLabel'], JSON_UNESCAPED_UNICODE); ?>)+': '+pct+'%</div>';
        }
        if(role==='assistant' && meta.should_escalate && meta.escalation_url){
            metaHtml += '<div class="mt-2"><a class="btn btn-sm btn-outline-danger" href="'+escapeHtml(meta.escalation_url)+'" target="_blank" rel="noopener noreferrer"><?php echo addslashes($helpAiTexts['aiEscalateTicket']); ?></a></div>';
        }
        if(role==='assistant' && meta.diagnostic_pack){
            metaHtml += '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-primary" data-copy-diag="1" data-diag="'+escapeHtml(meta.diagnostic_pack)+'">'+escapeHtml(text.copyDiag)+'</button></div>';
        }
        if(role==='assistant' && meta.sensitive_notice){
            metaHtml += '<div class="alert alert-warning py-1 px-2 small mt-2 mb-0">'+escapeHtml(meta.sensitive_notice)+'</div>';
        }
        row.innerHTML='<div class="small mb-1"><span class="badge '+badgeClass+'">'+escapeHtml(roleName)+'</span></div><div class="border rounded bg-white p-2 small" style="white-space: pre-wrap;">'+escapeHtml(content)+'</div>'+metaHtml;
        messagesEl.appendChild(row); messagesEl.scrollTop=messagesEl.scrollHeight;
    }

    function sendQuestion(){
        if(busy){return;} var question=String(inputEl.value||'').trim();
        if(!question){showError(text.emptyQuestion);return;}
        if(question.length>maxChars){showError(text.tooLong);return;}
        showError(''); renderMessage('user',question); history.push({role:'user',content:question}); inputEl.value=''; setBusy(true);
        fetch(buildUrl(),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CF_MOD_CSRF||''},body:JSON.stringify({query:question,history:history.slice(-8)})})
        .then(function(r){
            var contentType = String(r.headers.get('content-type') || '').toLowerCase();
            if (contentType.indexOf('application/json') !== -1) {
                return r.json();
            }
            return r.text().then(function(raw){
                var msg = String(raw || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                if (!msg) { msg = text.requestFailed; }
                return { success: false, error: msg };
            });
        }).then(function(res){
            if(res&&res.success&&res.data&&res.data.answer){
                var answer=String(res.data.answer||'').trim();
                if(answer!==''){
                    renderMessage('assistant',answer,{confidence:Number(res.data.confidence||0),should_escalate:!!res.data.should_escalate,escalation_url:String(res.data.escalation_url||''),sensitive_notice:String(res.data.sensitive_notice||''),diagnostic_pack:String(res.data.diagnostic_pack||'')});
                    history.push({role:'assistant',content:answer}); if(history.length>12){history=history.slice(-12);} return;
                }
            }
            showError((res&&res.error)?res.error:text.requestFailed);
        }).catch(function(){showError(text.requestFailed);}).finally(function(){setBusy(false); inputEl.focus();});
    }

    function openModal(){ var inst=ensureModal(); if(inst){inst.show();} if(!messagesEl.dataset.initialized){renderMessage('assistant',text.welcome); messagesEl.dataset.initialized='1';} inputEl.focus(); }

    document.querySelectorAll(triggerSelector).forEach(function(t){ t.addEventListener('click',function(e){e.preventDefault();openModal();}); });
    sendBtn.addEventListener('click', sendQuestion);
    inputEl.addEventListener('keydown', function(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendQuestion();} });

    if(chipsEl){
        chipsEl.addEventListener('click', function(e){
            var chip=e.target&&e.target.getAttribute('data-ai-chip'); if(!chip){return;}
            if(chip==='dns_health'){
                var entered = window.prompt(text.promptDomainInputTitle, '');
                if(entered===null){ return; }
                var normalized = String(entered||'').trim().toLowerCase().replace(/\.$/, '');
                if(!/^[a-z0-9][a-z0-9\-]{0,62}(?:\.[a-z0-9][a-z0-9\-]{0,62})+$/.test(normalized)){
                    showError(text.promptDomainInvalid);
                    inputEl.focus();
                    return;
                }
                inputEl.value = text.chipPromptDnsHealthWithDomain.replace('%s', normalized);
                showError('');
                inputEl.focus();
                return;
            }
            var map={
                delegated_meaning:text.chipPromptDelegatedMeaning,
                quota_increase:text.chipPromptQuotaIncrease,
                account_quota:text.chipPromptAccountQuota,
                account_expiry:text.chipPromptAccountExpiry,
                dns_health:text.chipPromptDnsHealth,
                api_summary:text.chipPromptApiSummary
            };
            inputEl.value=map[chip]||''; inputEl.focus();
        });
    }

    messagesEl.addEventListener('click', function(e){
        var btn=e.target&&e.target.closest('[data-copy-diag="1"]'); if(!btn){return;}
        var payload=String(btn.getAttribute('data-diag')||''); if(!payload){return;}
        var done=function(){showError(text.copied); setTimeout(function(){ if(errorEl&&errorEl.textContent===text.copied){showError('');}},1200); };
        if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(payload).then(done).catch(function(){showError(text.copyFailed);}); return; }
        try { var ta=document.createElement('textarea'); ta.value=payload; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); } catch(err){ showError(text.copyFailed); }
    });

    modalEl.addEventListener('hidden.bs.modal', function(){
        try { document.querySelectorAll('.modal-backdrop').forEach(function(el){el.remove();}); document.body.classList.remove('modal-open'); document.body.style.removeProperty('overflow'); document.body.style.removeProperty('padding-right'); } catch(e){}
    });

    try { var u=new URL(window.location.href); if(u.searchParams.get('open_help_ai')==='1'){ openModal(); u.searchParams.delete('open_help_ai'); if(window.history&&window.history.replaceState){window.history.replaceState({},document.title,u.toString());}} } catch(e){}
})();
</script>
<?php endif; ?>
