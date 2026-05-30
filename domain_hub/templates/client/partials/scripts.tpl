    <!-- Bootstrap JS -->
    <script src="<?php echo htmlspecialchars($cfmodAssetsBase . '/js/bootstrap.bundle.min.js', ENT_QUOTES); ?>"></script>

    <script>
        window.CF_CLIENT_LANG = <?php echo json_encode($cfClientJsLang ?? [], CFMOD_SAFE_JSON_FLAGS); ?>;
        window.CF_CLIENT_CONFIG = <?php echo json_encode([
            'disableNsManagement' => !empty($disableNsManagement),
            'domainGiftEnabled' => !empty($domainGiftEnabled),
            'quotaRedeemEnabled' => !empty($quotaRedeemEnabled),
            'moduleSlug' => $moduleSlug,
            'clientEntryScript' => $cfClientEntryScript ?? 'index.php',
            'clientBaseQuery' => $cfClientBaseEntryQuery ?? ['m' => $moduleSlug],
            'moduleActionParam' => 'module_action',
        ], CFMOD_SAFE_JSON_FLAGS); ?>;
    </script>
    <script src="<?php echo htmlspecialchars($cfmodAssetsBase . '/js/client.js?v=1', ENT_QUOTES); ?>"></script>

    <script>
        if (window.CFClient) {
            CFClient.setLangMap(window.CF_CLIENT_LANG || {});
            CFClient.setConfig(window.CF_CLIENT_CONFIG || {});
            CFClient.bootstrap();
        }

        function cfClientResolveRouteConfig() {
            var cfg = window.CF_CLIENT_CONFIG || {};
            var script = (typeof cfg.clientEntryScript === 'string' && cfg.clientEntryScript !== '') ? cfg.clientEntryScript : 'index.php';
            var baseQuery = (cfg.clientBaseQuery && Object(cfg.clientBaseQuery) === cfg.clientBaseQuery)
                ? cfg.clientBaseQuery
                : { m: (cfg.moduleSlug || 'domain_hub') };
            var actionParam = (typeof cfg.moduleActionParam === 'string' && cfg.moduleActionParam !== '')
                ? cfg.moduleActionParam
                : 'module_action';
            return {
                script: script,
                baseQuery: baseQuery,
                actionParam: actionParam
            };
        }

        function cfClientBuildModuleUrl(action, extraParams) {
            var route = cfClientResolveRouteConfig();
            var params = new URLSearchParams();

            Object.keys(route.baseQuery).forEach(function(key) {
                var value = route.baseQuery[key];
                if (value === null || typeof value === 'undefined') {
                    return;
                }
                params.set(key, String(value));
            });

            if (action) {
                params.set(route.actionParam, String(action));
            }

            if (extraParams && Object(extraParams) === extraParams) {
                Object.keys(extraParams).forEach(function(key) {
                    var value = extraParams[key];
                    if (value === null || typeof value === 'undefined') {
                        return;
                    }
                    params.set(key, String(value));
                });
            }

            return route.script + '?' + params.toString();
        }

        function confirmOrphanCleanupSubmission() {
            var select = document.getElementById('orphanCleanupSubdomainSelect');
            var confirmInput = document.getElementById('orphanCleanupConfirmDomainInput');
            if (!select || !confirmInput) {
                return false;
            }
            var selectedOption = select.options[select.selectedIndex];
            var selectedDomain = selectedOption ? String(selectedOption.getAttribute('data-domain') || selectedOption.text || '').trim().toLowerCase() : '';
            var typedDomain = String(confirmInput.value || '').trim().toLowerCase();
            if (!selectedDomain) {
                alert(cfLang('orphanCleanupSelectRequired', '请先选择要清理的域名'));
                return false;
            }
            if (selectedDomain !== typedDomain) {
                alert(cfLang('orphanCleanupConfirmMismatch', '手动输入的域名与下拉选择不一致，请确认后重试'));
                return false;
            }
            return confirm(cfLang('orphanCleanupDangerConfirm', '提交后会删除所选域名全部DNS记录，请谨慎操作。是否继续？'));
        }
        function showOrphanDnsCleanupModal() {
            var modalEl = document.getElementById('orphanDnsCleanupModal');
            if (!modalEl || typeof bootstrap === 'undefined') {
                return;
            }
            var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            instance.show();
        }
        window.showOrphanDnsCleanupModal = showOrphanDnsCleanupModal;
        (function setupOrphanCleanupRemoteSearch() {
            var searchInput = document.getElementById('orphanCleanupDomainSearchInput');
            var select = document.getElementById('orphanCleanupSubdomainSelect');
            var statusEl = document.getElementById('orphanCleanupSearchStatus');
            var paginationEl = document.getElementById('orphanCleanupPagination');
            var prevBtn = document.getElementById('orphanCleanupPrevPage');
            var nextBtn = document.getElementById('orphanCleanupNextPage');
            var pageInfo = document.getElementById('orphanCleanupPageInfo');
            var modalEl = document.getElementById('orphanDnsCleanupModal');
            if (!searchInput || !select) {
                return;
            }
            var state = {
                page: 1,
                totalPages: 1,
                keyword: '',
                loading: false,
                loadedOnce: false,
                debounceTimer: null,
                requestSeq: 0
            };
            function setStatus(message, isError) {
                if (!statusEl) { return; }
                statusEl.textContent = message || '';
                statusEl.classList.toggle('text-danger', !!isError);
                statusEl.classList.toggle('text-muted', !isError);
            }
            function setLoading(loading) {
                state.loading = !!loading;
                searchInput.disabled = !!loading && !state.loadedOnce;
                select.disabled = !!loading;
                if (prevBtn) { prevBtn.disabled = !!loading || state.page <= 1; }
                if (nextBtn) { nextBtn.disabled = !!loading || state.page >= state.totalPages; }
            }
            function clearOptions(placeholder) {
                select.innerHTML = '';
                var option = document.createElement('option');
                option.value = '';
                option.textContent = placeholder || cfLang('orphanCleanupSelectPlaceholder', '请选择要清理的域名');
                select.appendChild(option);
            }
            function renderPagination() {
                var visible = state.totalPages > 1;
                if (paginationEl) { paginationEl.style.display = visible ? 'flex' : 'none'; }
                if (pageInfo) {
                    pageInfo.textContent = visible
                        ? cfLangFormat('orphanCleanupPageInfo', '第 %1$s / %2$s 页', state.page, state.totalPages)
                        : '';
                }
                if (prevBtn) { prevBtn.disabled = state.loading || state.page <= 1; }
                if (nextBtn) { nextBtn.disabled = state.loading || state.page >= state.totalPages; }
            }
            function renderRows(rows) {
                clearOptions(cfLang('orphanCleanupSelectPlaceholder', '请选择要清理的域名'));
                rows.forEach(function(row){
                    var id = parseInt(row.id || 0, 10) || 0;
                    var domain = String(row.fullDomain || row.subdomain || row.domain || '').trim();
                    if (!id || !domain) { return; }
                    var option = document.createElement('option');
                    option.value = String(id);
                    option.textContent = domain;
                    option.setAttribute('data-domain', domain.toLowerCase());
                    select.appendChild(option);
                });
                if (select.options.length > 1) {
                    select.selectedIndex = 1;
                }
            }
            function loadDomains(page, keyword) {
                var nextPage = Math.max(1, parseInt(page || 1, 10) || 1);
                var nextKeyword = String(typeof keyword === 'string' ? keyword : state.keyword).trim().toLowerCase();
                var requestId = ++state.requestSeq;
                state.keyword = nextKeyword;
                setLoading(true);
                setStatus(cfLang('orphanCleanupLoading', '域名列表加载中...'), false);
                return fetch(cfClientBuildModuleUrl('ajax_search_orphan_cleanup_subdomains'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CF_MOD_CSRF || ''
                    },
                    body: JSON.stringify({ q: nextKeyword, page: nextPage, per_page: 20 })
                }).then(function(res){ return res.json(); }).then(function(res){
                    if (requestId !== state.requestSeq) { return; }
                    if (!res || !res.success) {
                        throw new Error((res && res.error) ? res.error : cfLang('orphanCleanupLoadFailed', '加载域名列表失败'));
                    }
                    var rows = Array.isArray(res.data) ? res.data : [];
                    var pagination = res.pagination || {};
                    state.page = Math.max(1, parseInt(pagination.page || nextPage, 10) || 1);
                    state.totalPages = Math.max(1, parseInt(pagination.total_pages || 1, 10) || 1);
                    state.loadedOnce = true;
                    renderRows(rows);
                    renderPagination();
                    if (rows.length === 0) {
                        clearOptions(cfLang('orphanCleanupNoMatch', '未找到匹配域名'));
                        setStatus(cfLang('orphanCleanupNoMatch', '未找到匹配域名'), false);
                    } else {
                        setStatus(cfLangFormat('orphanCleanupLoaded', '已加载 %1$s 个域名候选', rows.length), false);
                    }
                }).catch(function(err){
                    if (requestId !== state.requestSeq) { return; }
                    clearOptions(cfLang('orphanCleanupLoadFailed', '加载域名列表失败'));
                    renderPagination();
                    setStatus(err && err.message ? err.message : cfLang('networkError', '网络异常，请稍后再试'), true);
                }).finally(function(){
                    if (requestId === state.requestSeq) {
                        setLoading(false);
                    }
                });
            }
            searchInput.addEventListener('input', function(){
                window.clearTimeout(state.debounceTimer);
                state.debounceTimer = window.setTimeout(function(){
                    loadDomains(1, searchInput.value || '');
                }, 300);
            });
            if (prevBtn) {
                prevBtn.addEventListener('click', function(){
                    if (state.page > 1) { loadDomains(state.page - 1, state.keyword); }
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function(){
                    if (state.page < state.totalPages) { loadDomains(state.page + 1, state.keyword); }
                });
            }
            if (modalEl) {
                modalEl.addEventListener('shown.bs.modal', function(){
                    if (!state.loadedOnce) {
                        loadDomains(1, searchInput.value || '');
                    }
                });
            }
            clearOptions(cfLang('orphanCleanupSelectPlaceholder', '请选择要清理的域名'));
            renderPagination();
        })();

        const ROOT_LIMIT_MAP = <?php echo json_encode($rootLimitMap, CFMOD_SAFE_JSON_FLAGS); ?>;
        const ROOT_INVITE_REQUIRED_MAP = <?php echo json_encode($rootInviteRequiredMap ?? [], CFMOD_SAFE_JSON_FLAGS); ?>;
        const rootLimitHint = document.getElementById('register_limit_hint');
        const REGISTER_FORBIDDEN_PREFIXES = <?php echo json_encode(array_map('strtolower', (array) $forbidden), CFMOD_SAFE_JSON_FLAGS); ?>;
const dnsUnlockFeatureEnabled = <?php echo !empty($dnsUnlockFeatureEnabled) ? 'true' : 'false'; ?>;
const dnsUnlockRequired = dnsUnlockFeatureEnabled && <?php echo !empty($dnsUnlockRequired) ? 'true' : 'false'; ?>;

        // 注册根域名后缀实时显示
        const rootSelect = document.getElementById('register_rootdomain');
        const rootSuffix = document.getElementById('register_root_suffix');
        const inviteCodeContainer = document.getElementById('rootdomain_invite_code_container');
        const inviteCodeInput = document.getElementById('rootdomain_invite_code_input');
        const registerPrefixInput = document.getElementById('register_subdomain_prefix') || document.querySelector('input[name="subdomain"]');
        const registerAvailabilityHint = document.getElementById('register_availability_hint');
        let registerAvailabilityTimer = null;
        let registerAvailabilitySeq = 0;
        const registerAvailabilityCache = {};

        function setRegisterAvailabilityHint(level, message) {
            if (!registerAvailabilityHint) { return; }
            registerAvailabilityHint.className = 'form-text mt-1';
            ['text-muted','text-success','text-danger','text-warning','text-info'].forEach(function(cls){
                registerAvailabilityHint.classList.remove(cls);
            });
            if (!message) {
                registerAvailabilityHint.textContent = '';
                return;
            }
            const map = { success: 'text-success', danger: 'text-danger', warning: 'text-warning', info: 'text-info', muted: 'text-muted' };
            registerAvailabilityHint.classList.add(map[level] || 'text-muted');
            registerAvailabilityHint.textContent = message;
        }

        function runLocalRegisterAvailabilityPrecheck(prefix, rootdomain) {
            const minLen = registerPrefixInput ? parseInt(registerPrefixInput.getAttribute('minlength') || '0', 10) : 0;
            const maxLen = registerPrefixInput ? parseInt(registerPrefixInput.getAttribute('maxlength') || '255', 10) : 255;
            if (!rootdomain) {
                return { stop: true, level: 'muted', message: cfLang('registerAvailabilitySelectRoot', 'Please select a root domain first') };
            }
            if (!prefix) {
                return { stop: true, level: 'muted', message: '' };
            }
            if (prefix.length < minLen) {
                return { stop: true, level: 'muted', message: cfLangFormat('registerAvailabilityMinLength', 'Enter at least %s characters to check', minLen) };
            }
            if (prefix.length > maxLen) {
                return { stop: true, level: 'danger', message: cfLangFormat('registerAvailabilityMaxLength', 'Prefix cannot exceed %s characters', maxLen) };
            }
            if (!/^[a-zA-Z0-9-]+$/.test(prefix)) {
                return { stop: true, level: 'danger', message: cfLang('registerAvailabilityInvalidChars', 'Only letters, digits, and hyphens are allowed') };
            }
            if (prefix.startsWith('.') || prefix.startsWith('-') || prefix.endsWith('.') || prefix.endsWith('-')) {
                return { stop: true, level: 'danger', message: cfLang('registerEdgeError', '域名前缀不能以 "." 或 "-" 开头或结尾') };
            }
            if (REGISTER_FORBIDDEN_PREFIXES.includes(prefix.toLowerCase())) {
                return { stop: true, level: 'danger', message: cfLang('registerForbiddenPrefix', '该前缀被禁止使用，请选择其他前缀') };
            }
            const inviteRequired = ROOT_INVITE_REQUIRED_MAP[String(rootdomain || '').toLowerCase()] || false;
            const inviteCodeValue = inviteCodeInput ? inviteCodeInput.value.trim() : '';
            if (inviteRequired && !inviteCodeValue) {
                return { stop: true, level: 'warning', message: cfLang('registerAvailabilityInviteRequired', 'This root domain requires an invite code. Enter it to continue checking') };
            }
            return { stop: false };
        }

        function scheduleRegisterAvailabilityCheck() {
            if (!registerPrefixInput || !rootSelect || !registerAvailabilityHint) { return; }
            if (registerAvailabilityTimer) { clearTimeout(registerAvailabilityTimer); }
            const prefix = registerPrefixInput.value.trim();
            const rootdomain = rootSelect.value.trim();
            const local = runLocalRegisterAvailabilityPrecheck(prefix, rootdomain);
            if (local.stop) {
                setRegisterAvailabilityHint(local.level, local.message);
                return;
            }
            setRegisterAvailabilityHint('muted', cfLang('registerAvailabilityWaiting', 'Waiting for input to finish before checking...'));
            registerAvailabilityTimer = setTimeout(function(){
                const currentPrefix = registerPrefixInput.value.trim();
                const currentRoot = rootSelect.value.trim();
                const currentInvite = inviteCodeInput ? inviteCodeInput.value.trim() : '';
                const precheck = runLocalRegisterAvailabilityPrecheck(currentPrefix, currentRoot);
                if (precheck.stop) {
                    setRegisterAvailabilityHint(precheck.level, precheck.message);
                    return;
                }
                const cacheKey = [currentRoot.toLowerCase(), currentPrefix.toLowerCase(), currentInvite.toUpperCase()].join('|');
                const cached = registerAvailabilityCache[cacheKey];
                const now = Date.now();
                if (cached && cached.expiresAt > now) {
                    setRegisterAvailabilityHint(cached.level, cached.message);
                    return;
                }
                const seq = ++registerAvailabilitySeq;
                setRegisterAvailabilityHint('info', cfLang('registerAvailabilityChecking', 'Checking availability...'));
                fetch(cfClientBuildModuleUrl('ajax_check_subdomain_availability'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CF_MOD_CSRF || ''
                    },
                    body: JSON.stringify({
                        subdomain: currentPrefix,
                        rootdomain: currentRoot,
                        rootdomain_invite_code: currentInvite
                    })
                }).then(function(res){ return res.json(); }).then(function(res){
                    if (seq !== registerAvailabilitySeq) { return; }
                    const data = res && res.data ? res.data : res;
                    const level = data && data.level ? data.level : (res && res.success ? 'muted' : 'warning');
                    const message = data && data.message ? data.message : (res && res.error ? res.error : cfLang('registerAvailabilityFailed', 'Availability check failed. Please try again later'));
                    registerAvailabilityCache[cacheKey] = { level: level, message: message, expiresAt: now + 30000 };
                    setRegisterAvailabilityHint(level, message);
                }).catch(function(){
                    if (seq !== registerAvailabilitySeq) { return; }
                    setRegisterAvailabilityHint('warning', cfLang('registerAvailabilityFailed', 'Availability check failed. Please try again later'));
                });
            }, 500);
        }
        
        const updateRootLimitHint = () => {
            if (!rootLimitHint || !rootSelect) {
                return;
            }
            const selected = (rootSelect.value || '').toLowerCase();
            const limit = ROOT_LIMIT_MAP[selected] || 0;
            if (limit > 0) {
                rootLimitHint.textContent = cfLangFormat('rootLimitHint', '该根域名每个账号最多注册 %s 个', limit);
                rootLimitHint.style.display = '';
            } else {
                rootLimitHint.textContent = '';
                rootLimitHint.style.display = 'none';
            }
        };
        
        const updateInviteCodeRequirement = () => {
            if (!inviteCodeContainer || !inviteCodeInput || !rootSelect) {
                return;
            }
            const selected = (rootSelect.value || '').toLowerCase();
            const required = ROOT_INVITE_REQUIRED_MAP[selected] || false;
            if (required) {
                inviteCodeContainer.style.display = '';
                inviteCodeInput.setAttribute('required', 'required');
            } else {
                inviteCodeContainer.style.display = 'none';
                inviteCodeInput.removeAttribute('required');
                inviteCodeInput.value = '';
            }
        };
        
        if (rootSelect && rootSuffix) {
            const updateSuffix = () => {
                rootSuffix.textContent = rootSelect.value || cfLang('rootSuffixPlaceholder', '根域名');
                updateRootLimitHint();
                updateInviteCodeRequirement();
                scheduleRegisterAvailabilityCheck();
            };
            rootSelect.addEventListener('change', updateSuffix);
            updateSuffix();
        } else {
            updateRootLimitHint();
            updateInviteCodeRequirement();
        }

        if (registerPrefixInput) {
            registerPrefixInput.addEventListener('input', scheduleRegisterAvailabilityCheck);
            registerPrefixInput.addEventListener('blur', scheduleRegisterAvailabilityCheck);
        }
        if (inviteCodeInput) {
            inviteCodeInput.addEventListener('input', scheduleRegisterAvailabilityCheck);
        }
        scheduleRegisterAvailabilityCheck();

        // 表单验证
        const registerForm = document.getElementById('registerForm');
        if (registerForm) {
            registerForm.addEventListener('submit', function(e) {
                if (<?php echo ($pauseFreeRegistration ? 'true' : 'false'); ?> || <?php echo ($maintenanceMode ? 'true' : 'false'); ?>) {
                    e.preventDefault();
                    var msg = <?php echo json_encode($registerBlockMessage, CFMOD_SAFE_JSON_FLAGS); ?>;
                    alert(msg);
                    return;
                }
                const subdomain = document.querySelector('input[name="subdomain"]');
                const rootdomain = document.querySelector('select[name="rootdomain"]');
                if (!subdomain || !rootdomain) {
                    return;
                }

                if (!subdomain.value.trim()) {
                    e.preventDefault();
                    alert(cfLang('registerEnterPrefix', '请输入域名前缀'));
                    subdomain.focus();
                    return;
                }

                if (!rootdomain.value) {
                    e.preventDefault();
                    alert(cfLang('registerSelectRoot', '请选择根域名'));
                    rootdomain.focus();
                    return;
                }

                const rawPrefix = subdomain.value.trim();
                if (rawPrefix.startsWith('.') || rawPrefix.startsWith('-') || rawPrefix.endsWith('.') || rawPrefix.endsWith('-')) {
                    e.preventDefault();
                    alert(cfLang('registerEdgeError', '域名前缀不能以 "." 或 "-" 开头或结尾'));
                    subdomain.focus();
                    return;
                }

                const prefix = rawPrefix.toLowerCase();
                if (REGISTER_FORBIDDEN_PREFIXES.includes(prefix)) {
                    e.preventDefault();
                    alert(cfLang('registerForbiddenPrefix', '该前缀被禁止使用，请选择其他前缀'));
                    subdomain.focus();
                    return;
                }

                const selectedRoot = (rootdomain.value || '').toLowerCase();
                const inviteRequired = ROOT_INVITE_REQUIRED_MAP[selectedRoot] || false;
                if (inviteRequired) {
                    const inviteCodeValue = (inviteCodeInput ? inviteCodeInput.value.trim() : '');
                    if (!inviteCodeValue) {
                        e.preventDefault();
                        alert(cfLang('registerInviteCodeRequired', '该根域名需要邀请码，请输入邀请码'));
                        if (inviteCodeInput) inviteCodeInput.focus();
                        return;
                    }
                    if (inviteCodeValue.length !== 10) {
                        e.preventDefault();
                        alert(cfLang('registerInviteCodeLength', '邀请码必须是10位字符'));
                        if (inviteCodeInput) inviteCodeInput.focus();
                        return;
                    }
                }
            });
        }
        
	        // DNS设置模态框 - VPN检测由后端处理（仅NS记录需要检测）
        function isSubdomainNsManagementDisabledById(subdomainId) {
            const sid = parseInt(subdomainId || 0, 10);
            if (!sid) {
                return <?php echo ($disableNsManagement ? 'true' : 'false'); ?>;
            }
            if (<?php echo ($disableNsManagement ? 'true' : 'false'); ?>) {
                return true;
            }
            const rootMap = window.__subdomainRootMap || {};
            const rootDisabledMap = window.__rootNsDisabledMap || {};
            const root = String(rootMap[sid] || '').toLowerCase();
            if (!root) {
                return false;
            }
            return !!rootDisabledMap[root];
        }

        function syncDnsTypeOptionsByNsPolicy(subdomainId, preferredType) {
            const typeSelect = document.querySelector('select[name="record_type"]');
            if (!typeSelect) {
                return;
            }
            const nsDisabled = isSubdomainNsManagementDisabledById(subdomainId);
            const options = Array.from(typeSelect.options || []);
            options.forEach(function (opt) {
                if (String(opt.value || '').toUpperCase() === 'NS') {
                    opt.hidden = nsDisabled;
                    opt.disabled = nsDisabled;
                }
            });
            const wanted = String(preferredType || '').toUpperCase();
            if (nsDisabled && String(typeSelect.value || '').toUpperCase() === 'NS') {
                typeSelect.value = 'A';
            } else if (wanted && (!nsDisabled || wanted !== 'NS')) {
                typeSelect.value = wanted;
            }
        }

		        function showDnsForm(subdomainId, subdomainName, isUpdate, recordId = '', recordName = '', recordType = '', recordContent = '', recordTtl = '', recordLine = '') {
            const inlineAlert = document.getElementById('dns_external_block_alert');
            if (inlineAlert) {
                setModalAlertState(inlineAlert, '');
            }
            document.getElementById('dns_subdomain_id').value = subdomainId;
            document.getElementById('dns_subdomain_name').value = subdomainName;
            const dnsDomainText = document.getElementById('dns_subdomain_name_text');
            if (dnsDomainText) dnsDomainText.textContent = subdomainName;
            document.getElementById('dns_record_suffix').textContent = subdomainName;
            document.getElementById('dns_record_name').value = recordName || '';
            document.getElementById('dns_record_id').value = recordId;
            document.getElementById('dns_action').value = isUpdate ? 'update_dns' : 'create_dns';
            const lineSel = document.querySelector('select[name="line"]');
            const normalizeDnsLineValue = function (value) {
                const normalized = String(value || '').trim().toLowerCase();
                return normalized === '' ? 'default' : normalized;
            };
            const selectRecordLineOption = function (lineValue) {
                if (!lineSel) { return; }
                const normalizedLine = normalizeDnsLineValue(lineValue);
                const hasOption = Array.prototype.some.call(lineSel.options || [], function (option) {
                    return String(option.value || '').toLowerCase() === normalizedLine;
                });
                lineSel.value = hasOption ? normalizedLine : 'default';
            };
            if (lineSel) {
                const providerTypeMap = window.__subdomainProviderTypeMap || {};
                const providerType = String(providerTypeMap[subdomainId] || '').trim().toLowerCase();
                const allowLineSelect = providerType === 'alidns';
                lineSel.disabled = !allowLineSelect;
                selectRecordLineOption(allowLineSelect && isUpdate ? recordLine : 'default');
            }
const priorityInput = document.querySelector('input[name="record_priority"]');
const weightInput = document.querySelector('input[name="record_weight"]');
const portInput = document.querySelector('input[name="record_port"]');
const targetInput = document.querySelector('input[name="record_target"]');
const contentInput = document.querySelector('input[name="record_content"]');
const ttlSelect = document.querySelector('select[name="record_ttl"]');
const removeCustomTtlOption = function () {
if (!ttlSelect) return;
const customOption = ttlSelect.querySelector('option[data-current-ttl="1"]');
if (customOption) customOption.remove();
};
const resetTtlSelect = function () {
if (!ttlSelect) return;
removeCustomTtlOption();
ttlSelect.value = 'auto';
};
const selectRecordTtlOption = function (ttlValue) {
if (!ttlSelect) return;
removeCustomTtlOption();
const normalizedTtl = parseInt(String(ttlValue || '').trim(), 10);
if (!Number.isFinite(normalizedTtl) || normalizedTtl <= 0) {
ttlSelect.value = 'auto';
return;
}
const ttlString = String(normalizedTtl);
const existingOption = Array.prototype.find.call(ttlSelect.options, function (option) {
return option.value === ttlString;
});
if (existingOption) {
ttlSelect.value = ttlString;
return;
}
const customOption = document.createElement('option');
customOption.value = ttlString;
customOption.dataset.currentTtl = '1';
customOption.textContent = (typeof dnsUiIsChinese !== 'undefined' && dnsUiIsChinese)
? ('自定义 TTL：' + normalizedTtl + ' 秒')
: ('Custom TTL: ' + normalizedTtl + ' seconds');
ttlSelect.insertBefore(customOption, ttlSelect.firstChild);
ttlSelect.value = ttlString;
};
resetTtlSelect();
if (priorityInput) priorityInput.value = '10';
if (weightInput) weightInput.value = '0';
if (portInput) portInput.value = '1';
if (targetInput) targetInput.value = '';
if (contentInput) contentInput.value = '';
const typeSelect = document.querySelector('select[name="record_type"]');
const nsDisabledForSubdomain = isSubdomainNsManagementDisabledById(subdomainId);
if (nsDisabledForSubdomain && String(recordType || '').toUpperCase() === 'NS') {
    alert(cfLang('nsManagementDisabled', '已禁止设置 DNS 服务器（NS）。'));
    return;
}
syncDnsTypeOptionsByNsPolicy(subdomainId, recordType || '');
if (typeSelect) {
typeSelect.dispatchEvent(new Event('change'));
}
// 如果是更新模式，填充现有数据
if (isUpdate && recordType) {
selectRecordTtlOption(recordTtl);
if (typeSelect) {
typeSelect.value = recordType;
// 触发change事件以显示/隐藏相应字段
typeSelect.dispatchEvent(new Event('change'));
}
if (recordType === 'CAA' && recordContent) {
// 解析CAA记录内容：格式为 "flag tag "value""
const caaMatch = recordContent.match(/^(\d+)\s+(\w+)\s+"([^"]*)"$/);
if (caaMatch) {
const flag = caaMatch[1];
const tag = caaMatch[2];
const value = caaMatch[3];
const flagSelect = document.querySelector('select[name="caa_flag"]');
const tagSelect = document.querySelector('select[name="caa_tag"]');
const valueInput = document.querySelector('input[name="caa_value"]');
if (flagSelect) flagSelect.value = flag;
if (tagSelect) tagSelect.value = tag;
if (valueInput) valueInput.value = value;
}
} else if (recordType === 'SRV' && recordContent) {
const parts = recordContent.trim().split(/\s+/);
if (parts.length >= 4) {
if (priorityInput) priorityInput.value = parts[0];
if (weightInput) weightInput.value = parts[1];
if (portInput) portInput.value = parts[2];
if (targetInput) targetInput.value = parts.slice(3).join(' ');
}
if (contentInput) contentInput.value = recordContent;
} else {
if (contentInput) contentInput.value = recordContent;
}
} else {
if (contentInput) contentInput.value = '';
const caaFlag = document.querySelector('select[name="caa_flag"]');
const caaTag = document.querySelector('select[name="caa_tag"]');
const caaValue = document.querySelector('input[name="caa_value"]');
if (caaFlag) caaFlag.value = '0';
if (caaTag) caaTag.value = 'issue';
if (caaValue) caaValue.value = '';
if (priorityInput) priorityInput.value = '10';
if (weightInput) weightInput.value = '0';
if (portInput) portInput.value = '1';
if (targetInput) targetInput.value = '';
}
// 显示模态框
    updateDnsExternalDelegationState(subdomainId, recordType || (typeSelect ? typeSelect.value : 'A'));
            const advanced = document.getElementById('dnsAdvancedSettings');
            const advancedChevron = document.getElementById('dnsAdvancedChevron');
            const syncAdvancedChevron = function () {
                if (!advancedChevron || !advanced) return;
                advancedChevron.classList.remove('fa-chevron-down', 'fa-chevron-up');
                advancedChevron.classList.add(advanced.classList.contains('show') ? 'fa-chevron-up' : 'fa-chevron-down');
            };
            if (advanced && !advanced.dataset.chevronBound) {
                advanced.addEventListener('shown.bs.collapse', syncAdvancedChevron);
                advanced.addEventListener('hidden.bs.collapse', syncAdvancedChevron);
                advanced.dataset.chevronBound = '1';
            }
            syncAdvancedChevron();
            if (advanced && advanced.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(advanced, { toggle: false }).hide();
            }
            const modal = new bootstrap.Modal(document.getElementById('dnsModal'));
            modal.show();
        }

function isSubdomainDelegatedExternalById(subdomainId) {
    const current = (window.__nsBySubId && window.__nsBySubId[subdomainId]) ? window.__nsBySubId[subdomainId] : [];
    const defaultNs = parseWhoisDefaultNsList();
    if (!Array.isArray(defaultNs) || defaultNs.length === 0) {
        return false;
    }
    const normalizedCurrent = (Array.isArray(current) ? current : []).map(normalizeNsServer).filter(Boolean);
    return normalizedCurrent.length > 0 && !isSameNsSet(normalizedCurrent, defaultNs);
}

function updateDnsExternalDelegationState(subdomainId, recordTypeValue) {
    const alertEl = document.getElementById('dns_external_block_alert');
    const submitBtn = document.getElementById('dns_submit_btn');
    const recordNameInput = document.getElementById('dns_record_name');
    const recordContentInput = document.querySelector('input[name="record_content"]');
    if (!alertEl || !submitBtn) return;
    const delegated = isSubdomainDelegatedExternalById(subdomainId);
    const typeUpper = String(recordTypeValue || 'A').trim().toUpperCase();
    const shouldBlock = delegated && typeUpper !== 'NS';
    if (shouldBlock) {
        const warningText = cfLang('externalDnsBlockedInline', '域名正在使用外部DNS解析，请先将NS修改为本站DNS后再试') || '域名正在使用外部DNS解析，请先将NS修改为本站DNS后再试';
        setModalAlertState(alertEl, warningText);
        submitBtn.disabled = true;
        submitBtn.classList.add('disabled');
        if (recordNameInput) recordNameInput.setAttribute('disabled', 'disabled');
        if (recordContentInput) recordContentInput.setAttribute('disabled', 'disabled');
    } else {
        setModalAlertState(alertEl, '');
        submitBtn.disabled = false;
        submitBtn.classList.remove('disabled');
        if (recordNameInput) recordNameInput.removeAttribute('disabled');
        if (recordContentInput) recordContentInput.removeAttribute('disabled');
    }
}

function setModalAlertState(alertEl, message) {
    if (!alertEl) return;
    const text = String(message || '').trim();
    if (text === '') {
        alertEl.textContent = '';
        alertEl.classList.add('d-none');
        alertEl.style.setProperty('display', 'none', 'important');
        alertEl.style.setProperty('padding', '0', 'important');
        alertEl.style.setProperty('margin', '0', 'important');
        alertEl.style.setProperty('border', '0', 'important');
        alertEl.style.setProperty('min-height', '0', 'important');
        alertEl.style.setProperty('height', '0', 'important');
        alertEl.style.setProperty('overflow', 'hidden', 'important');
        return;
    }
    alertEl.textContent = text;
    alertEl.classList.remove('d-none');
    alertEl.style.removeProperty('padding');
    alertEl.style.removeProperty('margin');
    alertEl.style.removeProperty('border');
    alertEl.style.removeProperty('min-height');
    alertEl.style.removeProperty('height');
    alertEl.style.removeProperty('overflow');
    alertEl.style.setProperty('display', 'block', 'important');
}

function showDnsUnlockModal() {
    var modalEl = document.getElementById('dnsUnlockModal');
    if (!modalEl) { return; }
    var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    instance.show();
}
window.showDnsUnlockModal = showDnsUnlockModal;

function copyDnsUnlockCode() {
    var input = document.getElementById('dnsUnlockCodeText');
    if (!input) { return; }
    var value = input.value || '';
    if (!value) { return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(function(){
            alert(cfLang('dnsUnlockCopySuccess', '解锁码已复制'));
        }).catch(function(){
            alert(cfLang('dnsUnlockCopyFailed', '复制失败，请手动复制'));
        });
    } else {
        try {
            input.select();
            document.execCommand('copy');
            alert(cfLang('dnsUnlockCopySuccess', '解锁码已复制'));
        } catch (err) {
            alert(cfLang('dnsUnlockCopyFailed', '复制失败，请手动复制'));
        }
    }
}
window.copyDnsUnlockCode = copyDnsUnlockCode;

// 邀请注册功能
function showInviteRegistrationModal() {
    var modalEl = document.getElementById('inviteRegistrationModal');
    if (!modalEl) { return; }
    var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    instance.show();
}
window.showInviteRegistrationModal = showInviteRegistrationModal;

function copyInviteRegCode() {
    var input = document.getElementById('inviteRegCodeText');
    if (!input) { return; }
    var value = input.value || '';
    if (!value) { return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(function(){
            alert(cfLang('inviteRegCopySuccess', '邀请码已复制'));
        }).catch(function(){
            alert(cfLang('inviteRegCopyFailed', '复制失败，请手动复制'));
        });
    } else {
        try {
            input.select();
            document.execCommand('copy');
            alert(cfLang('inviteRegCopySuccess', '邀请码已复制'));
        } catch (err) {
            alert(cfLang('inviteRegCopyFailed', '复制失败，请手动复制'));
        }
    }
}
window.copyInviteRegCode = copyInviteRegCode;

function showDomainPermanentUpgradeModal() {
    var modalEl = document.getElementById('domainPermanentUpgradeModal');
    if (!modalEl) { return; }
    var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    instance.show();
}
window.showDomainPermanentUpgradeModal = showDomainPermanentUpgradeModal;
function showDomainPermanentIncentiveModal() {
    var modalEl = document.getElementById('domainPermanentIncentiveModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    instance.show();
}
window.showDomainPermanentIncentiveModal = showDomainPermanentIncentiveModal;

function copyDomainPermanentAssistCode(code) {
    var value = String(code || '').trim();
    if (!value) { return; }

    function buildShareText(assistCode) {
        var template = cfLang('domainPermanentUpgradeShareTemplate', '我在 DNSHE 注册了一个硬核域名，差你一票就能永久激活了！助力码：%s');
        if (template.indexOf('%s') !== -1) {
            return template.replace('%s', assistCode);
        }
        return template + ' ' + assistCode;
    }

    function showSharePrompt(assistCode) {
        var promptTitle = cfLang('domainPermanentUpgradeCopyPrompt', '已复制！快去发送给你的好友吧~（下方文案可直接复制）');
        var shareText = buildShareText(assistCode);
        if (typeof window.prompt === 'function') {
            window.prompt(promptTitle, shareText);
        } else {
            alert(promptTitle + '\n' + shareText);
        }
    }

    var shareValue = buildShareText(value);

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(shareValue).then(function () {
            showSharePrompt(value);
        }).catch(function () {
            alert(cfLang('domainPermanentUpgradeCopyFailed', '复制失败，请手动复制'));
        });
    } else {
        try {
            var input = document.createElement('input');
            input.value = shareValue;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            showSharePrompt(value);
        } catch (err) {
            alert(cfLang('domainPermanentUpgradeCopyFailed', '复制失败，请手动复制'));
        }
    }
}
window.copyDomainPermanentAssistCode = copyDomainPermanentAssistCode;

// VPN检测配置
const vpnDetectionDnsEnabled = <?php echo (!empty($vpnDetectionDnsEnabled) ? 'true' : 'false'); ?>;

// VPN检测AJAX函数
function checkVpnBeforeAction(callback) {
    if (!vpnDetectionDnsEnabled) {
        callback(false);
        return;
    }
    var ajaxUrl = cfClientBuildModuleUrl('ajax_check_vpn');
    fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CF_MOD_CSRF || ''
        }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.blocked) {
            alert(data.message || cfLang('dnsVpnBlocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。'));
            callback(true);
        } else {
            callback(false);
        }
    })
    .catch(function(err) {
        // 网络错误时不阻止操作
        console.error('VPN check failed:', err);
        callback(false);
    });
}

const nsUiDefaultInputs = 2;
const nsUiMaxInputs = Math.max(nsUiDefaultInputs, Math.min(8, <?php echo max(1, intval($nsMaxPerDomain ?? 8)); ?>));
function parseWhoisDefaultNsList() {
    const raw = (window.__whoisDefaultNs || '').toString();
    return raw.split(/[\r\n,;]+/).map(function(item) {
        return normalizeNsServer(item);
    }).filter(function(item, idx, arr) {
        return !!item && arr.indexOf(item) === idx;
    });
}
function isSameNsSet(a, b) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
    const aa = a.slice().sort();
    const bb = b.slice().sort();
    for (let i = 0; i < aa.length; i++) {
        if (aa[i] !== bb[i]) return false;
    }
    return true;
}

function normalizeNsServer(value) {
    return String(value || '').trim().toLowerCase().replace(/\.+$/g, '');
}

function getNsServerInputs() {
    return Array.from(document.querySelectorAll('#ns_inputs_container .ns-server-input'));
}

function updateNsInputPlaceholders() {
    getNsServerInputs().forEach(function(input, index) {
        input.placeholder = cfLangFormat('nsInputPlaceholderIndexed', '例如:ns%s.dnshe.com', index + 1);
    });
}

function collectNsServerValues() {
    const unique = [];
    getNsServerInputs().forEach(function(input) {
        const value = normalizeNsServer(input.value);
        if (value && unique.indexOf(value) === -1) {
            unique.push(value);
        }
    });
    return unique;
}

function syncNsHiddenTextarea() {
    const ta = document.getElementById('ns_lines');
    if (!ta) {
        return [];
    }
    const cleaned = collectNsServerValues();
    ta.value = cleaned.join('\n');
    return cleaned;
}

function updateNsAddButtonState() {
    const addBtn = document.getElementById('ns_add_input_btn');
    if (!addBtn) {
        return;
    }
    const reachedMax = getNsServerInputs().length >= nsUiMaxInputs;
    addBtn.disabled = reachedMax;
    addBtn.classList.toggle('disabled', reachedMax);
    addBtn.title = reachedMax ? cfLangFormat('nsMaxReached', '最多可添加 %s 个 DNS 服务器', nsUiMaxInputs) : cfLang('nsAddServer', '增加 DNS 服务器');
}

function appendNsServerInputRow(value) {
    const container = document.getElementById('ns_inputs_container');
    if (!container || getNsServerInputs().length >= nsUiMaxInputs) {
        return null;
    }

    const row = document.createElement('div');
    row.className = 'input-group ns-input-row';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control ns-server-input';
    input.placeholder = cfLang('nsInputPlaceholder', '例如:ns1.dnshe.com');
    input.autocomplete = 'off';
    input.value = normalizeNsServer(value);
    input.addEventListener('input', function() {
        syncNsHiddenTextarea();
    });
    input.addEventListener('blur', function() {
        input.value = normalizeNsServer(input.value);
        syncNsHiddenTextarea();
    });

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn ns-remove-input-btn';
    removeBtn.innerHTML = '<i class="fas fa-trash-alt"></i>';
    removeBtn.setAttribute('aria-label', cfLang('nsRemoveServer', '删除 DNS 服务器'));
    removeBtn.title = cfLang('nsRemoveServer', '删除 DNS 服务器');
    removeBtn.addEventListener('click', function() {
        const inputs = getNsServerInputs();
        if (inputs.length <= nsUiDefaultInputs) {
            input.value = '';
            input.focus();
        } else {
            row.remove();
        }
        updateNsInputPlaceholders();
        syncNsHiddenTextarea();
        updateNsAddButtonState();
    });

    row.appendChild(input);
    row.appendChild(removeBtn);
    container.appendChild(row);
    updateNsInputPlaceholders();
    updateNsAddButtonState();
    return input;
}

function renderNsServerInputs(values) {
    const container = document.getElementById('ns_inputs_container');
    if (!container) {
        return;
    }
    const normalized = [];
    (Array.isArray(values) ? values : []).forEach(function(item) {
        const value = normalizeNsServer(item);
        if (value && normalized.indexOf(value) === -1) {
            normalized.push(value);
        }
    });

    container.innerHTML = '';
    const inputCount = Math.min(nsUiMaxInputs, Math.max(nsUiDefaultInputs, normalized.length));
    for (let i = 0; i < inputCount; i++) {
        appendNsServerInputRow(normalized[i] || '');
    }
    syncNsHiddenTextarea();
    updateNsAddButtonState();
}

document.getElementById('ns_add_input_btn')?.addEventListener('click', function() {
    const inputs = getNsServerInputs();
    if (inputs.length >= nsUiMaxInputs) {
        alert(cfLangFormat('nsMaxReached', '最多可添加 %s 个 DNS 服务器', nsUiMaxInputs));
        return;
    }
    const newInput = appendNsServerInputRow('');
    if (newInput) {
        newInput.focus();
    }
    syncNsHiddenTextarea();
});

document.querySelector('#dnsForm select[name="record_type"]')?.addEventListener('change', function() {
    const sid = parseInt((document.getElementById('dns_subdomain_id')?.value || '0'), 10);
    updateDnsExternalDelegationState(sid, this.value);
});

function showNsModal(subId, name) {
    if (dnsUnlockFeatureEnabled && dnsUnlockRequired) {
        alert(cfLang('dnsUnlockRequired', '请先完成 DNS 解锁后再操作。'));
        showDnsUnlockModal();
        return;
    }
    if (isSubdomainNsManagementDisabledById(subId)) {
        alert(cfLang('nsManagementDisabled', '已禁止设置 DNS 服务器（NS）。'));
        return;
    }

    checkVpnBeforeAction(function(blocked) {
        if (blocked) {
            return;
        }
        document.getElementById('ns_subdomain_id').value = subId;
        document.getElementById('ns_subdomain_name').value = name;
        const current = (window.__nsBySubId && window.__nsBySubId[subId]) ? window.__nsBySubId[subId] : [];
        const defaultNs = parseWhoisDefaultNsList();
        const shouldShowDefault = current.length === 0 || isSameNsSet(current.map(normalizeNsServer).filter(Boolean), defaultNs);
        const labelEl = document.getElementById('ns_current_label');
        if (labelEl) {
            labelEl.textContent = shouldShowDefault
                ? cfLang('nsCurrentLabelDefault', '当前 NS（系统默认）')
                : cfLang('nsCurrentLabelDelegated', '当前 NS（已委派外部 DNS）');
        }
        const displayNs = shouldShowDefault ? defaultNs : current;
        document.getElementById('ns_current').textContent = displayNs.length ? displayNs.join(', ') : cfLang('nsNotConfigured', '（未设置）');
        renderNsServerInputs(displayNs);
        const switchDefaultBtn = document.getElementById('ns_switch_default_btn');
        if (switchDefaultBtn) {
            switchDefaultBtn.disabled = shouldShowDefault;
            switchDefaultBtn.classList.toggle('disabled', shouldShowDefault);
            switchDefaultBtn.classList.toggle('btn-outline-primary', !shouldShowDefault);
            switchDefaultBtn.classList.toggle('btn-outline-secondary', shouldShowDefault);
            switchDefaultBtn.title = shouldShowDefault
                ? cfLang('nsAlreadyDefault', '当前已是系统默认DNS')
                : cfLang('nsSwitchToDefault', '切换为系统默认DNS地址');
        }
        const modal = new bootstrap.Modal(document.getElementById('nsModal'));
        modal.show();
    });
}

document.getElementById('ns_switch_default_btn')?.addEventListener('click', function() {
    const defaultNs = parseWhoisDefaultNsList();
    const ta = document.getElementById('ns_lines');
    if (!ta) return;
    if (Array.isArray(defaultNs) && defaultNs.length > 0) {
        renderNsServerInputs(defaultNs);
        ta.value = defaultNs.join('\n');
    } else {
        renderNsServerInputs([]);
        ta.value = '';
    }
    const form = document.getElementById('nsForm');
    if (form) {
        form.requestSubmit ? form.requestSubmit() : form.submit();
    }
});

document.getElementById('nsForm')?.addEventListener('submit', function(e){
            if (dnsUnlockFeatureEnabled && dnsUnlockRequired) {
                e.preventDefault();
                alert(cfLang('dnsUnlockRequired', '请先完成 DNS 解锁后再操作。'));
                showDnsUnlockModal();
                return;
            }
            const ta = document.getElementById('ns_lines');
            if (!ta) return;
            const cleaned = syncNsHiddenTextarea();
            const defaultNs = parseWhoisDefaultNsList();
            if (cleaned.length === 0 || isSameNsSet(cleaned, defaultNs)) {
                ta.value = '';
                try { const btn = this.querySelector('button[type="submit"]'); if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + cfLang('buttonSubmitting', '提交中...'); } } catch(err) {}
                return;
            }
            const domainRegex = /^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+\.?$/;
            for (const ns of cleaned) {
                if (!domainRegex.test(ns)) {
                    e.preventDefault();
                    alert(cfLangFormat('nsInvalidFormat', 'NS 格式不正确: %s', ns));
                    return;
                }
            }
            try { const btn = this.querySelector('button[type="submit"]'); if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + cfLang('buttonSubmitting', '提交中...'); } } catch(err) {}
            ta.value = cleaned.join('\n');
        });

        const dnsUiIsChinese = <?php echo $modalIsChinese ? 'true' : 'false'; ?>;
        // DNS表单验证
        document.getElementById('dnsForm').addEventListener('submit', function(e) {
const recordType = document.querySelector('select[name="record_type"]');
const recordContent = document.querySelector('input[name="record_content"]');
const recordNameInput = document.getElementById('dns_record_name');
const type = recordType.value;
const subIdForDnsForm = parseInt((document.getElementById('dns_subdomain_id')?.value || '0'), 10);
if (isSubdomainDelegatedExternalById(subIdForDnsForm) && String(type || '').toUpperCase() !== 'NS') {
e.preventDefault();
alert(cfLang('externalDnsBlockedInline', '域名正在使用外部DNS解析，请先将NS修改为本站DNS后再试'));
return;
}
if (dnsUnlockFeatureEnabled && dnsUnlockRequired && recordType && recordType.value.toUpperCase() === 'NS') {
    e.preventDefault();
    alert(cfLang('dnsUnlockRequired', '请先完成 DNS 解锁后再操作。'));
    showDnsUnlockModal();
    return;
}
if (recordType && recordType.value.toUpperCase() === 'NS' && isSubdomainNsManagementDisabledById(subIdForDnsForm)) {
    e.preventDefault();
    alert(cfLang('nsManagementDisabled', '已禁止设置 DNS 服务器（NS）。'));
    return;
}
if (recordNameInput) {
let recordNameValue = recordNameInput.value.trim();
if (recordNameValue === '') {
recordNameValue = '@';
}
if (recordNameValue !== '@') {
if (recordNameValue.startsWith('.') || recordNameValue.startsWith('-') || recordNameValue.endsWith('.') || recordNameValue.endsWith('-')) {
e.preventDefault();
alert(cfLang('dnsNameEdgeError', '解析名称不能以点或连字符开头或结尾'));
recordNameInput.focus();
return;
}
if (recordNameValue.includes('..')) {
e.preventDefault();
alert(cfLang('dnsNameDoubleDot', '解析名称不能包含连续的点'));
recordNameInput.focus();
return;
}
const segments = recordNameValue.split('.');
for (const segment of segments) {
if (!segment) {
e.preventDefault();
alert(cfLang('dnsNameEmptyLabel', '解析名称不能包含空的标签片段'));
recordNameInput.focus();
return;
}
if (segment.startsWith('-') || segment.endsWith('-')) {
e.preventDefault();
alert(cfLang('dnsNameLabelEdge', '解析名称中的每个标签都不能以连字符开头或结尾'));
recordNameInput.focus();
return;
}
}
}
}
if (type === 'SRV') {
const priorityInput = document.querySelector('input[name="record_priority"]');
const weightInput = document.querySelector('input[name="record_weight"]');
const portInput = document.querySelector('input[name="record_port"]');
const targetInput = document.querySelector('input[name="record_target"]');
let priority = parseInt(priorityInput ? priorityInput.value : '0', 10);
let weight = parseInt(weightInput ? weightInput.value : '0', 10);
let port = parseInt(portInput ? portInput.value : '0', 10);
let target = targetInput ? targetInput.value.trim() : '';
if (!Number.isFinite(priority) || priority < 0 || priority > 65535) {
e.preventDefault();
alert(cfLang('srvPriorityInvalid', dnsUiIsChinese ? 'SRV记录的优先级必须在0-65535之间' : 'SRV priority must be between 0 and 65535'));
if (priorityInput) priorityInput.focus();
return;
}
if (!Number.isFinite(weight) || weight < 0 || weight > 65535) {
e.preventDefault();
alert(cfLang('srvWeightInvalid', dnsUiIsChinese ? 'SRV记录的权重必须在0-65535之间' : 'SRV weight must be between 0 and 65535'));
if (weightInput) weightInput.focus();
return;
}
if (!Number.isFinite(port) || port < 1 || port > 65535) {
e.preventDefault();
alert(cfLang('srvPortInvalid', dnsUiIsChinese ? 'SRV记录的端口必须在1-65535之间' : 'SRV port must be between 1 and 65535'));
if (portInput) portInput.focus();
return;
}
if (target.endsWith('.')) {
target = target.slice(0, -1);
}
if (!target) {
e.preventDefault();
alert(cfLang('srvTargetRequired', dnsUiIsChinese ? 'SRV记录的目标地址不能为空' : 'SRV target host cannot be empty'));
if (targetInput) targetInput.focus();
return;
}
if (!isValidDomain(target)) {
e.preventDefault();
alert(cfLang('srvTargetInvalid', dnsUiIsChinese ? '请输入有效的SRV目标主机名' : 'Please enter a valid SRV target hostname'));
if (targetInput) targetInput.focus();
return;
}
recordContent.value = `${priority} ${weight} ${port} ${target}`;
if (priorityInput) priorityInput.value = String(priority);
if (weightInput) weightInput.value = String(weight);
if (portInput) portInput.value = String(port);
if (targetInput) targetInput.value = target;
}
if (!recordContent.value.trim()) {
e.preventDefault();
alert(cfLang('recordContentRequired', dnsUiIsChinese ? '请输入记录内容' : 'Please enter a record value'));
recordContent.focus();
return;
}
const content = recordContent.value.trim();
// 根据记录类型验证内容格式
// NS/MX/SRV/TXT不支持代理，自动取消勾选
if (['NS','MX','SRV','TXT'].includes(type)) {
const proxied = document.getElementById('dns_proxied');
if (proxied) proxied.checked = false;
}
if (type === 'A' && !isValidIPv4(content)) {
e.preventDefault();
alert(cfLang('ipv4Invalid', dnsUiIsChinese ? '请输入有效的IPv4地址' : 'Please enter a valid IPv4 address'));
recordContent.focus();
return;
}
if (type === 'AAAA' && !isValidIPv6(content)) {
e.preventDefault();
alert(cfLang('ipv6Invalid', dnsUiIsChinese ? '请输入有效的IPv6地址' : 'Please enter a valid IPv6 address'));
recordContent.focus();
return;
}
if ((type === 'CNAME' || type === 'NS') && !isValidDomain(content)) {
e.preventDefault();
alert(cfLang('domainInvalid', dnsUiIsChinese ? '请输入有效的域名' : 'Please enter a valid domain name'));
recordContent.focus();
return;
}
if (type === 'CNAME' && (isValidIPv4(content) || isValidIPv6(content))) {
e.preventDefault();
alert(cfLang('cnameIpNotAllowed', dnsUiIsChinese ? 'CNAME记录的目标不能是IP地址，请填写域名' : 'CNAME target cannot be an IP address. Please enter a domain name'));
recordContent.focus();
return;
}
const localRecordsMap = window.__recordsBySubId || {};
const localRecords = Array.isArray(localRecordsMap[subIdForDnsForm]) ? localRecordsMap[subIdForDnsForm] : [];
const hostInputRaw = recordNameInput ? String(recordNameInput.value || '').trim() : '@';
const hostInput = hostInputRaw === '' ? '@' : hostInputRaw;
const fullHost = hostInput === '@'
    ? String(document.getElementById('dns_subdomain_name')?.value || '').trim().toLowerCase()
    : (hostInput + '.' + String(document.getElementById('dns_subdomain_name')?.value || '').trim()).toLowerCase();
if (fullHost) {
const hasCnameSameName = localRecords.some(function(rec){
    return String(rec.name || '').trim().toLowerCase() === fullHost && String(rec.type || '').trim().toUpperCase() === 'CNAME';
});
const hasNonCnameSameName = localRecords.some(function(rec){
    return String(rec.name || '').trim().toLowerCase() === fullHost && String(rec.type || '').trim().toUpperCase() !== 'CNAME';
});
if (type === 'CNAME' && hasNonCnameSameName) {
    e.preventDefault();
    alert(cfLang('cnameMutexConflict', dnsUiIsChinese ? '同名已存在其他类型记录，不能新增CNAME记录' : 'A record with the same name already exists in another type, so CNAME cannot be added'));
    return;
}
if (type !== 'CNAME' && hasCnameSameName) {
    e.preventDefault();
    alert(cfLang('cnameMutexConflict', dnsUiIsChinese ? '同名已存在CNAME记录，不能新增其他类型记录' : 'A CNAME record with the same name already exists, so another type cannot be added'));
    return;
}
}
// 防连点：提交后禁用按钮
try { const btn = this.querySelector('button[type="submit"]'); if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + cfLang('buttonSaving', '保存中...'); } } catch(err) {}
});
// 动态显示/隐藏字段
        document.querySelector('select[name="record_type"]').addEventListener('change', function() {
const recordType = this.value;
const priorityField = document.getElementById('priority_field');
const proxiedCheckbox = document.getElementById('dns_proxied');
const recordContentField = document.getElementById('record_content_field');
const caaFields = document.getElementById('caa_fields');
const srvFields = document.getElementById('srv_fields');
const recordContentInput = document.querySelector('input[name="record_content"]');
const dynamicTypeHint = document.getElementById('dns_type_dynamic_hint');
const dynamicContentHint = document.getElementById('dns_content_dynamic_hint');
// 显示/隐藏优先级字段
if (priorityField) {
priorityField.style.display = ['MX', 'SRV'].includes(recordType) ? '' : 'none';
}
// 根据不同类型切换字段显示
if (recordType === 'CAA') {
if (recordContentField) recordContentField.style.display = 'none';
if (caaFields) caaFields.style.display = '';
if (srvFields) srvFields.style.display = 'none';
if (recordContentInput) recordContentInput.removeAttribute('required');
} else if (recordType === 'SRV') {
if (recordContentField) recordContentField.style.display = 'none';
if (caaFields) caaFields.style.display = 'none';
if (srvFields) srvFields.style.display = '';
const priorityInput = document.querySelector('input[name="record_priority"]');
if (priorityInput && (priorityInput.value === '' || priorityInput.value === '10')) {
priorityInput.value = '0';
}
const weightInput = document.querySelector('input[name="record_weight"]');
const portInput = document.querySelector('input[name="record_port"]');
const targetInput = document.querySelector('input[name="record_target"]');
if (weightInput && weightInput.value === '') { weightInput.value = '0'; }
if (portInput && (portInput.value === '' || portInput.value === '0')) { portInput.value = '1'; }
if (recordContentInput) {
recordContentInput.removeAttribute('required');
recordContentInput.value = '';
}
} else {
if (recordContentField) recordContentField.style.display = '';
if (caaFields) caaFields.style.display = 'none';
if (srvFields) srvFields.style.display = 'none';
if (recordContentInput) recordContentInput.setAttribute('required', 'required');
}
// 根据记录类型控制CDN代理
if (proxiedCheckbox) {
if (['NS', 'MX', 'TXT', 'SRV', 'CAA'].includes(recordType)) {
proxiedCheckbox.checked = false;
proxiedCheckbox.disabled = true;
} else {
proxiedCheckbox.disabled = false;
}
}
	if (dynamicTypeHint && dynamicContentHint) {
const typeHintMap = {
    'A': [dnsUiIsChinese ? 'A记录（IPv4）' : 'A record (IPv4)', dnsUiIsChinese ? '请输入 IPv4 地址，例如：192.168.1.1' : 'Enter an IPv4 address, e.g. 192.168.1.1'],
    'AAAA': [dnsUiIsChinese ? 'AAAA记录（IPv6）' : 'AAAA record (IPv6)', dnsUiIsChinese ? '请输入 IPv6 地址，例如：2001:db8::1' : 'Enter an IPv6 address, e.g. 2001:db8::1'],
    'CNAME': [dnsUiIsChinese ? 'CNAME记录' : 'CNAME record', dnsUiIsChinese ? '请输入目标域名，例如：example.com' : 'Enter a target domain, e.g. example.com'],
    'NS': [dnsUiIsChinese ? 'NS记录' : 'NS record', dnsUiIsChinese ? '请输入DNS服务器地址，例如:ns1.dnshe.com' : 'Please enter a DNS nameserver address, e.g. ns1.dnshe.com'],
    'MX': [dnsUiIsChinese ? 'MX记录' : 'MX record', dnsUiIsChinese ? '请输入邮件服务器域名，例如：mail.example.com（并设置优先级）' : 'Enter a mail server domain, e.g. mail.example.com (and set priority)'],
    'TXT': [dnsUiIsChinese ? 'TXT记录' : 'TXT record', dnsUiIsChinese ? '请输入 TXT 文本内容，例如：v=spf1 include:_spf.google.com ~all' : 'Enter TXT content, e.g. v=spf1 include:_spf.google.com ~all'],
    'SRV': [dnsUiIsChinese ? 'SRV记录' : 'SRV record', dnsUiIsChinese ? '请填写优先级、权重、端口与目标地址' : 'Fill in priority, weight, port, and target host'],
};
const picked = typeHintMap[recordType] || [dnsUiIsChinese ? (recordType + '记录') : (recordType + ' record'), dnsUiIsChinese ? '请根据记录类型填写正确的记录值' : 'Please enter a valid value for this record type'];
dynamicTypeHint.textContent = (dnsUiIsChinese ? '当前类型：' : 'Current type: ') + picked[0];
dynamicContentHint.textContent = picked[1];
	}
});
document.querySelector('select[name="record_type"]')?.dispatchEvent(new Event('change'));
// 验证函数
        function isValidIPv4(ip) {
            const ipv4Regex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
            return ipv4Regex.test(ip);
        }
        
        function isValidIPv6(ip) {
            if (typeof ip !== 'string') {
                return false;
            }
            const value = ip.trim();
            if (value === '') {
                return false;
            }
            if (value === '::') {
                return true;
            }
            const parts = value.split('::');
            if (parts.length > 2) {
                return false;
            }
            const left = parts[0] ? parts[0].split(':').filter(Boolean) : [];
            const right = parts.length === 2 && parts[1] ? parts[1].split(':').filter(Boolean) : [];
            const blocks = left.concat(right);
            let ipv4Tail = null;
            if (blocks.length > 0 && blocks[blocks.length - 1].includes('.')) {
                ipv4Tail = blocks.pop();
                if (!isValidIPv4(ipv4Tail)) {
                    return false;
                }
            }
            const blockRegex = /^[0-9a-fA-F]{1,4}$/;
            if (!blocks.every(block => blockRegex.test(block))) {
                return false;
            }
            const totalSegments = blocks.length + (ipv4Tail ? 2 : 0);
            if (parts.length === 2) {
                return totalSegments < 8;
            }
            return totalSegments === 8;
        }
        
        function isValidDomain(domain) {
            if (typeof domain !== 'string') return false;
            const value = domain.trim().replace(/\.$/, '');
            if (!value || value.length > 253) return false;
            const labels = value.split('.');
            if (labels.length < 2) return false;
            const domainRegex = /^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/;
            return labels.every(label => domainRegex.test(label));
        }
        
        // 自动关闭提示（保留封�������横幅不消失）
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert.alert-dismissible');
            alerts.forEach(alert => {
                if (alert.id === 'banAlert') return;
                if (alert.id === 'prize-display-section') return;
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 8000);
        
        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化Bootstrap工具提示
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            (function initPermIncentiveCountdown() {
                var countdownEl = document.getElementById('permIncentiveCountdown');
                if (!countdownEl) { return; }
                var permIncentiveIsChinese = <?php echo in_array(strtolower((string)($currentLanguage ?? 'english')), ['chinese','zh','zh-cn','cn'], true) ? 'true' : 'false'; ?>;
                var submitBtn = document.getElementById('permIncentiveSubmitButton');
                var endTsRaw = parseInt(countdownEl.getAttribute('data-end-ts') || '0', 10);
                var endTs = endTsRaw > 0 ? (endTsRaw * 1000) : NaN;
                if (isNaN(endTs)) {
                    var endAtRaw = countdownEl.getAttribute('data-end-at') || '';
                    endTs = Date.parse(endAtRaw.replace(' ', 'T'));
                    if (isNaN(endTs)) { return; }
                }
                function tick() {
                    var diff = Math.floor((endTs - Date.now()) / 1000);
                    if (diff <= 0) {
                        countdownEl.textContent = cfLang('permIncentiveExpired', '活动状态：已结束');
                        if (submitBtn) { submitBtn.disabled = true; }
                        return;
                    }
                    var d = Math.floor(diff / 86400);
                    var h = Math.floor((diff % 86400) / 3600);
                    var m = Math.floor((diff % 3600) / 60);
                    var s = diff % 60;
                    if (permIncentiveIsChinese) {
                        countdownEl.textContent = cfLang('permIncentiveRemaining', '活动剩余时间') + ': ' + d + '天' + h + '小时' + m + '分钟' + s + '秒';
                    } else {
                        countdownEl.textContent = cfLang('permIncentiveRemaining', 'Campaign time remaining') + ': ' + d + 'd ' + h + 'h ' + m + 'm ' + s + 's';
                    }
                }
                tick();
                setInterval(tick, 1000);
            })();

            // 若后端注册失败，保持注册弹窗并在弹窗内显示错误
            var regErrRaw = <?php echo json_encode($registerError ?? '', CFMOD_SAFE_JSON_FLAGS); ?>;
            var regErr = (typeof regErrRaw === 'string' ? regErrRaw.trim() : '');
            if (regErr) {
                var modalEl = document.getElementById('registerModal');
                if (modalEl) {
                    var alertEl = document.getElementById('registerErrorAlert');
                    if (alertEl) { setModalAlertState(alertEl, regErr); }
                    var m = new bootstrap.Modal(modalEl);
                    m.show();
                }
            }
            var registerModalEl = document.getElementById('registerModal');
            if (registerModalEl) {
                registerModalEl.addEventListener('show.bs.modal', function() {
                    var alertEl = document.getElementById('registerErrorAlert');
                    if (!alertEl) return;
                    if (!regErr || !String(alertEl.textContent || '').trim()) {
                        setModalAlertState(alertEl, '');
                    }
                });
                registerModalEl.addEventListener('hide.bs.modal', function() {
                    var alertEl = document.getElementById('registerErrorAlert');
                    if (!alertEl) return;
                    setModalAlertState(alertEl, '');
                });
            }

            var dnsModalEl = document.getElementById('dnsModal');
            if (dnsModalEl) {
                dnsModalEl.addEventListener('show.bs.modal', function() {
                    var alertEl = document.getElementById('dns_external_block_alert');
                    if (!alertEl) return;
                    if (!String(alertEl.textContent || '').trim()) {
                        setModalAlertState(alertEl, '');
                    }
                });
                dnsModalEl.addEventListener('hide.bs.modal', function() {
                    var alertEl = document.getElementById('dns_external_block_alert');
                    if (!alertEl) return;
                    setModalAlertState(alertEl, '');
                });
            }

            var dnsForParam = <?php echo intval($dnsPageFor); ?>;
            if (dnsForParam > 0) {
                var detailsRow = document.getElementById('details_' + dnsForParam);
                if (detailsRow) {
                    detailsRow.style.display = 'table-row';
                    setTimeout(function(){
                        try { detailsRow.scrollIntoView({behavior: 'smooth', block: 'start'}); } catch (err) {}
                    }, 150);
                }
            }

            var openClientModal = <?php echo json_encode((string) ($openClientModal ?? ''), CFMOD_SAFE_JSON_FLAGS); ?>;
            if (openClientModal === 'domainPermanentUpgradeModal' && typeof showDomainPermanentUpgradeModal === 'function') {
                showDomainPermanentUpgradeModal();
            } else if (openClientModal === 'domainPermanentIncentiveModal' && typeof showDomainPermanentIncentiveModal === 'function') {
                showDomainPermanentIncentiveModal();
            } else if (openClientModal === 'inviteRegistrationModal' && typeof showInviteRegistrationModal === 'function') {
                showInviteRegistrationModal();
            } else if (window.location.hash === '#domainPermanentUpgradeModal' && typeof showDomainPermanentUpgradeModal === 'function') {
                showDomainPermanentUpgradeModal();
            } else if (window.location.hash === '#domainPermanentIncentiveModal' && typeof showDomainPermanentIncentiveModal === 'function') {
                showDomainPermanentIncentiveModal();
            } else if (window.location.hash === '#inviteRegistrationModal' && typeof showInviteRegistrationModal === 'function') {
                showInviteRegistrationModal();
            }
        });

        <?php if ($quotaRedeemEnabled): ?>
        (function(){
            if (!window.bootstrap) { return; }
            var modalEl = document.getElementById('quotaRedeemModal');
            if (!modalEl) { return; }
            var bsModal = new bootstrap.Modal(modalEl);
            var form = document.getElementById('quotaRedeemForm');
            var codeInput = document.getElementById('redeemCodeInput');
            var submitBtn = document.getElementById('redeemSubmitButton');
            var submitBtnLabel = submitBtn ? submitBtn.innerHTML : '';
            var alertBox = document.getElementById('redeemAlertPlaceholder');
            var historyBody = document.getElementById('redeemHistoryBody');
            var paginationEl = document.getElementById('redeemHistoryPagination');
            var state = { historyLoaded: false, loading: false };

            function buildUrl(action) {
                return cfClientBuildModuleUrl(action);
            }

            function sendRedeem(action, payload) {
                return fetch(buildUrl(action), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CF_MOD_CSRF || ''
                    },
                    body: JSON.stringify(payload || {})
                }).then(function(res){ return res.json(); });
            }

            function showRedeemAlert(type, message) {
                if (!alertBox) { return; }
                var safeMessage = htmlEscape(message || '');
                alertBox.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                    safeMessage +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                    '</div>';
            }

            function htmlEscape(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            window.openQuotaRedeemModal = function(){
                bsModal.show();
                if (!state.historyLoaded) {
                    loadHistory(1);
                }
            };

            if (form) {
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    if (state.loading) { return; }
                    var code = codeInput ? codeInput.value.trim() : '';
                    if (!code) {
                        showRedeemAlert('warning', cfLang('redeemEnterCode', '请输入兑换码'));
                        if (codeInput) { codeInput.focus(); }
                        return;
                    }
                    state.loading = true;
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + cfLang('buttonSubmitting', '提交中...');
                    }
                    sendRedeem('ajax_redeem_quota_code', { code: code }).then(function(res){
                        if (res && res.success) {
                            showRedeemAlert('success', cfLang('redeemSuccess', '兑换成功，正在刷新页面'));
                            setTimeout(function(){ window.location.reload(); }, 1200);
                        } else {
                            var errMsg = (res && res.error) ? res.error : '';
                            var template = cfLang('redeemFailed', '兑换失败：%s');
                            showRedeemAlert('danger', template.replace('%s', errMsg));
                        }
                    }).catch(function(){
                        showRedeemAlert('danger', cfLang('networkError', '网络异常，请稍后再试'));
                    }).finally(function(){
                        state.loading = false;
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = submitBtnLabel || '<i class="fas fa-check-circle"></i>';
                        }
                    });
                });
            }

            function renderHistory(rows) {
                if (!historyBody) { return; }
                if (!rows || !rows.length) {
                    historyBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">' + cfLang('redeemHistoryEmpty', '暂无兑换记录') + '</td></tr>';
                    return;
                }
                var statusMap = {
                    success: { label: cfLang('cfclient.redeem.status.success', '成功'), className: 'success' },
                    failed: { label: cfLang('cfclient.redeem.status.failed', '失败'), className: 'danger' }
                };
                historyBody.innerHTML = rows.map(function(item){
                    var info = statusMap[item.status] || { label: String(item.status || '-'), className: 'secondary' };
                    var amount = typeof item.grant_amount !== 'undefined' ? ('+' + item.grant_amount) : '-';
                    var timeText = item.created_at || '-';
                    return '<tr>' +
                        '<td><code>' + htmlEscape(item.code || '') + '</code></td>' +
                        '<td>' + htmlEscape(amount) + '</td>' +
                        '<td>' + htmlEscape(timeText) + '</td>' +
                        '<td><span class="badge bg-' + info.className + '">' + htmlEscape(info.label) + '</span></td>' +
                        '</tr>';
                }).join('');
            }

            function renderPagination(meta) {
                if (!paginationEl) { return; }
                var page = meta.page || 1;
                var total = meta.total_pages || 1;
                if (total <= 1) {
                    paginationEl.innerHTML = '';
                    return;
                }
                var html = '';
                html += '<li class="page-item ' + (page === 1 ? 'disabled' : '') + '"><a class="page-link" data-redeem-page="' + Math.max(1, page - 1) + '" href="#">&laquo;</a></li>';
                for (var i = 1; i <= total; i++) {
                    html += '<li class="page-item ' + (i === page ? 'active' : '') + '"><a class="page-link" data-redeem-page="' + i + '" href="#">' + i + '</a></li>';
                }
                html += '<li class="page-item ' + (page === total ? 'disabled' : '') + '"><a class="page-link" data-redeem-page="' + Math.min(total, page + 1) + '" href="#">&raquo;</a></li>';
                paginationEl.innerHTML = html;
                paginationEl.querySelectorAll('[data-redeem-page]').forEach(function(link){
                    link.addEventListener('click', function(evt){
                        evt.preventDefault();
                        var target = parseInt(link.getAttribute('data-redeem-page'), 10);
                        if (!isNaN(target)) {
                            loadHistory(target);
                        }
                    });
                });
            }

            function loadHistory(page) {
                sendRedeem('ajax_list_quota_redeems', { page: page }).then(function(res){
                    if (res && res.success) {
                        renderHistory(res.data || []);
                        renderPagination(res.pagination || {});
                        state.historyLoaded = true;
                    } else {
                        showRedeemAlert('danger', (res && res.error) ? res.error : cfLang('redeemHistoryLoadFailed', '加载兑换记录失败'));
                    }
                }).catch(function(){
                    showRedeemAlert('danger', cfLang('redeemHistoryLoadFailed', '加载兑换记录失败'));
                });
            }
        })();
        <?php endif; ?>

        <?php if ($domainGiftEnabled): ?>
        (function(){
            const workbenchEl = document.getElementById('giftWorkbench');
            if (!workbenchEl) { return; }

            const state = {
                subdomains: <?php echo json_encode($domainGiftSubdomains, CFMOD_SAFE_JSON_FLAGS); ?>,
                selectedSubdomainId: 0,
                historyLoaded: false,
                latestVoucher: null,
                countdownTimer: null,
                recentRows: [],
                domainPage: 1,
                domainTotalPages: 1,
                domainKeyword: '',
                domainLoading: false
            };

            const domainSearchInput = document.getElementById('giftDomainSearchInput');
            const domainListEl = document.getElementById('giftDomainList');
            const domainPaginationEl = document.getElementById('giftDomainPagination');
            const domainSelect = document.getElementById('giftDomainSelect');
            const generateBtn = document.getElementById('generateGiftButton');
            const acceptBtn = document.getElementById('acceptGiftButton');
            const copyBtn = document.getElementById('giftCopyButton');
            const historyBody = document.getElementById('giftHistoryTableBody');
            const paginationEl = document.getElementById('giftHistoryPagination');
            const historyTab = document.getElementById('gift-history-tab');
            const pendingCard = document.getElementById('giftPendingVoucherCard');
            const pendingEmpty = document.getElementById('giftVoucherEmpty');
            const recentListEl = document.getElementById('giftRecentList');
            const codeValueEl = document.getElementById('giftCodeValue');
            const codeExpireEl = document.getElementById('giftCodeExpire');
            const codeDomainEl = document.getElementById('giftCodeDomain');
            const codeCountdownEl = document.getElementById('giftCodeCountdown');

            function htmlEscape(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function buildUrl(action) {
                return cfClientBuildModuleUrl(action);
            }

            function giftFetch(action, payload){
                return fetch(buildUrl(action), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CF_MOD_CSRF || ''
                    },
                    body: JSON.stringify(payload || {})
                }).then(function(res){ return res.json(); });
            }

            function giftAlert(type, message) {
                const placeholder = document.getElementById('giftAlertPlaceholder');
                if (!placeholder) { return; }
                placeholder.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                    message +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                    '</div>';
            }

            function setSelectedDomain(subdomainId) {
                state.selectedSubdomainId = subdomainId > 0 ? subdomainId : 0;
                if (domainSelect) {
                    domainSelect.value = state.selectedSubdomainId ? String(state.selectedSubdomainId) : '';
                }
            }

            function loadGiftDomains(page, keyword) {
                const nextPage = Math.max(1, parseInt(page || 1, 10) || 1);
                const nextKeyword = String(typeof keyword === 'string' ? keyword : (state.domainKeyword || '')).trim().toLowerCase();
                state.domainLoading = true;
                state.domainKeyword = nextKeyword;
                if (domainListEl) {
                    domainListEl.innerHTML = '<div class="text-muted small py-3 text-center">' + cfLang('giftDomainsLoading', '域名列表加载中...') + '</div>';
                }
                return giftFetch('ajax_search_domain_gift_subdomains', {
                    q: nextKeyword,
                    page: nextPage,
                    per_page: 20
                }).then(function(res){
                    if (res && res.success) {
                        const rows = Array.isArray(res.data) ? res.data : [];
                        const pagination = res.pagination || {};
                        state.subdomains = rows;
                        state.domainPage = Math.max(1, parseInt(pagination.page || nextPage, 10) || 1);
                        state.domainTotalPages = Math.max(1, parseInt(pagination.total_pages || 1, 10) || 1);
                        renderDomainOptions();
                        renderDomainPagination();
                    } else {
                        if (domainListEl) {
                            domainListEl.innerHTML = '<div class="text-danger small py-3 text-center">' + htmlEscape((res && res.error) ? res.error : cfLang('giftDomainLoadFailed', '加载域名列表失败')) + '</div>';
                        }
                    }
                }).catch(function(){
                    if (domainListEl) {
                        domainListEl.innerHTML = '<div class="text-danger small py-3 text-center">' + cfLang('networkError', '网络异常，请稍后再试') + '</div>';
                    }
                }).finally(function(){
                    state.domainLoading = false;
                });
            }

            function renderDomainOptions() {
                if (!domainListEl) { return; }
                const allRows = Array.isArray(state.subdomains) ? state.subdomains : [];
                const selectedExists = allRows.some(function(item){
                    return parseInt(item.id, 10) === state.selectedSubdomainId && !item.locked;
                });
                if (!selectedExists) {
                    const firstAvailable = allRows.find(function(item){ return !item.locked; });
                    setSelectedDomain(firstAvailable ? parseInt(firstAvailable.id, 10) : 0);
                }

                if (!allRows.length) {
                    domainListEl.innerHTML = '<div class="text-muted small py-3 text-center">' + cfLang('giftNoDomainMatch', <?php echo json_encode(strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese' ? '暂无满足转赠条件（满7天且可转赠）的域名' : 'No domains currently meet transfer conditions (registered for at least 7 days and transferable)', CFMOD_SAFE_JSON_FLAGS); ?>) + '</div>';
                    return;
                }

                domainListEl.innerHTML = allRows.map(function(item){
                    const id = parseInt(item.id, 10) || 0;
                    const checked = id === state.selectedSubdomainId;
                    const isLocked = !!item.locked;
                    const rowClass = 'gift-domain-item' + (checked ? ' is-selected' : '') + (isLocked ? ' is-locked' : '');
                    const lockBadge = isLocked
                        ? '<span class="gift-domain-lock-text"><i class="fas fa-lock me-1"></i>' + htmlEscape(cfLang('giftInTransfer', '转赠中')) + '</span>'
                        : '';
                    return '<label class="' + rowClass + '">' +
                        '<input class="form-check-input" type="radio" name="giftDomainPick" value="' + id + '" ' + (checked ? 'checked ' : '') + (isLocked ? 'disabled ' : '') + '/>' +
                        '<span class="gift-domain-main">' +
                            '<span class="gift-domain-name">' + htmlEscape(item.fullDomain || '-') + '</span>' + lockBadge +
                        '</span>' +
                    '</label>';
                }).join('');

                domainListEl.querySelectorAll('input[name="giftDomainPick"]').forEach(function(input){
                    input.addEventListener('change', function(){
                        const nextId = parseInt(input.value, 10) || 0;
                        setSelectedDomain(nextId);
                        renderDomainOptions();
                    });
                });
            }

            function renderDomainPagination() {
                if (!domainPaginationEl) { return; }
                const page = state.domainPage || 1;
                const total = state.domainTotalPages || 1;
                if (total <= 1) {
                    domainPaginationEl.innerHTML = '';
                    return;
                }
                const prevDisabled = page <= 1 ? 'disabled' : '';
                const nextDisabled = page >= total ? 'disabled' : '';
                domainPaginationEl.innerHTML =
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-gift-domain-page="' + Math.max(1, page - 1) + '" ' + prevDisabled + '>&laquo;</button>' +
                    '<span class="small text-muted"> ' + page + ' / ' + total + ' </span>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-gift-domain-page="' + Math.min(total, page + 1) + '" ' + nextDisabled + '>&raquo;</button>';
                domainPaginationEl.querySelectorAll('[data-gift-domain-page]').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const target = parseInt(btn.getAttribute('data-gift-domain-page'), 10);
                        if (!target || target === state.domainPage || state.domainLoading) { return; }
                        loadGiftDomains(target, state.domainKeyword);
                    });
                });
            }

            function parseDateTime(raw) {
                if (!raw) { return null; }
                const normalized = String(raw).trim().replace(' ', 'T');
                const date = new Date(normalized);
                return Number.isFinite(date.getTime()) ? date : null;
            }

            function formatCountdown(totalSeconds) {
                const safeSeconds = Math.max(0, totalSeconds | 0);
                const days = Math.floor(safeSeconds / 86400);
                const hours = Math.floor((safeSeconds % 86400) / 3600);
                const minutes = Math.floor((safeSeconds % 3600) / 60);
                const seconds = safeSeconds % 60;

                const hh = String(hours).padStart(2, '0');
                const mm = String(minutes).padStart(2, '0');
                const ss = String(seconds).padStart(2, '0');
                if (days > 0) {
                    return days + 'd ' + hh + ':' + mm + ':' + ss;
                }
                return hh + ':' + mm + ':' + ss;
            }

            function stopCountdown() {
                if (state.countdownTimer) {
                    clearInterval(state.countdownTimer);
                    state.countdownTimer = null;
                }
            }

            function startCountdown(expiresAt) {
                stopCountdown();
                if (!codeCountdownEl) { return; }
                const expiresAtDate = parseDateTime(expiresAt);
                if (!expiresAtDate) {
                    codeCountdownEl.textContent = '--:--:--';
                    codeCountdownEl.classList.remove('is-expired');
                    return;
                }

                const tick = function(){
                    const secondsLeft = Math.floor((expiresAtDate.getTime() - Date.now()) / 1000);
                    if (secondsLeft <= 0) {
                        codeCountdownEl.textContent = cfLang('giftExpired', '已过期');
                        codeCountdownEl.classList.add('is-expired');
                        stopCountdown();
                        return;
                    }
                    codeCountdownEl.textContent = formatCountdown(secondsLeft);
                    codeCountdownEl.classList.remove('is-expired');
                };

                tick();
                state.countdownTimer = setInterval(tick, 1000);
            }

            function showPendingVoucher(voucher, source) {
                if (!pendingCard || !pendingEmpty) { return; }
                state.latestVoucher = {
                    code: voucher.code || '',
                    full_domain: voucher.full_domain || '',
                    expires_at: voucher.expires_at || '',
                    source: source || 'history'
                };

                if (codeValueEl) {
                    codeValueEl.textContent = state.latestVoucher.code || '-';
                }
                if (codeExpireEl) {
                    codeExpireEl.textContent = state.latestVoucher.expires_at || '-';
                }
                if (codeDomainEl) {
                    codeDomainEl.textContent = state.latestVoucher.full_domain || '-';
                }

                pendingCard.classList.remove('d-none');
                pendingEmpty.classList.add('d-none');
                startCountdown(state.latestVoucher.expires_at || '');
            }

            function hidePendingVoucher() {
                if (!pendingCard || !pendingEmpty) { return; }
                state.latestVoucher = null;
                stopCountdown();
                pendingCard.classList.add('d-none');
                pendingEmpty.classList.remove('d-none');
            }

            function renderRecentRows(rows) {
                if (!recentListEl) { return; }
                if (!rows || !rows.length) {
                    recentListEl.innerHTML = '<div class="text-muted small">' + cfLang('giftHistoryEmpty', '暂无转赠记录') + '</div>';
                    return;
                }

                const statusMap = {
                    pending: { label: cfLang('giftStatusPending', '进行中'), className: 'warning' },
                    accepted: { label: cfLang('giftStatusAccepted', '已完成'), className: 'success' },
                    cancelled: { label: cfLang('giftStatusCancelled', '已取消'), className: 'secondary' },
                    expired: { label: cfLang('giftStatusExpired', '已过期'), className: 'danger' }
                };

                recentListEl.innerHTML = rows.slice(0, 3).map(function(item){
                    const info = statusMap[item.status] || { label: item.status || '-', className: 'secondary' };
                    const roleLabel = item.role === 'received'
                        ? cfLang('giftRoleReceived', '（接收）')
                        : cfLang('giftRoleSent', '（转赠）');
                    const timeline = item.completed_at || item.cancelled_at || item.created_at || '-';
                    return '<div class="gift-recent-item">' +
                        '<div class="d-flex justify-content-between align-items-start gap-2 mb-1">' +
                            '<div class="gift-recent-domain">' + htmlEscape((item.full_domain || '-') + roleLabel) + '</div>' +
                            '<span class="badge bg-' + info.className + '">' + htmlEscape(info.label) + '</span>' +
                        '</div>' +
                        '<div class="small text-muted">' + htmlEscape(timeline) + '</div>' +
                    '</div>';
                }).join('');
            }

            function syncWorkbenchSide(rows, forceHistoryVoucher) {
                state.recentRows = Array.isArray(rows) ? rows : [];
                renderRecentRows(state.recentRows);

                if (!forceHistoryVoucher && state.latestVoucher && state.latestVoucher.source === 'generated') {
                    return;
                }

                const pendingSent = state.recentRows.find(function(item){
                    return item.role === 'sent' && item.status === 'pending';
                });

                if (pendingSent) {
                    showPendingVoucher({
                        code: pendingSent.code,
                        full_domain: pendingSent.full_domain,
                        expires_at: pendingSent.expires_at
                    }, 'history');
                } else {
                    hidePendingVoucher();
                }
            }

            function renderHistory(rows) {
                if (!historyBody) { return; }
                if (!rows || !rows.length) {
                    historyBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">' + cfLang('giftHistoryEmpty', '暂无转赠记录') + '</td></tr>';
                    return;
                }
                const statusMap = {
                    pending: {label: cfLang('giftStatusPending', '进行中'), className: 'warning'},
                    accepted: {label: cfLang('giftStatusAccepted', '已完成'), className: 'success'},
                    cancelled: {label: cfLang('giftStatusCancelled', '已取消'), className: 'secondary'},
                    expired: {label: cfLang('giftStatusExpired', '已过期'), className: 'danger'}
                };

                historyBody.innerHTML = rows.map(function(item){
                    const info = statusMap[item.status] || {label: item.status || '-', className: 'secondary'};
                    const timeline = [];
                    if (item.created_at) timeline.push(cfLang('giftTimelineStart', '发起：') + item.created_at);
                    if (item.completed_at) timeline.push(cfLang('giftTimelineCompleted', '完成：') + item.completed_at);
                    else if (item.cancelled_at) timeline.push(cfLang('giftTimelineEnded', '结束：') + item.cancelled_at);

                    const codeCell = item.status === 'pending'
                        ? '<span class="badge bg-light text-dark">' + htmlEscape(item.code || '') + '</span>'
                        : '<span class="text-muted">' + htmlEscape(item.code || '') + '</span>';

                    const roleLabel = item.role === 'received'
                        ? cfLang('giftRoleReceived', '（接收）')
                        : cfLang('giftRoleSent', '（转赠）');

                    const canCancel = item.role === 'sent' && item.status === 'pending';
                    const actionCell = canCancel
                        ? '<button class="btn btn-sm btn-outline-danger" data-gift-cancel="' + parseInt(item.id, 10) + '"><i class="fas fa-ban"></i> ' + cfLang('giftActionCancel', '取消') + '</button>'
                        : '-';

                    return '<tr>' +
                        '<td>' + htmlEscape(roleLabel + (item.full_domain || '-')) + '</td>' +
                        '<td>' + codeCell + '</td>' +
                        '<td><span class="badge bg-' + info.className + '">' + htmlEscape(info.label) + '</span></td>' +
                        '<td class="small text-muted">' + (timeline.length ? timeline.map(htmlEscape).join('<br>') : '-') + '</td>' +
                        '<td class="text-end">' + actionCell + '</td>' +
                    '</tr>';
                }).join('');

                historyBody.querySelectorAll('[data-gift-cancel]').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const giftId = parseInt(btn.getAttribute('data-gift-cancel'), 10);
                        if (!giftId) { return; }
                        btn.disabled = true;
                        giftFetch('ajax_cancel_domain_gift', { gift_id: giftId }).then(function(res){
                            if (res && res.success) {
                                giftAlert('success', cfLang('giftCancelSuccess', '已取消转赠，即将刷新页面'));
                                setTimeout(function(){ window.location.reload(); }, 1200);
                            } else {
                                giftAlert('danger', (res && res.error) ? res.error : cfLang('giftCancelFailed', '取消失败，请稍后再试'));
                            }
                        }).catch(function(){
                            giftAlert('danger', cfLang('networkError', '网络异常，请稍后再试'));
                        }).finally(function(){
                            btn.disabled = false;
                        });
                    });
                });
            }

            function renderPagination(meta) {
                if (!paginationEl) { return; }
                const page = meta.page || 1;
                const total = meta.total_pages || 1;
                if (total <= 1) {
                    paginationEl.innerHTML = '';
                    return;
                }
                let html = '';
                html += '<li class="page-item ' + (page === 1 ? 'disabled' : '') + '"><a class="page-link" data-gift-page="' + Math.max(1, page - 1) + '" href="#">&laquo;</a></li>';
                for (let i = 1; i <= total; i++) {
                    html += '<li class="page-item ' + (i === page ? 'active' : '') + '"><a class="page-link" data-gift-page="' + i + '" href="#">' + i + '</a></li>';
                }
                html += '<li class="page-item ' + (page === total ? 'disabled' : '') + '"><a class="page-link" data-gift-page="' + Math.min(total, page + 1) + '" href="#">&raquo;</a></li>';
                paginationEl.innerHTML = html;
                paginationEl.querySelectorAll('[data-gift-page]').forEach(function(link){
                    link.addEventListener('click', function(e){
                        e.preventDefault();
                        const target = parseInt(link.getAttribute('data-gift-page'), 10);
                        if (!isNaN(target)) {
                            loadHistory(target, { forceHistoryVoucher: false });
                        }
                    });
                });
            }

            function loadHistory(page, options){
                const opts = options || {};
                return giftFetch('ajax_list_domain_gifts', { page: page }).then(function(res){
                    if (res && res.success) {
                        const rows = Array.isArray(res.data) ? res.data : [];
                        renderHistory(rows);
                        renderPagination(res.pagination || {});
                        state.historyLoaded = true;
                        if (page === 1 || opts.forceSide) {
                            syncWorkbenchSide(rows, !!opts.forceHistoryVoucher);
                        }
                    } else if (!opts.silent) {
                        giftAlert('danger', (res && res.error) ? res.error : cfLang('giftHistoryLoadFailed', '加载历史记录失败'));
                    }
                }).catch(function(){
                    if (!opts.silent) {
                        giftAlert('danger', cfLang('networkError', '网络异常，请稍后再试'));
                    }
                });
            }

            function setActiveGiftTab(tabName) {
                const tab = tabName || 'initiate';
                const trigger = document.querySelector('[data-bs-target="#gift-' + tab + '-pane"]');
                if (!trigger) { return; }
                if (window.bootstrap && bootstrap.Tab) {
                    bootstrap.Tab.getOrCreateInstance(trigger).show();
                } else {
                    trigger.click();
                }
            }

            window.openDomainGiftModal = function(tab){
                setActiveGiftTab(tab);
                workbenchEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            if (domainSearchInput) {
                let giftDomainSearchTimer = null;
                domainSearchInput.addEventListener('input', function(){
                    if (giftDomainSearchTimer) {
                        clearTimeout(giftDomainSearchTimer);
                    }
                    giftDomainSearchTimer = setTimeout(function(){
                        loadGiftDomains(1, domainSearchInput.value || '');
                    }, 250);
                });
            }

            if (generateBtn) {
                generateBtn.addEventListener('click', function(){
                    const subId = parseInt(domainSelect ? domainSelect.value : '0', 10);
                    if (!subId) {
                        giftAlert('warning', cfLang('giftSelectRequired', '请选择要转赠的域名'));
                        return;
                    }
                    generateBtn.disabled = true;
                    giftFetch('ajax_initiate_domain_gift', { subdomain_id: subId }).then(function(res){
                        if (res && res.success) {
                            giftAlert('success', cfLang('giftGenerateSuccess', '接收码已生成，请尽快分享给受赠人。'));
                            showPendingVoucher({
                                code: (res.data && res.data.code) || '',
                                expires_at: (res.data && res.data.expires_at) || '',
                                full_domain: (res.data && res.data.full_domain) || ''
                            }, 'generated');

                            state.subdomains = state.subdomains.map(function(item){
                                if ((parseInt(item.id, 10) || 0) === subId) {
                                    item.locked = true;
                                }
                                return item;
                            });

                            const nextAvailable = state.subdomains.find(function(item){ return !item.locked; });
                            setSelectedDomain(nextAvailable ? parseInt(nextAvailable.id, 10) : 0);
                            renderDomainOptions();
                            renderDomainPagination();
                            loadHistory(1, { forceSide: true, forceHistoryVoucher: false, silent: true });
                        } else {
                            giftAlert('danger', (res && res.error) ? res.error : cfLang('giftGenerateFailed', '生成接收码失败，请稍后再试'));
                        }
                    }).catch(function(){
                        giftAlert('danger', cfLang('networkError', '网络异常，请稍后再试'));
                    }).finally(function(){
                        generateBtn.disabled = false;
                    });
                });
            }

            if (copyBtn) {
                copyBtn.addEventListener('click', function(){
                    const code = codeValueEl ? (codeValueEl.textContent || '').trim() : '';
                    if (!code || code === '-') {
                        giftAlert('warning', cfLang('giftCopyEmpty', '暂无可复制的接收码'));
                        return;
                    }

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(code).then(function(){
                            giftAlert('success', cfLang('giftCopySuccess', '接收码已复制'));
                        }).catch(function(){
                            giftAlert('warning', cfLang('giftCopyFailed', '复制失败，请手动复制'));
                        });
                    } else if (typeof copyText === 'function') {
                        copyText(code);
                        giftAlert('success', cfLang('giftCopySuccess', '接收码已复制'));
                    }
                });
            }

            if (acceptBtn) {
                acceptBtn.addEventListener('click', function(){
                    const input = document.getElementById('giftAcceptCode');
                    const code = (input ? input.value : '').trim();
                    if (!code) {
                        giftAlert('warning', cfLang('giftEnterCode', '请输入接收码'));
                        return;
                    }
                    acceptBtn.disabled = true;
                    giftFetch('ajax_accept_domain_gift', { code: code }).then(function(res){
                        if (res && res.success) {
                            giftAlert('success', cfLang('giftAcceptSuccess', '领取成功，即将刷新页面'));
                            setTimeout(function(){ window.location.reload(); }, 1500);
                        } else {
                            giftAlert('danger', (res && res.error) ? res.error : cfLang('giftAcceptFailed', '领取失败，请稍后再试'));
                        }
                    }).catch(function(){
                        giftAlert('danger', cfLang('networkError', '网络异常，请稍后再试'));
                    }).finally(function(){
                        acceptBtn.disabled = false;
                    });
                });
            }

            if (historyTab) {
                historyTab.addEventListener('shown.bs.tab', function(){
                    if (!state.historyLoaded) {
                        loadHistory(1, { forceSide: true, forceHistoryVoucher: false });
                    }
                });
            }

            loadGiftDomains(1, '');
            loadHistory(1, { forceSide: true, forceHistoryVoucher: true, silent: true });
        })();
        <?php endif; ?>

        <?php if (!empty($whoisFeatureEnabled)): ?>
        (function(){
            var form = document.getElementById('whoisLookupForm');
            if (!form) { return; }

            var whoisIsChinese = <?php echo strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese' ? 'true' : 'false'; ?>;
            var t = function(key, zh, en) {
                return cfLang(key, whoisIsChinese ? zh : en);
            };

            var domainInput = document.getElementById('whoisLookupDomainInput');
            var lookupBtn = document.getElementById('whoisLookupButton');
            var resultCard = document.getElementById('whoisResultCard');
            var resultContainer = document.getElementById('whoisResultContainer');
            var alertContainer = document.getElementById('whoisAlertContainer');
            var privacyToggle = document.getElementById('whoisPrivacyToggle');
            var privacySaveBtn = document.getElementById('whoisPrivacySaveButton');

            function buildUrl(action) {
                return cfClientBuildModuleUrl(action);
            }

            function send(action, payload) {
                return fetch(buildUrl(action), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CF_MOD_CSRF || ''
                    },
                    body: JSON.stringify(payload || {})
                }).then(function(res){ return res.json(); });
            }

            function showAlert(type, message) {
                if (!alertContainer) { return; }
                var safeMessage = escapeHtml(message || '');
                alertContainer.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                    safeMessage +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                    '</div>';
            }

            function renderResult(data) {
                if (!resultCard || !resultContainer) { return; }
                resultCard.style.display = '';

                var escapeHtml = function(value) {
                    return String(value || '').replace(/[&<>"']/g, function(ch){
                        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
                    });
                };

                var ns = Array.isArray(data.name_servers) ? data.name_servers : [];
                var nsHtml = ns.length ? '<ul class="mb-0 ps-3">' + ns.map(function(item){ return '<li>' + escapeHtml(item) + '</li>'; }).join('') + '</ul>' : '-';
                var sourceText = '';
                if (data.source === 'internal') {
                    sourceText = t('whoisSourceInternal', '系统记录', 'Internal');
                } else if (data.source === 'external') {
                    sourceText = t('whoisSourceExternal', '外部 WHOIS', 'External WHOIS');
                } else if (typeof data.source === 'string' && data.source.trim() !== '') {
                    sourceText = data.source.trim();
                } else {
                    sourceText = t('whoisSourceExternal', '外部 WHOIS', 'External WHOIS');
                }

                var statusValue = String(data.status || '').toLowerCase();
                var isUnregistered = data.registered === false || statusValue === 'unregistered';
                var sourceTypeValue = String(data.source_type || data.source || '').toLowerCase();
                var isExternalSource = sourceTypeValue === 'external';
                var rows = [];
                rows.push([t('whoisDomain', '域名', 'Domain'), escapeHtml(data.domain || '-')]);
                rows.push([t('whoisSource', '来源', 'Source'), escapeHtml(sourceText)]);
                var registrationStateText = isUnregistered
                    ? t('whoisStatusUnregistered', '未注册', 'Unregistered')
                    : (statusValue === 'serverhold'
                        ? t('whoisStatusServerHold', 'ServerHold', 'ServerHold')
                        : t('whoisStatusRegistered', '已注册', 'Registered'));
                rows.push([t('whoisRegisterState', '注册状态', 'Registration Status'), escapeHtml(registrationStateText)]);
                rows.push([t('whoisCreatedAt', '注册时间', 'Created At'), escapeHtml(data.created_at || '-')]);
                rows.push([t('whoisExpiresAt', '到期时间', 'Expires At'), escapeHtml(data.expires_at || '-')]);

                if (isUnregistered) {
                    rows.push([t('whoisLookupMessage', '查询结果', 'Lookup Result'), escapeHtml(data.message || t('whoisStatusUnregistered', '未注册', 'Unregistered'))]);
                } else if (!isExternalSource) {
                    var privacyEnabled = data.privacy_enabled === true
                        || data.privacy_enabled === 1
                        || data.privacy_enabled === '1'
                        || data.privacy_enabled === 'true'
                        || data.privacy_enabled === 'on'
                        || data.privacy_enabled === 'yes';
                    if (privacyEnabled) {
                        rows.push([t('whoisPrivacyStatus', 'WHOIS 隐私', 'WHOIS Privacy'), escapeHtml(t('whoisPrivacyOn', '已开启', 'Enabled'))]);
                    }
                    rows.push([t('whoisRegistrantName', '所有者姓名', 'Registrant Name'), escapeHtml(data.registrant_name || '-')]);
                    rows.push([t('whoisRegistrantEmail', '注册邮箱', 'Registrant Email'), escapeHtml(data.registrant_email || '-')]);
                    rows.push([t('whoisRegistrantPhone', '联系电话', 'Registrant Phone'), escapeHtml(data.registrant_phone || '-')]);
                    rows.push([t('whoisRegistrantCountry', '国家', 'Country'), escapeHtml(data.registrant_country || '-')]);

                    var provinceValue = data.registrant_province || '';
                    var cityValue = data.registrant_city || '';
                    if (!provinceValue && !cityValue && data.registrant_province_city) {
                        var provinceCityRaw = String(data.registrant_province_city || '').trim();
                        if (provinceCityRaw.indexOf('-') > -1) {
                            var provinceCityParts = provinceCityRaw.split('-');
                            provinceValue = provinceCityParts.shift() || '';
                            cityValue = provinceCityParts.join('-') || '';
                        } else {
                            provinceValue = provinceCityRaw;
                        }
                    }

                    rows.push([t('whoisRegistrantProvince', '省份', 'Province'), escapeHtml(provinceValue || '-')]);
                    rows.push([t('whoisRegistrantCity', '城市', 'City'), escapeHtml(cityValue || '-')]);
                    rows.push([t('whoisRegistrantAddress', '地址', 'Address'), escapeHtml(data.registrant_address || '-')]);
                }
                rows.push([t('whoisNameServers', 'DNS服务器', 'DNS Servers'), nsHtml]);

                var html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>';
                rows.forEach(function(row){
                    html += '<tr><th style="width:220px;">' + escapeHtml(row[0]) + '</th><td>' + row[1] + '</td></tr>';
                });
                html += '</tbody></table></div>';

                if (data.raw) {
                    html += '<div class="mt-3"><div class="small text-muted mb-1">RAW WHOIS</div><pre class="bg-light border rounded p-2 small mb-0" style="max-height:280px; overflow:auto;">' +
                        String(data.raw).replace(/[&<>]/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;'})[ch]; }) +
                        '</pre></div>';
                }

                resultContainer.innerHTML = html;
            }

            if (privacySaveBtn && privacyToggle) {
                privacySaveBtn.addEventListener('click', function(){
                    privacySaveBtn.disabled = true;
                    send('ajax_whois_privacy_update', {
                        privacy_enabled: privacyToggle.checked ? 1 : 0
                    }).then(function(res){
                        if (res && res.success) {
                            showAlert('success', t('whoisPrivacySaved', 'WHOIS 隐私设置已保存并应用于当前账号下所有域名', 'WHOIS privacy setting saved and applied to all domains under your current account'));
                        } else {
                            showAlert('danger', (res && res.error) ? res.error : t('whoisPrivacySaveFailed', '保存失败，请稍后再试', 'Save failed, please try again'));
                        }
                    }).catch(function(){
                        showAlert('danger', t('networkError', '网络异常，请稍后再试', 'Network error, please try again'));
                    }).finally(function(){
                        privacySaveBtn.disabled = false;
                    });
                });
            }

            form.addEventListener('submit', function(e){
                e.preventDefault();
                if (!domainInput) { return; }
                var domain = (domainInput.value || '').trim();
                if (!domain) {
                    showAlert('warning', t('whoisDomainRequired', '请输入要查询的域名', 'Please enter a domain to lookup'));
                    domainInput.focus();
                    return;
                }
                if (lookupBtn) {
                    lookupBtn.disabled = true;
                    lookupBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t('buttonLoading', '查询中...', 'Loading...');
                }
                send('ajax_whois_lookup', { domain: domain }).then(function(res){
                    if (res && res.success && res.data) {
                        renderResult(res.data);
                        showAlert('success', t('whoisLookupSuccess', 'WHOIS 查询成功', 'WHOIS lookup succeeded'));
                    } else {
                        showAlert('danger', (res && res.error) ? res.error : t('whoisLookupFailed', 'WHOIS 查询失败', 'WHOIS lookup failed'));
                    }
                }).catch(function(){
                    showAlert('danger', t('networkError', '网络异常，请稍后再试', 'Network error, please try again'));
                }).finally(function(){
                    if (lookupBtn) {
                        lookupBtn.disabled = false;
                        lookupBtn.innerHTML = '<i class="fas fa-search me-1"></i>' + t('whoisLookupSubmit', '查询 WHOIS', 'Lookup WHOIS');
                    }
                });
            });
        })();
        <?php endif; ?>

        <?php if (!empty($digFeatureEnabled)): ?>
        (function(){
            var form = document.getElementById('digLookupForm');
            if (!form) { return; }

            var digIsChinese = <?php echo strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese' ? 'true' : 'false'; ?>;
            var t = function(key, zh, en) {
                return cfLang(key, digIsChinese ? zh : en);
            };

            var domainInput = document.getElementById('digLookupDomainInput');
            var typeSelect = document.getElementById('digLookupTypeSelect');
            var lookupBtn = document.getElementById('digLookupButton');
            var resultCard = document.getElementById('digResultCard');
            var resultContainer = document.getElementById('digResultContainer');
            var alertContainer = document.getElementById('digAlertContainer');

            function buildUrl(action) {
                return cfClientBuildModuleUrl(action);
            }

            function send(action, payload) {
                return fetch(buildUrl(action), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.CF_MOD_CSRF || ''
                    },
                    body: JSON.stringify(payload || {})
                }).then(function(res){ return res.json(); });
            }

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function(ch){
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
                });
            }

            function showAlert(type, message) {
                if (!alertContainer) { return; }
                var safeMessage = escapeHtml(message || '');
                alertContainer.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                    safeMessage +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                    '</div>';
            }

            function renderResult(data) {
                if (!resultCard || !resultContainer) { return; }
                resultCard.style.display = '';

                var summary = data.summary || {};
                var resolverTotal = parseInt(summary.resolver_total || 0, 10) || 0;
                var resolverSuccess = parseInt(summary.resolver_success || 0, 10) || 0;
                var resolverWithAnswer = parseInt(summary.resolver_with_answer || 0, 10) || 0;
                var consensus = !!summary.consensus;
                var duration = parseInt(data.duration_ms || 0, 10) || 0;
                var flattenValues = Array.isArray(summary.flatten_values) ? summary.flatten_values : [];
                var resolvers = Array.isArray(data.resolvers) ? data.resolvers : [];

                var summaryHtml = '';
                summaryHtml += '<div class="d-flex flex-wrap gap-2 mb-3">';
                summaryHtml += '<span class="badge bg-light text-dark">' + t('digSummaryDomain', '域名', 'Domain') + ': ' + escapeHtml(data.domain || '-') + '</span>';
                summaryHtml += '<span class="badge bg-light text-dark">' + t('digSummaryType', '类型', 'Type') + ': ' + escapeHtml(data.record_type || '-') + '</span>';
                summaryHtml += '<span class="badge bg-light text-dark">' + t('digSummaryDuration', '耗时', 'Duration') + ': ' + duration + 'ms</span>';
                summaryHtml += '<span class="badge bg-info text-dark">' + t('digSummaryResolver', '解析器成功', 'Resolver Success') + ': ' + resolverSuccess + '/' + resolverTotal + '</span>';
                summaryHtml += '<span class="badge bg-secondary">' + t('digSummaryAnswer', '返回结果', 'With Answer') + ': ' + resolverWithAnswer + '</span>';
                summaryHtml += '<span class="badge ' + (consensus ? 'bg-success' : 'bg-warning text-dark') + '">' +
                    (consensus ? t('digConsensusYes', '结果一致', 'Consensus') : t('digConsensusNo', '结果存在差异', 'Diverged')) +
                    '</span>';
                summaryHtml += '</div>';

                if (flattenValues.length > 0) {
                    summaryHtml += '<div class="small text-muted mb-2">' + t('digFlattenValues', '合并结果', 'Merged Values') + ':</div>';
                    summaryHtml += '<div class="mb-3">' + flattenValues.map(function(item){
                        return '<code class="me-2">' + escapeHtml(item) + '</code>';
                    }).join('') + '</div>';
                }

                var tableHtml = '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                tableHtml += '<thead><tr>' +
                    '<th style="min-width:160px;">' + t('digTableResolver', '解析器', 'Resolver') + '</th>' +
                    '<th style="width:120px;">' + t('digTableStatus', '状态', 'Status') + '</th>' +
                    '<th style="width:120px;">RTT</th>' +
                    '<th>' + t('digTableValues', '记录值', 'Record Values') + '</th>' +
                    '<th>' + t('digTableError', '错误', 'Error') + '</th>' +
                    '</tr></thead><tbody>';

                if (resolvers.length === 0) {
                    tableHtml += '<tr><td colspan="5" class="text-center text-muted py-3">' + t('digNoResolver', '暂无解析器返回', 'No resolver response') + '</td></tr>';
                } else {
                    resolvers.forEach(function(item){
                        var values = Array.isArray(item.values) ? item.values : [];
                        var statusBadge = item.success
                            ? '<span class="badge bg-success">' + t('digStatusOk', '成功', 'OK') + '</span>'
                            : '<span class="badge bg-danger">' + t('digStatusFail', '失败', 'Failed') + '</span>';
                        var valuesHtml = values.length > 0
                            ? values.map(function(v){ return '<code class="me-1">' + escapeHtml(v) + '</code>'; }).join('')
                            : '<span class="text-muted">-</span>';
                        tableHtml += '<tr>' +
                            '<td>' + escapeHtml(item.resolver_name || item.resolver_key || '-') + '</td>' +
                            '<td>' + statusBadge + '</td>' +
                            '<td>' + escapeHtml(item.response_ms || 0) + 'ms</td>' +
                            '<td>' + valuesHtml + '</td>' +
                            '<td class="small text-muted">' + escapeHtml(item.error || '-') + '</td>' +
                            '</tr>';
                    });
                }

                tableHtml += '</tbody></table></div>';
                resultContainer.innerHTML = summaryHtml + tableHtml;
            }

            form.addEventListener('submit', function(e){
                e.preventDefault();
                if (!domainInput) { return; }

                var domain = (domainInput.value || '').trim();
                var recordType = typeSelect ? (typeSelect.value || 'A') : 'A';

                if (!domain) {
                    showAlert('warning', t('digDomainRequired', '请输入域名', 'Please enter a domain'));
                    domainInput.focus();
                    return;
                }

                if (lookupBtn) {
                    lookupBtn.disabled = true;
                    lookupBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t('buttonLoading', '查询中...', 'Loading...');
                }

                send('ajax_dig_lookup', {
                    domain: domain,
                    record_type: recordType
                }).then(function(res){
                    if (res && res.success && res.data) {
                        renderResult(res.data);
                        showAlert('success', t('digLookupSuccess', 'Dig 查询成功', 'Dig lookup succeeded'));
                    } else {
                        showAlert('danger', (res && res.error) ? res.error : t('digLookupFailed', 'Dig 查询失败', 'Dig lookup failed'));
                    }
                }).catch(function(){
                    showAlert('danger', t('networkError', '网络异常，请稍后再试', 'Network error, please try again'));
                }).finally(function(){
                    if (lookupBtn) {
                        lookupBtn.disabled = false;
                        lookupBtn.innerHTML = '<i class="fas fa-search-location me-1"></i>' + t('digLookupSubmit', '开始 Dig 探测', 'Run Dig Probe');
                    }
                });
            });
        })();
        <?php endif; ?>

        // 复制

        // 展开/收起域名详情
        function toggleSubdomainDetails(subdomainId) {
            const detailsRow = document.getElementById('details_' + subdomainId);
            if (detailsRow) {
                if (detailsRow.style.display === 'none') {
                    detailsRow.style.display = 'table-row';
                } else {
                    detailsRow.style.display = 'none';
                }
            }
        }
        
        // 控制消息提示的显示
        function dismissMessage() {
            const messageAlert = document.getElementById('messageAlert');
            if (messageAlert) {
                messageAlert.style.display = 'none';
            }
        }
        
        // 页面加载完成后，确保消息提示不会自动消失
        document.addEventListener('DOMContentLoaded', function() {
            const messageAlert = document.getElementById('messageAlert');
            if (messageAlert) {
                // 移除Bootstrap的自动消失功能
                messageAlert.classList.remove('fade');
                messageAlert.classList.remove('show');
                // 添加自定义样式
                messageAlert.style.opacity = '1';
                messageAlert.style.display = 'block';
            }
        });
        
        // 确保注册模态框中的重要说明始终可见
        document.addEventListener('DOMContentLoaded', function() {
            const registerModal = document.getElementById('registerModal');
            if (registerModal) {
                registerModal.addEventListener('shown.bs.modal', function() {
                    const importantInfo = document.getElementById('registerImportantInfo');
                    if (importantInfo) {
                        importantInfo.style.display = 'block';
                        importantInfo.style.opacity = '1';
                        importantInfo.style.visibility = 'visible';
                    }
                });
            }
        });
        
        // 确保DNS设置模态框中的提示始终可见
        document.addEventListener('DOMContentLoaded', function() {
            const dnsModal = document.getElementById('dnsModal');
            if (dnsModal) {
                dnsModal.addEventListener('shown.bs.modal', function() {
                    const importantInfo = document.getElementById('dnsImportantInfo');
                    const usageTips = document.getElementById('dnsUsageTips');
                    
                    if (importantInfo) {
                        importantInfo.style.display = 'block';
                        importantInfo.style.opacity = '1';
                        importantInfo.style.visibility = 'visible';
                    }
                    
                    if (usageTips) {
                        usageTips.style.display = 'block';
                        usageTips.style.opacity = '1';
                        usageTips.style.visibility = 'visible';
                    }
                });
            }
        });
        


        // 过滤应用/重置
        const fType=document.getElementById('flt_type');
        const fName=document.getElementById('flt_name');
        const fApply=document.getElementById('flt_apply');
        const fReset=document.getElementById('flt_reset');
        if(fApply){
          fApply.addEventListener('click',()=>{
            const url=new URL(location.href);
            if(fType&&fType.value) url.searchParams.set('filter_type',fType.value); else url.searchParams.delete('filter_type');
            if(fName&&fName.value) url.searchParams.set('filter_name',fName.value); else url.searchParams.delete('filter_name');
            url.searchParams.delete('dns_page');
            url.searchParams.delete('dns_for');
            url.searchParams.delete('page');
            location.href=url.toString();
          });
        }
        if(fReset){ fReset.addEventListener('click',()=>{ const url=new URL(location.href); url.searchParams.delete('filter_type'); url.searchParams.delete('filter_name'); url.searchParams.delete('dns_page'); url.searchParams.delete('dns_for'); url.searchParams.delete('page'); location.href=url.toString(); }); }

        // 冲突校验（A/AAAA 与 CNAME 互斥）
        document.getElementById('dnsForm').addEventListener('submit', function(e) {
          const type = document.querySelector('select[name="record_type"]').value.toUpperCase();
          const nameBase = document.getElementById('dns_subdomain_name').value;
          const recName = (document.getElementById('dns_record_name').value || '@');
          const fullName = recName==='@' ? nameBase : (recName + '.' + nameBase);
          // 构建当前名称的已有类型集合
          const typesHere = [];
        });

        // 解析预览：提交前生成摘要
        function showPreviewAndSubmit(form){
const type = form.record_type.value;
let content = form.record_content.value;
const ttlOption = form.record_ttl.options[form.record_ttl.selectedIndex];
const ttl = ttlOption ? ttlOption.textContent : form.record_ttl.value;
const line = form.line?.value || 'default';
const nameBase = document.getElementById('dns_subdomain_name').value;
const recName = form.record_name.value || '@';
const fullName = recName==='@' ? nameBase : (recName + '.' + nameBase);
const action = document.getElementById('dns_action').value;
// 如果是CAA记录，显示组合后的内容
if (type === 'CAA') {
const caaFlag = form.caa_flag?.value || '0';
const caaTag = form.caa_tag?.value || 'issue';
const caaValue = form.caa_value?.value || '';
if (!caaValue) {
alert(cfLang('caaValueRequired', 'CAA记录的Value不能为空'));
return;
}
content = `${caaFlag} ${caaTag} "${caaValue}"`;
form.record_content.value = content;
}
if (type === 'SRV') {
const priority = parseInt(form.record_priority.value || '0', 10);
const weight = parseInt(form.record_weight?.value || '0', 10);
const port = parseInt(form.record_port?.value || '0', 10);
let target = form.record_target?.value.trim() || '';
if (!Number.isFinite(priority) || priority < 0 || priority > 65535) {
alert(cfLang('srvPriorityInvalid', 'SRV记录的优先级必须在0-65535之间'));
return;
}
if (!Number.isFinite(weight) || weight < 0 || weight > 65535) {
alert(cfLang('srvWeightInvalid', 'SRV记录的权重必须在0-65535之间'));
return;
}
if (!Number.isFinite(port) || port < 1 || port > 65535) {
alert(cfLang('srvPortInvalid', 'SRV记录的端口必须在1-65535之间'));
return;
}
if (target.endsWith('.')) {
target = target.slice(0, -1);
}
if (!target) {
alert(cfLang('srvTargetRequired', 'SRV记录的目标地址不能为空'));
return;
}
if (!isValidDomain(target)) {
alert(cfLang('srvTargetInvalid', '请输入有效的SRV目标主机名'));
return;
}
content = `${priority} ${weight} ${port} ${target}`;
form.record_content.value = content;
}
const actionLabel = action==='create_dns' ? cfLang('dnsActionCreate', '创建') : cfLang('dnsActionUpdate', '更新');
const summary = cfLangFormat('dnsConfirmSummary', '将要%1$s记录\n名称: %2$s\n类型: %3$s\n内容: %4$s\nTTL: %5$s\n线路: %6$s', actionLabel, fullName, type, content, ttl, line);
if(confirm(summary + "\n\n" + cfLang('dnsConfirmPrompt', '确认提交吗？'))){
form.submit();
}
}
</script>
