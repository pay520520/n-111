<?php
$footerConfig = $cfAdminFooterConfig ?? [];
$footerLang = $footerConfig['lang'] ?? [];
$csrfToken = (string)($footerConfig['csrfToken'] ?? '');
$announcementEnabled = !empty($footerConfig['announcement']['enabled']);
$quotaEndpoint = $footerConfig['api']['quotaEndpoint'] ?? '?module=domain_hub&action=get_user_quota&userid=';
$subdomainDnsEndpoint = $footerConfig['api']['subdomainDnsEndpoint'] ?? '?module=domain_hub&action=get_subdomain_dns_records&subdomain_id=';
?>
<script>
(function(){
  var token = <?php echo json_encode($csrfToken, CFMOD_SAFE_JSON_FLAGS); ?> || '';
  window.CF_MOD_ADMIN_CSRF = token;
  function inject(scope){
    if (!scope || !token) { return; }
    scope.querySelectorAll('form').forEach(function(form){
      if (form.dataset.cfmodSkipCsrf === '1') { return; }
      if (form.querySelector("input[name='cfmod_admin_csrf']")) { return; }
      var el = document.createElement('input');
      el.type = 'hidden';
      el.name = 'cfmod_admin_csrf';
      el.value = token;
      form.appendChild(el);
    });
  }
  if (document.readyState !== 'loading') {
    inject(document);
  } else {
    document.addEventListener('DOMContentLoaded', function(){ inject(document); });
  }
})();
</script>
<script>
(function(){
  var lang = <?php echo json_encode($footerLang, CFMOD_SAFE_JSON_FLAGS); ?> || {};
  var quotaEndpoint = <?php echo json_encode($quotaEndpoint, CFMOD_SAFE_JSON_FLAGS); ?>;
  var subdomainDnsEndpoint = <?php echo json_encode($subdomainDnsEndpoint, CFMOD_SAFE_JSON_FLAGS); ?>;
  var heavyStatsEndpoint = <?php echo json_encode(($footerConfig['api']['heavyStatsEndpoint'] ?? '?module=domain_hub&action=get_admin_heavy_stats'), CFMOD_SAFE_JSON_FLAGS); ?>;
  function format(template, value){
    if (!template) { return ''; }
    return template.replace('%d', value);
  }

  function alertMessage(prefixKey, detail){
    var prefix = lang[prefixKey] || '';
    alert(prefix + detail);
  }

  function formatLocalDateTime(ts){
    if (!ts || Number.isNaN(Number(ts))) { return '-'; }
    try {
      var date = new Date(Number(ts) * 1000);
      var yyyy = date.getFullYear();
      var mm = String(date.getMonth() + 1).padStart(2, '0');
      var dd = String(date.getDate()).padStart(2, '0');
      var hh = String(date.getHours()).padStart(2, '0');
      var mi = String(date.getMinutes()).padStart(2, '0');
      var ss = String(date.getSeconds()).padStart(2, '0');
      return yyyy + '-' + mm + '-' + dd + ' ' + hh + ':' + mi + ':' + ss;
    } catch (e) {
      return '-';
    }
  }

  function updateHeavyStatsStatus(meta){
    var statusEl = document.getElementById('cfmod-heavy-stats-status');
    var generatedEl = document.getElementById('cfmod-heavy-stats-generated-at');
    var statusTextEl = document.getElementById('cfmod-heavy-stats-status-text');
    if (!statusEl) { return; }

    var stale = !!(meta && meta.stale);
    var pending = !!(meta && meta.pending);
    var generatedAt = meta && meta.generated_at ? Number(meta.generated_at) : 0;

    var text = '缓存就绪';
    if (pending) {
      text = '统计数据后台刷新中…';
    } else if (stale) {
      text = '缓存已过期，等待后台刷新';
    }

    statusEl.dataset.pending = pending ? '1' : '0';
    statusEl.dataset.stale = stale ? '1' : '0';
    statusEl.dataset.generatedAt = String(generatedAt || 0);

    if (statusTextEl) {
      statusTextEl.textContent = text;
    }

    var generatedText = formatLocalDateTime(generatedAt);
    if (generatedEl) {
      generatedEl.textContent = generatedText;
      return;
    }
    statusEl.textContent = text + '（缓存时间：' + generatedText + '）';
  }

  function renderRows(tbodyId, rows, options){
    var tbody = document.getElementById(tbodyId);
    if (!tbody) { return; }
    var cfg = options || {};
    var columns = Number(cfg.columns || 2);
    var badgeClass = cfg.badgeClass || 'bg-primary';
    var textKey = cfg.textKey || 'name';
    var numberKey = cfg.numberKey || 'count';
    var emptyText = tbody.dataset.emptyText || '暂无数据';

    tbody.innerHTML = '';
    if (!Array.isArray(rows) || rows.length === 0) {
      var emptyTr = document.createElement('tr');
      var emptyTd = document.createElement('td');
      emptyTd.colSpan = columns;
      emptyTd.className = 'text-center text-muted';
      emptyTd.textContent = emptyText;
      emptyTr.appendChild(emptyTd);
      tbody.appendChild(emptyTr);
      return;
    }

    rows.forEach(function(item){
      var tr = document.createElement('tr');
      var tdText = document.createElement('td');
      var tdValue = document.createElement('td');
      tdText.textContent = String((item && item[textKey]) || '');
      var badge = document.createElement('span');
      badge.className = 'badge ' + badgeClass;
      badge.textContent = String(Number((item && item[numberKey]) || 0));
      tdValue.appendChild(badge);
      tr.appendChild(tdText);
      tr.appendChild(tdValue);
      tbody.appendChild(tr);
    });
  }

  function applyHeavyStats(stats, meta){
    if (!stats || typeof stats !== 'object') { return; }
    var totalSubdomains = document.getElementById('cfmod-stat-total-subdomains');
    var activeSubdomains = document.getElementById('cfmod-stat-active-subdomains');
    var registeredUsers = document.getElementById('cfmod-stat-registered-users');
    var subdomainsCreated = document.getElementById('cfmod-stat-subdomains-created');
    var dnsOperations = document.getElementById('cfmod-stat-dns-operations');

    if (totalSubdomains) { totalSubdomains.textContent = String(Number(stats.totalSubdomains || 0)); }
    if (activeSubdomains) { activeSubdomains.textContent = String(Number(stats.activeSubdomains || 0)); }
    if (registeredUsers) { registeredUsers.textContent = String(Number(stats.registeredUsers || 0)); }
    if (subdomainsCreated) { subdomainsCreated.textContent = String(Number(stats.subdomainsCreated || 0)); }
    if (dnsOperations) { dnsOperations.textContent = String(Number(stats.dnsOperations || 0)); }

    renderRows('cfmod-stat-trend-body', stats.registrationTrend || [], {
      columns: 2,
      badgeClass: 'bg-primary',
      textKey: 'date',
      numberKey: 'count'
    });
    renderRows('cfmod-stat-usage-body', stats.usagePatterns || [], {
      columns: 2,
      badgeClass: 'bg-secondary',
      textKey: 'usage_level',
      numberKey: 'user_count'
    });
    renderRows('cfmod-stat-root-body', stats.popularRootdomains || [], {
      columns: 2,
      badgeClass: 'bg-info text-dark',
      textKey: 'rootdomain',
      numberKey: 'count'
    });
    renderRows('cfmod-stat-dns-types-body', stats.dnsRecordTypes || [], {
      columns: 2,
      badgeClass: 'bg-success',
      textKey: 'type',
      numberKey: 'count'
    });

    updateHeavyStatsStatus(meta || {});
  }

  function fetchHeavyStats(refresh, callback){
    if (!heavyStatsEndpoint) {
      if (typeof callback === 'function') { callback(null); }
      return;
    }
    var url = heavyStatsEndpoint + '&_=' + Date.now();
    if (refresh) {
      url += '&refresh=1';
    }
    fetch(url, { credentials: 'same-origin' })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (data && data.success) {
          applyHeavyStats(data.stats || {}, {
            generated_at: data.generated_at || 0,
            stale: !!data.stale,
            pending: !!data.pending
          });
        }
        if (typeof callback === 'function') {
          callback(data || null);
        }
      })
      .catch(function(){
        if (typeof callback === 'function') {
          callback(null);
        }
      });
  }

  function initHeavyStatsRefresh(){
    var statusEl = document.getElementById('cfmod-heavy-stats-status');
    if (!statusEl) { return; }

    var attempts = 0;
    var maxAttempts = 12;

    fetchHeavyStats(true, function(first){
      if (!first || !first.success) { return; }
      var shouldPoll = !!first.pending || !!first.stale;
      if (!shouldPoll) { return; }

      var timer = setInterval(function(){
        attempts += 1;
        fetchHeavyStats(false, function(next){
          if (!next || !next.success) {
            if (attempts >= maxAttempts) { clearInterval(timer); }
            return;
          }
          if (!next.pending && !next.stale) {
            clearInterval(timer);
            return;
          }
          if (attempts >= maxAttempts) {
            clearInterval(timer);
          }
        });
      }, 5000);
    });
  }

  window.toggleExpiryForm = function(id){
    var row = document.getElementById('expiry_form_' + id);
    if (!row) { return; }
    if (row.style.display === 'none' || row.style.display === '') {
      row.style.display = 'table-row';
    } else {
      row.style.display = 'none';
    }
  };

  window.confirmBatchDelete = function(){
    var checkedBoxes = document.querySelectorAll('.record-checkbox:checked');
    if (checkedBoxes.length === 0) {
      alert(lang.batchDeleteEmpty || '请选择要删除的记录');
      return;
    }
    var message = format(lang.batchDeleteConfirm || '确定要删除选中的 %d 条记录吗？此操作不可恢复！', checkedBoxes.length);
    if (confirm(message)) {
      var batchForm = document.getElementById('batchForm');
      if (batchForm) {
        batchForm.submit();
      }
    }
  };

  function getBatchExpiryElements(){
    return {
      panel: document.getElementById('batchExpiryPanel'),
      holder: document.getElementById('batchExpirySelectedHolder'),
      count: document.getElementById('batchExpiryCount'),
      mode: document.getElementById('batchExpiryMode'),
      dateInput: document.getElementById('batchExpiryDateInput'),
      extendInput: document.getElementById('batchExpiryExtendInput')
    };
  }

  function populateBatchExpirySelection(){
    var els = getBatchExpiryElements();
    if (!els.holder || !els.count) { return false; }
    els.holder.innerHTML = '';
    var checkedBoxes = document.querySelectorAll('.record-checkbox:checked');
    if (checkedBoxes.length === 0) {
      return false;
    }
    checkedBoxes.forEach(function(box){
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'selected_ids[]';
      input.value = box.value;
      els.holder.appendChild(input);
    });
    els.count.textContent = checkedBoxes.length;
    return true;
  }

  function updateBatchExpiryMode(){
    var els = getBatchExpiryElements();
    if (!els.mode) { return; }
    var mode = els.mode.value;
    document.querySelectorAll('.batch-expiry-field').forEach(function(node){
      var targetMode = node.getAttribute('data-mode');
      if (!targetMode || targetMode === mode) {
        node.style.display = '';
      } else {
        node.style.display = 'none';
      }
    });
  }

  window.openBatchExpiryPanel = function(){
    var els = getBatchExpiryElements();
    if (!els.panel) { return; }
    if (!populateBatchExpirySelection()) {
      alert(lang.batchExpirySelection || lang.batchDeleteEmpty || '请选择需要调整到期的子域名');
      els.panel.style.display = 'none';
      return;
    }
    if (els.mode) {
      els.mode.value = 'set';
      updateBatchExpiryMode();
    }
    if (els.dateInput) { els.dateInput.value = ''; }
    if (els.extendInput) { els.extendInput.value = ''; }
    els.panel.style.display = 'block';
    els.panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  window.refreshBatchExpirySelection = function(){
    var els = getBatchExpiryElements();
    if (!els.panel) { return; }
    if (!populateBatchExpirySelection()) {
      alert(lang.batchExpirySelection || lang.batchDeleteEmpty || '请选择需要调整到期的子域名');
      els.panel.style.display = 'none';
    }
  };

  window.closeBatchExpiryPanel = function(){
    var els = getBatchExpiryElements();
    if (els.panel) {
      els.panel.style.display = 'none';
    }
  };

  window.validateBatchExpiryForm = function(){
    var els = getBatchExpiryElements();
    if (!els.holder) { return false; }
    if (!els.holder.querySelector('input[name="selected_ids[]"]')) {
      alert(lang.batchExpirySelection || lang.batchDeleteEmpty || '请选择需要调整到期的子域名');
      return false;
    }
    if (!els.mode) { return true; }
    if (els.mode.value === 'set') {
      if (!els.dateInput || !els.dateInput.value) {
        alert(lang.batchExpiryDateRequired || '请先填写新的到期时间');
        return false;
      }
    } else if (els.mode.value === 'extend') {
      var extendVal = els.extendInput ? parseInt(els.extendInput.value, 10) : NaN;
      if (!extendVal || extendVal <= 0) {
        alert(lang.batchExpiryExtendRequired || '请填写延长天数（至少 1 天）');
        return false;
      }
    }
    return true;
  };

  function initSelectAll(){
    var selectAll = document.getElementById('selectAll');
    if (!selectAll) { return; }
    selectAll.addEventListener('change', function(){
      document.querySelectorAll('.record-checkbox').forEach(function(box){
        box.checked = selectAll.checked;
      });
    });
  }

  function initPurgeHelper(){
    var select = document.getElementById('cf-purge-root-select');
    var input = document.getElementById('cf-purge-root-input');
    if (!select || !input) { return; }
    select.addEventListener('change', function(){
      if (select.value) {
        input.value = select.value;
      }
    });
  }

  function initAnnouncementModal(){
    if (!<?php echo $announcementEnabled ? 'true' : 'false'; ?>) { return; }
    var key = 'cfmod_admin_announce_dismissed';
    if (document.cookie.indexOf(key + '=1') !== -1) { return; }
    var show = function(){
      var el = document.getElementById('adminAnnounceModal');
      if (!el) { return; }
      if (window.jQuery && typeof jQuery(el).modal === 'function') {
        jQuery(el).modal('show');
      } else {
        el.style.display = 'block';
        el.classList.add('in');
      }
    };
    document.addEventListener('DOMContentLoaded', show);
    var dismiss = document.getElementById('adminAnnounceDismiss');
    if (dismiss) {
      dismiss.addEventListener('click', function(){
        var expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1);
        document.cookie = key + '=1; path=/; expires=' + expires.toUTCString();
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    initSelectAll();
    initPurgeHelper();
    initAnnouncementModal();
    initHeavyStatsRefresh();
    initSubdomainDnsViewer();
    initModalPolyfill();
    var batchMode = document.getElementById('batchExpiryMode');
    if (batchMode) {
      batchMode.addEventListener('change', updateBatchExpiryMode);
      updateBatchExpiryMode();
    }
    document.addEventListener('keydown', function(e){
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        var searchInput = document.getElementById('api_search');
        if (searchInput) {
          e.preventDefault();
          searchInput.focus();
          searchInput.select();
          searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }
    });
  });

  function initModalPolyfill(){
    var hasBootstrapModal = window.jQuery && typeof jQuery.fn === 'object' && typeof jQuery.fn.modal === 'function';
    if (hasBootstrapModal) {
      return;
    }
    var activeBackdropClass = 'cfmod-modal-backdrop';

    function matchesSelector(el, selector){
      if (!el || el.nodeType !== 1) { return false; }
      var proto = Element.prototype;
      var fn = proto.matches || proto.msMatchesSelector || proto.webkitMatchesSelector;
      if (!fn) {
        var nodes = el.parentNode ? el.parentNode.querySelectorAll(selector) : [];
        for (var i = 0; nodes && i < nodes.length; i++) {
          if (nodes[i] === el) { return true; }
        }
        return false;
      }
      return fn.call(el, selector);
    }

    function closestElement(el, selector){
      while (el && el.nodeType === 1) {
        if (matchesSelector(el, selector)) {
          return el;
        }
        el = el.parentElement;
      }
      return null;
    }

    function showBackdrop(modal){
      var existing = null;
      if (modal && modal.id) {
        existing = document.querySelector('.' + activeBackdropClass + '[data-target="' + modal.id + '"]');
      }
      if (existing) { return existing; }
      var backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop fade in ' + activeBackdropClass;
      if (modal.id) {
        backdrop.setAttribute('data-target', modal.id);
      }
      document.body.appendChild(backdrop);
      return backdrop;
    }

    function hideBackdrop(modal){
      if (!modal.id) { return; }
      var backdrop = document.querySelector('.' + activeBackdropClass + '[data-target="' + modal.id + '"]');
      if (backdrop && backdrop.parentNode) {
        backdrop.parentNode.removeChild(backdrop);
      }
    }

    function openModal(modal){
      if (!modal) { return; }
      modal.style.display = 'block';
      modal.classList.add('in');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
      if (modal.id) {
        modal.setAttribute('data-modal-id', modal.id);
      }
      showBackdrop(modal);
      var focusable = modal.querySelector('input, button, select, textarea, [tabindex]');
      if (focusable && typeof focusable.focus === 'function') {
        focusable.focus();
      }
    }

    function closeModal(modal){
      if (!modal) { return; }
      modal.classList.remove('in');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      hideBackdrop(modal);
      if (!document.querySelector('.modal.in')) {
        document.body.classList.remove('modal-open');
      }
    }

    document.addEventListener('click', function(event){
      var trigger = closestElement(event.target, '[data-toggle="modal"]');
      if (!trigger) { return; }
      var selector = trigger.getAttribute('data-target') || trigger.getAttribute('href');
      if (!selector || selector === '#') { return; }
      var modal = document.querySelector(selector);
      if (!modal) { return; }
      event.preventDefault();
      openModal(modal);
    });

    document.addEventListener('click', function(event){
      var dismiss = closestElement(event.target, '[data-dismiss="modal"]');
      if (!dismiss) { return; }
      var modal = closestElement(event.target, '.modal');
      if (!modal) { return; }
      event.preventDefault();
      closeModal(modal);
    });

    document.addEventListener('keydown', function(event){
      if (event.key !== 'Escape') { return; }
      var modal = document.querySelector('.modal.in');
      if (modal) {
        closeModal(modal);
      }
    });
  }

  function validateBigNumber(value, min, max){

    if (value === '') {
      return { valid: false, error: lang.numberRequired || '请输入数字！' };
    }
    if (!/^\d+$/.test(value)) {
      return { valid: false, error: lang.numberInvalid || '请输入有效的数字（只能包含0-9）！' };
    }
    if (value.length > 1 && value[0] === '0') {
      return { valid: false, error: lang.numberLeadingZero || '数字不能以0开头！' };
    }
    var minStr = String(min);
    var maxStr = String(max);
    if (value.length < minStr.length || (value.length === minStr.length && value < minStr)) {
      return { valid: false, error: format(lang.numberMin || '数值不能小于 %d！', min) };
    }
    if (value.length > maxStr.length || (value.length === maxStr.length && value > maxStr)) {
      return { valid: false, error: format(lang.numberMax || '数值不能超过 %d！', max) };
    }
    return { valid: true };
  }

  window.editRateLimit = function(keyId, currentLimit){
    var modal = document.getElementById('editRateLimitModal');
    if (!modal) { return; }
    document.getElementById('edit_rate_key_id').value = keyId;
    document.getElementById('edit_rate_limit').value = currentLimit;
    modal.style.display = 'block';
  };

  window.manageUserQuota = function(userId, userName){
    var modal = document.getElementById('manageQuotaModal');
    if (!modal) { return; }
    document.getElementById('quota_user_id').value = userId;
    document.getElementById('quota_user_name').textContent = userName + ' (ID: ' + userId + ')';
    fetch(quotaEndpoint + encodeURIComponent(userId))
      .then(function(response){ return response.json(); })
      .then(function(data){
        if (data.success) {
          document.getElementById('max_count').value = data.quota.max_count || 0;
          document.getElementById('invite_bonus_limit').value = data.quota.invite_bonus_limit || 0;
        } else {
          document.getElementById('max_count').value = 5;
          document.getElementById('invite_bonus_limit').value = 5;
        }
        modal.style.display = 'block';
      })
      .catch(function(){
        document.getElementById('max_count').value = 5;
        document.getElementById('invite_bonus_limit').value = 5;
        modal.style.display = 'block';
      });
  };

  function getSubdomainDnsModalElements(){
    return {
      title: document.getElementById('cfmodSubdomainDnsModalTitle'),
      loading: document.getElementById('cfmodSubdomainDnsModalLoading'),
      alert: document.getElementById('cfmodSubdomainDnsModalAlert'),
      summary: document.getElementById('cfmodSubdomainDnsModalSummary'),
      records: document.getElementById('cfmodSubdomainDnsModalRecords')
    };
  }

  function setSubdomainDnsModalLoading(loading){
    var els = getSubdomainDnsModalElements();
    if (!els.loading) { return; }
    if (loading) {
      els.loading.classList.remove('d-none');
    } else {
      els.loading.classList.add('d-none');
    }
  }

  function setSubdomainDnsModalAlert(message, level){
    var els = getSubdomainDnsModalElements();
    if (!els.alert) { return; }
    if (!message) {
      els.alert.className = 'alert d-none';
      els.alert.textContent = '';
      return;
    }
    var style = level || 'warning';
    els.alert.className = 'alert alert-' + style;
    els.alert.textContent = message;
  }

  function setSubdomainDnsModalSummary(text){
    var els = getSubdomainDnsModalElements();
    if (!els.summary) { return; }
    if (!text) {
      els.summary.classList.add('d-none');
      els.summary.textContent = '';
      return;
    }
    els.summary.classList.remove('d-none');
    els.summary.textContent = text;
  }

  function clearSubdomainDnsModalRecords(){
    var els = getSubdomainDnsModalElements();
    if (els.records) {
      els.records.innerHTML = '';
    }
  }

  function appendSubdomainDnsRecordCard(container, record, subdomainId){
    if (!container || !record) { return; }
    var col = document.createElement('div');
    col.className = 'col-12';

    var card = document.createElement('div');
    card.className = 'card border-light shadow-sm';

    var body = document.createElement('div');
    body.className = 'card-body py-2 px-3';

    var top = document.createElement('div');
    top.className = 'd-flex justify-content-between align-items-center mb-2';

    var typeBadge = document.createElement('span');
    typeBadge.className = 'badge bg-primary';
    typeBadge.textContent = String(record.type || '').toUpperCase() || 'UNKNOWN';
    top.appendChild(typeBadge);

    var statusBadge = document.createElement('span');
    statusBadge.className = 'badge bg-light text-dark';
    statusBadge.textContent = record.status ? String(record.status) : 'active';
    top.appendChild(statusBadge);

    var name = document.createElement('div');
    name.className = 'fw-semibold text-break';
    name.textContent = String(record.name || '-');

    var content = document.createElement('code');
    content.className = 'd-block text-break mt-1';
    content.textContent = String(record.content || '');

    var meta = document.createElement('div');
    meta.className = 'small text-muted mt-2';
    var parts = ['TTL: ' + String(record.ttl || 0)];
    if (record.priority !== null && record.priority !== undefined) {
      parts.push('Priority: ' + String(record.priority));
    }
    if (record.line) {
      parts.push('线路: ' + String(record.line));
    }
    if (record.record_id) {
      parts.push('远端ID: ' + String(record.record_id));
    }
    if (record.updated_at) {
      parts.push('更新: ' + String(record.updated_at));
    }
    meta.textContent = parts.join(' | ');

    body.appendChild(top);
    body.appendChild(name);
    body.appendChild(content);
    body.appendChild(meta);
    if (record && record.record_id) {
      var actionWrap = document.createElement('div');
      actionWrap.className = 'mt-2 text-end';
      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn-sm btn-outline-danger';
      delBtn.textContent = '删除记录';
      delBtn.addEventListener('click', function(){
        if (!confirm('确定删除该解析记录？')) { return; }
        deleteAdminDnsRecord(subdomainId, String(record.record_id || ''))
          .then(function(ok){
            if (ok) {
              openSubdomainDnsViewer(subdomainId, null);
            }
          });
      });
      actionWrap.appendChild(delBtn);
      body.appendChild(actionWrap);
    }
    card.appendChild(body);
    col.appendChild(card);
    container.appendChild(col);
  }

  function openSubdomainDnsViewer(subdomainId, subdomainName){
    var sid = parseInt(subdomainId, 10);
    if (!sid || sid <= 0) { return; }

    var els = getSubdomainDnsModalElements();
    if (els.title) {
      els.title.textContent = '解析记录 - ' + (subdomainName || ('ID #' + sid));
    }

    setSubdomainDnsModalAlert('', '');
    setSubdomainDnsModalSummary('');
    clearSubdomainDnsModalRecords();
    setSubdomainDnsModalLoading(true);

    if (!subdomainDnsEndpoint) {
      setSubdomainDnsModalLoading(false);
      setSubdomainDnsModalAlert('未配置解析记录查询接口。', 'warning');
      return;
    }

    var requestUrl = subdomainDnsEndpoint + encodeURIComponent(sid) + '&_=' + Date.now();
    fetch(requestUrl, { credentials: 'same-origin' })
      .then(function(response){ return response.json(); })
      .then(function(data){
        setSubdomainDnsModalLoading(false);
        if (!data || !data.success) {
          setSubdomainDnsModalAlert('读取解析记录失败，请稍后重试。', 'danger');
          return;
        }

        var rows = Array.isArray(data.records) ? data.records : [];
        var container = els.records;
        if (!container) {
          return;
        }
        if (rows.length === 0) {
          setSubdomainDnsModalSummary('当前本地暂无已同步的解析记录。');
          setSubdomainDnsModalAlert('未找到解析记录（可能该子域名尚未设置解析或尚未同步）。', 'info');
          return;
        }

        setSubdomainDnsModalSummary('共 ' + rows.length + ' 条本地已同步解析记录。');
        rows.forEach(function(row){
          appendSubdomainDnsRecordCard(container, row, sid);
        });
      })
      .catch(function(){
        setSubdomainDnsModalLoading(false);
        setSubdomainDnsModalAlert('网络异常，读取解析记录失败。', 'danger');
      });
  }

  function deleteAdminDnsRecord(subdomainId, recordId){
    var payload = new URLSearchParams();
    payload.set('module', 'domain_hub');
    payload.set('action', 'admin_delete_dns_record');
    payload.set('subdomain_id', String(subdomainId || ''));
    payload.set('record_id', String(recordId || ''));
    payload.set('cfmod_admin_csrf', window.CF_MOD_ADMIN_CSRF || '');
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: payload.toString()
    }).then(function(resp){ return resp.json(); })
      .then(function(data){
        if (!data || !data.success) {
          var detail = (data && data.error) ? String(data.error) : '删除失败';
          setSubdomainDnsModalAlert('删除失败：' + detail, 'danger');
          return false;
        }
        setSubdomainDnsModalAlert('记录删除成功。', 'success');
        return true;
      }).catch(function(){
        setSubdomainDnsModalAlert('网络异常，删除失败。', 'danger');
        return false;
      });
  }

  function initSubdomainDnsViewer(){
    document.querySelectorAll('.js-view-subdomain-dns').forEach(function(button){
      button.addEventListener('click', function(){
        var sid = button.getAttribute('data-subdomain-id') || '';
        var name = button.getAttribute('data-subdomain-name') || '';
        openSubdomainDnsViewer(sid, name);
      });
    });
  }

  window.openSubdomainDnsViewer = openSubdomainDnsViewer;

  window.validateQuotaForm = function(){
    var maxCount = document.getElementById('max_count').value;
    var inviteLimit = document.getElementById('invite_bonus_limit').value;
    var result1 = validateBigNumber(maxCount, 0, 99999999999);
    if (!result1.valid) {
      alertMessage('quotaErrorPrefix', result1.error);
      return false;
    }
    var result2 = validateBigNumber(inviteLimit, 0, 99999999999);
    if (!result2.valid) {
      alertMessage('inviteErrorPrefix', result2.error);
      return false;
    }
    return true;
  };

  window.validateInviteLimit = function(form){
    var inviteLimit = form.new_invite_limit.value;
    var result = validateBigNumber(inviteLimit, 0, 99999999999);
    if (!result.valid) {
      alertMessage('inviteErrorPrefix', result.error);
      return false;
    }
    return true;
  };

  window.validateQuotaUpdate = function(form){
    var newQuota = form.new_quota.value;
    var result = validateBigNumber(newQuota, 0, 99999999999);
    if (!result.valid) {
      alertMessage('quotaUpdateErrorPrefix', result.error);
      return false;
    }
    return true;
  };

  window.validateRootDomain = function(form){
    var maxSubdomains = form.max_subdomains.value;
    var result = validateBigNumber(maxSubdomains, 1, 99999999999);
    if (!result.valid) {
      alertMessage('rootErrorPrefix', result.error);
      return false;
    }
    return true;
  };

  function loadLazyAdminBlock(block, targetEl, urlOverride){
    if (!block || !targetEl) { return; }
    var base = heavyStatsEndpoint ? heavyStatsEndpoint.replace('get_admin_heavy_stats', 'get_admin_lazy_block') : '?module=domain_hub&action=get_admin_lazy_block';
    var url = urlOverride || (base + '&block=' + encodeURIComponent(block) + '&_=' + Date.now());
    targetEl.innerHTML = '<div class="text-muted small">加载中...</div>';
    fetch(url, { credentials: 'same-origin' })
      .then(function(resp){ return resp.json(); })
      .then(function(data){
        if (!data || !data.success) {
          targetEl.innerHTML = '<div class="text-danger small">加载失败，请重试</div>';
          return;
        }
        targetEl.innerHTML = data.html || '<div class="text-muted small">暂无数据</div>';
        targetEl.dataset.loaded = '1';
      })
      .catch(function(){
        targetEl.innerHTML = '<div class="text-danger small">网络异常，请稍后重试</div>';
      });
  }

  document.querySelectorAll('[data-lazy-block]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var block = btn.getAttribute('data-lazy-block');
      var body = document.getElementById('lazy-body-' + block);
      if (!body || body.dataset.loaded === '1') { return; }
      loadLazyAdminBlock(block, body);
    });
  });

  document.addEventListener('submit', function(e){
    var form = e.target;
    var holder = form && form.closest && form.closest('[id^="lazy-body-"]');
    if (!holder) { return; }
    e.preventDefault();
    var block = holder.id.replace('lazy-body-', '');
    var formData = new FormData(form);
    formData.set('module', 'domain_hub');
    formData.set('action', 'get_admin_lazy_block');
    formData.set('block', block);
    var q = new URLSearchParams(formData).toString();
    loadLazyAdminBlock(block, holder, '?' + q);
  });

  document.addEventListener('click', function(e){
    var link = e.target.closest ? e.target.closest('a') : null;
    if (!link) { return; }
    var holder = link.closest && link.closest('[id^="lazy-body-"]');
    if (!holder) { return; }
    var href = link.getAttribute('href') || '';
    if (href === '' || href.indexOf('javascript:') === 0) { return; }
    e.preventDefault();
    var block = holder.id.replace('lazy-body-', '');
    var parsedUrl;
    try {
      parsedUrl = new URL(href, window.location.origin);
    } catch (err) {
      return;
    }
    if (parsedUrl.origin !== window.location.origin) { return; }
    var params = parsedUrl.searchParams;
    params.set('module', 'domain_hub');
    params.set('action', 'get_admin_lazy_block');
    params.set('block', block);
    var url = '?' + params.toString();
    loadLazyAdminBlock(block, holder, url);
  });

  window.onclick = function(event){
    if (event.target.classList && event.target.classList.contains('modal')) {
      event.target.style.display = 'none';
    }
  };
})();
</script>
