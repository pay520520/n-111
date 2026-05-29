<?php
$whoisIsChinese = strtolower((string) ($currentClientLanguage ?? 'english')) === 'chinese';
$whoisText = static function (string $key, string $zh, string $en, array $params = [], bool $escape = true) use ($whoisIsChinese): string {
    return cfclient_lang($key, $whoisIsChinese ? $zh : $en, $params, $escape);
};

$whoisPrivacyEnabled = !empty($whoisPrivacyEnabled);
$whoisManagedDomainCount = max(0, (int) ($whoisManagedDomainCount ?? 0));
?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-user-secret me-2 text-primary"></i><?php echo $whoisText('cfclient.whois.privacy.title', 'WHOIS 隐私总开关', 'WHOIS Privacy Master Toggle'); ?></h6>
                <p class="text-muted small mb-3"><?php echo $whoisText('cfclient.whois.privacy.desc', '开启或关闭将统一作用于当前账号下的所有域名。', 'Enabling or disabling this setting will apply to all domains under the current account.'); ?></p>

                <div class="alert alert-light border small mb-3">
                    <i class="fas fa-globe me-1"></i><?php echo $whoisText('cfclient.whois.privacy.domain_count', '当前账户域名数量：%s', 'Current account domain count: %s', [$whoisManagedDomainCount]); ?>
                </div>

                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="whoisPrivacyToggle" <?php echo $whoisPrivacyEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="whoisPrivacyToggle"><?php echo $whoisText('cfclient.whois.privacy.toggle', '开启 WHOIS 隐私保护（默认推荐开启）', 'Enable WHOIS privacy protection (recommended)'); ?></label>
                    </div>
                </div>

                <button type="button" class="btn btn-outline-primary" id="whoisPrivacySaveButton">
                    <i class="fas fa-save me-1"></i><?php echo $whoisText('cfclient.whois.privacy.save', '保存隐私设置', 'Save Privacy Setting'); ?>
                </button>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="card-title mb-3"><i class="fas fa-search me-2 text-success"></i><?php echo $whoisText('cfclient.whois.lookup.title', 'WHOIS 查询', 'WHOIS Lookup'); ?></h6>
                <p class="text-muted small mb-3"><?php echo $whoisText('cfclient.whois.lookup.desc', '支持查询DNSHE提供注册的任意域名（含他人注册）及外部域名 WHOIS信息。', 'Supports WHOIS lookup for any domain registered via DNSHE (including domains registered by other users) and external domains.'); ?></p>
                <form id="whoisLookupForm" class="d-flex flex-column gap-2">
                    <label class="form-label mb-0" for="whoisLookupDomainInput"><?php echo $whoisText('cfclient.whois.lookup.domain', '域名', 'Domain'); ?></label>
                    <input
                        type="text"
                        class="form-control"
                        id="whoisLookupDomainInput"
                        name="domain"
                        value=""
                        placeholder="example.com"
                        required
                    >
                    <button type="submit" class="btn btn-success" id="whoisLookupButton">
                        <i class="fas fa-search me-1"></i><?php echo $whoisText('cfclient.whois.lookup.submit', '查询 WHOIS', 'Lookup WHOIS'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-3" id="whoisResultCard" style="display:none;">
    <div class="card-body">
        <h6 class="card-title mb-3"><i class="fas fa-database me-2 text-secondary"></i><?php echo $whoisText('cfclient.whois.result.title', 'WHOIS 查询结果', 'WHOIS Result'); ?></h6>
        <div id="whoisResultContainer"></div>
    </div>
</div>

<div id="whoisAlertContainer" class="mt-3"></div>
