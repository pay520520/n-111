<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfAdminActionService
{
    private const HASH_PROVIDER = '#providerAccounts';
    private const ORPHAN_CURSOR_SETTING_KEY = 'orphan_scan_cursors';
    private const ORPHAN_CURSOR_DEFAULT_KEY = '__default__';

    private static $orphanCursorCache = null;
    private const HASH_ROOT_WHITELIST = '#rootdomainWhitelist';
    private const HASH_ROOT_REPLACE = '#rootdomainReplace';
    private const HASH_FORBIDDEN = '#forbiddenDomains';
    private const HASH_JOBS = '#queue-management';
    private const HASH_DOMAIN_GIFTS = '#domainGiftRecords';
    private const HASH_INVITE = '#invite_stats';
    private const HASH_SNAPSHOTS = '#snapshots';
    private const HASH_RUNTIME = '#runtime-control';
    private const HASH_ANNOUNCEMENTS = '#admin-announcements';
    private const HASH_BANS = '#ban-management';
    private const HASH_RISK_MONITOR = '#risk-monitor';
    private const HASH_PRIVILEGED = '#privileged';
    private const HASH_QUOTAS = '#quotas';
    private const HASH_SUBDOMAINS = '#subdomains';
    private const REDEEM_SAME_TYPE_DEFAULT_KEY = 'global';
    private const REDEEM_SAME_TYPE_KEY_MAX_LENGTH = 64;
    private const PDNS_LOCAL_EXPORT_CURSOR_SETTING_KEY = 'pdns_local_export_cursor_state';

    /**
     * @var array<string, callable>
     */
    private static array $handlers = [
        'admin_provider_create' => [self::class, 'handleProviderCreate'],
        'admin_provider_update' => [self::class, 'handleProviderUpdate'],
        'admin_provider_toggle_status' => [self::class, 'handleProviderToggleStatus'],
        'admin_provider_delete' => [self::class, 'handleProviderDelete'],
        'admin_provider_set_default' => [self::class, 'handleProviderSetDefault'],
        'admin_provider_test' => [self::class, 'handleProviderTest'],
        'add_rootdomain' => [self::class, 'handleRootdomainAdd'],
        'delete_rootdomain' => [self::class, 'handleRootdomainDelete'],
        'toggle_rootdomain' => [self::class, 'handleRootdomainToggle'],
        'toggle_rootdomain_maintenance' => [self::class, 'handleRootdomainToggleMaintenance'],
        'set_rootdomain_status' => [self::class, 'handleRootdomainSetStatus'],
        'set_rootdomain_limit' => [self::class, 'handleRootdomainSetLimit'],
        'update_rootdomain_order' => [self::class, 'handleRootdomainOrderUpdate'],
        'admin_rootdomain_update' => [self::class, 'handleRootdomainUpdate'],
        'transfer_rootdomain_provider' => [self::class, 'handleRootdomainTransfer'],
        'replace_rootdomain' => [self::class, 'handleRootdomainReplace'],
        'export_rootdomain' => [self::class, 'handleRootdomainExport'],
        'import_rootdomain' => [self::class, 'handleRootdomainImport'],
        'export_rootdomain_pdns' => [self::class, 'handleRootdomainPdnsExport'],
        'import_rootdomain_pdns' => [self::class, 'handleRootdomainPdnsImport'],
        'purge_rootdomain_local' => [self::class, 'handleRootdomainPurgeLocal'],
        'add_forbidden' => [self::class, 'handleForbiddenAdd'],
        'delete_forbidden' => [self::class, 'handleForbiddenDelete'],
        'toggle_subdomain_status' => [self::class, 'handleToggleSubdomainStatus'],
        'admin_toggle_subdomain_status' => [self::class, 'handleToggleSubdomainStatus'],
        'admin_delete_subdomain' => [self::class, 'handleDeleteSubdomain'],
        'admin_delete_dns_record' => [self::class, 'handleDeleteDnsRecord'],
        'delete' => [self::class, 'handleDeleteSubdomain'],
        'admin_regen_subdomain' => [self::class, 'handleSubdomainRegenerate'],
        'regen' => [self::class, 'handleSubdomainRegenerate'],
        'admin_cancel_domain_gift' => [self::class, 'handleDomainGiftCancel'],
        'admin_unlock_domain_gift_lock' => [self::class, 'handleDomainGiftUnlock'],
        'save_runtime_switches' => [self::class, 'handleRuntimeSwitches'],
        'admin_toggle_quota_redeem' => [self::class, 'handleToggleQuotaRedeem'],
        'admin_create_redeem_code' => [self::class, 'handleCreateRedeemCode'],
        'admin_generate_redeem_codes' => [self::class, 'handleGenerateRedeemCodes'],
        'admin_toggle_redeem_code_status' => [self::class, 'handleToggleRedeemCodeStatus'],
        'admin_delete_redeem_code' => [self::class, 'handleDeleteRedeemCode'],
        'save_admin_announce' => [self::class, 'handleSaveAdminAnnounce'],
        'job_retry' => [self::class, 'handleJobRetry'],
        'job_cancel' => [self::class, 'handleJobCancel'],
        'job_bulk_retry' => [self::class, 'handleJobBulkRetry'],
        'job_bulk_cancel' => [self::class, 'handleJobBulkCancel'],
        'job_force_fail' => [self::class, 'handleJobForceFail'],
        'job_skip' => [self::class, 'handleJobSkip'],
        'job_demote_priority' => [self::class, 'handleJobDemotePriority'],
        'enqueue_calibration' => [self::class, 'handleEnqueueCalibration'],
        'enqueue_root_calibration' => [self::class, 'handleEnqueueRootCalibration'],
        'enqueue_risk_scan' => [self::class, 'handleEnqueueRiskScan'],
        'enqueue_virustotal_scan' => [self::class, 'handleEnqueueVirusTotalScan'],
        'enqueue_virustotal_force_rescan' => [self::class, 'handleEnqueueVirusTotalForceRescan'],
        'clear_virustotal_expired_cache' => [self::class, 'handleClearVirusTotalExpiredCache'],
        'clear_virustotal_domain_cache' => [self::class, 'handleClearVirusTotalDomainCache'],
        'enqueue_reconcile' => [self::class, 'handleEnqueueReconcile'],
        'run_queue_once' => [self::class, 'handleRunQueueOnce'],
        'enqueue_custom_job' => [self::class, 'handleEnqueueCustomJob'],
        'run_migrations' => [self::class, 'handleRunMigrations'],
        'ban_user' => [self::class, 'handleBanUser'],
        'unban_user' => [self::class, 'handleUnbanUser'],
        'enforce_ban_dns' => [self::class, 'handleEnforceBanDns'],
        'save_invite_cycle_start' => [self::class, 'handleSaveInviteCycleStart'],
        'save_leaderboard_display' => [self::class, 'handleSaveLeaderboardDisplay'],
        'mark_reward_claimed' => [self::class, 'handleMarkRewardClaimed'],
        'admin_upsert_invite_reward' => [self::class, 'handleAdminUpsertInviteReward'],
        'admin_rebuild_invite_rewards' => [self::class, 'handleAdminRebuildInviteRewards'],
        'admin_settle_last_period' => [self::class, 'handleAdminSettleLastPeriod'],
        'migrate_invite_registration_existing_users' => [self::class, 'handleMigrateInviteRegistrationExistingUsers'],
        'enqueue_unlock_all_invite_registration_users' => [self::class, 'handleEnqueueUnlockAllInviteRegistrationUsers'],
        'generate_invite_snapshot' => [self::class, 'handleGenerateInviteSnapshot'],
        'remove_leaderboard_user' => [self::class, 'handleRemoveLeaderboardUser'],
        'admin_edit_leaderboard_user' => [self::class, 'handleAdminEditLeaderboardUser'],
        'update_user_invite_limit' => [self::class, 'handleUpdateUserInviteLimit'],
        'admin_add_privileged_user' => [self::class, 'handleAddPrivilegedUser'],
        'admin_remove_privileged_user' => [self::class, 'handleRemovePrivilegedUser'],
        'admin_set_user_quota' => [self::class, 'handleAdminSetUserQuota'],
        'update_user_quota' => [self::class, 'handleUpdateUserQuota'],
        'admin_adjust_expiry' => [self::class, 'handleAdminAdjustExpiry'],
        'save_renewal_notice_settings' => [self::class, 'handleSaveRenewalNoticeSettings'],
        'admin_test_renewal_notice' => [self::class, 'handleTestRenewalNotice'],
        'admin_test_renewal_notice_telegram' => [self::class, 'handleTestRenewalTelegramNotice'],
        'reset_module' => [self::class, 'handleResetModule'],
        'batch_delete' => [self::class, 'handleBatchDelete'],
        'batch_adjust_expiry' => [self::class, 'handleBatchAdjustExpiry'],
        'scan_orphan_records' => [self::class, 'handleScanOrphanRecords'],

    ];

    public static function supports(string $action): bool
    {
        return isset(self::$handlers[$action]);
    }

    public static function handle(string $action): void
    {
        $handler = self::$handlers[$action] ?? null;
        if ($handler === null) {
            return;
        }

        try {
            self::enforceRateLimitForAction($action);
        } catch (CfRateLimitExceededException $e) {
            self::flashError(self::formatRateLimitMessage($e->getRetryAfterSeconds()));
            self::redirect();
        }

        call_user_func($handler);
    }

    private static function triggerQueueInBackground(int $maxJobs = 1): bool
    {
        $workerCandidates = [
            __DIR__ . '/../../worker.php',
            __DIR__ . '/../worker.php',
        ];
        $worker = null;
        foreach ($workerCandidates as $candidate) {
            if (file_exists($candidate)) {
                $worker = $candidate;
                break;
            }
        }
        if ($worker === null) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('queue_trigger_missing_worker', ['max_jobs' => $maxJobs]);
            }
            return false;
        }
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $maxJobs = max(1, (int) $maxJobs);
        if (!function_exists('exec')) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('queue_trigger_exec_unavailable', [
                    'max_jobs' => $maxJobs,
                    'php_binary' => $phpBinary,
                ]);
            }
            return false;
        }

        $spawnLog = rtrim((string) (function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp'), '/\\') . '/domain_hub_worker_spawn.log';
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($worker) . ' ' . $maxJobs . ' >> ' . escapeshellarg($spawnLog) . ' 2>&1 & echo $!';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $pid = trim((string) (end($output) ?: ''));
        $success = ($exitCode === 0 && $pid !== '');
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('queue_trigger_background', [
                'max_jobs' => $maxJobs,
                'php_binary' => $phpBinary,
                'worker' => $worker,
                'spawn_log' => $spawnLog,
                'exit_code' => $exitCode,
                'pid' => $pid,
                'success' => $success ? 1 : 0,
            ]);
        }
        return $success;
    }

    private static function handleProviderCreate(): void
    {
        try {
            self::ensureProviderSchema();
            $name = trim($_POST['provider_name'] ?? '');
            if ($name === '') {
                throw new Exception('请输入账户名称');
            }
            $accessKeyId = trim($_POST['access_key_id'] ?? '');
            if ($accessKeyId === '') {
                throw new Exception('AccessKey ID 不能为空');
            }
            $accessKeySecret = trim($_POST['access_key_secret'] ?? '');
            if ($accessKeySecret === '') {
                throw new Exception('AccessKey Secret 不能为空');
            }
            $providerType = strtolower(trim($_POST['provider_type'] ?? 'alidns')) ?: 'alidns';
            $rateLimit = max(1, intval($_POST['provider_rate_limit'] ?? 60));
            $notes = trim($_POST['provider_notes'] ?? '');
            if ($notes !== '') {
                $notes = function_exists('mb_substr')
                    ? mb_substr($notes, 0, 500, 'UTF-8')
                    : substr($notes, 0, 500);
            } else {
                $notes = null;
            }
            $setAsDefault = ($_POST['set_as_default'] ?? '') === '1';
            $table = cfmod_get_provider_table_name();
            $now = date('Y-m-d H:i:s');
            $providerId = Capsule::table($table)->insertGetId([
                'name' => $name,
                'provider_type' => $providerType,
                'access_key_id' => $accessKeyId,
                'access_key_secret' => cfmod_encrypt_sensitive($accessKeySecret),
                'status' => 'active',
                'is_default' => $setAsDefault ? 1 : 0,
                'rate_limit' => $rateLimit,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($setAsDefault) {
                if (!cfmod_set_default_provider_account($providerId)) {
                    throw new Exception('账号已创建，但设置默认失败，请稍后重试');
                }
            } else {
                cf_clear_settings_cache();
            }
            self::flashSuccess('✅ 已新增供应商账号 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>');
        } catch (Exception $e) {
            self::flashError('❌ 新增供应商失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_PROVIDER);
    }

    private static function handleProviderUpdate(): void
    {
        try {
            self::ensureProviderSchema();
            $providerId = intval($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                throw new Exception('参数无效');
            }
            $table = cfmod_get_provider_table_name();
            $existingProvider = Capsule::table($table)->where('id', $providerId)->first();
            if (!$existingProvider) {
                throw new Exception('供应商不存在');
            }
            $name = trim($_POST['provider_name'] ?? '');
            if ($name === '') {
                throw new Exception('请输入账户名称');
            }
            $accessKeyId = trim($_POST['access_key_id'] ?? '');
            if ($accessKeyId === '') {
                throw new Exception('AccessKey ID 不能为空');
            }
            $providerType = strtolower(trim($_POST['provider_type'] ?? 'alidns')) ?: 'alidns';
            $rateLimit = max(1, intval($_POST['provider_rate_limit'] ?? 60));
            $notesUpdate = trim($_POST['provider_notes'] ?? '');
            if ($notesUpdate !== '') {
                $notesUpdate = function_exists('mb_substr')
                    ? mb_substr($notesUpdate, 0, 500, 'UTF-8')
                    : substr($notesUpdate, 0, 500);
            } else {
                $notesUpdate = null;
            }
            $updateData = [
                'name' => $name,
                'provider_type' => $providerType,
                'access_key_id' => $accessKeyId,
                'rate_limit' => $rateLimit,
                'notes' => $notesUpdate,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $newSecret = trim($_POST['access_key_secret'] ?? '');
            if ($newSecret !== '') {
                $updateData['access_key_secret'] = cfmod_encrypt_sensitive($newSecret);
            }
            Capsule::table($table)->where('id', $providerId)->update($updateData);
            $setAsDefault = ($_POST['set_as_default'] ?? '') === '1';
            if ($setAsDefault) {
                if (!cfmod_set_default_provider_account($providerId)) {
                    throw new Exception('账号已更新，但设置默认失败，请稍后重试');
                }
            } else {
                cf_clear_settings_cache();
            }
            self::flashSuccess('✅ 已更新供应商账号 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>');
        } catch (Exception $e) {
            self::flashError('❌ 更新供应商失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_PROVIDER);
    }

    private static function handleProviderToggleStatus(): void
    {
        try {
            self::ensureProviderSchema();
            $providerId = intval($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                throw new Exception('参数无效');
            }
            $targetStatus = ($_POST['target_status'] ?? '') === 'active' ? 'active' : 'disabled';
            $table = cfmod_get_provider_table_name();
            $providerRow = Capsule::table($table)->where('id', $providerId)->first();
            if (!$providerRow) {
                throw new Exception('供应商不存在');
            }
            $isDefault = intval($providerRow->is_default ?? 0) === 1;
            if ($isDefault && $targetStatus !== 'active') {
                throw new Exception('请先设置其他账号为默认后再停用当前账号');
            }
            Capsule::table($table)->where('id', $providerId)->update([
                'status' => $targetStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            cf_clear_settings_cache();
            self::flashSuccess($targetStatus === 'active' ? '✅ 已启用该供应商账号' : '✅ 已停用该供应商账号');
        } catch (Exception $e) {
            self::flashError('❌ 更新状态失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_PROVIDER);
    }

    private static function handleProviderDelete(): void
    {
        try {
            self::ensureProviderSchema();
            $providerId = intval($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                throw new Exception('参数无效');
            }
            $table = cfmod_get_provider_table_name();
            $providerRow = Capsule::table($table)->where('id', $providerId)->first();
            if (!$providerRow) {
                throw new Exception('供应商不存在');
            }
            if (intval($providerRow->is_default ?? 0) === 1) {
                throw new Exception('无法删除默认账号，请先切换默认账号');
            }
            $boundRoots = Capsule::table('mod_cloudflare_rootdomains')->where('provider_account_id', $providerId)->count();
            if ($boundRoots > 0) {
                throw new Exception('仍有 ' . $boundRoots . ' 个根域名绑定该账号，请先迁移');
            }
            Capsule::table($table)->where('id', $providerId)->delete();
            $defaultProviderAccountId = self::getDefaultProviderAccountId();
            if ($defaultProviderAccountId === $providerId) {
                $newDefault = cfmod_get_active_provider_account(null, false, true);
                if ($newDefault) {
                    $defaultProviderAccountId = intval($newDefault['id']);
                    Capsule::table('tbladdonmodules')->updateOrInsert([
                        'module' => CF_MODULE_NAME,
                        'setting' => 'default_provider_account_id'
                    ], ['value' => $defaultProviderAccountId]);
                } else {
                    Capsule::table('tbladdonmodules')
                        ->where('module', CF_MODULE_NAME)
                        ->where('setting', 'default_provider_account_id')
                        ->delete();
                }
            }
            cf_clear_settings_cache();
            self::flashSuccess('✅ 已删除该供应商账号');
        } catch (Exception $e) {
            self::flashError('❌ 删除失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_PROVIDER);
    }

    private static function handleProviderSetDefault(): void
    {
        try {
            $providerId = intval($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                throw new Exception('参数无效');
            }
            if (!cfmod_set_default_provider_account($providerId)) {
                throw new Exception('设置默认失败，请稍后重试');
            }
            self::flashSuccess('✅ 默认供应商账户已更新');
        } catch (Exception $e) {
            self::flashError('❌ 设置默认失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_PROVIDER);
    }

    private static function handleProviderTest(): void
    {
        try {
            self::ensureProviderSchema();
            $providerId = intval($_POST['provider_id'] ?? 0);
            if ($providerId <= 0) {
                throw new Exception('参数无效');
            }
            $providerAccount = cfmod_get_provider_account($providerId, true);
            if (!$providerAccount) {
                throw new Exception('供应商不存在');
            }
            $accessKeyId = trim((string) ($providerAccount['access_key_id'] ?? ''));
            $accessKeySecret = trim((string) ($providerAccount['access_key_secret'] ?? ''));
            if ($accessKeyId === '' || $accessKeySecret === '') {
                throw new Exception('凭据不完整，无法测试');
            }
            $settingsSnapshot = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
            $context = cfmod_make_provider_client($providerId, null, null, $settingsSnapshot);
            if (!$context || empty($context['client'])) {
                throw new Exception('无法初始化供应商客户端，请检查凭据是否正确');
            }
            $tester = $context['client'];
            if (!method_exists($tester, 'validateCredentials')) {
                throw new Exception('该供应商暂不支持连通性测试');
            }
            if ($tester->validateCredentials()) {
                $labels = self::providerTypeLabels();
                $typeKey = strtolower($providerAccount['provider_type'] ?? 'alidns');
                $label = $labels[$typeKey] ?? strtoupper($typeKey);
                self::flashSuccess('✅ 凭据验证通过，可以正常连接 ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
            } else {
                throw new Exception('API 验证失败，请检查凭据');
            }
        } catch (Exception $e) {
            self::flashError('❌ 连通性测试失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_PROVIDER);
    }

    private static function handleRootdomainAdd(): void
    {
        try {
            self::ensureRootdomainNsManagementColumn();
            self::ensureRootdomainGrayColumns();
            $newDomain = strtolower(trim($_POST['domain'] ?? ''));
            $description = trim($_POST['description'] ?? '');
            $maxSubdomains = intval($_POST['max_subdomains'] ?? 1000);
            if ($maxSubdomains <= 0) {
                $maxSubdomains = 1000;
            }
            $defaultTermYears = intval($_POST['default_term_years'] ?? 0);
            if ($defaultTermYears < 0) {
                $defaultTermYears = 0;
            }
            $disableNsPerRoot = (($_POST['disable_ns_management'] ?? '0') === '1') ? 1 : 0;
            if ($newDomain === '') {
                throw new Exception('请输入根域名');
            }
            $providerAccount = self::resolveProviderAccount(intval($_POST['provider_account_id'] ?? 0), true);
            if (!$providerAccount) {
                throw new Exception('请选择有效的 DNS 供应商账号');
            }
            $providerAccountId = intval($providerAccount['id']);
            $exists = Capsule::table('mod_cloudflare_rootdomains')->whereRaw('LOWER(domain)=?', [$newDomain])->count();
            if ($exists > 0) {
                throw new Exception('根域名已存在');
            }
            $zoneId = null;
            try {
                $settingsSnapshot = self::moduleSettings();
                $providerContext = cfmod_make_provider_client($providerAccountId, $newDomain, null, $settingsSnapshot, true);
                $providerClient = is_array($providerContext) ? ($providerContext['client'] ?? null) : null;
                if (is_object($providerClient) && method_exists($providerClient, 'getZoneId')) {
                    $resolvedZone = $providerClient->getZoneId($newDomain);
                    if ($resolvedZone !== false && $resolvedZone !== null && $resolvedZone !== '') {
                        $zoneId = (string) $resolvedZone;
                    }
                }
            } catch (\Throwable $e) {
                $zoneId = null;
            }
            Capsule::table('mod_cloudflare_rootdomains')->insert([
                'domain' => $newDomain,
                'cloudflare_zone_id' => $zoneId,
                'status' => 'active',
                'display_order' => self::resolveNextRootdomainOrderValue(),
                'description' => $description,
                'max_subdomains' => $maxSubdomains,
                'per_user_limit' => 0,
                'default_term_years' => $defaultTermYears,
                'disable_ns_management' => $disableNsPerRoot,
                'provider_account_id' => $providerAccountId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
                cfmod_clear_rootdomain_limits_cache();
            }
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_add_rootdomain', [
                    'domain' => $newDomain,
                    'zone' => $zoneId,
                    'description' => $description,
                    'max_subdomains' => $maxSubdomains,
                    'default_term_years' => $defaultTermYears,
                    'disable_ns_management' => $disableNsPerRoot,
                    'provider_account_id' => $providerAccountId,
                ]);
            }
            self::flashSuccess('根域名已添加');
        } catch (Exception $e) {
            self::flashError('添加根域名失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainDelete(): void
    {
        $id = intval($_POST['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        $row = Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->first();
        Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->delete();
        if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
            cfmod_clear_rootdomain_limits_cache();
        }
        if ($row && function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('admin_delete_rootdomain', ['domain' => $row->domain ?? null]);
        }
        self::flashSuccess('根域名已删除');
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainToggle(): void
    {
        $id = intval($_POST['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        $row = Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->first();
        if ($row) {
            $newStatus = $row->status === 'active' ? 'suspended' : 'active';
            Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->update([
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_toggle_rootdomain', ['domain' => $row->domain, 'status' => $newStatus]);
            }
            self::flashSuccess('根域名状态已更新');
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainToggleMaintenance(): void
    {
        $id = intval($_POST['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        $row = Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->first();
        if ($row) {
            $currentMaintenance = intval($row->maintenance ?? 0);
            $newMaintenance = $currentMaintenance === 1 ? 0 : 1;
            Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->update([
                'maintenance' => $newMaintenance,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_toggle_rootdomain_maintenance', [
                    'domain' => $row->domain,
                    'maintenance' => $newMaintenance
                ]);
            }
            $statusText = $newMaintenance ? '维护模式已开启' : '维护模式已关闭';
            self::flashSuccess($statusText);
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainSetStatus(): void
    {
        $sel = trim($_POST['rootdomain_id'] ?? '');
        $newStatus = (($_POST['new_status'] ?? '') === 'active') ? 'active' : 'suspended';
        if ($sel === '') {
            self::flashError('参数无效：缺少根域名');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        if (preg_match('/^id-(\d+)$/', $sel, $m)) {
            $rid = intval($m[1]);
            $row = Capsule::table('mod_cloudflare_rootdomains')->where('id', $rid)->first();
            if ($row) {
                Capsule::table('mod_cloudflare_rootdomains')->where('id', $rid)->update([
                    'status' => $newStatus,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_set_rootdomain_status', ['domain' => $row->domain, 'status' => $newStatus]);
                }
                self::flashSuccess('已更新根域名状态');
            } else {
                self::flashError('未找到该根域名');
            }
        } else {
            self::flashError('参数无效：未知选择器');
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainSetLimit(): void
    {
        $sel = trim($_POST['rootdomain_id'] ?? '');
        $limitRaw = $_POST['per_user_limit'] ?? '0';
        $limitValue = is_numeric($limitRaw) ? intval($limitRaw) : 0;
        if ($limitValue < 0) {
            $limitValue = 0;
        }
        if ($sel === '') {
            self::flashError('参数无效：缺少根域名');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        if (preg_match('/^id-(\d+)$/', $sel, $m)) {
            $rid = intval($m[1]);
            $row = Capsule::table('mod_cloudflare_rootdomains')->where('id', $rid)->first();
            if ($row) {
                Capsule::table('mod_cloudflare_rootdomains')->where('id', $rid)->update([
                    'per_user_limit' => $limitValue,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
                    cfmod_clear_rootdomain_limits_cache();
                }
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_set_rootdomain_limit', ['domain' => $row->domain, 'per_user_limit' => $limitValue]);
                }
                if ($limitValue > 0) {
                    $message = cfmod_format_rootdomain_limit_message($row->domain, $limitValue);
                } else {
                    $message = '已取消 ' . $row->domain . ' 的单用户注册限制';
                }
                self::flash($message ?: '单用户上限已更新', 'success');
            } else {
                self::flashError('未找到该根域名');
            }
        } else {
            self::flashError('参数无效：未知选择器');
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainOrderUpdate(): void
    {
        $orders = $_POST['display_order'] ?? [];
        if ((!is_array($orders) || empty($orders)) && isset($_POST['display_order_snapshot'])) {
            $snapshotRaw = trim((string) $_POST['display_order_snapshot']);
            if ($snapshotRaw !== '') {
                $decodedSnapshot = json_decode($snapshotRaw, true);
                if (is_array($decodedSnapshot)) {
                    $orders = $decodedSnapshot;
                }
            }
        }
        if (!is_array($orders) || empty($orders)) {
            self::flashError('未提交排序数据');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        $sanitized = [];
        $orderMin = -2147483648;
        $orderMax = 2147483647;
        foreach ($orders as $id => $value) {
            if (!is_numeric($id)) {
                continue;
            }
            $raw = trim((string) $value);
            $orderValue = ($raw !== '' && is_numeric($raw)) ? (int) $raw : 0;
            if ($orderValue < $orderMin) {
                $orderValue = $orderMin;
            } elseif ($orderValue > $orderMax) {
                $orderValue = $orderMax;
            }
            $sanitized[(int) $id] = $orderValue;
        }
        if (empty($sanitized)) {
            self::flashError('未找到有效的排序数据');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
        try {
            $now = date('Y-m-d H:i:s');
            $ids = array_keys($sanitized);
            $existingIds = Capsule::table('mod_cloudflare_rootdomains')
                ->whereIn('id', $ids)
                ->pluck('id')
                ->toArray();
            if (empty($existingIds)) {
                self::flashError('未找到对应的根域名');
                self::redirect(self::HASH_ROOT_WHITELIST);
            }
            $updatedCount = 0;
            foreach ($existingIds as $id) {
                $orderValue = $sanitized[(int) $id] ?? 0;
                $affected = Capsule::table('mod_cloudflare_rootdomains')->where('id', $id)->update([
                    'display_order' => $orderValue,
                    'updated_at' => $now,
                ]);
                if ($affected > 0) {
                    $updatedCount++;
                }
            }
            if ($updatedCount > 0) {
                self::flashSuccess('根域名排序已更新（' . $updatedCount . ' 项）');
            } else {
                self::flash('未检测到排序变更，请确认输入值是否与当前一致。', 'warning');
            }
        } catch (\Throwable $e) {
            self::flashError('更新排序失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainUpdate(): void
    {
        try {
            self::ensureRootdomainNsManagementColumn();
            $rootId = intval($_POST['rootdomain_id'] ?? 0);
            if ($rootId <= 0) {
                throw new Exception('参数无效');
            }
            $rootRow = Capsule::table('mod_cloudflare_rootdomains')->where('id', $rootId)->first();
            if (!$rootRow) {
                throw new Exception('根域名不存在');
            }
            $providerSelection = intval($_POST['provider_account_id'] ?? 0);
            $providerAccount = self::resolveProviderAccount($providerSelection, false);
            if (!$providerAccount) {
                throw new Exception('请选择有效的 DNS 供应商账号');
            }
            $providerIdForUpdate = intval($providerAccount['id']);
            $maxSubdomainsInput = intval($_POST['max_subdomains'] ?? ($rootRow->max_subdomains ?? 1000));
            if ($maxSubdomainsInput <= 0) {
                $maxSubdomainsInput = 1000;
            }
            $perUserLimitInput = intval($_POST['per_user_limit'] ?? ($rootRow->per_user_limit ?? 0));
            if ($perUserLimitInput < 0) {
                $perUserLimitInput = 0;
            }
            $defaultTermInput = intval($_POST['default_term_years'] ?? ($rootRow->default_term_years ?? 0));
            if ($defaultTermInput < 0) {
                $defaultTermInput = 0;
            }
            $zoneIdInput = trim($_POST['cloudflare_zone_id'] ?? '');
            if ($zoneIdInput === '') {
                try {
                    $settingsSnapshot = self::moduleSettings();
                    $providerContext = cfmod_make_provider_client($providerIdForUpdate, (string) ($rootRow->domain ?? ''), null, $settingsSnapshot, true);
                    $providerClient = is_array($providerContext) ? ($providerContext['client'] ?? null) : null;
                    if (is_object($providerClient) && method_exists($providerClient, 'getZoneId')) {
                        $resolvedZone = $providerClient->getZoneId((string) ($rootRow->domain ?? ''));
                        if ($resolvedZone !== false && $resolvedZone !== null && $resolvedZone !== '') {
                            $zoneIdInput = (string) $resolvedZone;
                        }
                    }
                } catch (\Throwable $e) {
                    $zoneIdInput = '';
                }
            }
            $descriptionInput = trim($_POST['description'] ?? '');
            $requireInviteCode = (($_POST['require_invite_code'] ?? '0') === '1') ? 1 : 0;
            $disableNsPerRoot = (($_POST['disable_ns_management'] ?? '0') === '1') ? 1 : 0;
            $grayEnabled = (($_POST['gray_enabled'] ?? '0') === '1') ? 1 : 0;
            $grayRatio = intval($_POST['gray_ratio'] ?? ($rootRow->gray_ratio ?? 100));
            $grayRatio = max(0, min(100, $grayRatio));
            $updatePayload = [
                'cloudflare_zone_id' => $zoneIdInput !== '' ? $zoneIdInput : null,
                'description' => $descriptionInput !== '' ? $descriptionInput : null,
                'max_subdomains' => $maxSubdomainsInput,
                'per_user_limit' => $perUserLimitInput,
                'default_term_years' => $defaultTermInput,
                'provider_account_id' => $providerIdForUpdate,
                'require_invite_code' => $requireInviteCode,
                'disable_ns_management' => $disableNsPerRoot,
                'gray_enabled' => $grayEnabled,
                'gray_ratio' => $grayRatio,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            Capsule::table('mod_cloudflare_rootdomains')->where('id', $rootId)->update($updatePayload);
            if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
                cfmod_clear_rootdomain_limits_cache();
            }
            $oldProviderId = intval($rootRow->provider_account_id ?? 0);
            if ($oldProviderId !== $providerIdForUpdate && function_exists('cfmod_reassign_subdomains_provider')) {
                cfmod_reassign_subdomains_provider($rootRow->domain ?? '', $providerIdForUpdate);
            }
            self::flashSuccess('✅ 已更新根域名 ' . htmlspecialchars($rootRow->domain ?? '', ENT_QUOTES, 'UTF-8'));
        } catch (Exception $e) {
            self::flashError('❌ 更新根域名失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainReplace(): void
    {
        $from = trim($_POST['from_root'] ?? '');
        $to = trim($_POST['to_root'] ?? '');
        if ($from === '' || $to === '' || $from === $to) {
            self::flashError('参数无效');
            self::redirect(self::HASH_ROOT_REPLACE);
        }
        $deleteOld = (($_POST['delete_old_records'] ?? '') === '1');
        $mode = ($_POST['run_mode'] ?? 'queue') === 'now' ? 'now' : 'queue';
        try {
            if ($mode === 'queue') {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'replace_root_domain',
                    'payload_json' => json_encode([
                        'from_root' => $from,
                        'to_root' => $to,
                        'delete_old' => $deleteOld,
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                self::triggerQueueInBackground();
                $message = '替换任务已加入队列';
                $type = 'success';
            } else {
                if (function_exists('cfmod_job_replace_root')) {
                    cfmod_job_replace_root(0, ['from_root' => $from, 'to_root' => $to, 'delete_old' => $deleteOld]);
                }
                $message = '替换已完成';
                $type = 'success';
            }
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_replace_rootdomain', [
                    'from' => $from,
                    'to' => $to,
                    'delete_old' => $deleteOld ? 1 : 0,
                    'mode' => $mode,
                ]);
            }
            $fromRow = null;
            $toRow = null;
            try {
                $fromRow = Capsule::table('mod_cloudflare_rootdomains')->whereRaw('LOWER(domain)=?', [strtolower($from)])->first();
                $toRow = Capsule::table('mod_cloudflare_rootdomains')->whereRaw('LOWER(domain)=?', [strtolower($to)])->first();
            } catch (Exception $e) {
            }
            $toZone = null;
            try {
                $zoneProviderAccount = $fromRow ? self::resolveProviderAccount(intval($fromRow->provider_account_id ?? 0), true) : self::resolveProviderAccount(null, true);
                if ($zoneProviderAccount) {
                    $settingsSnapshot = self::moduleSettings();
                    $providerContext = cfmod_make_provider_client(intval($zoneProviderAccount['id']), $to, null, $settingsSnapshot, true);
                    $providerClient = is_array($providerContext) ? ($providerContext['client'] ?? null) : null;
                    if (is_object($providerClient) && method_exists($providerClient, 'getZoneId')) {
                        $resolvedZone = $providerClient->getZoneId($to);
                        if ($resolvedZone !== false && $resolvedZone !== null && $resolvedZone !== '') {
                            $toZone = (string) $resolvedZone;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $toZone = null;
            }
            if ($fromRow) {
                try {
                    if ($toRow) {
                        Capsule::table('mod_cloudflare_rootdomains')->where('id', $fromRow->id)->delete();
                    } else {
                        Capsule::table('mod_cloudflare_rootdomains')->where('id', $fromRow->id)->update([
                            'domain' => $to,
                            'cloudflare_zone_id' => $toZone,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                } catch (Exception $e) {
                }
            }
            if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
                cfmod_clear_rootdomain_limits_cache();
            }
            self::flash($message, $type);
        } catch (Exception $e) {
            self::flashError('替换失败: ' . $e->getMessage());
        }
        self::redirect(self::HASH_ROOT_REPLACE);
    }

    private static function normalizeTransferMigrationMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, ['local', 'local-only', 'local_only'], true)) {
            return 'local_only';
        }
        if (in_array($mode, ['cloud', 'cloud-only', 'remote', 'cloud_only'], true)) {
            return 'cloud_only';
        }
        return 'mixed';
    }

    private static function inspectLocalTransferIntegrity(string $rootdomain): array
    {
        $rootdomain = strtolower(trim($rootdomain));
        if ($rootdomain === '') {
            return [
                'total_subdomains' => 0,
                'subdomains_with_local_records' => 0,
                'missing_subdomains' => 0,
                'missing_rate' => 0.0,
            ];
        }

        $totalSubdomains = intval(Capsule::table('mod_cloudflare_subdomain')
            ->whereRaw('LOWER(rootdomain) = ?', [$rootdomain])
            ->count());

        if ($totalSubdomains <= 0) {
            return [
                'total_subdomains' => 0,
                'subdomains_with_local_records' => 0,
                'missing_subdomains' => 0,
                'missing_rate' => 0.0,
            ];
        }

        $withLocalRecords = intval(Capsule::table('mod_cloudflare_dns_records as d')
            ->join('mod_cloudflare_subdomain as s', 's.id', '=', 'd.subdomain_id')
            ->whereRaw('LOWER(s.rootdomain) = ?', [$rootdomain])
            ->distinct()
            ->count('d.subdomain_id'));

        if ($withLocalRecords > $totalSubdomains) {
            $withLocalRecords = $totalSubdomains;
        }

        $missingSubdomains = max(0, $totalSubdomains - $withLocalRecords);
        $missingRate = $totalSubdomains > 0
            ? round(($missingSubdomains / $totalSubdomains) * 100, 2)
            : 0.0;

        return [
            'total_subdomains' => $totalSubdomains,
            'subdomains_with_local_records' => $withLocalRecords,
            'missing_subdomains' => $missingSubdomains,
            'missing_rate' => $missingRate,
        ];
    }

    private static function handleRootdomainTransfer(): void
    {
        $rootdomain = strtolower(trim($_POST['transfer_rootdomain'] ?? ''));
        $targetProviderId = intval($_POST['target_provider_account_id'] ?? 0);
        $batchSize = intval($_POST['transfer_batch_size'] ?? 200);
        if ($batchSize <= 0) {
            $batchSize = 200;
        }
        $batchSize = max(25, min(5000, $batchSize));
        $transferMode = self::normalizeTransferMigrationMode((string) ($_POST['transfer_migration_mode'] ?? 'mixed'));
        $transferModeLabels = [
            'local_only' => '仅本地',
            'mixed' => '混合',
            'cloud_only' => '仅云端',
        ];
        $transferModeLabel = $transferModeLabels[$transferMode] ?? '混合';
        $localMissingThreshold = floatval($_POST['transfer_local_missing_threshold'] ?? 30);
        if (!is_finite($localMissingThreshold)) {
            $localMissingThreshold = 30;
        }
        $localMissingThreshold = max(0.0, min(100.0, $localMissingThreshold));
        $deleteOld = ($_POST['transfer_delete_old'] ?? '') === '1';
        $pauseRegistration = ($_POST['transfer_pause_registration'] ?? '') === '1';
        $autoResume = ($_POST['transfer_auto_resume'] ?? '1') === '1';
        $runMode = (($_POST['transfer_run_mode'] ?? 'queue') === 'now') ? 'now' : 'queue';

        if ($rootdomain === '') {
            self::flashError('请选择要迁移的根域名');
            self::redirect(self::HASH_ROOT_WHITELIST);
        }

        try {
            if ($targetProviderId <= 0) {
                throw new Exception('请选择目标 DNS 供应商');
            }
            $moduleSettings = self::moduleSettings();
            $rootRow = Capsule::table('mod_cloudflare_rootdomains')
                ->whereRaw('LOWER(domain)=?', [$rootdomain])
                ->first();
            if (!$rootRow) {
                throw new Exception('未找到该根域名');
            }

            $localIntegrityInfo = null;
            if ($transferMode !== 'cloud_only') {
                $localIntegrityInfo = self::inspectLocalTransferIntegrity($rootdomain);
                $missingRate = floatval($localIntegrityInfo['missing_rate'] ?? 0);
                $totalSubdomains = intval($localIntegrityInfo['total_subdomains'] ?? 0);
                if ($totalSubdomains > 0 && $missingRate > $localMissingThreshold) {
                    throw new Exception(
                        '本地完整性检查未通过：缺失率 ' . $missingRate . '% 超过阈值 ' . $localMissingThreshold . '%（共 ' . $totalSubdomains . ' 个子域）'
                    );
                }
            }

            $targetAccount = self::resolveProviderAccount($targetProviderId, true);
            if (!$targetAccount) {
                throw new Exception('目标供应商账号不可用或已停用');
            }

            $targetContext = cfmod_make_provider_client(intval($targetAccount['id']), $rootdomain, null, $moduleSettings, true);
            if (!$targetContext || empty($targetContext['client'])) {
                throw new Exception('无法连接目标供应商，请检查凭据');
            }
            $targetClient = $targetContext['client'];
            $targetZoneId = $targetClient->getZoneId($rootdomain);
            if (!$targetZoneId) {
                throw new Exception('目标供应商中未找到该根域名，请先完成托管后再试');
            }

            $payload = [
                'rootdomain' => $rootdomain,
                'target_provider_id' => intval($targetAccount['id']),
                'target_zone_identifier' => $targetZoneId,
                'batch_size' => $batchSize,
                'transfer_mode' => $transferMode,
                'local_missing_threshold' => $localMissingThreshold,
                'delete_old_records' => $deleteOld ? 1 : 0,
                'cursor_id' => 0,
                'resume_status' => $rootRow->status ?? 'active',
                'auto_resume' => $autoResume ? 1 : 0,
                'pause_registration' => $pauseRegistration ? 1 : 0,
            ];
            if (is_array($localIntegrityInfo)) {
                $payload['local_integrity'] = $localIntegrityInfo;
            }

            $now = date('Y-m-d H:i:s');
            if ($pauseRegistration && ($rootRow->status ?? '') === 'active') {
                Capsule::table('mod_cloudflare_rootdomains')
                    ->where('id', $rootRow->id)
                    ->update([
                        'status' => 'suspended',
                        'updated_at' => $now,
                    ]);
            }

            if ($runMode === 'queue') {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'transfer_root_provider',
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                self::triggerQueueInBackground();
                self::flashSuccess('迁移任务已加入队列（模式：' . $transferModeLabel . '），稍后将在后台逐批处理。');
            } else {
                $jobStub = (object) ['id' => 0, 'priority' => 5];
                $round = 0;
                $maxRounds = 2000;
                $cursor = 0;
                $summaryStats = [
                    'processed_subdomains' => 0,
                    'records_created_on_cf' => 0,
                    'records_updated_on_cf' => 0,
                    'records_exists_on_cf' => 0,
                    'records_deleted_on_cf' => 0,
                    'records_updated_local' => 0,
                    'records_imported_local' => 0,
                    'remote_rate_limit_hits' => 0,
                    'rate_limit_backoffs' => 0,
                ];
                $warningCount = 0;
                $lastMessage = '迁移已完成';
                do {
                    $round++;
                    $runPayload = $payload;
                    $runPayload['cursor_id'] = $cursor;
                    $runPayload['target_zone_identifier'] = $targetZoneId;
                    $runPayload['disable_continuation_enqueue'] = 1;
                    $stats = cfmod_job_transfer_root_provider($jobStub, $runPayload);
                    foreach ($summaryStats as $key => $value) {
                        $summaryStats[$key] += intval($stats[$key] ?? 0);
                    }
                    $warnings = $stats['warnings'] ?? [];
                    if (is_array($warnings)) {
                        $warningCount += count($warnings);
                    }
                    $lastMessage = $stats['message'] ?? $lastMessage;
                    $hasMore = !empty($stats['has_more']);
                    $nextCursor = intval($stats['cursor_end'] ?? $cursor);
                    if ($hasMore && $nextCursor <= $cursor) {
                        $hasMore = false;
                        $warningCount++;
                    }
                    $cursor = $nextCursor;
                } while ($hasMore && $round < $maxRounds);

                $summaryParts = [];
                $summaryParts[] = '子域 ' . intval($summaryStats['processed_subdomains']) . ' 个';
                $summaryParts[] = '新增解析 ' . intval($summaryStats['records_created_on_cf']) . ' 条';
                if (intval($summaryStats['records_exists_on_cf']) > 0) {
                    $summaryParts[] = '已存在 ' . intval($summaryStats['records_exists_on_cf']) . ' 条';
                }
                if (intval($summaryStats['records_updated_on_cf']) > 0) {
                    $summaryParts[] = '更新解析 ' . intval($summaryStats['records_updated_on_cf']) . ' 条';
                }
                if (intval($summaryStats['records_deleted_on_cf']) > 0) {
                    $summaryParts[] = '删除旧平台解析 ' . intval($summaryStats['records_deleted_on_cf']) . ' 条';
                }
                if (intval($summaryStats['records_imported_local']) > 0 || intval($summaryStats['records_updated_local']) > 0) {
                    $summaryParts[] = '本地同步 ' . (intval($summaryStats['records_imported_local']) + intval($summaryStats['records_updated_local'])) . ' 条';
                }
                if (intval($summaryStats['remote_rate_limit_hits']) > 0) {
                    $summaryParts[] = '云端限流 ' . intval($summaryStats['remote_rate_limit_hits']) . ' 次';
                }
                if (intval($summaryStats['rate_limit_backoffs']) > 0) {
                    $summaryParts[] = '指数退避 ' . intval($summaryStats['rate_limit_backoffs']) . ' 次';
                }
                if ($warningCount > 0) {
                    $summaryParts[] = '警告 ' . $warningCount . ' 条';
                }
                if ($round >= $maxRounds) {
                    $summaryParts[] = '达到单次执行上限，请改用队列模式继续';
                }

                $summary = implode('，', array_filter($summaryParts));
                if ($summary === '') {
                    $summary = $lastMessage;
                }
                self::flashSuccess('迁移执行完成：' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));
            }

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_transfer_root_provider', [
                    'rootdomain' => $rootdomain,
                    'target_provider_id' => intval($targetAccount['id']),
                    'batch_size' => $batchSize,
                    'transfer_mode' => $transferMode,
                    'local_missing_threshold' => $localMissingThreshold,
                    'local_integrity' => $localIntegrityInfo,
                    'delete_old_records' => $deleteOld ? 1 : 0,
                    'run_mode' => $runMode,
                    'pause_registration' => $pauseRegistration ? 1 : 0,
                ]);
            }
        } catch (Exception $e) {
            self::flashError('域名平台迁移失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainExport(): void
    {
        try {
            if (!function_exists('cfmod_collect_rootdomain_dataset') || !function_exists('cfmod_stream_export_dataset')) {
                throw new Exception('当前环境不支持数据导出');
            }
            $targetRoot = trim($_POST['export_rootdomain_value'] ?? '');
            if ($targetRoot === '') {
                throw new Exception('请选择要导出的根域名');
            }
            $dataset = cfmod_collect_rootdomain_dataset($targetRoot);
            cfmod_stream_export_dataset($dataset, $targetRoot);
            exit;
        } catch (Exception $e) {
            self::flashError('导出失败：' . $e->getMessage());
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
    }

    private static function handleRootdomainImport(): void
    {
        try {
            if (!function_exists('cfmod_import_rootdomain_dataset')) {
                throw new Exception('当前环境不支持数据导入');
            }
            $data = self::parseUploadedJsonFile('import_rootdomain_file');
            $summary = cfmod_import_rootdomain_dataset($data);
            if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
                cfmod_clear_rootdomain_limits_cache();
            }
            $parts = [];
            $parts[] = '子域 ' . intval($summary['subdomains_inserted'] ?? 0) . ' 个';
            $parts[] = 'DNS 记录 ' . intval($summary['dns_records_inserted'] ?? 0) . ' 条';
            if (!empty($summary['domain_risk_inserted'])) {
                $parts[] = '域名风险 ' . intval($summary['domain_risk_inserted']) . ' 条';
            }
            if (!empty($summary['risk_events_inserted'])) {
                $parts[] = '风险事件 ' . intval($summary['risk_events_inserted']) . ' 条';
            }
            if (!empty($summary['sync_results_inserted'])) {
                $parts[] = '同步差异 ' . intval($summary['sync_results_inserted']) . ' 条';
            }
            $parts[] = '配额更新 ' . intval($summary['quota_updates'] ?? 0) . ' 项';
            if (!empty($summary['quota_created'])) {
                $parts[] = '新增配额 ' . intval($summary['quota_created']) . ' 项';
            }
            $rootLabel = $summary['rootdomain'] ?? '未知';
            $message = '导入完成（根域：' . $rootLabel . '）：' . implode('，', $parts);
            if (!empty($summary['warnings'])) {
                $preview = array_slice($summary['warnings'], 0, 3);
                $message .= '。注意：' . implode('；', $preview);
                if (count($summary['warnings']) > 3) {
                    $message .= ' 等。';
                }
            }
            self::flash($message, 'success');
        } catch (Exception $e) {
            self::flashError('导入失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleRootdomainPdnsExport(): void
    {
        try {
            if (!function_exists('cfmod_collect_rootdomain_pdns_dataset') || !function_exists('cfmod_stream_export_dataset')) {
                throw new Exception('当前环境不支持 PDNS 兼容导出');
            }
            $targetRoot = trim($_POST['export_rootdomain_pdns_value'] ?? '');
            if ($targetRoot === '') {
                throw new Exception('请选择要导出的根域名');
            }
            $exportSource = strtolower(trim((string) ($_POST['pdns_export_source'] ?? 'remote')));
            if (!in_array($exportSource, ['remote', 'local'], true)) {
                $exportSource = 'remote';
            }
            $useSegmented = (($_POST['pdns_segmented_export'] ?? '0') === '1');
            $segmentSizeRaw = $_POST['pdns_export_segment_size'] ?? 10000;
            $segmentSize = function_exists('cfmod_pdns_resolve_segment_size')
                ? cfmod_pdns_resolve_segment_size($segmentSizeRaw)
                : max(1000, min(50000, intval($segmentSizeRaw ?: 10000)));

            if ($exportSource === 'local') {
                if (!function_exists('cfmod_collect_rootdomain_pdns_dataset_from_local')) {
                    throw new Exception('当前环境不支持本地缓存导出');
                }
                $localLimitMode = strtolower(trim((string) ($_POST['pdns_local_export_limit_mode'] ?? 'none')));
                if (!in_array($localLimitMode, ['none', 'subdomain', 'record'], true)) {
                    $localLimitMode = 'none';
                }
                $localLimitValue = intval($_POST['pdns_local_export_limit_value'] ?? 1000);
                if ($localLimitValue <= 0) {
                    $localLimitValue = 1000;
                }
                $autoContinuousExport = isset($_POST['pdns_local_auto_continue'])
                    && (string) $_POST['pdns_local_auto_continue'] === '1';
                $resumeCursor = isset($_POST['pdns_local_resume_cursor'])
                    && (string) $_POST['pdns_local_resume_cursor'] === '1';
                $resetCursor = isset($_POST['pdns_local_reset_cursor'])
                    && (string) $_POST['pdns_local_reset_cursor'] === '1';

                $exportOptions = [
                    'limit_mode' => $localLimitMode,
                    'limit_value' => $localLimitValue,
                ];

                if (in_array($localLimitMode, ['subdomain', 'record'], true)) {
                    if ($resetCursor) {
                        self::clearPdnsLocalExportCursorState($targetRoot, $localLimitMode, $localLimitValue);
                    }
                    if ($resumeCursor) {
                        $cursorState = self::getPdnsLocalExportCursorState($targetRoot, $localLimitMode, $localLimitValue);
                        if (is_array($cursorState) && !empty($cursorState['has_more'])) {
                            $resumeFromCursor = max(0, intval($cursorState['next_cursor'] ?? 0));
                            if ($resumeFromCursor > 0) {
                                $exportOptions['cursor'] = $resumeFromCursor;
                            }
                        }
                    }
                } else {
                    self::clearPdnsLocalExportCursorState($targetRoot);
                }

                if ($autoContinuousExport) {
                    if (!in_array($localLimitMode, ['subdomain', 'record'], true)) {
                        throw new Exception('自动连续导出需选择“仅前 N 个子域名的记录”或“仅前 N 条 DNS 记录”限制模式');
                    }
                    if (!function_exists('cfmod_collect_rootdomain_pdns_dataset_from_local_auto')) {
                        throw new Exception('当前环境不支持自动连续导出');
                    }
                    $exportOptions['sleep_seconds'] = 10;
                    $dataset = cfmod_collect_rootdomain_pdns_dataset_from_local_auto($targetRoot, $exportOptions);
                } else {
                    $dataset = cfmod_collect_rootdomain_pdns_dataset_from_local($targetRoot, $exportOptions);
                }

                if (in_array($localLimitMode, ['subdomain', 'record'], true)) {
                    self::syncPdnsLocalExportCursorState($targetRoot, $localLimitMode, $localLimitValue, $dataset);
                }
                if ($useSegmented && function_exists('cfmod_pdns_make_segmented_dataset')) {
                    $dataset = cfmod_pdns_make_segmented_dataset($dataset, $segmentSize);
                    cfmod_stream_export_dataset($dataset, $targetRoot, 'domain_hub_pdns_export_local_segmented');
                } else {
                    cfmod_stream_export_dataset($dataset, $targetRoot, 'domain_hub_pdns_export_local');
                }
            } else {
                self::clearPdnsLocalExportCursorState($targetRoot);
                if ($useSegmented && function_exists('cfmod_collect_rootdomain_pdns_dataset_segmented')) {
                    $dataset = cfmod_collect_rootdomain_pdns_dataset_segmented($targetRoot, $segmentSize);
                    cfmod_stream_export_dataset($dataset, $targetRoot, 'domain_hub_pdns_export_segmented');
                } else {
                    $dataset = cfmod_collect_rootdomain_pdns_dataset($targetRoot);
                    cfmod_stream_export_dataset($dataset, $targetRoot, 'domain_hub_pdns_export');
                }
            }
            exit;
        } catch (Exception $e) {
            self::flashError('PDNS 兼容导出失败：' . $e->getMessage());
            self::redirect(self::HASH_ROOT_WHITELIST);
        }
    }

    private static function handleRootdomainPdnsImport(): void
    {
        try {
            if (!function_exists('cfmod_import_rootdomain_pdns_dataset')) {
                throw new Exception('当前环境不支持 PDNS 兼容导入');
            }
            $targetRoot = trim($_POST['import_rootdomain_pdns_target'] ?? '');
            if ($targetRoot === '') {
                throw new Exception('请选择目标根域名');
            }

            $overwriteSameNameType = isset($_POST['pdns_overwrite_same_name_type']) && (string) $_POST['pdns_overwrite_same_name_type'] === '1';
            $enqueueCalibration = isset($_POST['pdns_enqueue_root_calibration']) && (string) $_POST['pdns_enqueue_root_calibration'] === '1';
            $useSegmentedImport = isset($_POST['pdns_segmented_import']) && (string) $_POST['pdns_segmented_import'] === '1';
            $segmentSizeRaw = $_POST['pdns_import_segment_size'] ?? 10000;
            $segmentSize = function_exists('cfmod_pdns_resolve_segment_size')
                ? cfmod_pdns_resolve_segment_size($segmentSizeRaw)
                : max(1000, min(50000, intval($segmentSizeRaw ?: 10000)));

            $data = self::parseUploadedJsonFile('import_rootdomain_pdns_file');

            $summary = cfmod_import_rootdomain_pdns_dataset($data, $targetRoot, [
                'overwrite_same_name_type' => $overwriteSameNameType ? 1 : 0,
                'chunked_import' => $useSegmentedImport ? 1 : 0,
                'segment_size' => $segmentSize,
            ]);

            if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
                cfmod_clear_rootdomain_limits_cache();
            }

            $calibrationJobId = null;
            if ($enqueueCalibration) {
                $calibrationJobId = self::enqueueRootCalibrationJob((string) ($summary['rootdomain'] ?? $targetRoot));
            }

            $parts = [];
            $parts[] = 'RRSet ' . intval($summary['rrsets_total'] ?? 0) . ' 组';
            if (!empty($summary['segments_processed']) && intval($summary['segments_processed']) > 1) {
                $parts[] = '分段 ' . intval($summary['segments_processed']) . '/' . intval($summary['segments_total'] ?? 0) . ' 段';
            }
            $parts[] = '新增 ' . intval($summary['records_created'] ?? 0) . ' 条';
            if (!empty($summary['records_deleted_existing'])) {
                $parts[] = '替换删除 ' . intval($summary['records_deleted_existing']) . ' 条';
            }
            if (!empty($summary['records_skipped_existing'])) {
                $parts[] = '跳过已存在 ' . intval($summary['records_skipped_existing']) . ' 条';
            }
            if (!empty($summary['records_failed'])) {
                $parts[] = '失败 ' . intval($summary['records_failed']) . ' 条';
            }

            $message = 'PDNS 兼容导入完成（根域：' . ($summary['rootdomain'] ?? $targetRoot) . '）：' . implode('，', $parts);
            if ($calibrationJobId !== null) {
                $message .= '。已自动提交校准任务（Job ID: ' . intval($calibrationJobId) . '）。';
            }
            if (!empty($summary['warnings']) && is_array($summary['warnings'])) {
                $preview = array_slice($summary['warnings'], 0, 3);
                if (!empty($preview)) {
                    $message .= ' 注意：' . implode('；', $preview);
                    if (count($summary['warnings']) > 3) {
                        $message .= ' 等。';
                    }
                }
            }

            $flashType = !empty($summary['records_failed']) ? 'warning' : 'success';
            self::flash($message, $flashType);
        } catch (Exception $e) {
            self::flashError('PDNS 兼容导入失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function parseUploadedJsonFile(string $fieldName): array
    {
        if (!isset($_FILES[$fieldName])) {
            throw new Exception('请上传导出文件');
        }
        $fileInfo = $_FILES[$fieldName];
        if (($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('文件上传失败');
        }
        $content = file_get_contents($fileInfo['tmp_name']);
        if ($content === '' || $content === false) {
            throw new Exception('文件内容为空');
        }
        if (function_exists('gzdecode') && substr($content, 0, 2) === "\x1f\x8b") {
            $decoded = @gzdecode($content);
            if ($decoded !== false) {
                $content = $decoded;
            }
        }
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('解析 JSON 失败：' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new Exception('导入文件格式不正确');
        }
        return $data;
    }

    private static function enqueueRootCalibrationJob(string $rootdomain): ?int
    {
        $rootdomain = strtolower(trim($rootdomain));
        if ($rootdomain === '') {
            return null;
        }
        if (function_exists('cfmod_table_exists') && !cfmod_table_exists('mod_cloudflare_jobs')) {
            return null;
        }
        $payload = [
            'mode' => 'fix',
            'rootdomain' => $rootdomain,
            'fix_ttl' => 1,
            'fix_missing' => 1,
            'fix_extra' => 0,
        ];
        $now = date('Y-m-d H:i:s');
        $jobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
            'type' => 'calibrate_all',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'priority' => 5,
            'status' => 'pending',
            'attempts' => 0,
            'next_run_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::triggerQueueInBackground();
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('admin_enqueue_root_calibration', $payload + ['job_id' => $jobId]);
        }
        return $jobId > 0 ? intval($jobId) : null;
    }

    private static function handleRootdomainPurgeLocal(): void
    {
        try {
            $targetRoot = strtolower(trim($_POST['target_rootdomain'] ?? ''));
            $confirmRoot = strtolower(trim($_POST['confirm_rootdomain'] ?? ''));
            $batchSizeInput = intval($_POST['batch_size'] ?? 200);
            $runNow = (($_POST['run_now'] ?? '') === '1');
            if ($targetRoot === '') {
                throw new Exception('请指定要清理的根域名');
            }
            if ($confirmRoot === '' || $confirmRoot !== $targetRoot) {
                throw new Exception('确认根域名与目标不一致');
            }
            if (!preg_match('/^[a-z0-9.-]+$/', $targetRoot)) {
                throw new Exception('根域名格式不正确');
            }
            $estimated = Capsule::table('mod_cloudflare_subdomain')
                ->whereRaw('LOWER(rootdomain) = ?', [$targetRoot])
                ->count();
            if ($estimated === 0) {
                self::flash('未找到根域名 ' . $targetRoot . ' 下的子域名', 'warning');
                self::redirect(self::HASH_ROOT_WHITELIST);
            }
            $batchSize = max(20, min(5000, ($batchSizeInput > 0 ? $batchSizeInput : 200)));
            $payload = [
                'rootdomain' => $targetRoot,
                'batch_size' => $batchSize,
                'initiator' => 'admin',
            ];
            if (!empty($_SESSION['adminid'])) {
                $payload['admin_id'] = intval($_SESSION['adminid']);
            }
            $nowTs = date('Y-m-d H:i:s');
            $jobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                'type' => 'purge_root_local',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'priority' => 6,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $nowTs,
                'updated_at' => $nowTs
            ]);
            if ($runNow) {
                self::triggerQueueInBackground();
            }
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_purge_rootdomain_local_requested', [
                    'rootdomain' => $targetRoot,
                    'batch_size' => $batchSize,
                    'estimated_subdomains' => $estimated,
                    'job_id' => $jobId,
                    'run_now' => $runNow ? 1 : 0,
                ]);
            }
            self::flashSuccess("已提交本地删除任务（Job ID: {$jobId}），预计处理 {$estimated} 个子域名。仅清理本地数据，云端记录保持不变。");
        } catch (Exception $e) {
            self::flashError('提交删除任务失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_ROOT_WHITELIST);
    }

    private static function handleForbiddenAdd(): void
    {
        $banDomain = trim($_POST['ban_domain'] ?? '');
        $banRoot = trim($_POST['ban_root'] ?? '');
        $banReason = trim($_POST['ban_reason'] ?? '');
        if ($banDomain === '') {
            self::flashError('请输入域名');
            self::redirect(self::HASH_FORBIDDEN);
        }
        try {
            $exists = Capsule::table('mod_cloudflare_forbidden_domains')->where('domain', $banDomain)->count();
            if ($exists > 0) {
                self::flash('禁止域名已存在', 'warning');
            } else {
                Capsule::table('mod_cloudflare_forbidden_domains')->insert([
                    'domain' => strtolower($banDomain),
                    'rootdomain' => $banRoot ?: null,
                    'reason' => $banReason ?: null,
                    'added_by' => 'admin',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_add_forbidden_domain', ['domain' => $banDomain, 'root' => $banRoot, 'reason' => $banReason]);
                }
                self::flashSuccess('已添加禁止域名');
            }
        } catch (Exception $e) {
            self::flashError('添加失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_FORBIDDEN);
    }

    private static function handleForbiddenDelete(): void
    {
        $id = intval($_POST['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) {
            self::flashError('参数无效');
        } else {
            Capsule::table('mod_cloudflare_forbidden_domains')->where('id', $id)->delete();
            self::flashSuccess('已移除禁止域名');
        }
        self::redirect(self::HASH_FORBIDDEN);
    }


    private static function handleJobRetry(): void
    {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_JOBS);
        }

        try {
            Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->update([
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
            ]);
            self::flashSuccess('已重试作业 #' . $jobId);
        } catch (Exception $e) {
            self::flashError('重试失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function normalizeBulkJobIdsFromPost(): array
    {
        $raw = $_POST['job_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        $ids = [];
        foreach ($raw as $item) {
            $id = intval($item);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private static function resetJobForRetry(int $jobId, string $now): bool
    {
        $update = [
            'status' => 'pending',
            'attempts' => 0,
            'next_run_at' => $now,
            'updated_at' => $now,
            'last_error' => null,
        ];
        try {
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'lease_token')) {
                $update['lease_token'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'worker_id')) {
                $update['worker_id'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'heartbeat_at')) {
                $update['heartbeat_at'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'started_at')) {
                $update['started_at'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'finished_at')) {
                $update['finished_at'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'duration_seconds')) {
                $update['duration_seconds'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'stats_json')) {
                $update['stats_json'] = null;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested')) {
                $update['cancel_requested'] = 0;
            }
            if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested_at')) {
                $update['cancel_requested_at'] = null;
            }
        } catch (\Throwable $ignored) {
        }

        $updated = Capsule::table('mod_cloudflare_jobs')
            ->where('id', $jobId)
            ->where('status', '!=', 'running')
            ->update($update);

        return $updated > 0;
    }

    private static function handleJobBulkRetry(): void
    {
        $ids = self::normalizeBulkJobIdsFromPost();
        if (empty($ids)) {
            self::flashError('请先勾选需要重试的作业');
            self::redirect(self::HASH_JOBS);
        }

        $now = date('Y-m-d H:i:s');
        $ok = 0;
        $skip = 0;
        foreach ($ids as $id) {
            try {
                if (self::resetJobForRetry((int) $id, $now)) {
                    $ok++;
                } else {
                    $skip++;
                }
            } catch (\Throwable $e) {
                $skip++;
            }
        }

        if ($ok > 0) {
            self::flashSuccess('批量重试完成：成功 ' . $ok . '，跳过 ' . $skip . '（运行中任务不会被重试）');
        } else {
            self::flashError('批量重试未生效：请确认所选任务不是运行中状态');
        }
        self::redirect(self::HASH_JOBS);
    }

    private static function handleJobCancel(): void
    {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_JOBS);
        }

        try {
            $job = Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->first();
            if (!$job) {
                self::flashError('作业不存在');
                self::redirect(self::HASH_JOBS);
            }

            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => 'cancelled',
                'next_run_at' => null,
                'updated_at' => $now,
                'last_error' => 'cancelled_by_admin',
            ];
            try {
                if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'lease_token')) {
                    $update['lease_token'] = null;
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'worker_id')) {
                    $update['worker_id'] = null;
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'heartbeat_at')) {
                    $update['heartbeat_at'] = null;
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'finished_at')) {
                    $update['finished_at'] = $now;
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested')) {
                    $update['cancel_requested'] = 1;
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested_at')) {
                    $update['cancel_requested_at'] = $now;
                }
            } catch (\Throwable $ignored) {
            }
            Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->update($update);
            self::flashSuccess('已取消作业 #' . $jobId);
        } catch (Exception $e) {
            self::flashError('取消失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function handleJobBulkCancel(): void
    {
        $ids = self::normalizeBulkJobIdsFromPost();
        if (empty($ids)) {
            self::flashError('请先勾选需要取消的作业');
            self::redirect(self::HASH_JOBS);
        }

        $now = date('Y-m-d H:i:s');
        $ok = 0;
        foreach ($ids as $id) {
            try {
                $update = [
                    'status' => 'cancelled',
                    'next_run_at' => null,
                    'updated_at' => $now,
                    'last_error' => 'cancelled_by_admin_bulk',
                ];
                try {
                    if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'lease_token')) {
                        $update['lease_token'] = null;
                    }
                    if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'worker_id')) {
                        $update['worker_id'] = null;
                    }
                    if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'heartbeat_at')) {
                        $update['heartbeat_at'] = null;
                    }
                    if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'finished_at')) {
                        $update['finished_at'] = $now;
                    }
                    if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested')) {
                        $update['cancel_requested'] = 1;
                    }
                    if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested_at')) {
                        $update['cancel_requested_at'] = $now;
                    }
                } catch (\Throwable $ignored) {
                }

                $updated = Capsule::table('mod_cloudflare_jobs')->where('id', intval($id))->update($update);
                if ($updated > 0) {
                    $ok++;
                }
            } catch (\Throwable $e) {
            }
        }

        if ($ok > 0) {
            self::flashSuccess('批量取消完成：成功 ' . $ok . ' 条');
        } else {
            self::flashError('批量取消失败：未匹配到可更新的任务');
        }
        self::redirect(self::HASH_JOBS);
    }

    private static function handleJobForceFail(): void
    {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_JOBS);
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->update([
            'status' => 'failed',
            'next_run_at' => null,
            'updated_at' => $now,
            'last_error' => 'forced_failed_by_admin',
        ]);
        self::flashSuccess('已强制失败作业 #' . $jobId);
        self::redirect(self::HASH_JOBS);
    }

    private static function handleJobSkip(): void
    {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_JOBS);
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->update([
            'status' => 'cancelled',
            'next_run_at' => null,
            'updated_at' => $now,
            'last_error' => 'skipped_by_admin',
        ]);
        self::flashSuccess('已手动跳过作业 #' . $jobId);
        self::redirect(self::HASH_JOBS);
    }

    private static function handleJobDemotePriority(): void
    {
        $jobId = intval($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            self::flashError('参数无效');
            self::redirect(self::HASH_JOBS);
        }
        $job = Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->first();
        if (!$job) {
            self::flashError('作业不存在');
            self::redirect(self::HASH_JOBS);
        }
        $old = max(1, intval($job->priority ?? 10));
        $next = min(99, $old + 10);
        Capsule::table('mod_cloudflare_jobs')->where('id', $jobId)->update([
            'priority' => $next,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::flashSuccess('作业 #' . $jobId . ' 优先级已降级：' . $old . ' → ' . $next);
        self::redirect(self::HASH_JOBS);
    }

    private static function handleEnqueueCalibration(): void
    {
        try {
            $mode = self::resolveSyncJobMode();
            $scope = self::resolveSyncFixScopeFromPost($mode);
            $targetRoot = self::resolveSyncTargetRootdomain(false);
            $targetUserId = self::resolveSyncTargetUserId();

            $payload = [
                'mode' => $mode,
                'fix_ttl' => $scope['ttl'] ? 1 : 0,
                'fix_missing' => $scope['missing'] ? 1 : 0,
                'fix_extra' => $scope['extra'] ? 1 : 0,
            ];
            if ($targetRoot !== '') {
                $payload['rootdomain'] = $targetRoot;
            }
            if ($targetUserId > 0) {
                $payload['userid'] = $targetUserId;
            }

            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'calibrate_all',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::triggerQueueInBackground();
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_enqueue_calibration', $payload);
            }
            $scopeText = ($scope['ttl'] ? 'TTL/' : '') . ($scope['missing'] ? '缺失/' : '') . ($scope['extra'] ? '多余/' : '');
            $scopeText = rtrim($scopeText, '/');
            self::flashSuccess('已提交校准作业（范围：' . ($scopeText !== '' ? $scopeText : '默认') . '）');
        } catch (Exception $e) {
            self::flashError('提交校准失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function handleEnqueueRootCalibration(): void
    {
        try {
            $mode = self::resolveSyncJobMode();
            $scope = self::resolveSyncFixScopeFromPost($mode);
            $targetRoot = self::resolveSyncTargetRootdomain(true);
            $targetUserId = self::resolveSyncTargetUserId();

            $payload = [
                'mode' => $mode,
                'rootdomain' => $targetRoot,
                'fix_ttl' => $scope['ttl'] ? 1 : 0,
                'fix_missing' => $scope['missing'] ? 1 : 0,
                'fix_extra' => $scope['extra'] ? 1 : 0,
            ];
            if ($targetUserId > 0) {
                $payload['userid'] = $targetUserId;
            }

            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'calibrate_all',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::triggerQueueInBackground();
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_enqueue_root_calibration', $payload);
            }
            self::flashSuccess(sprintf('已提交根域 %s 的校准作业', htmlspecialchars($targetRoot, ENT_QUOTES, 'UTF-8')));
        } catch (Exception $e) {
            self::flashError('提交校准失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function handleEnqueueRiskScan(): void
    {
        try {
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'risk_scan_all',
                'payload_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_enqueue_risk_scan', []);
            }
            self::flashSuccess('已提交风险扫描作业（将由 Cron 异步执行）');
        } catch (Exception $e) {
            self::flashError('提交风险扫描失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_RISK_MONITOR);
    }

    private static function handleEnqueueVirusTotalScan(): void
    {
        try {
            $moduleSettings = self::moduleSettings();
            $batchSize = max(10, min(1000, intval($moduleSettings['virustotal_scan_batch_size'] ?? ($moduleSettings['risk_scan_batch_size'] ?? 50))));
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'virustotal_scan_all',
                'payload_json' => json_encode(['batch_size' => $batchSize], JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_enqueue_virustotal_scan', ['batch_size' => $batchSize]);
            }
            self::flashSuccess('已提交 VirusTotal 扫描作业（将由 Cron 异步执行）');
        } catch (Exception $e) {
            self::flashError('提交 VirusTotal 扫描失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_RISK_MONITOR);
    }

    private static function handleEnqueueVirusTotalForceRescan(): void
    {
        try {
            $moduleSettings = self::moduleSettings();
            $batchSize = max(10, min(1000, intval($moduleSettings['virustotal_scan_batch_size'] ?? ($moduleSettings['risk_scan_batch_size'] ?? 50))));
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'virustotal_scan_all',
                'payload_json' => json_encode(['batch_size' => $batchSize, 'force_refresh' => 1], JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::flashSuccess('已提交 VirusTotal 强制重扫作业。');
        } catch (Exception $e) {
            self::flashError('提交 VirusTotal 强制重扫失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_RISK_MONITOR);
    }

    private static function handleClearVirusTotalExpiredCache(): void
    {
        $moduleSettings = self::moduleSettings();
        $ttl = max(1, intval($moduleSettings['virustotal_cache_ttl_hours'] ?? 12));
        $deleted = class_exists('CfVirusTotalService') ? CfVirusTotalService::clearExpiredCache($ttl) : 0;
        self::flashSuccess('VirusTotal 过期缓存清理完成，删除 ' . intval($deleted) . ' 条记录。');
        self::redirect(self::HASH_RISK_MONITOR);
    }

    private static function handleClearVirusTotalDomainCache(): void
    {
        $domain = trim((string) ($_POST['virustotal_domain'] ?? ''));
        $deleted = class_exists('CfVirusTotalService') ? CfVirusTotalService::clearDomainCache($domain) : 0;
        self::flashSuccess('VirusTotal 域名缓存清理完成，删除 ' . intval($deleted) . ' 条记录。');
        self::redirect(self::HASH_RISK_MONITOR);
    }

    private static function handleEnqueueReconcile(): void
    {
        try {
            $mode = self::resolveSyncJobMode();
            $scope = self::resolveSyncFixScopeFromPost($mode);
            $targetRoot = self::resolveSyncTargetRootdomain(false);
            $targetUserId = self::resolveSyncTargetUserId();

            $payload = [
                'mode' => $mode,
                'fix_ttl' => $scope['ttl'] ? 1 : 0,
                'fix_missing' => $scope['missing'] ? 1 : 0,
                'fix_extra' => $scope['extra'] ? 1 : 0,
            ];
            if ($targetRoot !== '') {
                $payload['rootdomain'] = $targetRoot;
            }
            if ($targetUserId > 0) {
                $payload['userid'] = $targetUserId;
            }

            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'reconcile_all',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::triggerQueueInBackground();
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_enqueue_reconcile', $payload);
            }
            self::flashSuccess('对账任务已入队（' . ($mode === 'fix' ? 'fix' : 'dry') . '）');
        } catch (Exception $e) {
            self::flashError('入队失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function resolveSyncJobMode(): string
    {
        return (($_POST['mode'] ?? 'dry') === 'fix') ? 'fix' : 'dry';
    }

    private static function resolveSyncFixScopeFromPost(string $mode): array
    {
        $scopeExplicit = self::requestFlag('sync_scope_present')
            || array_key_exists('fix_ttl', $_POST)
            || array_key_exists('fix_missing', $_POST)
            || array_key_exists('fix_extra', $_POST);

        if (!$scopeExplicit) {
            return [
                'ttl' => true,
                'missing' => true,
                'extra' => true,
            ];
        }

        $scope = [
            'ttl' => self::requestFlag('fix_ttl'),
            'missing' => self::requestFlag('fix_missing'),
            'extra' => self::requestFlag('fix_extra'),
        ];

        if ($mode === 'fix' && !$scope['ttl'] && !$scope['missing'] && !$scope['extra']) {
            throw new Exception('请至少勾选一个修复范围（TTL/缺失/多余）');
        }

        return $scope;
    }

    private static function resolveSyncTargetRootdomain(bool $required): string
    {
        $rootdomainRaw = trim((string) ($_POST['rootdomain'] ?? ''));
        $normalizedRoot = strtolower($rootdomainRaw);
        if ($normalizedRoot === '') {
            if ($required) {
                throw new Exception('请选择要校准的根域名');
            }
            return '';
        }

        if (!self::rootdomainExists($normalizedRoot)) {
            throw new Exception('未找到该根域名或尚未接入：' . htmlspecialchars($rootdomainRaw, ENT_QUOTES, 'UTF-8'));
        }

        return $normalizedRoot;
    }

    private static function rootdomainExists(string $normalizedRoot): bool
    {
        $exists = false;
        try {
            $exists = Capsule::table('mod_cloudflare_rootdomains')
                ->whereRaw('LOWER(domain) = ?', [$normalizedRoot])
                ->exists();
        } catch (Exception $e) {
            $exists = false;
        }
        if (!$exists && function_exists('cfmod_get_known_rootdomains')) {
            try {
                $known = array_map('strtolower', cfmod_get_known_rootdomains(self::moduleSettings()));
                $exists = in_array($normalizedRoot, $known, true);
            } catch (Exception $e) {
                $exists = false;
            }
        }
        return $exists;
    }

    private static function resolveSyncTargetUserId(): int
    {
        $userIdRaw = trim((string) ($_POST['userid'] ?? ''));
        if ($userIdRaw === '') {
            return 0;
        }
        if (!ctype_digit($userIdRaw)) {
            throw new Exception('用户ID格式不正确');
        }
        $userId = intval($userIdRaw);
        if ($userId <= 0) {
            return 0;
        }
        $exists = Capsule::table('tblclients')->where('id', $userId)->exists();
        if (!$exists) {
            throw new Exception('用户ID不存在：' . $userId);
        }
        return $userId;
    }

    private static function requestFlag(string $key): bool
    {
        $value = $_POST[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    private static function handleRunQueueOnce(): void
    {
        try {
            $ok = self::triggerQueueInBackground();
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_run_queue_once', ['mode' => 'background', 'success' => !empty($ok) ? 1 : 0]);
            }
            if (!$ok) {
                self::flashError('队列触发失败：请检查 CLI/exec 可用性，或查看系统日志中的 queue_trigger_background 记录。');
                self::redirect(self::HASH_JOBS);
            }
            self::flashSuccess('已触发后台执行队列（1 个作业）。请稍后刷新');
        } catch (Exception $e) {
            self::flashError('执行队列失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function handleEnqueueCustomJob(): void
    {
        try {
            $type = strtolower(trim((string)($_POST['job_type'] ?? '')));
            if ($type === '' || !preg_match('/^[a-z0-9_]{3,64}$/', $type)) {
                throw new \RuntimeException('任务类型不合法');
            }
            $priority = intval($_POST['job_priority'] ?? 10);
            $priority = max(1, min(99, $priority));
            $payloadRaw = trim((string)($_POST['job_payload_json'] ?? ''));
            $payload = [];
            if ($payloadRaw !== '') {
                $decoded = json_decode($payloadRaw, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Payload JSON 格式错误');
                }
                $payload = $decoded;
            }
            $now = date('Y-m-d H:i:s');
            $jobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                'type' => $type,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'priority' => $priority,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::triggerQueueInBackground(1);
            self::flashSuccess('已创建任务 #' . intval($jobId) . '（' . $type . '）');
        } catch (\Throwable $e) {
            self::flashError('创建任务失败：' . $e->getMessage());
        }
        self::redirect(self::HASH_JOBS);
    }

    private static function handleRunMigrations(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_domain_risk')) {
                Capsule::schema()->create('mod_cloudflare_domain_risk', function ($table) {
                    $table->increments('id');
                    $table->integer('subdomain_id')->unsigned();
                    $table->integer('risk_score')->default(0);
                    $table->string('risk_level', 16)->default('low');
                    $table->text('reasons_json')->nullable();
                    $table->dateTime('last_checked_at')->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                    $table->unique('subdomain_id');
                    $table->index(['risk_score', 'risk_level']);
                });
            }
            if (!Capsule::schema()->hasTable('mod_cloudflare_risk_events')) {
                Capsule::schema()->create('mod_cloudflare_risk_events', function ($table) {
                    $table->increments('id');
                    $table->integer('subdomain_id')->unsigned();
                    $table->string('source', 32);
                    $table->integer('score')->default(0);
                    $table->string('level', 16)->default('low');
                    $table->string('reason', 255)->nullable();
                    $table->text('details_json')->nullable();
                    $table->dateTime('created_at')->nullable();
                    $table->dateTime('updated_at')->nullable();
                    $table->index('subdomain_id');
                    $table->index('created_at');
                });
            }
            self::flashSuccess('迁移/修复完成');
        } catch (Exception $e) {
            self::flashError('迁移失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function handleSaveAdminAnnounce(): void
    {
        try {
            $enabled = isset($_POST['admin_announce_enabled']) && $_POST['admin_announce_enabled'] === '1' ? '1' : '0';
            $title = trim($_POST['admin_announce_title'] ?? '公告');
            $htmlInput = trim($_POST['admin_announce_html'] ?? '');
            $html = html_entity_decode($htmlInput, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::persistModuleSettings([
                'admin_announce_enabled' => $enabled,
                'admin_announce_title' => $title,
                'admin_announce_html' => $html,
            ]);
            self::flashSuccess('后台公告设置已保存');
        } catch (Exception $e) {
            self::flashError('保存公告失败：' . $e->getMessage());
        }

        self::redirect(self::HASH_ANNOUNCEMENTS);
    }

    private static function handleRuntimeSwitches(): void
    {
        $moduleSettings = self::moduleSettings();
        $presetProfile = strtolower(trim((string) ($_POST['runtime_preset_profile'] ?? '')));
        $applyPreset = (($_POST['runtime_preset_apply'] ?? '') === '1');
        $pause = (($_POST['pause_free_registration'] ?? '') === '1') ? '1' : '0';
        $disableNs = (($_POST['disable_ns_management'] ?? '') === '1') ? '1' : '0';
        $maintenance = (($_POST['maintenance_mode'] ?? '') === '1') ? '1' : '0';
        $maintenanceMsg = trim($_POST['maintenance_message'] ?? '');
        $disableDnsWrite = (($_POST['disable_dns_write'] ?? '') === '1') ? '1' : '0';
        $dnsConflictAutoRepairEnabled = (($_POST['dns_conflict_auto_repair_enabled'] ?? '') === '1') ? '1' : '0';
        $dnsRepairPostUpdateVerifyEnabled = (($_POST['dns_repair_post_update_verify_enabled'] ?? '') === '1') ? '1' : '0';
        $hideInviteFeature = (($_POST['hide_invite_feature'] ?? '') === '1') ? '1' : '0';
        $clientDeleteMode = strtolower(trim((string) ($_POST['client_domain_delete_mode'] ?? '')));
        if (!in_array($clientDeleteMode, ['disabled', 'allow_all', 'never_had_dns_only', 'no_current_dns_only'], true)) {
            $clientDeleteMode = (($_POST['enable_client_domain_delete'] ?? '') === '1') ? 'never_had_dns_only' : 'disabled';
        }
        $enableClientDelete = $clientDeleteMode === 'disabled' ? '0' : '1';
        $clientDeleteGrayEnabled = (($_POST['client_domain_delete_gray_enabled'] ?? '') === '1');
        $clientDeleteGrayRatio = max(0, min(100, intval($_POST['client_domain_delete_gray_ratio'] ?? ($moduleSettings['client_domain_delete_gray_ratio'] ?? 0))));
        $domainPermanentUpgradeRealtimeFeed = (($_POST['domain_permanent_upgrade_enable_realtime_feed'] ?? '') === '1');
        $privilegedAllowRegisterSuspendedRoot = (($_POST['privileged_allow_register_suspended_root'] ?? '') === '1');
        $privilegedUnlimitedInviteGeneration = (($_POST['privileged_unlimited_invite_generation'] ?? '') === '1');
        $privilegedForceNeverExpire = (($_POST['privileged_force_never_expire'] ?? '') === '1');
        $privilegedAllowDeleteWithDnsHistory = (($_POST['privileged_allow_delete_with_dns_history'] ?? '') === '1');
        $syncInviteLimitUpOnly = (($_POST['sync_invite_limit_up_only'] ?? '') === '1');
        $grayWhitelistUserIdsRaw = trim((string) ($_POST['gray_whitelist_userids'] ?? ($moduleSettings['gray_whitelist_userids'] ?? '')));
        $grayWhitelistEmailsRaw = trim((string) ($_POST['gray_whitelist_emails'] ?? ($moduleSettings['gray_whitelist_emails'] ?? '')));
        $grayPaidPriorityEnabled = (($_POST['gray_paid_priority_enabled'] ?? '') === '1');
        $grayPaidPriorityWindowDays = max(1, min(3650, intval($_POST['gray_paid_priority_window_days'] ?? ($moduleSettings['gray_paid_priority_window_days'] ?? 90))));
        $clientOrphanCleanupEnabled = (($_POST['client_orphan_dns_cleanup_enabled'] ?? '') === '1');
        $clientOrphanCleanupMode = strtolower(trim((string) ($_POST['client_orphan_dns_cleanup_mode'] ?? ($moduleSettings['client_orphan_dns_cleanup_mode'] ?? 'queue'))));
        if (!in_array($clientOrphanCleanupMode, ['queue', 'sync'], true)) {
            $clientOrphanCleanupMode = 'queue';
        }
        $clientFeatureCardsOrder = trim((string) ($_POST['client_feature_cards_order'] ?? ($moduleSettings['client_feature_cards_order'] ?? '')));
        $clientFeatureCardsHidden = trim((string) ($_POST['client_feature_cards_hidden'] ?? ($moduleSettings['client_feature_cards_hidden'] ?? '')));
        $clientFeatureCardsNewBadge = trim((string) ($_POST['client_feature_cards_new_badge'] ?? ($moduleSettings['client_feature_cards_new_badge'] ?? '')));
        $clientPageSizeInput = intval($_POST['client_page_size'] ?? ($moduleSettings['client_page_size'] ?? 20));
        $clientPageSize = max(1, min(20, $clientPageSizeInput));
        $cleanupIntervalInput = intval($_POST['domain_cleanup_interval_minutes'] ?? ($moduleSettings['domain_cleanup_interval_minutes'] ?? 1440));
        $cleanupIntervalMinutes = max(5, min(9999, $cleanupIntervalInput));
        $rateLimitRegister = max(0, intval($_POST['rate_limit_register_per_hour'] ?? ($moduleSettings['rate_limit_register_per_hour'] ?? 30)));
        $rateLimitDns = max(0, intval($_POST['rate_limit_dns_per_hour'] ?? ($moduleSettings['rate_limit_dns_per_hour'] ?? 120)));
        $rateLimitApiKey = max(0, intval($_POST['rate_limit_api_key_per_hour'] ?? ($moduleSettings['rate_limit_api_key_per_hour'] ?? 20)));
        $rateLimitQuota = max(0, intval($_POST['rate_limit_quota_gift_per_hour'] ?? ($moduleSettings['rate_limit_quota_gift_per_hour'] ?? 20)));
        $rateLimitAjax = max(0, intval($_POST['rate_limit_ajax_per_hour'] ?? ($moduleSettings['rate_limit_ajax_per_hour'] ?? 60)));
        $rateLimitDnsUnlock = max(0, intval($_POST['rate_limit_dns_unlock_per_hour'] ?? ($moduleSettings['rate_limit_dns_unlock_per_hour'] ?? 10)));
        $rateLimitPermIncentive = max(0, intval($_POST['rate_limit_perm_incentive_per_hour'] ?? ($moduleSettings['rate_limit_perm_incentive_per_hour'] ?? 10)));
        $riskScanBatchInput = intval($_POST['risk_scan_batch_size'] ?? ($moduleSettings['risk_scan_batch_size'] ?? 50));
        $riskScanBatchSize = max(10, min(1000, $riskScanBatchInput));
        $queueMaxWorkers = max(1, min(16, intval($moduleSettings['queue_max_workers'] ?? 1)));
        $cleanupMainConcurrency = max(1, min(10, intval($moduleSettings['cleanup_expired_main_concurrency'] ?? 1)));
        $cleanupRemoteConcurrency = max(1, min(20, intval($moduleSettings['cleanup_expired_remote_concurrency'] ?? 2)));
        $cleanupBatchSize = max(10, min(5000, intval($moduleSettings['domain_cleanup_batch_size'] ?? 50)));
        $cleanupRemoteHardTimeout = max(5, min(120, intval($moduleSettings['cleanup_remote_hard_timeout_seconds'] ?? 20)));
        $cleanupRemoteEnqueueLimit = max(1, min(9999, intval($moduleSettings['cleanup_remote_enqueue_limit_per_run'] ?? 20)));
        $apiLogsRetentionDays = max(1, min(3650, intval($moduleSettings['api_logs_retention_days'] ?? 30)));
        $generalLogsRetentionDays = max(1, min(3650, intval($moduleSettings['general_logs_retention_days'] ?? 90)));
        $syncLogsRetentionDays = max(1, min(3650, intval($moduleSettings['sync_logs_retention_days'] ?? 30)));
        $enableDnsUnlockFeature = (($_POST['enable_dns_unlock'] ?? '') === '1');
        $dnsUnlockShareEnabledSetting = (($_POST['dns_unlock_share_enabled'] ?? '') === '1');
        $dnsUnlockPurchaseEnabledSetting = (($_POST['dns_unlock_purchase_enabled'] ?? '') === '1');
        $dnsUnlockPurchasePriceInput = (float) ($_POST['dns_unlock_purchase_price'] ?? ($moduleSettings['dns_unlock_purchase_price'] ?? 0));
        $dnsUnlockPurchasePrice = round(max(0, $dnsUnlockPurchasePriceInput), 2);
        $clientSupportTicketUrl = trim((string) ($_POST['client_support_ticket_url'] ?? ($moduleSettings['client_support_ticket_url'] ?? 'submitticket.php')));
        $clientSupportGroupUrl = trim((string) ($_POST['client_support_group_url'] ?? ($moduleSettings['client_support_group_url'] ?? 'https://t.me/+l9I5TNRDLP5lZDBh')));
        if ($clientSupportTicketUrl === '') {
            $clientSupportTicketUrl = 'submitticket.php';
        }
        if ($clientSupportGroupUrl === '') {
            $clientSupportGroupUrl = 'https://t.me/+l9I5TNRDLP5lZDBh';
        }
        if (preg_match('/^\s*javascript:/i', $clientSupportTicketUrl)) {
            $clientSupportTicketUrl = 'submitticket.php';
        }
        if (preg_match('/^\s*javascript:/i', $clientSupportGroupUrl)) {
            $clientSupportGroupUrl = 'https://t.me/+l9I5TNRDLP5lZDBh';
        }

        $helpAiSearchEnabled = (($_POST['enable_help_ai_search'] ?? '') === '1');
        $helpAiFabEnabled = (($_POST['help_ai_fab_enabled'] ?? '') === '1');
        $helpAiProvider = 'qwen';
        $helpAiAssistantName = trim((string) ($_POST['help_ai_assistant_name'] ?? ($moduleSettings['help_ai_assistant_name'] ?? 'AI 助手')));
        if ($helpAiAssistantName === '') {
            $helpAiAssistantName = 'AI 助手';
        }
        $helpAiSystemPrompt = trim((string) ($_POST['help_ai_system_prompt'] ?? ($moduleSettings['help_ai_system_prompt'] ?? '')));
        $helpAiKbSource = strtolower(trim((string) ($_POST['help_ai_kb_source'] ?? ($moduleSettings['help_ai_kb_source'] ?? 'mixed'))));
        if (!in_array($helpAiKbSource, ['db','static','mixed'], true)) { $helpAiKbSource = 'mixed'; }
        $helpAiIncludeModuleHelp = (($_POST['help_ai_include_module_help'] ?? '') === '1');
        $helpAiKbRefreshMinutes = max(1, min(1440, intval($_POST['help_ai_kb_refresh_minutes'] ?? ($moduleSettings['help_ai_kb_refresh_minutes'] ?? 30))));
        $helpAiMaxInputCharsInput = intval($_POST['help_ai_max_input_chars'] ?? ($moduleSettings['help_ai_max_input_chars'] ?? 600));
        $helpAiMaxInputChars = max(200, min(2000, $helpAiMaxInputCharsInput));
        $jobWarnSeconds = max(5, min(3600, intval($_POST['job_warn_seconds'] ?? ($moduleSettings['job_warn_seconds'] ?? 45))));
        $jobFailRetryBackoffRaw = trim((string) ($_POST['job_fail_retry_backoff'] ?? ($moduleSettings['job_fail_retry_backoff'] ?? '1,2,4,8,16')));
        if ($jobFailRetryBackoffRaw === '') {
            $jobFailRetryBackoffRaw = '1,2,4,8,16';
        }
        $maxJobsPerMinute = max(0, min(10000, intval($_POST['max_jobs_per_minute'] ?? ($moduleSettings['max_jobs_per_minute'] ?? 0))));
        $helpAiQwenModel = trim((string) ($_POST['help_ai_qwen_model'] ?? ($moduleSettings['help_ai_qwen_model'] ?? 'qwen3.6-flash')));
        if ($helpAiQwenModel === '') { $helpAiQwenModel = 'qwen3.6-flash'; }
        $helpAiQwenFallbackModel = trim((string) ($_POST['help_ai_qwen_fallback_model'] ?? ($moduleSettings['help_ai_qwen_fallback_model'] ?? 'qwen3.5-flash')));
        if ($helpAiQwenFallbackModel === '') { $helpAiQwenFallbackModel = 'qwen3.5-flash'; }
        $postedHelpAiQwenApiKey = trim((string) ($_POST['help_ai_qwen_api_key'] ?? ''));
        if (self::isMaskedSensitivePlaceholder($postedHelpAiQwenApiKey)) { $postedHelpAiQwenApiKey = ''; }
        $existingHelpAiQwenApiKey = trim((string) ($moduleSettings['help_ai_qwen_api_key'] ?? ''));
        if (self::isStoredMaskedSensitiveValue($existingHelpAiQwenApiKey)) { $existingHelpAiQwenApiKey = ''; }
        $helpAiQwenApiKey = $postedHelpAiQwenApiKey !== '' ? $postedHelpAiQwenApiKey : $existingHelpAiQwenApiKey;
        if ($helpAiQwenApiKey !== '') {
            try {
                if (class_exists('CfAiHelpSearchService')) {
                    CfAiHelpSearchService::testQwenConnectivity($helpAiQwenApiKey, $helpAiQwenModel);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('Qwen 连通性校验失败：' . $e->getMessage());
            }
        }

        $enableGithubStarReward = (($_POST['enable_github_star_reward'] ?? '') === '1');
        $githubStarRepoUrl = trim((string) ($_POST['github_star_repo_url'] ?? ($moduleSettings['github_star_repo_url'] ?? '')));
        if (class_exists('CfGithubStarRewardService')) {
            $githubStarRepoUrl = CfGithubStarRewardService::normalizeRepoUrl($githubStarRepoUrl);
        }
        $githubStarRewardAmountInput = intval($_POST['github_star_reward_amount'] ?? ($moduleSettings['github_star_reward_amount'] ?? 1));
        $githubStarRewardAmount = max(1, min(1000, $githubStarRewardAmountInput));

        $enableTelegramGroupReward = (($_POST['enable_telegram_group_reward'] ?? '') === '1');
        $telegramGroupLink = trim((string) ($_POST['telegram_group_link'] ?? ($moduleSettings['telegram_group_link'] ?? '')));
        $telegramGroupChatId = trim((string) ($_POST['telegram_group_chat_id'] ?? ($moduleSettings['telegram_group_chat_id'] ?? '')));
        $telegramGroupBotUsername = trim((string) ($_POST['telegram_group_bot_username'] ?? ($moduleSettings['telegram_group_bot_username'] ?? '')));
        if ($telegramGroupBotUsername !== '' && strpos($telegramGroupBotUsername, '@') === 0) {
            $telegramGroupBotUsername = ltrim($telegramGroupBotUsername, '@');
        }
        $postedTelegramBotToken = trim((string) ($_POST['telegram_group_bot_token'] ?? ''));
        if (self::isMaskedSensitivePlaceholder($postedTelegramBotToken)) {
            $postedTelegramBotToken = '';
        }
        $existingTelegramBotToken = trim((string) ($moduleSettings['telegram_group_bot_token'] ?? ''));
        if (self::isStoredMaskedSensitiveValue($existingTelegramBotToken)) {
            $existingTelegramBotToken = '';
        }
        $telegramGroupBotToken = $postedTelegramBotToken !== '' ? $postedTelegramBotToken : $existingTelegramBotToken;
        $telegramGroupRewardAmountInput = intval($_POST['telegram_group_reward_amount'] ?? ($moduleSettings['telegram_group_reward_amount'] ?? 1));
        $telegramGroupRewardAmount = max(1, min(1000, $telegramGroupRewardAmountInput));
        $telegramAuthMaxAgeInput = intval($_POST['telegram_reward_auth_max_age_seconds'] ?? ($moduleSettings['telegram_reward_auth_max_age_seconds'] ?? 86400));
        $telegramAuthMaxAge = max(60, min(604800, $telegramAuthMaxAgeInput));
        if (preg_match('/^\s*javascript:/i', $telegramGroupLink)) {
            $telegramGroupLink = '';
        }

        $allowedInviteGateModes = ['disabled', 'invite_only', 'github_only', 'telegram_only', 'invite_or_github', 'invite_or_telegram', 'github_or_telegram', 'invite_or_github_or_telegram'];
        $inviteRegistrationGateMode = trim((string) ($_POST['invite_registration_gate_mode'] ?? ($moduleSettings['invite_registration_gate_mode'] ?? 'disabled')));
        if (!in_array($inviteRegistrationGateMode, $allowedInviteGateModes, true)) {
            $inviteRegistrationGateMode = 'disabled';
        }
        $inviteTelegramBotUsername = trim((string) ($_POST['invite_registration_telegram_bot_username'] ?? ($moduleSettings['invite_registration_telegram_bot_username'] ?? '')));
        if ($inviteTelegramBotUsername !== '' && strpos($inviteTelegramBotUsername, '@') === 0) {
            $inviteTelegramBotUsername = ltrim($inviteTelegramBotUsername, '@');
        }
        $postedInviteTelegramBotToken = trim((string) ($_POST['invite_registration_telegram_bot_token'] ?? ''));
        if (self::isMaskedSensitivePlaceholder($postedInviteTelegramBotToken)) {
            $postedInviteTelegramBotToken = '';
        }
        $existingInviteTelegramBotToken = trim((string) ($moduleSettings['invite_registration_telegram_bot_token'] ?? ''));
        if (self::isStoredMaskedSensitiveValue($existingInviteTelegramBotToken)) {
            $existingInviteTelegramBotToken = '';
        }
        $inviteTelegramBotToken = $postedInviteTelegramBotToken !== '' ? $postedInviteTelegramBotToken : $existingInviteTelegramBotToken;
        $inviteTelegramAuthMaxAgeInput = intval($_POST['invite_registration_telegram_auth_max_age_seconds'] ?? ($moduleSettings['invite_registration_telegram_auth_max_age_seconds'] ?? ($moduleSettings['telegram_reward_auth_max_age_seconds'] ?? 86400)));
        $inviteTelegramAuthMaxAge = max(60, min(604800, $inviteTelegramAuthMaxAgeInput));
        $inviteBalanceUnlockGrayEnabled = (($_POST['invite_registration_balance_unlock_gray_enabled'] ?? '') === '1');
        $inviteBalanceUnlockGrayRatio = max(0, min(100, intval($_POST['invite_registration_balance_unlock_gray_ratio'] ?? ($moduleSettings['invite_registration_balance_unlock_gray_ratio'] ?? 100))));

        $currentInviteGateMode = trim((string) ($moduleSettings['invite_registration_gate_mode'] ?? 'disabled'));
        if (!in_array($currentInviteGateMode, $allowedInviteGateModes, true)) {
            $currentInviteGateMode = 'disabled';
        }
        $inviteGateEnabledBefore = $currentInviteGateMode !== 'disabled';
        $inviteGateEnabledAfter = $inviteRegistrationGateMode !== 'disabled';
        $shouldEnqueueUnlockAllForExistingUsers = !$inviteGateEnabledBefore && $inviteGateEnabledAfter;
        $inviteGateEnabledAtSetting = trim((string) ($moduleSettings['invite_registration_gate_enabled_at'] ?? ''));
        $inviteGateCutoffUserIdSetting = max(0, intval($moduleSettings['invite_registration_gate_cutoff_userid'] ?? 0));
        if ($shouldEnqueueUnlockAllForExistingUsers) {
            $inviteGateEnabledAtSetting = date('Y-m-d H:i:s');
            try {
                $inviteGateCutoffUserIdSetting = max(0, intval(Capsule::table('tblclients')->max('id') ?? 0));
            } catch (\Throwable $e) {
                $inviteGateCutoffUserIdSetting = max(0, $inviteGateCutoffUserIdSetting);
            }
        }

        if ($applyPreset && in_array($presetProfile, ['dev', 'small', 'medium', 'large'], true)) {
            $presets = [
                'dev' => ['workers' => 1, 'main' => 1, 'remote' => 1, 'batch' => 20, 'timeout' => 12, 'enqueue' => 10, 'dns' => 300, 'ajax' => 300, 'interval' => 10, 'risk' => 20, 'api_ret' => 7, 'log_ret' => 14, 'sync_ret' => 7],
                'small' => ['workers' => 1, 'main' => 1, 'remote' => 2, 'batch' => 50, 'timeout' => 20, 'enqueue' => 20, 'dns' => 120, 'ajax' => 80, 'interval' => 30, 'risk' => 50, 'api_ret' => 30, 'log_ret' => 90, 'sync_ret' => 30],
                'medium' => ['workers' => 2, 'main' => 2, 'remote' => 4, 'batch' => 120, 'timeout' => 25, 'enqueue' => 80, 'dns' => 180, 'ajax' => 100, 'interval' => 20, 'risk' => 120, 'api_ret' => 60, 'log_ret' => 120, 'sync_ret' => 60],
                'large' => ['workers' => 4, 'main' => 4, 'remote' => 8, 'batch' => 300, 'timeout' => 30, 'enqueue' => 300, 'dns' => 240, 'ajax' => 150, 'interval' => 10, 'risk' => 300, 'api_ret' => 90, 'log_ret' => 180, 'sync_ret' => 90],
            ];
            $p = $presets[$presetProfile];
            $queueMaxWorkers = $p['workers'];
            $cleanupMainConcurrency = $p['main'];
            $cleanupRemoteConcurrency = $p['remote'];
            $cleanupBatchSize = $p['batch'];
            $cleanupRemoteHardTimeout = $p['timeout'];
            $cleanupRemoteEnqueueLimit = $p['enqueue'];
            $rateLimitDns = $p['dns'];
            $rateLimitAjax = $p['ajax'];
            $cleanupIntervalMinutes = $p['interval'];
            $riskScanBatchSize = $p['risk'];
            $apiLogsRetentionDays = $p['api_ret'];
            $generalLogsRetentionDays = $p['log_ret'];
            $syncLogsRetentionDays = $p['sync_ret'];
        }

        try {
            self::persistModuleSettings([
                'pause_free_registration' => $pause,
                'disable_ns_management' => $disableNs,
                'maintenance_mode' => $maintenance,
                'maintenance_message' => $maintenanceMsg,
                'disable_dns_write' => $disableDnsWrite,
                'dns_conflict_auto_repair_enabled' => $dnsConflictAutoRepairEnabled,
                'dns_repair_post_update_verify_enabled' => $dnsRepairPostUpdateVerifyEnabled,
                'hide_invite_feature' => $hideInviteFeature,
                'enable_client_domain_delete' => $enableClientDelete,
                'client_domain_delete_mode' => $clientDeleteMode,
                'client_domain_delete_gray_enabled' => $clientDeleteGrayEnabled ? '1' : '0',
                'client_domain_delete_gray_ratio' => (string) $clientDeleteGrayRatio,
                'domain_permanent_upgrade_enable_realtime_feed' => $domainPermanentUpgradeRealtimeFeed ? '1' : '0',
                'privileged_allow_register_suspended_root' => $privilegedAllowRegisterSuspendedRoot ? '1' : '0',
                'privileged_unlimited_invite_generation' => $privilegedUnlimitedInviteGeneration ? '1' : '0',
                'privileged_force_never_expire' => $privilegedForceNeverExpire ? '1' : '0',
                'privileged_allow_delete_with_dns_history' => $privilegedAllowDeleteWithDnsHistory ? '1' : '0',
                'sync_invite_limit_up_only' => $syncInviteLimitUpOnly ? '1' : '0',
                'gray_whitelist_userids' => $grayWhitelistUserIdsRaw,
                'gray_whitelist_emails' => $grayWhitelistEmailsRaw,
                'gray_paid_priority_enabled' => $grayPaidPriorityEnabled ? '1' : '0',
                'gray_paid_priority_window_days' => (string) $grayPaidPriorityWindowDays,
                'client_orphan_dns_cleanup_enabled' => $clientOrphanCleanupEnabled ? '1' : '0',
                'client_orphan_dns_cleanup_mode' => $clientOrphanCleanupMode,
                'client_feature_cards_order' => $clientFeatureCardsOrder,
                'client_feature_cards_hidden' => $clientFeatureCardsHidden,
                'client_feature_cards_new_badge' => $clientFeatureCardsNewBadge,
                'client_page_size' => (string) $clientPageSize,
                'enable_dns_unlock' => $enableDnsUnlockFeature ? '1' : '0',
                'dns_unlock_share_enabled' => $dnsUnlockShareEnabledSetting ? '1' : '0',
                'dns_unlock_purchase_enabled' => $dnsUnlockPurchaseEnabledSetting ? '1' : '0',
                'dns_unlock_purchase_price' => number_format($dnsUnlockPurchasePrice, 2, '.', ''),
                'client_support_ticket_url' => $clientSupportTicketUrl,
                'client_support_group_url' => $clientSupportGroupUrl,
                'enable_help_ai_search' => $helpAiSearchEnabled ? '1' : '0',
                'help_ai_fab_enabled' => $helpAiFabEnabled ? '1' : '0',
                'help_ai_provider' => $helpAiProvider,
                'help_ai_assistant_name' => $helpAiAssistantName,
                'help_ai_system_prompt' => $helpAiSystemPrompt,
                'help_ai_kb_source' => $helpAiKbSource,
                'help_ai_include_module_help' => $helpAiIncludeModuleHelp ? '1' : '0',
                'help_ai_kb_refresh_minutes' => (string) $helpAiKbRefreshMinutes,
                'help_ai_max_input_chars' => (string) $helpAiMaxInputChars,
                'job_warn_seconds' => (string) $jobWarnSeconds,
                'job_fail_retry_backoff' => $jobFailRetryBackoffRaw,
                'max_jobs_per_minute' => (string) $maxJobsPerMinute,
                'help_ai_qwen_api_key' => $helpAiQwenApiKey,
                'help_ai_qwen_model' => $helpAiQwenModel,
                'help_ai_qwen_fallback_model' => $helpAiQwenFallbackModel,
                'enable_github_star_reward' => $enableGithubStarReward ? '1' : '0',
                'github_star_repo_url' => $githubStarRepoUrl,
                'github_star_reward_amount' => (string) $githubStarRewardAmount,
                'enable_telegram_group_reward' => $enableTelegramGroupReward ? '1' : '0',
                'telegram_group_link' => $telegramGroupLink,
                'telegram_group_chat_id' => $telegramGroupChatId,
                'telegram_group_bot_username' => $telegramGroupBotUsername,
                'telegram_group_bot_token' => $telegramGroupBotToken,
                'telegram_group_reward_amount' => (string) $telegramGroupRewardAmount,
                'telegram_reward_auth_max_age_seconds' => (string) $telegramAuthMaxAge,
                'invite_registration_gate_mode' => $inviteRegistrationGateMode,
                'invite_registration_gate_enabled_at' => $inviteGateEnabledAtSetting,
                'invite_registration_gate_cutoff_userid' => (string) $inviteGateCutoffUserIdSetting,
                'invite_registration_telegram_bot_username' => $inviteTelegramBotUsername,
                'invite_registration_telegram_bot_token' => $inviteTelegramBotToken,
                'invite_registration_telegram_auth_max_age_seconds' => (string) $inviteTelegramAuthMaxAge,
                'invite_registration_balance_unlock_gray_enabled' => $inviteBalanceUnlockGrayEnabled ? '1' : '0',
                'invite_registration_balance_unlock_gray_ratio' => (string) $inviteBalanceUnlockGrayRatio,
                'risk_scan_batch_size' => (string) $riskScanBatchSize,
                'queue_max_workers' => (string) $queueMaxWorkers,
                'cleanup_expired_main_concurrency' => (string) $cleanupMainConcurrency,
                'cleanup_expired_remote_concurrency' => (string) $cleanupRemoteConcurrency,
                'domain_cleanup_batch_size' => (string) $cleanupBatchSize,
                'cleanup_remote_hard_timeout_seconds' => (string) $cleanupRemoteHardTimeout,
                'cleanup_remote_enqueue_limit_per_run' => (string) $cleanupRemoteEnqueueLimit,
                'api_logs_retention_days' => (string) $apiLogsRetentionDays,
                'general_logs_retention_days' => (string) $generalLogsRetentionDays,
                'sync_logs_retention_days' => (string) $syncLogsRetentionDays,
                'rate_limit_register_per_hour' => (string) $rateLimitRegister,
                'rate_limit_dns_per_hour' => (string) $rateLimitDns,
                'rate_limit_api_key_per_hour' => (string) $rateLimitApiKey,
                'rate_limit_quota_gift_per_hour' => (string) $rateLimitQuota,
                'rate_limit_ajax_per_hour' => (string) $rateLimitAjax,
                'rate_limit_dns_unlock_per_hour' => (string) $rateLimitDnsUnlock,
                'rate_limit_perm_incentive_per_hour' => (string) $rateLimitPermIncentive,
                'domain_cleanup_interval_minutes' => (string) $cleanupIntervalMinutes,
                'domain_cleanup_interval_hours' => (string) max(1, intval(ceil($cleanupIntervalMinutes / 60))),
            ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_save_runtime_switches', [
                    'pause_free_registration' => $pause,
                    'disable_ns_management' => $disableNs,
                    'maintenance_mode' => $maintenance,
                    'disable_dns_write' => $disableDnsWrite,
                    'hide_invite_feature' => $hideInviteFeature,
                    'enable_client_domain_delete' => $enableClientDelete,
                    'client_domain_delete_mode' => $clientDeleteMode,
                    'domain_permanent_upgrade_enable_realtime_feed' => $domainPermanentUpgradeRealtimeFeed ? 1 : 0,
                    'privileged_allow_register_suspended_root' => $privilegedAllowRegisterSuspendedRoot ? 1 : 0,
                    'privileged_unlimited_invite_generation' => $privilegedUnlimitedInviteGeneration ? 1 : 0,
                    'privileged_force_never_expire' => $privilegedForceNeverExpire ? 1 : 0,
                    'privileged_allow_delete_with_dns_history' => $privilegedAllowDeleteWithDnsHistory ? 1 : 0,
                    'client_orphan_dns_cleanup_enabled' => $clientOrphanCleanupEnabled ? 1 : 0,
                    'client_orphan_dns_cleanup_mode' => $clientOrphanCleanupMode,
                    'maintenance_message_length' => strlen($maintenanceMsg),
                    'client_page_size' => $clientPageSize,
                    'dns_unlock_share_enabled' => $dnsUnlockShareEnabledSetting ? 1 : 0,
                    'dns_unlock_purchase_enabled' => $dnsUnlockPurchaseEnabledSetting ? 1 : 0,
                    'dns_unlock_purchase_price' => $dnsUnlockPurchasePrice,
                    'client_support_ticket_url' => $clientSupportTicketUrl,
                    'client_support_group_url' => $clientSupportGroupUrl,
                    'enable_help_ai_search' => $helpAiSearchEnabled ? 1 : 0,
                    'help_ai_fab_enabled' => $helpAiFabEnabled ? 1 : 0,
                    'help_ai_provider' => $helpAiProvider,
                    'help_ai_assistant_name' => $helpAiAssistantName,
                    'help_ai_system_prompt_length' => strlen($helpAiSystemPrompt),
                    'help_ai_include_module_help' => $helpAiIncludeModuleHelp ? 1 : 0,
                    'help_ai_max_input_chars' => $helpAiMaxInputChars,
                    'job_warn_seconds' => $jobWarnSeconds,
                    'job_fail_retry_backoff' => $jobFailRetryBackoffRaw,
                    'max_jobs_per_minute' => $maxJobsPerMinute,
                    'help_ai_qwen_model' => $helpAiQwenModel,
                    'help_ai_qwen_fallback_model' => $helpAiQwenFallbackModel,
                    'help_ai_qwen_api_key_set' => $helpAiQwenApiKey !== '' ? 1 : 0,
                    'enable_github_star_reward' => $enableGithubStarReward ? 1 : 0,
                    'github_star_repo_url' => $githubStarRepoUrl,
                    'github_star_reward_amount' => $githubStarRewardAmount,
                    'enable_telegram_group_reward' => $enableTelegramGroupReward ? 1 : 0,
                    'telegram_group_link' => $telegramGroupLink,
                    'telegram_group_chat_id' => $telegramGroupChatId,
                    'telegram_group_bot_username' => $telegramGroupBotUsername,
                    'telegram_group_bot_token_set' => $telegramGroupBotToken !== '' ? 1 : 0,
                    'telegram_group_reward_amount' => $telegramGroupRewardAmount,
                    'telegram_reward_auth_max_age_seconds' => $telegramAuthMaxAge,
                    'invite_registration_gate_mode' => $inviteRegistrationGateMode,
                    'invite_registration_gate_enabled_before' => $inviteGateEnabledBefore ? 1 : 0,
                    'invite_registration_gate_enabled_after' => $inviteGateEnabledAfter ? 1 : 0,
                    'invite_registration_gate_enabled_at' => $inviteGateEnabledAtSetting,
                    'invite_registration_gate_cutoff_userid' => $inviteGateCutoffUserIdSetting,
                    'invite_registration_telegram_bot_username' => $inviteTelegramBotUsername,
                    'invite_registration_telegram_bot_token_set' => $inviteTelegramBotToken !== '' ? 1 : 0,
                    'invite_registration_telegram_auth_max_age_seconds' => $inviteTelegramAuthMaxAge,
                    'rate_limit_register_per_hour' => $rateLimitRegister,
                    'rate_limit_dns_per_hour' => $rateLimitDns,
                    'rate_limit_api_key_per_hour' => $rateLimitApiKey,
                    'rate_limit_quota_gift_per_hour' => $rateLimitQuota,
                    'rate_limit_ajax_per_hour' => $rateLimitAjax,
                    'rate_limit_dns_unlock_per_hour' => $rateLimitDnsUnlock,
                    'rate_limit_perm_incentive_per_hour' => $rateLimitPermIncentive,
                    'domain_cleanup_interval_minutes' => $cleanupIntervalMinutes,
                ]);
            }
            if ($shouldEnqueueUnlockAllForExistingUsers) {
                try {
                    $enqueueResult = self::enqueueUnlockAllInviteRegistrationUsersJob(500);
                    if (!empty($enqueueResult['already_exists'])) {
                        self::flashSuccess('运行控制设置已保存；检测到准入模式由关闭改为开启，历史用户全量解锁任务已在队列中。');
                    } elseif (!empty($enqueueResult['queued'])) {
                        self::flashSuccess('运行控制设置已保存；检测到准入模式由关闭改为开启，已自动提交历史用户全量解锁任务。');
                    } else {
                        self::flashSuccess('运行控制设置已保存；准入模式已开启，但自动提交历史用户全量解锁任务失败，请在“邀请注册日志”手动执行“一键全量解锁”。');
                    }
                } catch (\Throwable $e) {
                    self::flashSuccess('运行控制设置已保存；准入模式已开启，但自动提交历史用户全量解锁任务失败，请在“邀请注册日志”手动执行“一键全量解锁”。');
                }
            }
            if ($syncInviteLimitUpOnly) {
                try {
                    $global = intval(Capsule::table('tbladdonmodules')
                        ->where('module', 'domain_hub')
                        ->where('setting', 'invite_bonus_limit_global')
                        ->value('value') ?? 5);
                    if ($global > 0) {
                        $candidates = Capsule::table('mod_cloudflare_subdomain_quotas')
                            ->whereIn('invite_bonus_limit', [0, 5])
                            ->get();
                        foreach ($candidates as $candidate) {
                            $limit = intval($candidate->invite_bonus_limit ?? 0);
                            if ($limit < $global) {
                                Capsule::table('mod_cloudflare_subdomain_quotas')
                                    ->where('userid', $candidate->userid)
                                    ->update(['invite_bonus_limit' => $global]);
                            }
                        }
                    }
                } catch (Exception $e) {
                    // ignore sync errors
                }
            }
            if (!$shouldEnqueueUnlockAllForExistingUsers) {
                self::flashSuccess('运行控制设置已保存');
            }
        } catch (Exception $e) {
            self::flashError('保存失败：' . $e->getMessage());
        }

        self::redirect(self::HASH_RUNTIME);
    }

    private static function handleDomainGiftCancel(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_domain_gifts')) {
                throw new Exception('尚未启用域名转赠功能');
            }
            $giftId = intval($_POST['gift_id'] ?? 0);
            if ($giftId <= 0) {
                throw new Exception('缺少转赠记录');
            }
            $adminId = isset($_SESSION['adminid']) ? intval($_SESSION['adminid']) : null;
            $now = date('Y-m-d H:i:s');
            $giftInfo = Capsule::transaction(function () use ($giftId, $adminId, $now) {
                $gift = Capsule::table('mod_cloudflare_domain_gifts')
                    ->where('id', $giftId)
                    ->lockForUpdate()
                    ->first();
                if (!$gift) {
                    throw new Exception('转赠记录不存在');
                }
                if (($gift->status ?? '') !== 'pending') {
                    throw new Exception('仅可取消进行中的转赠');
                }
                Capsule::table('mod_cloudflare_domain_gifts')
                    ->where('id', $giftId)
                    ->update([
                        'status' => 'cancelled',
                        'cancelled_at' => $now,
                        'cancelled_by_admin' => $adminId,
                        'updated_at' => $now,
                    ]);
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $gift->subdomain_id)
                    ->where('gift_lock_id', $gift->id)
                    ->update([
                        'gift_lock_id' => null,
                        'updated_at' => $now,
                    ]);
                return $gift;
            });
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_cancel_domain_gift', [
                    'gift_id' => $giftId,
                    'full_domain' => $giftInfo->full_domain ?? '',
                    'from_userid' => $giftInfo->from_userid ?? null,
                ]);
            }
            $_SESSION['admin_api_success'] = '✅ 已取消该转赠记录并解除锁定';
        } catch (Exception $e) {
            $_SESSION['admin_api_error'] = '❌ 取消转赠失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }

        self::redirect(self::HASH_DOMAIN_GIFTS);
    }

    private static function handleDomainGiftUnlock(): void
    {
        try {
            $giftId = intval($_POST['gift_id'] ?? 0);
            $subdomainId = intval($_POST['subdomain_id'] ?? 0);
            if ($giftId <= 0 || $subdomainId <= 0) {
                throw new Exception('参数不完整');
            }
            $now = date('Y-m-d H:i:s');
            Capsule::transaction(function () use ($giftId, $subdomainId, $now) {
                $gift = Capsule::table('mod_cloudflare_domain_gifts')
                    ->where('id', $giftId)
                    ->lockForUpdate()
                    ->first();
                $subdomain = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomainId)
                    ->lockForUpdate()
                    ->first();
                if (!$subdomain) {
                    throw new Exception('未找到子域名');
                }
                if (intval($subdomain->gift_lock_id ?? 0) !== $giftId) {
                    throw new Exception('该域名未被该转赠记录锁定');
                }
                if ($gift && ($gift->status ?? '') === 'pending') {
                    throw new Exception('请先取消该转赠再解除锁定');
                }
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomainId)
                    ->update([
                        'gift_lock_id' => null,
                        'updated_at' => $now,
                    ]);
            });
            $_SESSION['admin_api_success'] = '✅ 已解除域名转赠锁定';
        } catch (Exception $e) {
            $_SESSION['admin_api_error'] = '❌ 解除锁定失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }

        self::redirect(self::HASH_DOMAIN_GIFTS);
    }
    private static function handleBanUser(): void
    {
        $userid = 0;
        $userEmail = trim($_POST['user_email'] ?? '');
        $banReason = trim($_POST['ban_reason'] ?? '');
        $banType = in_array(($_POST['ban_type'] ?? 'permanent'), ['permanent', 'temporary', 'weekly'], true)
            ? $_POST['ban_type']
            : 'permanent';
        $banDurationDays = intval($_POST['ban_days'] ?? 0);
        $banExpiresAt = null;
        if ($banType === 'temporary') {
            $banDurationDays = max(1, $banDurationDays ?: 7);
            $banExpiresAt = date('Y-m-d H:i:s', time() + $banDurationDays * 86400);
        } elseif ($banType === 'weekly') {
            $banExpiresAt = date('Y-m-d H:i:s', time() + 7 * 86400);
        }

        $lookupSource = 'email';
        if ($userEmail !== '') {
            // Check if input looks like an email or a subdomain
            if (filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                // Standard email lookup
                try {
                    $row = Capsule::table('tblclients')->where('email', $userEmail)->first();
                    if ($row) {
                        $userid = intval($row->id);
                    }
                } catch (Exception $e) {
                    // ignore lookup failures
                }
            } else {
                // Try to find user by subdomain
                $lookupSource = 'subdomain';
                $subdomainInput = strtolower(trim($userEmail));
                try {
                    // Try exact match first (full subdomain like "test.example.com")
                    $subRow = Capsule::table('mod_cloudflare_subdomain')
                        ->where('subdomain', $subdomainInput)
                        ->first();

                    // If not found, try matching as prefix (user might enter just "test" or "test.sub")
                    if (!$subRow) {
                        $subRow = Capsule::table('mod_cloudflare_subdomain')
                            ->where('subdomain', 'like', $subdomainInput . '.%')
                            ->first();
                    }

                    // Also try if input contains root domain (e.g., "test.example.com")
                    if (!$subRow && strpos($subdomainInput, '.') !== false) {
                        $subRow = Capsule::table('mod_cloudflare_subdomain')
                            ->whereRaw('LOWER(CONCAT(subdomain, ".", rootdomain)) = ?', [$subdomainInput])
                            ->first();
                    }

                    if ($subRow && !empty($subRow->userid)) {
                        $userid = intval($subRow->userid);
                        // Fetch user email for logging
                        $clientRow = Capsule::table('tblclients')->where('id', $userid)->first();
                        if ($clientRow) {
                            $userEmail = $clientRow->email ?? '';
                        }
                    }
                } catch (Exception $e) {
                    // ignore lookup failures
                }
            }
        }

        if (!$userid) {
            $errorMsg = $lookupSource === 'subdomain'
                ? '未找到该子域名对应的用户，请检查子域名是否正确'
                : '未找到指定用户';
            self::flashError($errorMsg);
            self::redirect(self::HASH_BANS);
        }

        try {
            $user = Capsule::table('tblclients')->where('id', $userid)->first();
            if (!$user) {
                throw new Exception('用户不存在');
            }

            Capsule::table('tblclients')->where('id', $userid)->update(['status' => 'Inactive']);

            $banTimestamp = date('Y-m-d H:i:s');
            self::ensureUserBansTable();
            $banInsert = [
                'userid' => $userid,
                'ban_reason' => $banReason,
                'banned_by' => 'admin',
                'banned_at' => $banTimestamp,
                'status' => 'banned',
            ];
            try {
                if (Capsule::schema()->hasColumn('mod_cloudflare_user_bans', 'ban_type')) {
                    $banInsert['ban_type'] = $banType;
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_user_bans', 'ban_expires_at')) {
                    $banInsert['ban_expires_at'] = $banExpiresAt;
                }
            } catch (Exception $e) {
                // ignore schema issues for optional fields
            }
            Capsule::table('mod_cloudflare_user_bans')->insert($banInsert);

            $deleteRecords = (($_POST['delete_user_records_on_ban'] ?? '') === '1');
            $deleteDomains = (($_POST['delete_user_domains_on_ban'] ?? '') === '1');
            $cleanupJobQueued = false;
            if ($deleteRecords || $deleteDomains) {
                $cleanupJobQueued = self::enqueueBanUserDeleteDnsLikeClientJob($userid, $deleteRecords, $deleteDomains);
            }

            try {
                $disabled = Capsule::table('mod_cloudflare_api_keys')
                    ->where('userid', $userid)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'disabled_by_ban',
                        'updated_at' => $banTimestamp,
                    ]);
                if ($disabled && function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_ban_user_disable_api', [
                        'userid' => $userid,
                        'disabled_keys' => $disabled,
                        'status' => 'disabled_by_ban',
                    ]);
                }
            } catch (Exception $e) {
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_ban_user_disable_api_error', [
                        'userid' => $userid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $enforceNow = (($_POST['enforce_dns_now'] ?? '') === '1');
            $moduleSettings = self::moduleSettings();
            $ip4 = trim($_POST['enforce_dns_ip4'] ?? ($moduleSettings['default_ip'] ?? ''));
            if ($enforceNow && $ip4 !== '') {
                try {
                    Capsule::table('mod_cloudflare_jobs')->insert([
                        'type' => 'enforce_ban_dns',
                        'payload_json' => json_encode(['userid' => $userid, 'ipv4' => $ip4], JSON_UNESCAPED_UNICODE),
                        'priority' => 5,
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_run_at' => null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    self::triggerQueueInBackground();
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('admin_ban_user_enforce_dns_enqueue', [
                            'userid' => $userid,
                            'ipv4' => $ip4,
                        ]);
                    }
                } catch (Exception $e) {
                    // ignore enqueue failure
                }
            }

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_ban_user', [
                    'userid' => $userid,
                    'reason' => $banReason,
                    'ban_type' => $banType,
                    'ban_expires_at' => $banExpiresAt,
                ]);
            }

            self::sendBanNotificationEmail($userid, $banTimestamp, $banReason);

            $statusMessage = $cleanupJobQueued
                ? '用户已封禁，已提交处置任务'
                : '用户已封禁';
            self::flashSuccess($statusMessage);
        } catch (Exception $e) {
            self::flashError('封禁用户失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_BANS);
    }

    private static function sendBanNotificationEmail(int $userId, string $banTimestamp, string $banReason): void
    {
        if ($userId <= 0 || !function_exists('localAPI')) {
            return;
        }

        try {
            $settings = self::moduleSettings();
            $enabledRaw = strtolower(trim((string) ($settings['ban_email_notify_enabled'] ?? '0')));
            $enabled = in_array($enabledRaw, ['1', 'on', 'yes', 'true', 'enabled'], true);
            if (!$enabled) {
                return;
            }

            $templateName = trim((string) ($settings['ban_email_template_name'] ?? ''));
            if ($templateName === '') {
                return;
            }

            $customVars = [
                'ban_time' => $banTimestamp,
                'ban_reason' => trim($banReason) !== '' ? trim($banReason) : '未填写封禁原因',
            ];

            $response = localAPI('SendEmail', [
                'messagename' => $templateName,
                'id' => $userId,
                'customvars' => base64_encode(serialize($customVars)),
            ]);

            if (!is_array($response) || ($response['result'] ?? '') !== 'success') {
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_ban_user_email_notify_failed', [
                        'userid' => $userId,
                        'template' => $templateName,
                        'response' => is_array($response) ? $response : ['response' => 'invalid'],
                    ]);
                }
                return;
            }

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_ban_user_email_notify_sent', [
                    'userid' => $userId,
                    'template' => $templateName,
                    'ban_time' => $banTimestamp,
                ]);
            }
        } catch (Throwable $e) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_ban_user_email_notify_error', [
                    'userid' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function enqueueBanUserDeleteDnsLikeClientJob(int $userid, bool $deleteRecords, bool $deleteDomains): bool
    {
        if ($userid <= 0 || (!$deleteRecords && !$deleteDomains)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $timeWindow = date('YmdHi');
        $dedupeKey = 'ban_user_delete_dns_like_client:' . $userid . ':' . $timeWindow . ':' . ($deleteRecords ? '1' : '0') . ':' . ($deleteDomains ? '1' : '0');
        $enqueued = false;
        try {
            Capsule::transaction(function () use ($userid, $deleteRecords, $deleteDomains, $dedupeKey, $now, &$enqueued) {
                $existing = Capsule::table('mod_cloudflare_jobs')
                    ->where('type', 'ban_user_delete_dns_like_client')
                    ->whereIn('status', ['pending', 'running'])
                    ->where('payload_json', 'like', '%"dedupe_key":"' . $dedupeKey . '"%')
                    ->exists();
                if ($existing) {
                    $enqueued = true;
                    return;
                }
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'ban_user_delete_dns_like_client',
                    'payload_json' => json_encode([
                        'userid' => $userid,
                        'delete_records' => $deleteRecords ? 1 : 0,
                        'delete_domains' => $deleteDomains ? 1 : 0,
                        'force_local_dns_cleanup' => 1,
                        'dedupe_key' => $dedupeKey,
                        'initiated_by' => 'admin',
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 8,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $enqueued = true;
            });

            if ($enqueued) {
                self::triggerQueueInBackground();
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_ban_user_cleanup', [
                        'userid' => $userid,
                        'delete_records' => $deleteRecords ? 1 : 0,
                        'delete_domains' => $deleteDomains ? 1 : 0,
                        'job_type' => 'ban_user_delete_dns_like_client',
                        'dedupe_key' => $dedupeKey,
                    ]);
                }
            }
            return $enqueued;
        } catch (Exception $e) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_ban_user_cleanup_error', [
                    'userid' => $userid,
                    'error' => $e->getMessage(),
                    'job_type' => 'ban_user_delete_dns_like_client',
                    'dedupe_key' => $dedupeKey,
                ]);
            }
            return false;
        }
    }

    private static function handleUnbanUser(): void
    {
        $userid = intval($_POST['userid'] ?? 0);
        if (!$userid && isset($_POST['user_email'])) {
            try {
                $row = Capsule::table('tblclients')->where('email', trim($_POST['user_email']))->first();
                if ($row) {
                    $userid = intval($row->id);
                }
            } catch (Exception $e) {
                // ignore lookup failure
            }
        }

        if (!$userid) {
            self::flashError('参数无效');
            self::redirect(self::HASH_BANS);
        }

        try {
            $now = date('Y-m-d H:i:s');
            self::ensureUserBansTable();

            $latestBanAt = null;
            try {
                $latestBanRecord = Capsule::table('mod_cloudflare_user_bans')
                    ->where('userid', $userid)
                    ->where('status', 'banned')
                    ->orderBy('id', 'desc')
                    ->first();
                if ($latestBanRecord && !empty($latestBanRecord->banned_at)) {
                    $latestBanAt = (string) $latestBanRecord->banned_at;
                }
            } catch (Exception $e) {
                $latestBanAt = null;
            }

            Capsule::table('tblclients')->where('id', $userid)->update(['status' => 'Active']);
            Capsule::table('mod_cloudflare_user_bans')
                ->where('userid', $userid)
                ->where('status', 'banned')
                ->update([
                    'status' => 'unbanned',
                    'unbanned_at' => $now,
                ]);

            $restoredByBanFlag = 0;
            $restoredLegacy = 0;
            try {
                $restoredByBanFlag = Capsule::table('mod_cloudflare_api_keys')
                    ->where('userid', $userid)
                    ->where('status', 'disabled_by_ban')
                    ->update([
                        'status' => 'active',
                        'updated_at' => $now,
                    ]);

                if (!empty($latestBanAt)) {
                    $restoredLegacy = Capsule::table('mod_cloudflare_api_keys')
                        ->where('userid', $userid)
                        ->where('status', 'disabled')
                        ->where('updated_at', $latestBanAt)
                        ->update([
                            'status' => 'active',
                            'updated_at' => $now,
                        ]);
                }
            } catch (Exception $e) {
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('admin_unban_user_restore_api_error', [
                        'userid' => $userid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_unban_user', [
                    'userid' => $userid,
                    'restored_api_keys' => intval($restoredByBanFlag) + intval($restoredLegacy),
                    'restored_by_ban_flag' => intval($restoredByBanFlag),
                    'restored_legacy' => intval($restoredLegacy),
                ]);
            }
            self::flashSuccess('用户已解封');
        } catch (Exception $e) {
            self::flashError('解封用户失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_BANS);
    }

    private static function handleEnforceBanDns(): void
    {
        try {
            $userid = intval($_POST['userid'] ?? 0);
            $moduleSettings = self::moduleSettings();
            $ip4 = trim($_POST['enforce_dns_ip4'] ?? ($moduleSettings['default_ip'] ?? ''));
            if ($userid <= 0 || $ip4 === '') {
                throw new Exception('参数无效（缺少用户或IP）');
            }
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'enforce_ban_dns',
                'payload_json' => json_encode(['userid' => $userid, 'ipv4' => $ip4], JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::triggerQueueInBackground();
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_enforce_ban_dns_enqueue', [
                    'userid' => $userid,
                    'ipv4' => $ip4,
                ]);
            }
            self::flashSuccess('已提交处置DNS作业');
        } catch (Exception $e) {
            self::flashError('提交失败：' . $e->getMessage());
        }

        self::redirect(self::HASH_BANS);
    }

    private static function handleSaveInviteCycleStart(): void
    {
        try {
            $value = trim($_POST['invite_cycle_start'] ?? '');
            if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new Exception('日期格式应为 YYYY-MM-DD');
            }
            self::persistModuleSetting('invite_cycle_start', $value);
            self::flashSuccess('周期开始日期已保存');
        } catch (Exception $e) {
            self::flashError('保存失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleSaveLeaderboardDisplay(): void
    {
        try {
            $hideCurrentWeek = (($_POST['hide_current_week_leaderboard'] ?? '') === '1') ? '1' : '0';
            self::persistModuleSetting('hide_current_week_leaderboard', $hideCurrentWeek);
            self::flashSuccess('排行榜显示设置已保存');
        } catch (Exception $e) {
            self::flashError('保存失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleMarkRewardClaimed(): void
    {
        try {
            $rewardId = intval($_POST['reward_id'] ?? 0);
            if ($rewardId <= 0) {
                throw new Exception('缺少奖励ID');
            }
            Capsule::table('mod_cloudflare_invite_rewards')
                ->where('id', $rewardId)
                ->update([
                    'status' => 'claimed',
                    'claimed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            self::flashSuccess('已标记为已发放');
        } catch (Exception $e) {
            self::flashError('操作失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleAdminUpsertInviteReward(): void
    {
        self::ensureInviteTables();
        $moduleSettings = self::moduleSettings();
        [$periodStart, $periodEnd] = self::currentInvitePeriod($moduleSettings);

        try {
            $identifier = trim($_POST['user_identifier'] ?? '');
            if ($identifier === '') {
                throw new Exception('缺少用户邮箱或ID');
            }
            if (ctype_digit($identifier)) {
                $userId = intval($identifier);
            } else {
                $client = Capsule::table('tblclients')->select('id')->where('email', $identifier)->first();
                if (!$client) {
                    throw new Exception('找不到该邮箱对应的用户');
                }
                $userId = intval($client->id);
            }
            $rank = max(1, intval($_POST['rank'] ?? 0));
            $count = max(0, intval($_POST['count'] ?? 0));
            $code = trim($_POST['code'] ?? '');
            $status = in_array($_POST['status'] ?? 'eligible', ['eligible', 'pending', 'claimed'], true)
                ? ($_POST['status'] ?? 'eligible')
                : 'eligible';
            if ($code === '') {
                $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->select('code')->where('userid', $userId)->first();
                $code = $codeRow->code ?? '';
            }
            $existing = Capsule::table('mod_cloudflare_invite_rewards')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->where('inviter_userid', $userId)
                ->first();
            if ($existing) {
                Capsule::table('mod_cloudflare_invite_rewards')->where('id', $existing->id)->update([
                    'rank' => $rank,
                    'count' => $count,
                    'code' => $code,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                self::flashSuccess('已更新当前周期榜单条目');
            } else {
                Capsule::table('mod_cloudflare_invite_rewards')->insert([
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'inviter_userid' => $userId,
                    'code' => $code,
                    'rank' => $rank,
                    'count' => $count,
                    'status' => $status,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                self::flashSuccess('已新增当前周期榜单条目');
            }
        } catch (Exception $e) {
            self::flashError('操作失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleAdminRebuildInviteRewards(): void
    {
        self::ensureInviteTables();
        $moduleSettings = self::moduleSettings();
        $topN = max(1, intval($moduleSettings['invite_leaderboard_top'] ?? 5));
        $periodDays = max(1, intval($moduleSettings['invite_leaderboard_period_days'] ?? 7));
        $overwrite = (($_POST['overwrite'] ?? '') === '1');
        $periodEnd = date('Y-m-d', strtotime('yesterday'));
        $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . ($periodDays - 1) . ' days'));

        try {
            if ($overwrite) {
                Capsule::table('mod_cloudflare_invite_rewards')
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->delete();
            }
            $winners = Capsule::table('mod_cloudflare_invitation_claims as ic')
                ->select('ic.inviter_userid', Capsule::raw('COUNT(*) as cnt'))
                ->whereBetween('ic.created_at', [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'])
                ->groupBy('ic.inviter_userid')
                ->orderBy('cnt', 'desc')
                ->limit($topN)
                ->get();
            foreach ($winners as $index => $winner) {
                $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->select('code')->where('userid', $winner->inviter_userid)->first();
                Capsule::table('mod_cloudflare_invite_rewards')->insert([
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'inviter_userid' => intval($winner->inviter_userid),
                    'code' => $codeRow->code ?? '',
                    'rank' => $index + 1,
                    'count' => intval($winner->cnt),
                    'status' => 'eligible',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            self::flashSuccess('当前周期榜单已重建');
        } catch (Exception $e) {
            self::flashError('重建失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleAdminSettleLastPeriod(): void
    {
        self::ensureInviteTables();
        $moduleSettings = self::moduleSettings();
        $topN = max(1, intval($moduleSettings['invite_leaderboard_top'] ?? 5));
        $periodDays = max(1, intval($moduleSettings['invite_leaderboard_period_days'] ?? 7));

        try {
            $periodEnd = date('Y-m-d', strtotime('yesterday'));
            $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . ($periodDays - 1) . ' days'));
            $exists = Capsule::table('mod_cloudflare_invite_leaderboard')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->count();
            if ($exists) {
                throw new Exception('该周期已结算');
            }
            $winners = Capsule::table('mod_cloudflare_invitation_claims as ic')
                ->select('ic.inviter_userid', Capsule::raw('COUNT(*) as cnt'))
                ->whereBetween('ic.created_at', [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'])
                ->groupBy('ic.inviter_userid')
                ->orderBy('cnt', 'desc')
                ->limit($topN)
                ->get();
            $top = [];
            foreach ($winners as $idx => $winner) {
                $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->select('code')->where('userid', $winner->inviter_userid)->first();
                $top[] = [
                    'rank' => $idx + 1,
                    'inviter_userid' => intval($winner->inviter_userid),
                    'code' => $codeRow->code ?? '',
                    'count' => intval($winner->cnt),
                ];
                $rewardExists = Capsule::table('mod_cloudflare_invite_rewards')
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->where('inviter_userid', $winner->inviter_userid)
                    ->count();
                if (!$rewardExists) {
                    Capsule::table('mod_cloudflare_invite_rewards')->insert([
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'inviter_userid' => $winner->inviter_userid,
                        'code' => $codeRow->code ?? '',
                        'rank' => $idx + 1,
                        'count' => intval($winner->cnt),
                        'status' => 'eligible',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            Capsule::table('mod_cloudflare_invite_leaderboard')->insert([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'top_json' => json_encode($top, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::flashSuccess('已结算上一期：' . $periodStart . ' ~ ' . $periodEnd);
        } catch (Exception $e) {
            self::flashError('手动结算失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleGenerateInviteSnapshot(): void
    {
        self::ensureInviteTables();
        $moduleSettings = self::moduleSettings();
        $topN = max(1, intval($moduleSettings['invite_leaderboard_top'] ?? 5));
        $periodDays = max(1, intval($moduleSettings['invite_leaderboard_period_days'] ?? 7));

        try {
            $periodEnd = trim($_POST['period_end'] ?? date('Y-m-d', strtotime('yesterday')));
            $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . ($periodDays - 1) . ' days'));
            $exists = Capsule::table('mod_cloudflare_invite_leaderboard')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->count();
            if ($exists) {
                throw new Exception('该周期快照已存在');
            }
            $winners = Capsule::table('mod_cloudflare_invitation_claims as ic')
                ->select('ic.inviter_userid', Capsule::raw('COUNT(*) as cnt'))
                ->whereBetween('ic.created_at', [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'])
                ->groupBy('ic.inviter_userid')
                ->orderBy('cnt', 'desc')
                ->limit($topN)
                ->get();
            $top = [];
            foreach ($winners as $idx => $winner) {
                $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->select('code')->where('userid', $winner->inviter_userid)->first();
                $top[] = [
                    'rank' => $idx + 1,
                    'inviter_userid' => intval($winner->inviter_userid),
                    'code' => $codeRow->code ?? '',
                    'count' => intval($winner->cnt),
                ];
            }
            Capsule::table('mod_cloudflare_invite_leaderboard')->insert([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'top_json' => json_encode($top, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::flashSuccess('快照已生成');
        } catch (Exception $e) {
            self::flashError('生成失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_SNAPSHOTS);
    }

    private static function handleRemoveLeaderboardUser(): void
    {
        try {
            $periodStart = trim($_POST['period_start'] ?? '');
            $periodEnd = trim($_POST['period_end'] ?? '');
            $userId = intval($_POST['userid'] ?? 0);
            if ($periodStart === '' || $periodEnd === '' || !$userId) {
                throw new Exception('缺少必要参数');
            }
            Capsule::table('mod_cloudflare_invite_rewards')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->where('inviter_userid', $userId)
                ->delete();
            $snap = Capsule::table('mod_cloudflare_invite_leaderboard')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->first();
            if ($snap && $snap->top_json) {
                $arr = json_decode($snap->top_json, true) ?: [];
                $arr = array_values(array_filter($arr, function ($row) use ($userId) {
                    return intval($row['inviter_userid'] ?? 0) !== $userId;
                }));
                foreach ($arr as $idx => &$row) {
                    $row['rank'] = $idx + 1;
                }
                Capsule::table('mod_cloudflare_invite_leaderboard')
                    ->where('id', $snap->id)
                    ->update([
                        'top_json' => json_encode($arr, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
            self::flashSuccess('已移除该上榜用户');
        } catch (Exception $e) {
            self::flashError('移除失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleAdminEditLeaderboardUser(): void
    {
        try {
            $rewardId = intval($_POST['reward_id'] ?? 0);
            if ($rewardId <= 0) {
                throw new Exception('缺少ID');
            }
            $rank = max(1, intval($_POST['rank'] ?? 1));
            $count = max(0, intval($_POST['count'] ?? 0));
            $code = trim($_POST['code'] ?? '');
            $row = Capsule::table('mod_cloudflare_invite_rewards')->where('id', $rewardId)->first();
            if (!$row) {
                throw new Exception('记录不存在');
            }
            Capsule::table('mod_cloudflare_invite_rewards')->where('id', $rewardId)->update([
                'rank' => $rank,
                'count' => $count,
                'code' => $code,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $snap = Capsule::table('mod_cloudflare_invite_leaderboard')
                ->where('period_start', $row->period_start)
                ->where('period_end', $row->period_end)
                ->first();
            if ($snap && $snap->top_json) {
                $arr = json_decode($snap->top_json, true) ?: [];
                foreach ($arr as &$entry) {
                    if (intval($entry['inviter_userid'] ?? 0) === intval($row->inviter_userid)) {
                        $entry['rank'] = $rank;
                        $entry['count'] = $count;
                        $entry['code'] = $code;
                        break;
                    }
                }
                usort($arr, function ($a, $b) {
                    return intval($a['rank'] ?? 0) <=> intval($b['rank'] ?? 0);
                });
                Capsule::table('mod_cloudflare_invite_leaderboard')
                    ->where('id', $snap->id)
                    ->update([
                        'top_json' => json_encode($arr, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
            self::flashSuccess('已修改上榜用户');
        } catch (Exception $e) {
            self::flashError('修改失败: ' . $e->getMessage());
        }

        self::redirect(self::HASH_INVITE);
    }

    private static function handleUpdateUserInviteLimit(): void
    {
        try {
            $userId = intval($_POST['user_id'] ?? 0);
            $email = trim((string) ($_POST['user_email'] ?? ''));
            
            if ($userId <= 0 && $email !== '') {
                $userLookup = Capsule::table('tblclients')->where('email', $email)->first();
                if ($userLookup) {
                    $userId = intval($userLookup->id);
                }
            }
            
            if ($userId <= 0) {
                throw new Exception('用户ID无效或邮箱不存在');
            }
            
            $user = Capsule::table('tblclients')->where('id', $userId)->first();
            if (!$user) {
                throw new Exception('用户不存在');
            }
            
            $newInviteLimit = null;
            if (isset($_POST['new_invite_limit']) && $_POST['new_invite_limit'] !== '') {
                $newInviteLimit = max(0, min(99999999999, intval($_POST['new_invite_limit'])));
            }
            
            if ($newInviteLimit === null) {
                throw new Exception('请填写新的邀请上限值');
            }
            
            $settings = self::moduleSettings();
            $quotaRow = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->first();
            
            if ($quotaRow) {
                Capsule::table('mod_cloudflare_subdomain_quotas')
                    ->where('userid', $userId)
                    ->update([
                        'invite_bonus_limit' => $newInviteLimit,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } else {
                $usedCount = Capsule::table('mod_cloudflare_subdomain')->where('userid', $userId)->count();
                $maxCount = max(0, intval($settings['max_subdomain_per_user'] ?? 0));
                
                Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                    'userid' => $userId,
                    'used_count' => $usedCount,
                    'max_count' => $maxCount,
                    'invite_bonus_count' => 0,
                    'invite_bonus_limit' => $newInviteLimit,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_update_user_invite_limit', [
                    'userid' => $userId,
                    'new_invite_limit' => $newInviteLimit,
                ]);
            }
            
            $name = trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? ''));
            if ($name === '') {
                $name = $user->email ?? ('ID:' . $userId);
            }
            
            self::flashSuccess('✅ 用户 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong> 的邀请上限已更新为 ' . $newInviteLimit);
        } catch (Exception $e) {
            self::flashError('❌ 更新邀请上限失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_QUOTAS);
    }

    private static function handleAddPrivilegedUser(): void
    {
        try {
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new Exception('用户ID无效');
            }
            $user = Capsule::table('tblclients')->where('id', $userId)->first();
            if (!$user) {
                throw new Exception('用户ID ' . $userId . ' 不存在');
            }
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($notes !== '') {
                $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 255, 'UTF-8') : substr($notes, 0, 255);
            } else {
                $notes = null;
            }
            $settings = self::moduleSettings();
            $inviteLimitGlobal = intval($settings['invite_bonus_limit_global'] ?? 5);
            if ($inviteLimitGlobal <= 0) {
                $inviteLimitGlobal = 5;
            }
            $now = date('Y-m-d H:i:s');
            $exists = Capsule::table('mod_cloudflare_special_users')->where('userid', $userId)->first();
            if ($exists) {
                Capsule::table('mod_cloudflare_special_users')
                    ->where('userid', $userId)
                    ->update([
                        'notes' => $notes,
                        'updated_at' => $now,
                    ]);
            } else {
                Capsule::table('mod_cloudflare_special_users')->insert([
                    'userid' => $userId,
                    'notes' => $notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if (function_exists('cf_clear_privileged_cache')) {
                cf_clear_privileged_cache();
            }
            $shouldForceNeverExpire = function_exists('cf_is_privileged_feature_enabled')
                && cf_is_privileged_feature_enabled('force_never_expire', $settings);
            if ($shouldForceNeverExpire && function_exists('cf_mark_user_domains_never_expires')) {
                cf_mark_user_domains_never_expires($userId);
            }
            if (function_exists('cf_ensure_privileged_quota')) {
                cf_ensure_privileged_quota($userId, null, $inviteLimitGlobal);
            }
            $name = trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? ''));
            if ($name === '') {
                $name = $user->email ?? ('ID:' . $userId);
            }
            self::flashSuccess('✅ 已为用户 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong> (ID:' . $userId . ') 启用特权功能。');
        } catch (Exception $e) {
            self::flashError('❌ 启用特权功能失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_PRIVILEGED);
    }

    private static function handleRemovePrivilegedUser(): void
    {
        try {
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new Exception('用户ID无效');
            }
            $user = Capsule::table('tblclients')->where('id', $userId)->first();
            if (!$user) {
                throw new Exception('用户ID ' . $userId . ' 不存在');
            }
            Capsule::table('mod_cloudflare_special_users')->where('userid', $userId)->delete();
            if (function_exists('cf_clear_privileged_cache')) {
                cf_clear_privileged_cache();
            }
            $settings = self::moduleSettings();
            $baseMax = max(0, intval($settings['max_subdomain_per_user'] ?? 0));
            $inviteLimitGlobal = intval($settings['invite_bonus_limit_global'] ?? 5);
            if ($inviteLimitGlobal <= 0) {
                $inviteLimitGlobal = 5;
            }
            if (function_exists('cf_reset_user_quota_to_base')) {
                cf_reset_user_quota_to_base($userId, $baseMax, $inviteLimitGlobal);
            }
            $name = trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? ''));
            if ($name === '') {
                $name = $user->email ?? ('ID:' . $userId);
            }
            self::flashSuccess('ℹ️ 已取消用户 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong> (ID:' . $userId . ') 的特权功能。');
        } catch (Exception $e) {
            self::flashError('❌ 取消特权失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_PRIVILEGED);
    }

    private static function handleAdminSetUserQuota(): void
    {
        self::handleUserQuotaUpdate(true);
    }

    private static function handleUpdateUserQuota(): void
    {
        self::handleUserQuotaUpdate(false);
    }

    private static function handleUserQuotaUpdate(bool $legacyPayload): void
    {
        try {
            $userId = intval($_POST['user_id'] ?? 0);
            $email = trim((string) ($_POST['user_email'] ?? ''));
            if ($userId <= 0 && !$legacyPayload && $email !== '') {
                $userLookup = Capsule::table('tblclients')->where('email', $email)->first();
                if ($userLookup) {
                    $userId = intval($userLookup->id);
                }
            }
            if ($userId <= 0) {
                throw new Exception('用户ID无效');
            }
            $user = Capsule::table('tblclients')->where('id', $userId)->first();
            if (!$user) {
                throw new Exception('用户不存在');
            }
            $quotaValue = null;
            foreach (['new_quota', 'max_count'] as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $quotaValue = intval($_POST[$field]);
                    break;
                }
            }
            if ($quotaValue === null) {
                throw new Exception('请填写新的配额值');
            }
            $quotaValue = max(0, min(99999999999, $quotaValue));
            $inviteLimitInput = null;
            if (isset($_POST['invite_bonus_limit']) && $_POST['invite_bonus_limit'] !== '') {
                $inviteLimitInput = max(0, min(99999999999, intval($_POST['invite_bonus_limit'])));
            }
            $settings = self::moduleSettings();
            $quotaRow = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->first();
            if ($quotaRow) {
                $updateData = [
                    'max_count' => $quotaValue,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($inviteLimitInput !== null) {
                    $updateData['invite_bonus_limit'] = $inviteLimitInput;
                }
                Capsule::table('mod_cloudflare_subdomain_quotas')
                    ->where('userid', $userId)
                    ->update($updateData);
            } else {
                $usedCount = Capsule::table('mod_cloudflare_subdomain')->where('userid', $userId)->count();
                if ($inviteLimitInput === null) {
                    $inviteLimitInput = max(0, intval($settings['invite_bonus_limit_global'] ?? 5));
                }
                Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                    'userid' => $userId,
                    'used_count' => $usedCount,
                    'max_count' => $quotaValue,
                    'invite_bonus_count' => 0,
                    'invite_bonus_limit' => $inviteLimitInput,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_update_user_quota', [
                    'userid' => $userId,
                    'new_quota' => $quotaValue,
                    'invite_bonus_limit' => $inviteLimitInput,
                ]);
            }
            $name = trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? ''));
            if ($name === '') {
                $name = $user->email ?? ('ID:' . $userId);
            }
            $limitText = $inviteLimitInput !== null ? '，邀请上限 ' . $inviteLimitInput : '';
            self::flashSuccess('✅ 用户 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong> 配额已更新为 ' . $quotaValue . $limitText);
        } catch (Exception $e) {
            self::flashError('❌ 更新用户配额失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_QUOTAS);
    }

    private static function handleToggleQuotaRedeem(): void
    {
        try {
            $enable = ($_POST['enable_quota_redeem'] ?? '') === '1';
            self::persistModuleSetting('enable_quota_redeem', $enable ? '1' : '0');
            self::flashSuccess($enable ? '✅ 兑换功能已开启' : '✅ 兑换功能已关闭');
        } catch (Exception $e) {
            self::flashError('❌ 更新兑换功能状态失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_QUOTAS);
    }

    private static function handleCreateRedeemCode(): void
    {
        try {
            if (class_exists('CfQuotaRedeemService')) {
                CfQuotaRedeemService::ensureTables();
            }
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            if ($code === '') {
                $code = CfQuotaRedeemService::randomCode();
            }
            if (strlen($code) > 191) {
                throw new Exception('兑换码长度不能超过 191 个字符');
            }
            $exists = Capsule::table('mod_cloudflare_quota_codes')->where('code', $code)->exists();
            if ($exists) {
                throw new Exception('该兑换码已存在，请更换其他值');
            }
            $grantAmount = max(1, intval($_POST['grant_amount'] ?? 1));
            $mode = ($_POST['mode'] ?? 'single_use') === 'multi_use' ? 'multi_use' : 'single_use';
            $maxTotal = max(0, intval($_POST['max_total_uses'] ?? 1));
            $perUserLimit = max(1, intval($_POST['per_user_limit'] ?? 1));
            if ($mode === 'single_use') {
                $maxTotal = 1;
                $perUserLimit = 1;
            } elseif ($maxTotal > 0 && $maxTotal < $perUserLimit) {
                $maxTotal = $perUserLimit;
            }
            $validToRaw = trim((string) ($_POST['valid_to'] ?? ''));
            $validTo = null;
            if ($validToRaw !== '') {
                $ts = strtotime($validToRaw);
                if ($ts === false) {
                    throw new Exception('兑换码截止时间格式无效');
                }
                $validTo = date('Y-m-d H:i:s', $ts);
            }
            $notes = trim((string) ($_POST['notes'] ?? ''));
            [$sameTypeLimitEnabled, $sameTypeKey] = self::resolveRedeemSameTypeConfigFromPost();
            $now = date('Y-m-d H:i:s');
            Capsule::table('mod_cloudflare_quota_codes')->insert([
                'code' => $code,
                'grant_amount' => $grantAmount,
                'mode' => $mode,
                'max_total_uses' => $maxTotal,
                'per_user_limit' => $perUserLimit,
                'same_type_limit_enabled' => $sameTypeLimitEnabled ? 1 : 0,
                'same_type_key' => $sameTypeLimitEnabled ? $sameTypeKey : null,
                'redeemed_total' => 0,
                'valid_from' => $now,
                'valid_to' => $validTo,
                'status' => 'active',
                'batch_tag' => null,
                'created_by_admin_id' => null,
                'notes' => $notes !== '' ? $notes : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::flashSuccess('✅ 兑换码 ' . htmlspecialchars($code) . ' 已创建');
        } catch (Exception $e) {
            self::flashError('❌ 创建兑换码失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_QUOTAS);
    }

    private static function handleGenerateRedeemCodes(): void
    {
        try {
            if (class_exists('CfQuotaRedeemService')) {
                CfQuotaRedeemService::ensureTables();
            }
            $count = max(1, min(200, intval($_POST['count'] ?? 1)));
            $grantAmount = max(1, intval($_POST['grant_amount'] ?? 1));
            $mode = ($_POST['mode'] ?? 'multi_use') === 'single_use' ? 'single_use' : 'multi_use';
            $maxTotal = max(0, intval($_POST['max_total_uses'] ?? 0));
            $perUserLimit = max(1, intval($_POST['per_user_limit'] ?? 1));
            $validDays = max(0, intval($_POST['valid_days'] ?? 0));
            $batchTag = trim((string) ($_POST['batch_tag'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            [$sameTypeLimitEnabled, $sameTypeKey] = self::resolveRedeemSameTypeConfigFromPost();
            if ($mode === 'single_use') {
                $maxTotal = 1;
                $perUserLimit = 1;
            } elseif ($maxTotal > 0 && $maxTotal < $perUserLimit) {
                $maxTotal = $perUserLimit;
            }
            $now = date('Y-m-d H:i:s');
            $validTo = $validDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $validDays . ' days')) : null;
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $code = CfQuotaRedeemService::randomCode();
                $attempts = 0;
                while (Capsule::table('mod_cloudflare_quota_codes')->where('code', $code)->exists()) {
                    $code = CfQuotaRedeemService::randomCode();
                    $attempts++;
                    if ($attempts > 5) {
                        throw new Exception('生成兑换码时出现重复，请重试');
                    }
                }
                $rows[] = [
                    'code' => $code,
                    'grant_amount' => $grantAmount,
                    'mode' => $mode,
                    'max_total_uses' => $maxTotal,
                    'per_user_limit' => $perUserLimit,
                    'same_type_limit_enabled' => $sameTypeLimitEnabled ? 1 : 0,
                    'same_type_key' => $sameTypeLimitEnabled ? $sameTypeKey : null,
                    'redeemed_total' => 0,
                    'valid_from' => $now,
                    'valid_to' => $validTo,
                    'status' => 'active',
                    'batch_tag' => $batchTag !== '' ? $batchTag : null,
                    'created_by_admin_id' => null,
                    'notes' => $notes !== '' ? $notes : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Capsule::table('mod_cloudflare_quota_codes')->insert($rows);
            self::flashSuccess('✅ 已批量生成 ' . count($rows) . ' 个兑换码');
        } catch (Exception $e) {
            self::flashError('❌ 批量生成失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_QUOTAS);
    }

    private static function resolveRedeemSameTypeConfigFromPost(): array
    {
        $enabled = (($_POST['same_type_limit_enabled'] ?? '') === '1');
        if (!$enabled) {
            return [false, null];
        }

        $rawKey = trim((string) ($_POST['same_type_key'] ?? ''));
        $normalizedKey = self::normalizeRedeemSameTypeKey($rawKey);
        if ($normalizedKey === '') {
            $normalizedKey = self::REDEEM_SAME_TYPE_DEFAULT_KEY;
        }

        return [true, $normalizedKey];
    }

    private static function normalizeRedeemSameTypeKey(string $rawKey): string
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') {
            return '';
        }

        $normalized = strtolower($rawKey);
        $normalized = preg_replace('/\s+/u', '_', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9._-]/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized, '._-');
        if ($normalized === '') {
            return '';
        }

        return substr($normalized, 0, self::REDEEM_SAME_TYPE_KEY_MAX_LENGTH);
    }

    private static function handleToggleRedeemCodeStatus(): void
    {
        try {
            $codeId = intval($_POST['code_id'] ?? 0);
            $target = ($_POST['target_status'] ?? '') === 'active' ? 'active' : 'disabled';
            if ($codeId <= 0) {
                throw new Exception('参数无效');
            }
            $codeRow = Capsule::table('mod_cloudflare_quota_codes')->where('id', $codeId)->first();
            if (!$codeRow) {
                throw new Exception('兑换码不存在');
            }
            Capsule::table('mod_cloudflare_quota_codes')->where('id', $codeId)->update([
                'status' => $target,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            self::flashSuccess($target === 'active' ? '✅ 兑换码已启用' : '✅ 兑换码已停用');
        } catch (Exception $e) {
            self::flashError('❌ 更新兑换码状态失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_QUOTAS);
    }

    private static function handleDeleteRedeemCode(): void
    {
        try {
            $codeId = intval($_POST['code_id'] ?? 0);
            if ($codeId <= 0) {
                throw new Exception('参数无效');
            }
            $codeRow = Capsule::table('mod_cloudflare_quota_codes')->where('id', $codeId)->first();
            if (!$codeRow) {
                throw new Exception('兑换码不存在或已删除');
            }
            Capsule::table('mod_cloudflare_quota_codes')->where('id', $codeId)->delete();
            self::flashSuccess('✅ 已删除兑换码 ' . htmlspecialchars($codeRow->code));
        } catch (Exception $e) {
            self::flashError('❌ 删除兑换码失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_QUOTAS);
    }

    private static function handleAdminAdjustExpiry(): void
    {
        $subdomainId = intval($_POST['subdomain_id'] ?? 0);
        $mode = (string) ($_POST['mode'] ?? 'set');
        if ($subdomainId <= 0) {
            self::flashError('无效的子域名ID');
            self::redirect(self::HASH_SUBDOMAINS);
        }

        try {
            $subdomain = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->first();
            if (!$subdomain) {
                throw new Exception('子域名不存在');
            }
            $label = trim((string) ($subdomain->subdomain ?? ''));
            if ($label === '') {
                $label = 'ID#' . $subdomainId;
            }
            $now = date('Y-m-d H:i:s');
            $updates = ['updated_at' => $now];
            $targetExpiryTs = null;
            $extendDays = null;
            $logDetails = [
                'subdomain' => $label,
                'previous_expires_at' => $subdomain->expires_at ?? null,
                'previous_never_expires' => intval($subdomain->never_expires ?? 0),
                'mode' => $mode,
            ];

            if ($mode === 'set') {
                $inputRaw = trim((string) ($_POST['expires_at_input'] ?? ''));
                if ($inputRaw === '') {
                    throw new Exception('请输入新的到期时间');
                }
                $parsedTs = strtotime(str_replace('T', ' ', $inputRaw));
                if ($parsedTs === false) {
                    throw new Exception('无法解析到期时间');
                }
                $targetExpiryTs = $parsedTs;
                $updates['expires_at'] = date('Y-m-d H:i:s', $parsedTs);
                $updates['never_expires'] = 0;
                $updates['renewed_at'] = $now;
                $updates['auto_deleted_at'] = null;
            } elseif (preg_match('/^extend(\d+)$/', $mode, $matches)) {
                $extendDays = intval($matches[1]);
                if ($extendDays <= 0) {
                    throw new Exception('无效的延长天数');
                }
                if (intval($subdomain->never_expires ?? 0) === 1) {
                    throw new Exception('当前域名为永久有效，请先保存新的到期时间');
                }
                $baseTs = $subdomain->expires_at ? strtotime($subdomain->expires_at) : time();
                if ($baseTs === false || $baseTs < time()) {
                    $baseTs = time();
                }
                $newExpiryTs = strtotime('+' . $extendDays . ' days', $baseTs);
                if ($newExpiryTs === false) {
                    throw new Exception('续期计算失败，请稍后重试');
                }
                $targetExpiryTs = $newExpiryTs;
                $updates['expires_at'] = date('Y-m-d H:i:s', $newExpiryTs);
                $updates['never_expires'] = 0;
                $updates['renewed_at'] = $now;
                $updates['auto_deleted_at'] = null;
                $logDetails['extend_days'] = $extendDays;
            } elseif ($mode === 'never') {
                $updates['expires_at'] = null;
                $updates['never_expires'] = 1;
                $updates['auto_deleted_at'] = null;
            } else {
                throw new Exception('未知操作类型');
            }

            Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->update($updates);

            $logDetails['new_expires_at'] = $updates['expires_at'] ?? null;
            $logDetails['new_never_expires'] = $updates['never_expires'] ?? intval($subdomain->never_expires ?? 0);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_adjust_subdomain_expiry', $logDetails, intval($subdomain->userid ?? 0), $subdomainId);
            }

            $displayExpiry = $targetExpiryTs !== null ? date('Y-m-d H:i', $targetExpiryTs) : null;
            $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            if ($mode === 'never') {
                self::flashSuccess('已将 ' . $labelSafe . ' 设置为永不过期');
            } elseif ($mode === 'set') {
                self::flashSuccess('已将 ' . $labelSafe . ' 的到期时间更新为 ' . ($displayExpiry ?? '未设置'));
            } else {
                self::flashSuccess('已为 ' . $labelSafe . ' 延长 ' . $extendDays . ' 天，新到期时间：' . ($displayExpiry ?? '未设置'));
            }
        } catch (Exception $e) {
            self::flashError('调整到期失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_SUBDOMAINS);
    }

    private static function handleResetModule(): void
    {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'RESET') {
            self::flash('请在确认框输入 RESET 以执行重置', 'warning');
            self::redirect(self::HASH_JOBS);
        }

        $tables = [
            'mod_cloudflare_dns_records',
            'mod_cloudflare_sync_results',
            'mod_cloudflare_jobs',
            'mod_cloudflare_logs',
            'mod_cloudflare_domain_risk',
            'mod_cloudflare_risk_events',
            'mod_cloudflare_forbidden_domains',
            'mod_cloudflare_user_stats',
            'mod_cloudflare_user_bans',
            'mod_cloudflare_invitation_claims',
            'mod_cloudflare_invitation_codes',
            'mod_cloudflare_invite_leaderboard',
            'mod_cloudflare_invite_rewards',
            'mod_cloudflare_subdomain',
            'mod_cloudflare_subdomain_quotas',
            'mod_cloudflare_rootdomains',
            'mod_cloudflare_api_keys',
            'mod_cloudflare_api_logs',
            'mod_cloudflare_api_rate_limit',
        ];

        try {
            $clearedCount = 0;
            foreach ($tables as $table) {
                if (!Capsule::schema()->hasTable($table)) {
                    continue;
                }
                try {
                    Capsule::statement("TRUNCATE TABLE `{$table}`");
                } catch (Exception $e) {
                    Capsule::table($table)->delete();
                    Capsule::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                }
                $clearedCount++;
            }

            try {
                Capsule::table('tbladdonmodules')->whereIn('module', self::moduleSlugList())->delete();
            } catch (Exception $e) {
                // ignore cleanup failures
            }
            if (function_exists('cf_clear_settings_cache')) {
                cf_clear_settings_cache();
            }
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_reset_module', [
                    'cleared_tables' => $clearedCount,
                    'total_tables' => count($tables),
                ]);
            }
            self::flashSuccess('已完成本地数据清理并重置插件配置（已清空 ' . $clearedCount . ' 个数据表，所有ID已重置为1）');
        } catch (Exception $e) {
            self::flashError('重置失败: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_JOBS);
    }

    private static function handleBatchDelete(): void
    {
        $selected = $_POST['selected_ids'] ?? [];
        if (!is_array($selected) || count($selected) === 0) {
            self::flash('请选择要删除的子域名', 'warning');
            self::redirect(self::HASH_SUBDOMAINS);
        }

        $moduleSettings = self::moduleSettings();
        $deletedCount = 0;
        $totalDnsDeleted = 0;

        try {
            $failedMessages = [];
            foreach ($selected as $rawId) {
                $subId = intval($rawId);
                if ($subId <= 0) {
                    continue;
                }
                try {
                    $record = Capsule::table('mod_cloudflare_subdomain')->where('id', $subId)->first();
                    if (!$record) {
                        continue;
                    }
                    $client = null;
                    if (function_exists('cfmod_acquire_provider_client_for_subdomain')) {
                        $providerContext = cfmod_acquire_provider_client_for_subdomain($record, $moduleSettings);
                        $client = $providerContext['client'] ?? null;
                    }
                    $deletedDns = 0;
                    if (function_exists('cfmod_admin_deep_delete_subdomain')) {
                        $deletedDns = intval(cfmod_admin_deep_delete_subdomain($client, $record));
                    }
                    Capsule::table('mod_cloudflare_subdomain')->where('id', $subId)->delete();
                    Capsule::table('mod_cloudflare_subdomain_quotas')
                        ->where('userid', $record->userid)
                        ->decrement('used_count');
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('admin_batch_delete_subdomain', [
                            'subdomain' => $record->subdomain,
                            'dns_records_deleted' => $deletedDns,
                        ], $record->userid, $record->id);
                    }
                    $deletedCount++;
                    $totalDnsDeleted += $deletedDns;
                } catch (\Throwable $inner) {
                    $failedMessages[] = 'ID ' . $subId . '：' . $inner->getMessage();
                }
            }

            if ($deletedCount === 0) {
                if (!empty($failedMessages)) {
                    self::flashError('批量删除失败：' . htmlspecialchars(implode('；', array_slice($failedMessages, 0, 3)), ENT_QUOTES, 'UTF-8'));
                } else {
                    self::flash('未删除任何子域名，请选择要处理的记录后再试。', 'warning');
                }
            } else {
                $dnsSummary = $totalDnsDeleted > 0 ? '，清理 DNS 记录 ' . $totalDnsDeleted . ' 条' : '';
                $warnSummary = !empty($failedMessages) ? ('（跳过 ' . count($failedMessages) . ' 个失败项）') : '';
                self::flashSuccess('批量删除成功，共删除 ' . $deletedCount . ' 个子域名' . $dnsSummary . $warnSummary);
            }
        } catch (Exception $e) {
            self::flashError('批量删除失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_SUBDOMAINS);
    }

    private static function handleBatchAdjustExpiry(): void
    {
        $selected = $_POST['selected_ids'] ?? [];
        if (!is_array($selected) || count($selected) === 0) {
            self::flash('请选择要调整的子域名', 'warning');
            self::redirect(self::HASH_SUBDOMAINS);
        }
        $ids = [];
        foreach ($selected as $rawId) {
            $id = intval($rawId);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            self::flash('请选择要调整的子域名', 'warning');
            self::redirect(self::HASH_SUBDOMAINS);
        }
        $mode = (string) ($_POST['batch_expiry_mode'] ?? 'set');
        if (!in_array($mode, ['set', 'extend', 'never'], true)) {
            $mode = 'set';
        }
        $targetTimestamp = null;
        $extendDays = null;
        if ($mode === 'set') {
            $inputRaw = trim((string) ($_POST['expires_at_input'] ?? ''));
            if ($inputRaw === '') {
                self::flashError('请输入新的到期时间');
                self::redirect(self::HASH_SUBDOMAINS);
            }
            $parsedTs = strtotime(str_replace('T', ' ', $inputRaw));
            if ($parsedTs === false) {
                self::flashError('无法解析到期时间，请确认格式');
                self::redirect(self::HASH_SUBDOMAINS);
            }
            $targetTimestamp = $parsedTs;
        } elseif ($mode === 'extend') {
            $extendDays = intval($_POST['extend_days'] ?? 0);
            if ($extendDays <= 0) {
                self::flashError('请输入有效的延长天数（至少 1 天）');
                self::redirect(self::HASH_SUBDOMAINS);
            }
        }

        try {
            $records = Capsule::table('mod_cloudflare_subdomain')
                ->whereIn('id', $ids)
                ->get();
            $items = self::normalizeRecordList($records);
            if (empty($items)) {
                self::flash('未找到需要调整的子域名，请刷新页面后重试。', 'warning');
                self::redirect(self::HASH_SUBDOMAINS);
            }
            $now = date('Y-m-d H:i:s');
            $updated = 0;
            $skipped = 0;
            foreach ($items as $record) {
                $subId = intval($record->id ?? 0);
                if ($subId <= 0) {
                    $skipped++;
                    continue;
                }
                try {
                    $updates = ['updated_at' => $now];
                    $newExpiryTs = null;
                    if ($mode === 'set') {
                        $newExpiryTs = $targetTimestamp;
                        $updates['expires_at'] = date('Y-m-d H:i:s', $targetTimestamp);
                        $updates['never_expires'] = 0;
                        $updates['renewed_at'] = $now;
                        $updates['auto_deleted_at'] = null;
                    } elseif ($mode === 'extend') {
                        if (intval($record->never_expires ?? 0) === 1) {
                            $skipped++;
                            continue;
                        }
                        $baseTs = $record->expires_at ? strtotime($record->expires_at) : time();
                        if ($baseTs === false || $baseTs < time()) {
                            $baseTs = time();
                        }
                        $computed = strtotime('+' . $extendDays . ' days', $baseTs);
                        if ($computed === false) {
                            $skipped++;
                            continue;
                        }
                        $newExpiryTs = $computed;
                        $updates['expires_at'] = date('Y-m-d H:i:s', $computed);
                        $updates['never_expires'] = 0;
                        $updates['renewed_at'] = $now;
                        $updates['auto_deleted_at'] = null;
                    } else {
                        $updates['expires_at'] = null;
                        $updates['never_expires'] = 1;
                        $updates['auto_deleted_at'] = null;
                    }

                    Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subId)
                        ->update($updates);

                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('admin_batch_adjust_subdomain_expiry', [
                            'mode' => $mode,
                            'subdomain' => $record->subdomain ?? '',
                            'previous_expires_at' => $record->expires_at ?? null,
                            'previous_never_expires' => intval($record->never_expires ?? 0),
                            'new_expires_at' => $updates['expires_at'] ?? null,
                            'extend_days' => $mode === 'extend' ? $extendDays : null,
                        ], intval($record->userid ?? 0), $subId);
                    }
                    $updated++;
                } catch (\Throwable $rowEx) {
                    $skipped++;
                }
            }

            if ($updated === 0) {
                self::flash('未能更新任何子域名，请确认所选记录是否满足条件。', 'warning');
            } else {
                $message = '已批量更新 ' . $updated . ' 个子域名的到期设置';
                if ($skipped > 0) {
                    $message .= '（另有 ' . $skipped . ' 个子域名被跳过）';
                }
                self::flashSuccess($message);
            }
        } catch (Exception $e) {
            self::flashError('批量调整失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_SUBDOMAINS);
    }

    private static function handleSaveRenewalNoticeSettings(): void
    {
        $settings = self::moduleSettings();

        $enabled = isset($_POST['renewal_notice_enabled']) && $_POST['renewal_notice_enabled'] === '1';
        $template = trim((string) ($_POST['renewal_notice_template'] ?? ''));
        $day1 = intval($_POST['renewal_notice_days_primary'] ?? 0);
        $day2 = intval($_POST['renewal_notice_days_secondary'] ?? 0);

        $telegramEnabled = isset($_POST['renewal_notice_telegram_enabled']) && $_POST['renewal_notice_telegram_enabled'] === '1';
        $telegramBotUsername = ltrim(trim((string) ($_POST['renewal_notice_telegram_bot_username']
            ?? ($settings['renewal_notice_telegram_bot_username'] ?? ''))), '@');

        $postedTelegramBotToken = trim((string) ($_POST['renewal_notice_telegram_bot_token'] ?? ''));
        if (self::isMaskedSensitivePlaceholder($postedTelegramBotToken)) {
            $postedTelegramBotToken = '';
        }

        $existingTelegramBotToken = trim((string) ($settings['renewal_notice_telegram_bot_token'] ?? ''));
        if (self::isStoredMaskedSensitiveValue($existingTelegramBotToken)) {
            $existingTelegramBotToken = '';
        } elseif (strpos($existingTelegramBotToken, 'enc::') === 0) {
            $existingTelegramBotToken = trim((string) cfmod_decrypt_sensitive(substr($existingTelegramBotToken, strlen('enc::'))));
        }

        $telegramBotToken = $postedTelegramBotToken !== '' ? $postedTelegramBotToken : $existingTelegramBotToken;
        $telegramDaysInput = trim((string) ($_POST['renewal_notice_telegram_days']
            ?? ($settings['renewal_notice_telegram_days'] ?? '30,10')));
        $legacyTelegramTemplate = trim((string) ($settings['renewal_notice_telegram_template'] ?? ''));
        $telegramTemplateZh = trim((string) ($_POST['renewal_notice_telegram_template_zh']
            ?? ($settings['renewal_notice_telegram_template_zh'] ?? $legacyTelegramTemplate)));
        $telegramTemplateEn = trim((string) ($_POST['renewal_notice_telegram_template_en']
            ?? ($settings['renewal_notice_telegram_template_en'] ?? '')));

        if ($telegramTemplateZh === '') {
            $telegramTemplateZh = $legacyTelegramTemplate;
        }
        if ($telegramTemplateZh === '') {
            if (class_exists('CfTelegramExpiryReminderService')) {
                $telegramTemplateZh = CfTelegramExpiryReminderService::defaultTemplate();
            } else {
                $telegramTemplateZh = "【域名到期提醒】
域名：{\$fqdn}
到期时间：{\$expiry_datetime}
剩余天数：{\$days_left} 天
请及时续期，避免域名失效。";
            }
        }

        $telegramTemplate = $telegramTemplateZh;
        $telegramAuthMaxAgeInput = intval($_POST['renewal_notice_telegram_auth_max_age_seconds']
            ?? ($settings['renewal_notice_telegram_auth_max_age_seconds'] ?? 86400));
        $telegramAuthMaxAge = max(60, min(604800, $telegramAuthMaxAgeInput));

        if ($enabled && $template === '') {
            self::flashError('请先填写邮件模板名称。');
            self::redirect(self::HASH_RUNTIME);
        }

        if ($telegramEnabled) {
            if (!class_exists('CfTelegramExpiryReminderService')) {
                self::flashError('Telegram 到期提醒服务未加载，请检查模块文件。');
                self::redirect(self::HASH_RUNTIME);
            }

            $telegramDays = CfTelegramExpiryReminderService::parseConfiguredDays([
                'renewal_notice_telegram_days' => $telegramDaysInput,
            ]);

            if ($telegramBotUsername === '') {
                self::flashError('请先填写 Telegram Bot 用户名。');
                self::redirect(self::HASH_RUNTIME);
            }

            if (!CfTelegramExpiryReminderService::validateBotToken($telegramBotToken)) {
                self::flashError('Telegram Bot Token 格式不正确，请检查后重试。');
                self::redirect(self::HASH_RUNTIME);
            }

            if (empty($telegramDays)) {
                self::flashError('请至少配置一个有效的 Telegram 提醒天数（如 30,10）。');
                self::redirect(self::HASH_RUNTIME);
            }

            $telegramDaysInput = CfTelegramExpiryReminderService::formatDaysCsv($telegramDays);
        }

        try {
            CfRenewalNoticeService::ensureTable();
            if (class_exists('CfTelegramExpiryReminderService')) {
                CfTelegramExpiryReminderService::ensureTables();
            }

            $telegramTokenStored = '';
            if ($telegramBotToken !== '') {
                $encryptedTelegramToken = cfmod_encrypt_sensitive($telegramBotToken);
                if ($encryptedTelegramToken !== null && $encryptedTelegramToken !== '') {
                    $telegramTokenStored = 'enc::' . $encryptedTelegramToken;
                }
            }

            self::persistModuleSettings([
                'renewal_notice_enabled' => $enabled ? '1' : '0',
                'renewal_notice_template' => $template,
                'renewal_notice_days_primary' => (string) max(0, $day1),
                'renewal_notice_days_secondary' => (string) max(0, $day2),
                'renewal_notice_telegram_enabled' => $telegramEnabled ? '1' : '0',
                'renewal_notice_telegram_bot_username' => $telegramBotUsername,
                'renewal_notice_telegram_bot_token' => $telegramTokenStored,
                'renewal_notice_telegram_template' => $telegramTemplate,
                'renewal_notice_telegram_template_zh' => $telegramTemplateZh,
                'renewal_notice_telegram_template_en' => $telegramTemplateEn,
                'renewal_notice_telegram_days' => $telegramDaysInput,
                'renewal_notice_telegram_auth_max_age_seconds' => (string) $telegramAuthMaxAge,
            ]);

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_save_renewal_notice', [
                    'enabled' => $enabled ? 1 : 0,
                    'template' => $template,
                    'days_primary' => max(0, $day1),
                    'days_secondary' => max(0, $day2),
                    'telegram_enabled' => $telegramEnabled ? 1 : 0,
                    'telegram_bot_username' => $telegramBotUsername,
                    'telegram_template_length' => function_exists('mb_strlen')
                        ? mb_strlen($telegramTemplate, 'UTF-8')
                        : strlen($telegramTemplate),
                    'telegram_template_zh_length' => function_exists('mb_strlen')
                        ? mb_strlen($telegramTemplateZh, 'UTF-8')
                        : strlen($telegramTemplateZh),
                    'telegram_template_en_length' => function_exists('mb_strlen')
                        ? mb_strlen($telegramTemplateEn, 'UTF-8')
                        : strlen($telegramTemplateEn),
                    'telegram_days' => $telegramDaysInput,
                    'telegram_auth_max_age' => $telegramAuthMaxAge,
                ]);
            }
            self::flashSuccess('到期提醒设置已保存');
        } catch (Exception $e) {
            self::flashError('保存失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_RUNTIME);
    }

    private static function handleTestRenewalNotice(): void
    {
        $settings = self::moduleSettings();
        $template = trim((string)($settings['renewal_notice_template'] ?? ''));
        $daysList = CfRenewalNoticeService::parseConfiguredDays($settings);
        $overrideDays = intval($_POST['test_notice_days'] ?? 0);
        $overrideEmail = trim((string)($_POST['test_override_email'] ?? ''));
        $subdomainId = intval($_POST['test_subdomain_id'] ?? 0);
        $subdomainLabel = trim((string)($_POST['test_subdomain'] ?? ''));

        if ($template === '') {
            self::flashError('请先在上方保存邮件模板名称。');
            self::redirect(self::HASH_RUNTIME);
        }

        if ($overrideDays > 0) {
            $days = $overrideDays;
        } elseif (!empty($daysList)) {
            $days = $daysList[0];
        } else {
            self::flashError('请先在上方配置至少一个提醒天数。');
            self::redirect(self::HASH_RUNTIME);
        }

        if ($subdomainId <= 0 && $subdomainLabel === '') {
            self::flashError('请填写目标子域名或 ID。');
            self::redirect(self::HASH_RUNTIME);
        }

        try {
            CfRenewalNoticeService::ensureTable();
            $query = Capsule::table('mod_cloudflare_subdomain');
            if ($subdomainId > 0) {
                $query->where('id', $subdomainId);
            } else {
                $query->where('subdomain', $subdomainLabel);
            }
            $record = $query->first();
            if (!$record) {
                throw new Exception('未找到对应的子域名记录');
            }
            $result = CfRenewalNoticeService::sendReminderEmail($record, $template, $days, $overrideEmail !== '' ? $overrideEmail : null);
            if (!$result['success']) {
                throw new Exception($result['message'] ?? '发送失败');
            }
            self::flashSuccess('测试提醒邮件已发送（提前 ' . $days . ' 天）。');
        } catch (Exception $e) {
            self::flashError('测试发送失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_RUNTIME);
    }


    private static function handleTestRenewalTelegramNotice(): void
    {
        $settings = self::moduleSettings();
        if (!class_exists('CfTelegramExpiryReminderService')) {
            self::flashError('Telegram 到期提醒服务未加载，请检查模块文件。');
            self::redirect(self::HASH_RUNTIME);
        }

        if (!CfTelegramExpiryReminderService::isConfigured($settings)) {
            self::flashError('请先完成 Telegram Bot 用户名/Token 配置后再测试发送。');
            self::redirect(self::HASH_RUNTIME);
        }

        $daysList = CfTelegramExpiryReminderService::parseConfiguredDays($settings);
        $overrideDays = intval($_POST['test_telegram_notice_days'] ?? 0);
        $overrideTelegramUserId = intval($_POST['test_override_telegram_user_id'] ?? 0);
        $subdomainId = intval($_POST['test_telegram_subdomain_id'] ?? 0);
        $subdomainLabel = trim((string) ($_POST['test_telegram_subdomain'] ?? ''));

        if ($overrideDays > 0) {
            $days = $overrideDays;
        } elseif (!empty($daysList)) {
            $days = intval($daysList[0]);
        } else {
            self::flashError('请先配置至少一个有效的 Telegram 提醒天数。');
            self::redirect(self::HASH_RUNTIME);
        }

        if ($subdomainId <= 0 && $subdomainLabel === '') {
            self::flashError('请填写目标子域名或 ID。');
            self::redirect(self::HASH_RUNTIME);
        }

        try {
            CfTelegramExpiryReminderService::ensureTables();

            $query = Capsule::table('mod_cloudflare_subdomain');
            if ($subdomainId > 0) {
                $query->where('id', $subdomainId);
            } else {
                $query->where('subdomain', $subdomainLabel);
            }
            $record = $query->first();
            if (!$record) {
                throw new Exception('未找到对应的子域名记录');
            }

            $recordObj = is_array($record) ? (object) $record : $record;
            if ($overrideTelegramUserId > 0) {
                $recordObj->reminder_telegram_user_id = $overrideTelegramUserId;
            }

            $result = CfTelegramExpiryReminderService::sendReminderMessage($recordObj, $days, $settings);
            if (empty($result['success'])) {
                $message = (string) ($result['message'] ?? '发送失败');
                if ($message === 'telegram_not_bound') {
                    $message = '未找到可用的 Telegram 绑定，请先在前台完成绑定，或填写覆盖 Telegram 用户ID。';
                }
                throw new Exception($message);
            }

            $targetHint = $overrideTelegramUserId > 0
                ? ('（目标 Telegram 用户ID：' . $overrideTelegramUserId . '）')
                : '';
            self::flashSuccess('测试 Telegram 到期提醒已发送（提前 ' . $days . ' 天）' . $targetHint . '。');
        } catch (CfTelegramExpiryReminderException $e) {
            self::flashError('测试发送失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        } catch (Exception $e) {
            self::flashError('测试发送失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_RUNTIME);
    }

    private static function handleScanOrphanRecords(): void
    {
        $rootdomain = strtolower(trim((string)($_POST['orphan_rootdomain'] ?? '')));
        $limit = intval($_POST['orphan_subdomain_limit'] ?? 100);
        if ($limit < 10) { $limit = 10; }
        if ($limit > 5000) { $limit = 5000; }
        $mode = $_POST['orphan_mode'] ?? 'dry';
        $cursorMode = strtolower(trim((string)($_POST['orphan_cursor_mode'] ?? 'resume')));
        if (!in_array($cursorMode, ['resume', 'reset'], true)) {
            $cursorMode = 'resume';
        }

        $payload = [
            'rootdomain' => $rootdomain,
            'limit' => $limit,
            'mode' => $mode,
            'cursor_mode' => $cursorMode,
            'requested_by_admin' => isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0,
        ];

        try {
            $jobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                'type' => 'cleanup_orphan_dns',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'priority' => 12,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            self::triggerQueueInBackground();
            self::flashSuccess('孤儿记录扫描任务已加入队列（Job #' . $jobId . '），系统会自动批量处理直至完成。');
        } catch (Exception $e) {
            self::flashError('提交孤儿记录扫描任务失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_RUNTIME);
    }

    public static function executeOrphanScan(array $params = []): array
    {
        $rootdomain = strtolower(trim((string)($params['rootdomain'] ?? '')));
        $limit = intval($params['limit'] ?? 100);
        if ($limit < 10) { $limit = 10; }
        if ($limit > 5000) { $limit = 5000; }
        $mode = $params['mode'] ?? 'dry';
        $cursorMode = strtolower(trim((string)($params['cursor_mode'] ?? 'resume')));
        if (!in_array($cursorMode, ['resume', 'reset'], true)) {
            $cursorMode = 'resume';
        }
        $dryRun = $mode !== 'delete';

        if ($cursorMode === 'reset') {
            self::setOrphanCursor($rootdomain, 0);
        }
        $lastCursor = self::getOrphanCursor($rootdomain);

        $query = Capsule::table('mod_cloudflare_subdomain')->orderBy('id', 'asc');
        if ($rootdomain !== '') {
            $query->whereRaw('LOWER(rootdomain) = ?', [$rootdomain]);
        }
        if ($cursorMode === 'resume' && $lastCursor > 0) {
            $query->where('id', '>', $lastCursor);
        }
        $subdomains = $query->limit($limit)->get();
        $subdomainItems = self::normalizeRecordList($subdomains);
        $subdomainCount = count($subdomainItems);
        if ($subdomainCount === 0) {
            $message = '未找到符合条件的子域名，请尝试选择“从头开始”或调整根域/数量。';
            if ($lastCursor > 0) {
                $message .= '（当前游标：' . $lastCursor . '）';
            }
            return [
                'scanned_subdomains' => 0,
                'total_records' => 0,
                'orphans_found' => 0,
                'deleted' => 0,
                'cursor' => $lastCursor,
                'mode' => $mode,
                'dry_run' => $dryRun ? 1 : 0,
                'warnings' => [],
                'has_more' => false,
                'message' => $message,
            ];
        }

        $settings = self::moduleSettings();
        $providerService = CfProviderService::instance();
        $providerCache = [];
        $totalRecords = 0;
        $orphans = [];
        $warnings = [];

        foreach ($subdomainItems as $subItem) {
            $sub = is_array($subItem) ? (object) $subItem : $subItem;
            $cacheKey = self::buildProviderCacheKey($sub);
            if (!isset($providerCache[$cacheKey])) {
                $providerCache[$cacheKey] = $providerService->acquireProviderClientForSubdomain($sub, $settings);
            }
            $context = $providerCache[$cacheKey];
            if (!$context || empty($context['client'])) {
                $warnings[] = 'provider:' . ($sub->id ?? 'unknown');
                continue;
            }
            $client = $context['client'];
            $zoneId = $sub->cloudflare_zone_id ?: ($sub->rootdomain ?? null);
            if (!$zoneId) {
                $warnings[] = 'zone:' . ($sub->id ?? 'unknown');
                continue;
            }

            try {
                $remote = $client->getDnsRecords($zoneId, $sub->subdomain);
            } catch (\Throwable $e) {
                $warnings[] = 'remote:' . ($sub->id ?? 'unknown');
                continue;
            }
            if (!($remote['success'] ?? false)) {
                $warnings[] = 'remote:' . ($sub->id ?? 'unknown');
                continue;
            }

            $remoteIds = [];
            $remoteKeys = [];
            foreach (($remote['result'] ?? []) as $rr) {
                $rid = isset($rr['id']) ? (string) $rr['id'] : '';
                if ($rid !== '') {
                    $remoteIds[$rid] = true;
                }
                $remoteKeys[self::normalizeDnsRecordKey($rr['name'] ?? '', $rr['type'] ?? '', $rr['content'] ?? '')] = true;
            }

            $locals = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $sub->id)
                ->get();
            foreach ($locals as $lr) {
                $totalRecords++;
                $localRecordId = trim((string)($lr->record_id ?? ''));
                $isOrphan = true;
                if ($localRecordId !== '' && isset($remoteIds[$localRecordId])) {
                    $isOrphan = false;
                } else {
                    $localKey = self::normalizeDnsRecordKey($lr->name ?? '', $lr->type ?? '', $lr->content ?? '');
                    if (isset($remoteKeys[$localKey])) {
                        $isOrphan = false;
                    }
                }
                if ($isOrphan) {
                    $orphans[] = [
                        'id' => intval($lr->id),
                        'subdomain_id' => intval($sub->id),
                        'subdomain' => (string)($sub->subdomain ?? ''),
                        'rootdomain' => (string)($sub->rootdomain ?? ''),
                        'name' => (string)($lr->name ?? ''),
                        'type' => strtoupper((string)($lr->type ?? '')),
                        'content' => (string)($lr->content ?? ''),
                        'record_id' => $localRecordId,
                    ];
                }
            }
        }

        $orphanCount = count($orphans);
        $deletedCount = 0;
        if (!$dryRun && $orphanCount > 0) {
            $ids = array_column($orphans, 'id');
            foreach (array_chunk($ids, 500) as $chunk) {
                $deletedCount += Capsule::table('mod_cloudflare_dns_records')->whereIn('id', $chunk)->delete();
            }
            if (function_exists('cloudflare_subdomain_log')) {
                foreach ($orphans as $entry) {
                    cloudflare_subdomain_log('admin_cleanup_orphan_dns', [
                        'record' => $entry['name'] . ' ' . $entry['type'],
                        'content' => $entry['content'],
                    ], null, $entry['subdomain_id']);
                }
            }
            self::clearPrimaryPointersForOrphans($orphans);
        }

        $lastSubdomain = $subdomainItems[$subdomainCount - 1] ?? null;
        if (is_array($lastSubdomain)) {
            $lastProcessedId = intval($lastSubdomain['id'] ?? 0);
        } elseif (is_object($lastSubdomain)) {
            $lastProcessedId = intval($lastSubdomain->id ?? 0);
        } else {
            $lastProcessedId = 0;
        }
        if ($lastProcessedId > 0) {
            self::setOrphanCursor($rootdomain, $lastProcessedId);
        }
        $currentCursor = self::getOrphanCursor($rootdomain);

        $hasMore = false;
        if ($lastProcessedId > 0) {
            $moreQuery = Capsule::table('mod_cloudflare_subdomain')->where('id', '>', $lastProcessedId);
            if ($rootdomain !== '') {
                $moreQuery->whereRaw('LOWER(rootdomain) = ?', [$rootdomain]);
            }
            $hasMore = (bool) $moreQuery->exists();
        }

        $message = sprintf(
            '已扫描 %d 个子域，共 %d 条记录，发现 %d 条孤儿记录。',
            $subdomainCount,
            $totalRecords,
            $orphanCount
        );
        if ($dryRun) {
            $message .= '（干跑，仅统计）';
        } else {
            $message .= ' 已删除 ' . $deletedCount . ' 条孤儿记录。';
        }
        $message .= ' 当前游标：' . $currentCursor . '。';
        if ($subdomainCount >= $limit) {
            $message .= ' 可继续执行以扫描下一批子域。';
        } else {
            $message .= ' 已到达末尾，如需重头扫描请选择“从头开始”。';
        }
        if ($rootdomain !== '') {
            $message .= ' 根域：' . $rootdomain . '。';
        }
        if ($orphanCount > 0) {
            $preview = array_slice($orphans, 0, 5);
            $parts = [];
            foreach ($preview as $sample) {
                $label = ($sample['subdomain'] ?: $sample['rootdomain']) . ' - ' . $sample['type'];
                $parts[] = $label;
            }
            if (!empty($parts)) {
                $message .= ' 示例：' . implode('，', $parts);
            }
        }
        if (!empty($warnings)) {
            $message .= ' （有 ' . count($warnings) . ' 个子域因供应商或远端错误被跳过）';
        }

        return [
            'scanned_subdomains' => $subdomainCount,
            'total_records' => $totalRecords,
            'orphans_found' => $orphanCount,
            'deleted' => $deletedCount,
            'cursor' => $currentCursor,
            'mode' => $mode,
            'dry_run' => $dryRun ? 1 : 0,
            'warnings' => $warnings,
            'rootdomain' => $rootdomain,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $currentCursor : null,
            'message' => $message,
        ];
    }

    private static function handleDeleteSubdomain(): void
    {
        $subdomainId = intval($_POST['subdomain_id'] ?? ($_POST['id'] ?? 0));
        if ($subdomainId <= 0) {
            self::flashError('子域名ID无效');
            self::redirect(self::HASH_SUBDOMAINS);
        }

        try {
            $record = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->first();
            if (!$record) {
                throw new Exception('子域名不存在或已删除');
            }
            $moduleSettings = self::moduleSettings();
            $client = null;
            if (function_exists('cfmod_acquire_provider_client_for_subdomain')) {
                $providerContext = cfmod_acquire_provider_client_for_subdomain($record, $moduleSettings);
                $client = $providerContext['client'] ?? null;
            }
            $deletedDns = 0;
            if (function_exists('cfmod_admin_deep_delete_subdomain')) {
                $deletedDns = intval(cfmod_admin_deep_delete_subdomain($client, $record));
            }
            Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->delete();
            Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $record->userid)
                ->decrement('used_count');
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_delete_subdomain', [
                    'subdomain' => $record->subdomain,
                    'dns_records_deleted' => $deletedDns,
                ], intval($record->userid ?? 0), $subdomainId);
            }
            $dnsSummary = $deletedDns > 0 ? '（同时清理 ' . $deletedDns . ' 条 DNS 记录）' : '';
            self::flashSuccess('子域名删除成功' . $dnsSummary);
        } catch (Exception $e) {
            self::flashError('删除子域名失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_SUBDOMAINS);
    }

    private static function handleDeleteDnsRecord(): void
    {
        $subdomainId = intval($_POST['subdomain_id'] ?? 0);
        $recordIdRaw = trim((string) ($_POST['record_id'] ?? ''));
        if ($subdomainId <= 0 || $recordIdRaw === '') {
            self::flashError('参数无效');
            self::redirect(self::HASH_SUBDOMAINS);
        }
        try {
            self::deleteDnsRecordCore($subdomainId, $recordIdRaw);
            self::flashSuccess('DNS 记录删除成功');
        } catch (\Throwable $e) {
            self::flashError('删除 DNS 记录失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::redirect(self::HASH_SUBDOMAINS);
    }

    public static function deleteDnsRecordCore(int $subdomainId, string $recordIdRaw): array
    {
        $sub = Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomainId)
            ->first();
        if (!$sub) {
            throw new \RuntimeException('subdomain_not_found');
        }
        $rec = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomainId)
            ->where('record_id', $recordIdRaw)
            ->first();
        if (!$rec && ctype_digit($recordIdRaw)) {
            $rec = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomainId)
                ->where('id', intval($recordIdRaw))
                ->first();
        }
        if (!$rec) {
            throw new \RuntimeException('record_not_found');
        }
        $settings = self::moduleSettings();
        $providerContext = function_exists('cfmod_acquire_provider_client_for_subdomain')
            ? cfmod_acquire_provider_client_for_subdomain($sub, $settings)
            : null;
        $cf = $providerContext['client'] ?? null;
        if (!$cf) {
            throw new \RuntimeException('provider_unavailable');
        }
        $zoneId = (string) ($rec->zone_id ?: ($sub->cloudflare_zone_id ?: $sub->rootdomain));
        $remoteRecordId = trim((string) ($rec->record_id ?? ''));
        if ($remoteRecordId === '') {
            throw new \RuntimeException('remote_record_id_missing');
        }
        $delRes = $cf->deleteSubdomain($zoneId, $remoteRecordId, [
            'name' => $rec->name ?? null,
            'type' => $rec->type ?? null,
            'content' => $rec->content ?? null,
        ]);
        if (!($delRes['success'] ?? false) && !cfmod_admin_provider_not_found($delRes)) {
            $detail = cfmod_admin_provider_error_text($delRes);
            throw new \RuntimeException($detail !== '' ? $detail : 'provider_delete_failed');
        }

        Capsule::transaction(function () use ($subdomainId, $sub, $rec, $remoteRecordId): void {
            $deleted = Capsule::table('mod_cloudflare_dns_records')
                ->where('id', intval($rec->id))
                ->where('subdomain_id', $subdomainId)
                ->delete();
            if ($deleted <= 0) {
                throw new \RuntimeException('record_not_found');
            }
            $subDnsRecordId = trim((string) ($sub->dns_record_id ?? ''));
            $localRecordId = trim((string) ($rec->record_id ?? ''));
            if ((string) ($rec->name ?? '') === (string) ($sub->subdomain ?? '')
                && ($subDnsRecordId !== '' && ($subDnsRecordId === $localRecordId || $subDnsRecordId === $remoteRecordId))) {
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomainId)
                    ->update(['dns_record_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            if (Capsule::table('mod_cloudflare_dns_records')->where('subdomain_id', $subdomainId)->count() === 0) {
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomainId)
                    ->update(['notes' => '已注册，等待解析设置', 'updated_at' => date('Y-m-d H:i:s')]);
            }
            if (class_exists('CfSubdomainService')) {
                CfSubdomainService::syncDnsHistoryFlag($subdomainId);
            }
        });
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('admin_delete_dns_record', [
                'record_id' => (string) ($rec->record_id ?? ''),
                'name' => $rec->name ?? '',
                'type' => $rec->type ?? '',
            ], intval($sub->userid ?? 0), $subdomainId);
        }
        return ['subdomain_id' => $subdomainId, 'record_id' => $remoteRecordId];
    }

    private static function handleToggleSubdomainStatus(): void
    {
        $subdomainId = intval($_POST['id'] ?? ($_POST['subdomain_id'] ?? 0));
        if ($subdomainId <= 0) {
            self::flashError('子域名ID无效');
            self::redirect(self::HASH_SUBDOMAINS);
        }

        try {
            $record = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->first();
            if (!$record) {
                throw new Exception('子域名不存在');
            }
            $newStatus = ($record->status === 'active') ? 'suspended' : 'active';
            Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->update([
                    'status' => $newStatus,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_toggle_subdomain_status', [
                    'subdomain' => $record->subdomain,
                    'from_status' => (string) ($record->status ?? ''),
                    'status' => $newStatus,
                ], intval($record->userid ?? 0), $subdomainId);
                if ($newStatus === 'suspended') {
                    cloudflare_subdomain_log('admin_subdomain_server_hold_enabled', [
                        'subdomain' => $record->subdomain,
                        'from_status' => (string) ($record->status ?? ''),
                        'to_status' => $newStatus,
                    ], intval($record->userid ?? 0), $subdomainId);
                } elseif ((string) ($record->status ?? '') === 'suspended' && $newStatus === 'active') {
                    cloudflare_subdomain_log('admin_subdomain_server_hold_disabled', [
                        'subdomain' => $record->subdomain,
                        'from_status' => (string) ($record->status ?? ''),
                        'to_status' => $newStatus,
                    ], intval($record->userid ?? 0), $subdomainId);
                }
            }
            self::flashSuccess('子域名状态已更新');
        } catch (Exception $e) {
            self::flashError('更新子域名状态失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_SUBDOMAINS);
    }

    private static function handleSubdomainRegenerate(): void
    {
        $subdomainId = intval($_POST['subdomain_id'] ?? ($_POST['id'] ?? 0));
        if ($subdomainId <= 0) {
            self::flashError('子域名ID无效');
            self::redirect(self::HASH_SUBDOMAINS);
        }

        try {
            $record = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->first();
            if (!$record) {
                throw new Exception('子域名不存在');
            }
            if (!function_exists('cfmod_acquire_provider_client_for_subdomain')) {
                throw new Exception('当前环境不支持此操作');
            }
            $settings = self::moduleSettings();
            $providerContext = cfmod_acquire_provider_client_for_subdomain($record, $settings);
            if (!$providerContext || empty($providerContext['client'])) {
                throw new Exception('未找到可用的 DNS 供应商账号');
            }
            $cf = $providerContext['client'];
            if (!method_exists($cf, 'createSubdomain')) {
                throw new Exception('当前供应商不支持重新生成解析');
            }
            $zoneIdentifier = $record->cloudflare_zone_id ?: ($record->rootdomain ?? '');
            if ($zoneIdentifier === '') {
                throw new Exception('缺少 Zone 信息，请先绑定根域名');
            }
            $defaultIp = trim((string) ($settings['default_ip'] ?? ''));
            if ($defaultIp === '') {
                $defaultIp = '192.0.2.1';
            }
            $response = $cf->createSubdomain($zoneIdentifier, $record->subdomain, $defaultIp);
            if (!($response['success'] ?? false)) {
                $errorPayload = $response['errors'] ?? '供应商返回失败';
                if (is_array($errorPayload)) {
                    $errorPayload = json_encode($errorPayload, JSON_UNESCAPED_UNICODE);
                }
                throw new Exception((string) $errorPayload);
            }
            $recordId = $response['result']['id'] ?? null;
            Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->update([
                    'dns_record_id' => $recordId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('admin_regen_dns', [
                    'subdomain' => $record->subdomain,
                    'record_id' => $recordId,
                ], intval($record->userid ?? 0), $subdomainId);
            }
            self::flashSuccess('解析重新生成成功');
        } catch (Exception $e) {
            self::flashError('重新生成失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        self::redirect(self::HASH_SUBDOMAINS);
    }

    private static function ensureInviteTables(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_invite_leaderboard')) {
                Capsule::schema()->create('mod_cloudflare_invite_leaderboard', function ($table) {
                    $table->increments('id');
                    $table->date('period_start');
                    $table->date('period_end');
                    $table->text('top_json');
                    $table->timestamps();
                    $table->unique(['period_start', 'period_end']);
                    $table->index('period_start');
                });
            }
            if (!Capsule::schema()->hasTable('mod_cloudflare_invite_rewards')) {
                Capsule::schema()->create('mod_cloudflare_invite_rewards', function ($table) {
                    $table->increments('id');
                    $table->date('period_start');
                    $table->date('period_end');
                    $table->integer('inviter_userid')->unsigned();
                    $table->string('code', 64);
                    $table->integer('rank')->unsigned();
                    $table->integer('count')->unsigned();
                    $table->string('status', 20)->default('eligible');
                    $table->dateTime('requested_at')->nullable();
                    $table->dateTime('claimed_at')->nullable();
                    $table->text('notes')->nullable();
                    $table->timestamps();
                    $table->index(['period_start', 'period_end']);
                    $table->index(['inviter_userid', 'period_start']);
                    $table->index('status');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_invite_rewards', 'requested_at')) {
                Capsule::schema()->table('mod_cloudflare_invite_rewards', function ($table) {
                    $table->dateTime('requested_at')->nullable()->after('status');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_invite_rewards', 'claimed_at')) {
                Capsule::schema()->table('mod_cloudflare_invite_rewards', function ($table) {
                    $table->dateTime('claimed_at')->nullable()->after('requested_at');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_invite_rewards', 'notes')) {
                Capsule::schema()->table('mod_cloudflare_invite_rewards', function ($table) {
                    $table->text('notes')->nullable()->after('claimed_at');
                });
            }
        } catch (Exception $e) {
            // ignore migrations errors
        }
    }

    private static function enforceRateLimitForAction(string $action): void
    {
        $scope = self::resolveRateLimitScope($action);
        if ($scope === null) {
            return;
        }
        $limit = CfRateLimiter::resolveLimit($scope, self::moduleSettings());
        CfRateLimiter::enforce($scope, $limit, [
            'userid' => isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'identifier' => $action,
        ]);
    }

    private static function resolveRateLimitScope(string $action): ?string
    {
        static $map = [
            'admin_create_redeem_code' => CfRateLimiter::SCOPE_QUOTA_GIFT,
            'admin_generate_redeem_codes' => CfRateLimiter::SCOPE_QUOTA_GIFT,
            'admin_delete_redeem_code' => CfRateLimiter::SCOPE_QUOTA_GIFT,
            'admin_toggle_redeem_code_status' => CfRateLimiter::SCOPE_QUOTA_GIFT,
            'admin_cancel_domain_gift' => CfRateLimiter::SCOPE_QUOTA_GIFT,
            'admin_unlock_domain_gift_lock' => CfRateLimiter::SCOPE_QUOTA_GIFT,
        ];
        return $map[$action] ?? null;
    }

    private static function formatRateLimitMessage(int $retryAfterSeconds): string
    {
        $minutes = CfRateLimiter::formatRetryMinutes($retryAfterSeconds);
        $template = cfmod_trans('cfadmin.rate_limit.hit', '操作频率过高，请 %s 分钟后再试。');
        try {
            return sprintf($template, $minutes);
        } catch (\Throwable $e) {
            return '操作频率过高，请稍后再试。';
        }
    }

    private static function clearPrimaryPointersForOrphans(array $orphans): void
    {
        $map = [];
        foreach ($orphans as $entry) {
            $recordId = $entry['record_id'] ?? '';
            if ($recordId === null || $recordId === '') {
                continue;
            }
            $map[$entry['subdomain_id']][] = (string) $recordId;
        }
        if (empty($map)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($map as $subId => $recordIds) {
            $current = Capsule::table('mod_cloudflare_subdomain')->where('id', $subId)->value('dns_record_id');
            if ($current === null || $current === '') {
                continue;
            }
            foreach ($recordIds as $rid) {
                if ((string) $current === $rid) {
                    Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subId)
                        ->update([
                            'dns_record_id' => null,
                            'updated_at' => $now,
                        ]);
                    break;
                }
            }
        }
    }

    private static function loadOrphanCursorMap(): array
    {
        if (self::$orphanCursorCache !== null) {
            return self::$orphanCursorCache;
        }
        $settings = self::moduleSettings();
        $raw = $settings[self::ORPHAN_CURSOR_SETTING_KEY] ?? '';
        $map = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }
        if (!isset($map[self::ORPHAN_CURSOR_DEFAULT_KEY])) {
            $map[self::ORPHAN_CURSOR_DEFAULT_KEY] = 0;
        }
        self::$orphanCursorCache = $map;
        return $map;
    }

    private static function saveOrphanCursorMap(array $map): void
    {
        ksort($map);
        self::$orphanCursorCache = $map;
        self::persistModuleSetting(self::ORPHAN_CURSOR_SETTING_KEY, json_encode($map));
    }

    private static function getOrphanCursorKey(string $rootdomain): string
    {
        $normalized = strtolower(trim($rootdomain));
        if ($normalized === '') {
            return self::ORPHAN_CURSOR_DEFAULT_KEY;
        }
        return 'root:' . $normalized;
    }

    private static function setOrphanCursor(string $rootdomain, int $cursor): void
    {
        $map = self::loadOrphanCursorMap();
        $key = self::getOrphanCursorKey($rootdomain);
        $map[$key] = max(0, $cursor);
        self::saveOrphanCursorMap($map);
    }

    public static function getOrphanCursor(string $rootdomain = ''): int
    {
        $map = self::loadOrphanCursorMap();
        $key = self::getOrphanCursorKey($rootdomain);
        return intval($map[$key] ?? 0);
    }

    public static function getOrphanCursorSummaryForView(): array
    {
        $map = self::loadOrphanCursorMap();
        $list = [];
        foreach ($map as $key => $value) {
            if ($key === self::ORPHAN_CURSOR_DEFAULT_KEY) {
                continue;
            }
            if (strpos($key, 'root:') === 0) {
                $list[] = [
                    'rootdomain' => substr($key, 5),
                    'cursor' => intval($value),
                ];
            }
        }
        usort($list, static function ($a, $b) {
            return strcmp($a['rootdomain'], $b['rootdomain']);
        });
        return [
            'default' => intval($map[self::ORPHAN_CURSOR_DEFAULT_KEY] ?? 0),
            'list' => $list,
        ];
    }


    private static function normalizePdnsCursorRootdomain(string $rootdomain): string
    {
        if (function_exists('cfmod_normalize_rootdomain')) {
            return (string) cfmod_normalize_rootdomain($rootdomain);
        }
        return strtolower(trim($rootdomain));
    }

    private static function pdnsLocalExportCursorStateKey(string $rootdomain, string $limitMode, int $limitValue): string
    {
        $root = self::normalizePdnsCursorRootdomain($rootdomain);
        $mode = strtolower(trim($limitMode));
        return $root . '|' . $mode . '|' . max(1, $limitValue);
    }

    private static function loadPdnsLocalExportCursorStateMap(): array
    {
        $settings = self::moduleSettings();
        $raw = trim((string) ($settings[self::PDNS_LOCAL_EXPORT_CURSOR_SETTING_KEY] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rootdomain = self::normalizePdnsCursorRootdomain((string) ($item['rootdomain'] ?? ''));
            $limitMode = strtolower(trim((string) ($item['limit_mode'] ?? '')));
            if (!in_array($limitMode, ['subdomain', 'record'], true)) {
                continue;
            }
            $limitValue = max(1, intval($item['limit_value'] ?? 0));
            $nextCursor = max(0, intval($item['next_cursor'] ?? 0));
            $hasMore = !empty($item['has_more']) ? 1 : 0;
            if ($rootdomain === '' || $nextCursor <= 0 || $hasMore !== 1) {
                continue;
            }
            $key = self::pdnsLocalExportCursorStateKey($rootdomain, $limitMode, $limitValue);
            $map[$key] = [
                'rootdomain' => $rootdomain,
                'limit_mode' => $limitMode,
                'limit_value' => $limitValue,
                'next_cursor' => $nextCursor,
                'has_more' => 1,
                'updated_at' => trim((string) ($item['updated_at'] ?? '')),
                'updated_by_admin' => intval($item['updated_by_admin'] ?? 0),
            ];
        }

        return $map;
    }

    private static function savePdnsLocalExportCursorStateMap(array $map): void
    {
        if (empty($map)) {
            self::persistModuleSetting(self::PDNS_LOCAL_EXPORT_CURSOR_SETTING_KEY, '');
            return;
        }

        uasort($map, static function (array $a, array $b): int {
            $timeA = (string) ($a['updated_at'] ?? '');
            $timeB = (string) ($b['updated_at'] ?? '');
            if ($timeA === $timeB) {
                return strcmp((string) ($a['rootdomain'] ?? ''), (string) ($b['rootdomain'] ?? ''));
            }
            return strcmp($timeB, $timeA);
        });

        if (count($map) > 200) {
            $map = array_slice($map, 0, 200, true);
        }

        $encoded = json_encode(array_values($map), JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        self::persistModuleSetting(self::PDNS_LOCAL_EXPORT_CURSOR_SETTING_KEY, $encoded);
    }

    private static function getPdnsLocalExportCursorState(string $rootdomain, string $limitMode, int $limitValue): ?array
    {
        $root = self::normalizePdnsCursorRootdomain($rootdomain);
        if ($root === '') {
            return null;
        }
        $mode = strtolower(trim($limitMode));
        if (!in_array($mode, ['subdomain', 'record'], true)) {
            return null;
        }
        $key = self::pdnsLocalExportCursorStateKey($root, $mode, max(1, $limitValue));
        $map = self::loadPdnsLocalExportCursorStateMap();
        $item = $map[$key] ?? null;
        return is_array($item) ? $item : null;
    }

    private static function clearPdnsLocalExportCursorState(string $rootdomain, ?string $limitMode = null, ?int $limitValue = null): void
    {
        $root = self::normalizePdnsCursorRootdomain($rootdomain);
        if ($root === '') {
            return;
        }

        $map = self::loadPdnsLocalExportCursorStateMap();
        if (empty($map)) {
            return;
        }

        if ($limitMode !== null && $limitValue !== null) {
            $mode = strtolower(trim($limitMode));
            if (in_array($mode, ['subdomain', 'record'], true)) {
                $key = self::pdnsLocalExportCursorStateKey($root, $mode, max(1, intval($limitValue)));
                if (isset($map[$key])) {
                    unset($map[$key]);
                    self::savePdnsLocalExportCursorStateMap($map);
                }
                return;
            }
        }

        $changed = false;
        foreach ($map as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['rootdomain'] ?? '') === $root) {
                unset($map[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            self::savePdnsLocalExportCursorStateMap($map);
        }
    }

    private static function syncPdnsLocalExportCursorState(string $rootdomain, string $limitMode, int $limitValue, array $dataset): void
    {
        $root = self::normalizePdnsCursorRootdomain($rootdomain);
        $mode = strtolower(trim($limitMode));
        $limit = max(1, intval($limitValue));
        if ($root === '' || !in_array($mode, ['subdomain', 'record'], true)) {
            self::clearPdnsLocalExportCursorState($root);
            return;
        }

        $rule = isset($dataset['partial_rule']) && is_array($dataset['partial_rule']) ? $dataset['partial_rule'] : [];
        $hasMore = !empty($rule['has_more']);
        $nextCursor = max(0, intval($rule['next_cursor'] ?? 0));

        if (!$hasMore || $nextCursor <= 0) {
            self::clearPdnsLocalExportCursorState($root, $mode, $limit);
            return;
        }

        $map = self::loadPdnsLocalExportCursorStateMap();
        $key = self::pdnsLocalExportCursorStateKey($root, $mode, $limit);
        $map[$key] = [
            'rootdomain' => $root,
            'limit_mode' => $mode,
            'limit_value' => $limit,
            'next_cursor' => $nextCursor,
            'has_more' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by_admin' => intval($_SESSION['adminid'] ?? 0),
        ];
        self::savePdnsLocalExportCursorStateMap($map);
    }

    public static function getPdnsLocalExportCursorSummaryForView(): array
    {
        $map = self::loadPdnsLocalExportCursorStateMap();
        if (empty($map)) {
            return [];
        }
        $list = array_values($map);
        usort($list, static function (array $a, array $b): int {
            $timeA = (string) ($a['updated_at'] ?? '');
            $timeB = (string) ($b['updated_at'] ?? '');
            if ($timeA === $timeB) {
                return strcmp((string) ($a['rootdomain'] ?? ''), (string) ($b['rootdomain'] ?? ''));
            }
            return strcmp($timeB, $timeA);
        });
        return $list;
    }

    private static function buildProviderCacheKey($sub): string
    {
        $pid = intval($sub->provider_account_id ?? 0);
        if ($pid > 0) {
            return 'pid_' . $pid;
        }
        $root = strtolower(trim((string)($sub->rootdomain ?? '')));
        if ($root !== '') {
            return 'root_' . $root;
        }
        return 'sub_' . intval($sub->id ?? 0);
    }

    private static function normalizeDnsRecordKey(?string $name, ?string $type, ?string $content): string
    {
        $normalizedName = strtolower(trim((string) $name));
        if ($normalizedName === '' || $normalizedName === '@') {
            $normalizedName = '@';
        } else {
            $normalizedName = rtrim($normalizedName, '.');
        }
        $normalizedType = strtoupper(trim((string) $type));
        $value = trim((string) $content);
        if (in_array($normalizedType, ['CNAME', 'NS', 'MX', 'SRV'], true)) {
            $value = rtrim($value, '.');
        }
        if ($normalizedType === 'TXT') {
            $value = trim($value, '"');
        }
        $value = strtolower($value);
        return $normalizedName . '|' . $normalizedType . '|' . $value;
    }

    private static function currentInvitePeriod(array $moduleSettings): array
    {
        $periodDays = max(1, intval($moduleSettings['invite_leaderboard_period_days'] ?? 7));
        $cycleStart = trim($moduleSettings['invite_cycle_start'] ?? '');
        if ($cycleStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cycleStart)) {
            $start = $cycleStart;
            $end = date('Y-m-d', strtotime($start . ' +' . ($periodDays - 1) . ' days'));
        } else {
            $end = date('Y-m-d', strtotime('yesterday'));
            $start = date('Y-m-d', strtotime($end . ' -' . ($periodDays - 1) . ' days'));
        }
        return [$start, $end];
    }

    private static function moduleSettings(): array
    {
        if (function_exists('cf_get_module_settings_cached')) {
            $settings = cf_get_module_settings_cached();
            if (is_array($settings)) {
                return $settings;
            }
        }
        $rows = Capsule::table('tbladdonmodules')->where('module', 'domain_hub')->get();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row->setting] = $row->value;
        }
        return $settings;
    }

    private static function moduleSlugList(): array
    {
        $slug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
        $legacy = defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain';
        return array_values(array_unique([$slug, $legacy]));
    }

    private static function isMaskedSensitivePlaceholder(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $length = function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 4 || $length > 512) {
            return false;
        }

        return preg_match('/^[\*\x{FF0A}\x{2022}\x{25CF}]+$/u', $value) === 1;
    }

    private static function isStoredMaskedSensitiveValue(string $storedValue): bool
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return false;
        }

        $plain = $storedValue;
        if (strpos($storedValue, 'enc::') === 0 && function_exists('cfmod_decrypt_sensitive')) {
            $plain = trim((string) cfmod_decrypt_sensitive(substr($storedValue, strlen('enc::'))));
        }

        return self::isMaskedSensitivePlaceholder($plain);
    }

    private static function persistModuleSetting(string $key, string $value): void
    {
        $exists = Capsule::table('tbladdonmodules')
            ->where('module', 'domain_hub')
            ->where('setting', $key)
            ->count();
        if ($exists) {
            Capsule::table('tbladdonmodules')
                ->where('module', 'domain_hub')
                ->where('setting', $key)
                ->update(['value' => $value]);
        } else {
            Capsule::table('tbladdonmodules')->insert([
                'module' => 'domain_hub',
                'setting' => $key,
                'value' => $value,
            ]);
        }
        if (function_exists('cf_clear_settings_cache')) {
            cf_clear_settings_cache();
        }
    }

    private static function persistModuleSettings(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            self::persistModuleSetting($key, $value);
        }
    }

    private static function ensureUserBansTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_user_bans')) {
                Capsule::schema()->create('mod_cloudflare_user_bans', function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->text('ban_reason');
                    $table->string('banned_by', 100);
                    $table->dateTime('banned_at');
                    $table->dateTime('unbanned_at')->nullable();
                    $table->string('status', 20)->default('banned');
                    $table->string('ban_type', 20)->default('permanent');
                    $table->dateTime('ban_expires_at')->nullable();
                    $table->timestamps();
                    $table->index('userid');
                    $table->index('status');
                    $table->index('banned_at');
                });
            } else {
                if (!Capsule::schema()->hasColumn('mod_cloudflare_user_bans', 'ban_type')) {
                    Capsule::schema()->table('mod_cloudflare_user_bans', function ($table) {
                        $table->string('ban_type', 20)->default('permanent')->after('status');
                    });
                }
                if (!Capsule::schema()->hasColumn('mod_cloudflare_user_bans', 'ban_expires_at')) {
                    Capsule::schema()->table('mod_cloudflare_user_bans', function ($table) {
                        $table->dateTime('ban_expires_at')->nullable()->after('ban_type');
                    });
                }
            }
        } catch (Exception $e) {
            // ignore migration errors
        }
    }

    private static function flashSuccess(string $message): void
    {
        self::flash($message, 'success');
    }

    private static function flashError(string $message): void
    {
        self::flash($message, 'danger');
    }

    private static function flash(string $message, string $type = 'info'): void
    {
        if (!isset($_SESSION['cfmod_admin_flash']) || !is_array($_SESSION['cfmod_admin_flash'])) {
            $_SESSION['cfmod_admin_flash'] = [];
        }
        $_SESSION['cfmod_admin_flash'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private static function redirect(string $hash = ''): void
    {
        $redirectUrl = cfmod_admin_current_url_without_action();
        if ($hash !== '') {
            $redirectUrl .= $hash;
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    private static function ensureProviderSchema(): void
    {
        cfmod_ensure_provider_schema();
    }

    private static function getDefaultProviderAccountId(): int
    {
        $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
        return intval($settings['default_provider_account_id'] ?? 0);
    }

    private static function resolveProviderAccount(?int $providerId = null, bool $withSecret = false): ?array
    {
        $candidateId = $providerId;
        if ($candidateId === null || $candidateId <= 0) {
            $default = cfmod_get_active_provider_account(null, $withSecret, true);
            return $default ?: null;
        }
        $account = cfmod_get_active_provider_account($candidateId, $withSecret, true);
        if ($account) {
            return $account;
        }
        return cfmod_get_active_provider_account(null, $withSecret, true);
    }

    private static function providerTypeLabels(): array
    {
        return [
            'alidns' => '阿里云 DNS (AliDNS)',
            'dnspod_legacy' => 'DNSPod 国际版 Legacy API',
            'dnspod_intl' => 'DNSPod 国际版 API 3.0',
            'powerdns' => 'PowerDNS (自建)',
        ];
    }

    private static function resolveNextRootdomainOrderValue(): int
    {
        if (function_exists('cfmod_next_rootdomain_display_order')) {
            return cfmod_next_rootdomain_display_order();
        }
        try {
            $max = Capsule::table('mod_cloudflare_rootdomains')->max('display_order');
            return (is_numeric($max) ? (int) $max : 0) + 1;
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private static function ensureRootdomainNsManagementColumn(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomains')) {
                return;
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'disable_ns_management')) {
                Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                    $table->boolean('disable_ns_management')->default(0)->after('maintenance');
                });
            }
        } catch (\Throwable $e) {
            // ignore runtime schema ensure failures
        }
    }

    private static function ensureRootdomainGrayColumns(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomains')) {
                return;
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'gray_enabled')) {
                Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                    $table->tinyInteger('gray_enabled')->default(0)->after('per_user_limit');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'gray_ratio')) {
                Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                    $table->integer('gray_ratio')->default(100)->after('gray_enabled');
                });
            }
        } catch (\Throwable $e) {
            // ignore runtime schema ensure failures
        }
    }

    private static function normalizeRecordList($records): array
    {
        if ($records instanceof \Illuminate\Support\Collection) {
            return $records->all();
        }
        if ($records instanceof \Traversable) {
            return iterator_to_array($records);
        }
        if (is_array($records)) {
            return array_values($records);
        }
        return [];
    }

    /**
     * 批量为老用户自动解锁邀请注册
     */
    private static function handleMigrateInviteRegistrationExistingUsers(): void
    {
        try {
            if (!class_exists('CfInviteRegistrationService')) {
                require_once __DIR__ . '/InviteRegistrationService.php';
            }
            $count = CfInviteRegistrationService::migrateExistingUsers();
            if ($count > 0) {
                self::flashSuccess(sprintf('✅ 已为 %d 位老用户自动解锁邀请注册限制。', $count));
            } else {
                self::flashSuccess('✅ 没有需要迁移的老用户，或所有老用户已自动解锁。');
            }
        } catch (\Throwable $e) {
            self::flashError('❌ 迁移失败：' . $e->getMessage());
        }
        self::redirect('#invite-reg-logs');
    }

    private static function handleEnqueueUnlockAllInviteRegistrationUsers(): void
    {
        try {
            $batchSize = intval($_POST['unlock_all_batch_size'] ?? 500);
            $enqueueResult = self::enqueueUnlockAllInviteRegistrationUsersJob($batchSize);
            if (!empty($enqueueResult['already_exists'])) {
                self::flashSuccess('✅ 全量解锁作业已在队列中，请稍后刷新查看进度。');
                self::redirect('#invite-reg-logs');
            }
            if (!empty($enqueueResult['queued'])) {
                self::flashSuccess('✅ 已提交“全量解锁邀请注册”任务（后台分批执行）。');
            } else {
                throw new \RuntimeException('unknown_enqueue_state');
            }
        } catch (\Throwable $e) {
            self::flashError('❌ 提交全量解锁任务失败：' . $e->getMessage());
        }
        self::redirect('#invite-reg-logs');
    }

    private static function enqueueUnlockAllInviteRegistrationUsersJob(int $batchSize = 500): array
    {
        $batchSize = max(100, min(5000, intval($batchSize)));
        $adminId = intval($_SESSION['adminid'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $payload = [
            'cursor_id' => 0,
            'batch_size' => $batchSize,
            'admin_id' => $adminId,
            'requested_at' => date('c'),
        ];

        $exists = Capsule::table('mod_cloudflare_jobs')
            ->where('type', 'unlock_invite_registration_all')
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if ($exists) {
            return ['queued' => false, 'already_exists' => true];
        }

        Capsule::table('mod_cloudflare_jobs')->insert([
            'type' => 'unlock_invite_registration_all',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'priority' => 6,
            'status' => 'pending',
            'attempts' => 0,
            'next_run_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::triggerQueueInBackground();
        return ['queued' => true, 'already_exists' => false];
    }
}
