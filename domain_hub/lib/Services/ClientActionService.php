<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../AtomicOperations.php';
require_once __DIR__ . '/../ErrorFormatter.php';
require_once __DIR__ . '/../TtlHelper.php';
require_once __DIR__ . '/../RootDomainLimitHelper.php';
require_once __DIR__ . '/../ProviderResolver.php';
require_once __DIR__ . '/DnsUnlockService.php';
require_once __DIR__ . '/InviteRegistrationService.php';

class CfClientActionService
{
    private const DELETION_LOCKED_STATUSES = ['pending_delete', 'pending_remove', 'expired_pending_remote_cleanup'];

    public static function process(array $globals): array
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return [];
        }

        if (!array_key_exists('action', $_POST)) {
            return [];
        }

        $action = (string) $_POST['action'];
        if ($action === '' || in_array($action, ['__csrf_failed__', '__maintenance__', '__banned__'], true)) {
            return [];
        }
        self::normalizePostedSubdomainIdentifiers();

        $isAsyncReplay = !empty($_POST['__cf_async_dns']);
        if ($isAsyncReplay) {
            unset($_POST['__cf_async_dns']);
        }

        extract($globals, EXTR_SKIP);

        $module_settings = $module_settings ?? [];
        $resolvedUserId = intval($userid ?? 0);
        $isPrivilegedUser = $resolvedUserId > 0
            && function_exists('cf_is_user_privileged')
            && cf_is_user_privileged($resolvedUserId);
        $privilegedAllowRegisterSuspendedRoot = $isPrivilegedUser
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('allow_register_suspended_root', $module_settings);
        $privilegedAllowDeleteWithDnsHistory = $isPrivilegedUser
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('allow_delete_with_dns_history', $module_settings);
        $enableDnsUnlockFeature = cfmod_setting_enabled($module_settings['enable_dns_unlock'] ?? '0');
        $dnsUnlockPurchaseEnabled = cfmod_setting_enabled($module_settings['dns_unlock_purchase_enabled'] ?? '0');
        $dnsUnlockShareEnabled = cfmod_setting_enabled($module_settings['dns_unlock_share_enabled'] ?? '1');
        $dnsUnlockPurchasePrice = round(max(0, (float)($module_settings['dns_unlock_purchase_price'] ?? 0)), 2);
        $rootVerifyEnabled = cfmod_setting_enabled($module_settings['enable_rootdomain_verify'] ?? '0');
        if ($rootVerifyEnabled) {
            self::cleanupExpiredRootVerifyTasks($module_settings, 10);
        }
        $inviteBalanceUnlockEnabled = cfmod_setting_enabled($module_settings['invite_registration_balance_unlock_enabled'] ?? '0');
        $inviteBalanceUnlockPrice = round(max(0, (float) ($module_settings['invite_registration_balance_unlock_price'] ?? 0)), 2);
        $inviteBalanceUnlockGrayEnabled = $module_settings['invite_registration_balance_unlock_gray_enabled'] ?? '0';
        $inviteBalanceUnlockGrayRatio = max(0, min(100, intval($module_settings['invite_registration_balance_unlock_gray_ratio'] ?? 100)));
        $clientDeleteMode = function_exists('cfmod_client_delete_effective_mode')
            ? cfmod_client_delete_effective_mode($module_settings, $resolvedUserId)
            : strtolower(trim((string) ($module_settings['client_domain_delete_mode'] ?? 'disabled')));

        $msg = $globals['msg'] ?? '';
        $msg_type = $globals['msg_type'] ?? '';
        $registerError = $globals['registerError'] ?? '';

        try {
            self::enforceClientRateLimit($action, $module_settings, intval($userid ?? 0));
        } catch (CfRateLimitExceededException $e) {
            $minutes = CfRateLimiter::formatRetryMinutes($e->getRetryAfterSeconds());
            $rateMessage = self::actionText('rate_limit.hit', '操作频率过高，请 %s 分钟后再试。', [$minutes]);
            if ($action === 'register') {
                $registerError = $rateMessage;
            } else {
                $msg = $rateMessage;
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'purchase_dns_unlock') {
            if (!$enableDnsUnlockFeature) {
                $msg = self::actionText('dns.unlock.disabled', '当前未启用 DNS 解锁功能。');
                $msg_type = 'warning';
            } elseif (!$dnsUnlockPurchaseEnabled) {
                $msg = self::actionText('dns.unlock.purchase_disabled', 'Paid unlock is disabled.');
                $msg_type = 'warning';
            } elseif ($dnsUnlockPurchasePrice <= 0) {
                $msg = self::actionText('dns.unlock.purchase_invalid_price', 'Unlock price is not configured.');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('dns.unlock.invalid', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif (CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
                $msg = self::actionText('dns.unlock.already', '您已完成 DNS 解锁，无需再次操作。');
                $msg_type = 'info';
            } else {
                try {
                    $email = self::resolveClientEmail($userid);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    Capsule::transaction(function () use ($userid, $dnsUnlockPurchasePrice, $email, $ip) {
                        $clientRow = Capsule::table('tblclients')->where('id', $userid)->lockForUpdate()->first();
                        if (!$clientRow) {
                            throw new \RuntimeException('client_missing');
                        }
                        $currentCredit = (float) ($clientRow->credit ?? 0);
                        if (($currentCredit + 1e-6) < $dnsUnlockPurchasePrice) {
                            throw new \RuntimeException('insufficient_credit');
                        }
                        $newCredit = $currentCredit - $dnsUnlockPurchasePrice;
                        Capsule::table('tblclients')
                            ->where('id', $userid)
                            ->update(['credit' => number_format($newCredit, 2, '.', '')]);
                        static $creditSchemaInfoPurchase = null;
                        if ($creditSchemaInfoPurchase === null) {
                            $creditSchemaInfoPurchase = [
                                'has_table' => false,
                                'has_relid' => false,
                                'has_refundid' => false,
                            ];
                            try {
                                $creditSchemaInfoPurchase['has_table'] = Capsule::schema()->hasTable('tblcredit');
                                if ($creditSchemaInfoPurchase['has_table']) {
                                    $creditSchemaInfoPurchase['has_relid'] = Capsule::schema()->hasColumn('tblcredit', 'relid');
                                    $creditSchemaInfoPurchase['has_refundid'] = Capsule::schema()->hasColumn('tblcredit', 'refundid');
                                }
                            } catch (\Throwable $ignored) {
                                $creditSchemaInfoPurchase = [
                                    'has_table' => false,
                                    'has_relid' => false,
                                    'has_refundid' => false,
                                ];
                            }
                        }
                        if ($creditSchemaInfoPurchase['has_table']) {
                            $creditRow = [
                                'clientid' => $userid,
                                'date' => date('Y-m-d H:i:s'),
                                'description' => self::actionText('dns.unlock.purchase_credit_desc', 'DNS unlock purchase'),
                                'amount' => 0 - $dnsUnlockPurchasePrice,
                            ];
                            if ($creditSchemaInfoPurchase['has_relid']) {
                                $creditRow['relid'] = 0;
                            }
                            if ($creditSchemaInfoPurchase['has_refundid']) {
                                $creditRow['refundid'] = 0;
                            }
                            Capsule::table('tblcredit')->insert($creditRow);
                        }
                        CfDnsUnlockService::unlockByPurchase($userid, $email, $ip);
                    });
                    $msg = self::actionText('dns.unlock.purchase_success', 'DNS unlock completed via account balance.');
                    $msg_type = 'success';
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'insufficient_credit') {
                        $msg = self::actionText('dns.unlock.purchase_insufficient', 'Insufficient balance for unlock.');
                        $msg_type = 'danger';
                    } else {
                        $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                } catch (\InvalidArgumentException $e) {
                    $reason = $e->getMessage();
                    if ($reason === 'owner_banned') {
                        $msg = self::actionText('dns.unlock.owner_banned', '该解锁码已失效，请联系管理员。');
                        $msg_type = 'danger';
                    } else {
                        $msg = self::actionText('dns.unlock.already', '您已完成 DNS 解锁，无需再次操作。');
                        $msg_type = 'info';
                    }
                } catch (\Throwable $e) {
                    $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'dns_unlock') {
            if (!$enableDnsUnlockFeature) {
                $msg = self::actionText('dns.unlock.disabled', '当前未启用 DNS 解锁功能。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('dns.unlock.invalid', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif (!$dnsUnlockShareEnabled) {
                $msg = self::actionText('dns.unlock.share_disabled', '管理员已关闭解锁码分享，请使用余额解锁。');
                $msg_type = 'warning';
            } else {
                $inputCode = strtoupper(trim($_POST['unlock_code'] ?? ''));
                if ($inputCode === '') {
                    $msg = self::actionText('dns.unlock.code_required', '请输入解锁码后再提交。');
                    $msg_type = 'warning';
                } else {
                    try {
                        $email = self::resolveClientEmail($userid);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        CfDnsUnlockService::unlockForUser($userid, $inputCode, $userid, $email, $ip);
                        $msg = self::actionText('dns.unlock.success', 'DNS 解锁成功，现在可以设置 NS 服务器。');
                        $msg_type = 'success';
                    } catch (\InvalidArgumentException $e) {
                        $reason = $e->getMessage();
                        if ($reason === 'self_code') {
                            $msg = self::actionText('dns.unlock.self', '不能使用自己的解锁码。');
                        } elseif ($reason === 'invalid_code') {
                            $msg = self::actionText('dns.unlock.invalid', '解锁码不正确，请核对后重试。');
                        } elseif ($reason === 'owner_banned') {
                            $msg = self::actionText('dns.unlock.owner_banned', '该解锁码已失效，请联系管理员。');
                        } elseif ($reason === 'already_unlocked') {
                            $msg = self::actionText('dns.unlock.already', '您已完成 DNS 解锁，无需再次操作。');
                        } else {
                            $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$reason]);
                        }
                        $msg_type = 'danger';
                    } catch (\Throwable $e) {
                        $msg = self::actionText('dns.unlock.error', '解锁失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'set_renew_button_display_mode') {
            if ($userid <= 0) {
                $msg = self::actionText('renew.display.mode.login_required', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $mode = strtolower(trim((string) ($_POST['renew_button_display_mode'] ?? 'window_only')));
                if (!in_array($mode, ['always', 'window_only'], true)) {
                    $mode = 'window_only';
                }
                try {
                    self::ensureUserPreferencesTable();
                    $now = date('Y-m-d H:i:s');
                    Capsule::table('mod_cloudflare_user_preferences')->updateOrInsert(
                        ['userid' => (int) $userid],
                        [
                            'renew_button_display_mode' => $mode,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                    $msg = $mode === 'always'
                        ? self::actionText('renew.display.mode.saved.always', '续期按钮显示模式已设置为：固定显示。')
                        : self::actionText('renew.display.mode.saved.window_only', '续期按钮显示模式已设置为：到免费续期窗口时显示。');
                    $msg_type = 'success';
                } catch (\Throwable $e) {
                    $msg = self::actionText('renew.display.mode.save_failed', '续期按钮显示模式保存失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'root_verify_create') {
            if (!$rootVerifyEnabled) {
                $msg = self::actionText('root_verify.disabled', '根域名验证功能未启用。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('root_verify.login_required', '未找到登录信息，请刷新后重试。');
                $msg_type = 'danger';
            } else {
                try {
                    self::ensureRootVerifyTable();
                    $subdomainId = max(0, intval($_POST['root_verify_subdomain_id'] ?? 0));
                    $provider = strtolower(trim((string) ($_POST['root_verify_provider'] ?? '')));
                    $txtValue = trim((string) ($_POST['root_verify_txt_value'] ?? ''));
                    if (!in_array($provider, ['alidns', 'dnspod'], true)) {
                        throw new \InvalidArgumentException('invalid_provider');
                    }
                    if (!self::isRootVerifyProviderEnabled($provider, $module_settings)) {
                        throw new \InvalidArgumentException('provider_disabled');
                    }
                    if ($txtValue === '' || strlen($txtValue) > 255) {
                        throw new \InvalidArgumentException('invalid_txt');
                    }
                    $window = max(60, min(1800, intval($module_settings['root_verify_window_seconds'] ?? 300)));
                    $ttl = max(60, min(3600, intval($module_settings['root_verify_ttl'] ?? 600)));
                    $host = $provider === 'alidns'
                        ? trim((string) ($module_settings['root_verify_alidns_host'] ?? 'alidnscheck'))
                        : trim((string) ($module_settings['root_verify_dnspod_host'] ?? 'dnspodcheck'));
                    if ($host === '') {
                        $host = $provider === 'alidns' ? 'alidnscheck' : 'dnspodcheck';
                    }

                    Capsule::transaction(function () use ($userid, $subdomainId, $provider, $txtValue, $window, $ttl, $host, &$msg, &$msg_type, $module_settings) {
                        $dailyLimit = max(0, min(9999, intval($module_settings['root_verify_daily_limit_per_user'] ?? 1)));
                        $dayStart = date('Y-m-d 00:00:00');
                        $dayEnd = date('Y-m-d 23:59:59');
                        if ($dailyLimit > 0) {
                            $todayCount = intval(Capsule::table('mod_cloudflare_root_verify_tasks')
                                ->where('client_id', intval($userid))
                                ->whereBetween('created_at', [$dayStart, $dayEnd])
                                ->count());
                            if ($todayCount >= $dailyLimit) {
                                throw new \RuntimeException('daily_limit_reached');
                            }
                        }
                        $userActive = Capsule::table('mod_cloudflare_root_verify_tasks')
                            ->where('client_id', intval($userid))
                            ->whereIn('status', ['active', 'pending'])
                            ->where('locked_until', '>', date('Y-m-d H:i:s'))
                            ->lockForUpdate()
                            ->first();
                        if ($userActive) {
                            throw new \RuntimeException('user_active_exists');
                        }
                        $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->where('userid', $userid)->lockForUpdate()->first();
                        if (!$sub) {
                            throw new \InvalidArgumentException('invalid_subdomain');
                        }
                        $rootdomain = trim((string) ($sub->rootdomain ?? ''));
                        if ($rootdomain === '') {
                            throw new \RuntimeException('rootdomain_missing');
                        }
                        if (self::isRootdomainBlockedForVerify($rootdomain, $module_settings)) {
                            throw new \RuntimeException('rootdomain_disabled');
                        }
                        $active = Capsule::table('mod_cloudflare_root_verify_tasks')
                            ->where('rootdomain', $rootdomain)
                            ->where('status', 'active')
                            ->where('locked_until', '>', date('Y-m-d H:i:s'))
                            ->lockForUpdate()
                            ->first();
                        if ($active && intval($active->client_id ?? 0) !== intval($userid)) {
                            throw new \RuntimeException('root_locked');
                        }
                        list($cf, $providerError,) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                        if (!$cf) {
                            throw new \RuntimeException($providerError ?: 'provider_unavailable');
                        }
                        $zoneId = (string) ($sub->cloudflare_zone_id ?? $rootdomain);
                        $providerClass = is_object($cf) ? get_class($cf) : '';
                        $isPowerDnsProvider = stripos($providerClass, 'PowerDNS') !== false;
                        if ($isPowerDnsProvider) {
                            $zoneId = $rootdomain;
                        }
                        $recordNamesToTry = $isPowerDnsProvider
                            ? [$host . '.' . $rootdomain, $host, $host . '.' . $rootdomain . '.']
                            : [$host, $host . '.' . $rootdomain];
                        self::cleanupExistingRootVerifyTxtRecords($cf, $zoneId, $rootdomain, $host, $txtValue);
                        $createRes = ['success' => false, 'errors' => ['create_failed']];
                        foreach ($recordNamesToTry as $recordNameTry) {
                            $createRes = cfmod_pdns_create_record_on_provider($cf, $zoneId, [
                                'name' => $recordNameTry,
                                'type' => 'TXT',
                                'content' => $txtValue,
                                'ttl' => $ttl,
                            ]);
                            if (($createRes['success'] ?? false) === true) {
                                break;
                            }
                            $errPreview = strtolower(trim((string) (($createRes['errors'][0] ?? '') ?: ($createRes['message'] ?? ''))));
                            if (strpos($errPreview, 'out of zone') === false && strpos($errPreview, 'name is out of zone') === false) {
                                break;
                            }
                        }
                        if (!($createRes['success'] ?? false)) {
                            $err = $createRes['errors'] ?? ($createRes['message'] ?? 'create_failed');
                            if (is_array($err)) {
                                $err = implode('; ', array_map('strval', $err));
                            }
                            $err = trim((string) $err);
                            throw new \RuntimeException($err !== '' ? ('create_failed:' . $err) : 'create_failed');
                        }
                        $recordId = trim((string) (
                            $createRes['record_id']
                            ?? $createRes['id']
                            ?? ($createRes['result']['record_id'] ?? '')
                            ?? ($createRes['result']['id'] ?? '')
                        ));
                        if (!self::rootVerifyTxtExistsOnProvider($cf, $zoneId, $rootdomain, $host, $txtValue)) {
                            throw new \RuntimeException('create_verify_failed:record_not_found_after_create');
                        }
                        $now = date('Y-m-d H:i:s');
                        $lockedUntil = date('Y-m-d H:i:s', time() + $window);
                        Capsule::table('mod_cloudflare_root_verify_tasks')->insert([
                            'client_id' => $userid,
                            'subdomain_id' => $subdomainId,
                            'rootdomain' => $rootdomain,
                            'provider' => $provider,
                            'host' => $host,
                            'txt_value' => $txtValue,
                            'ttl' => $ttl,
                            'record_id' => $recordId,
                            'status' => 'active',
                            'locked_until' => $lockedUntil,
                            'expires_at' => $lockedUntil,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        cloudflare_subdomain_log('root_verify_create', ['rootdomain' => $rootdomain, 'provider' => $provider, 'host' => $host], $userid, $subdomainId);
                        $msg = self::actionText('root_verify.create_success', '根域名验证记录已创建，请在 5 分钟内完成验证。');
                        $msg_type = 'success';
                    });
                } catch (\Throwable $e) {
                    $reason = $e->getMessage();
                    if ($reason === 'root_locked') {
                        $msg = self::actionText('root_verify.root_locked', '请等待前方用户验证完成。');
                        $msg_type = 'warning';
                    } elseif ($reason === 'daily_limit_reached') {
                        $msg = self::actionText('root_verify.daily_limit_reached', '今日提交次数已达上限，请明天再试。');
                        $msg_type = 'warning';
                    } elseif ($reason === 'user_active_exists') {
                        $msg = self::actionText('root_verify.user_active_exists', '您已有进行中的验证任务，请先完成或取消后再提交。');
                        $msg_type = 'warning';
                    } elseif ($reason === 'rootdomain_disabled') {
                        $msg = self::actionText('root_verify.rootdomain_disabled', '该根域名不支持根域名验证。');
                        $msg_type = 'warning';
                    } elseif ($reason === 'provider_disabled') {
                        $msg = self::actionText('root_verify.provider_disabled', '该验证平台未启用，请联系管理员。');
                        $msg_type = 'warning';
                    } else {
                        if (strpos($reason, 'create_failed:') === 0) {
                            $reason = substr($reason, strlen('create_failed:'));
                        }
                        $reasonLower = strtolower($reason);
                        if (strpos($reasonLower, 'out of zone') !== false || strpos($reasonLower, 'name is out of zone') !== false) {
                            $reason = self::actionText(
                                'root_verify.reason_out_of_zone',
                                '记录名称超出当前解析域范围（Name is out of zone）。请检查该根域名在 PDNS 的 Zone 名称是否与系统根域一致，或检查验证主机记录名前缀配置。'
                            );
                        }
                        if (strpos($reasonLower, 'domainrecordconflict') !== false
                            || (strpos($reasonLower, 'conflict') !== false && strpos($reasonLower, 'cname') !== false)
                            || (strpos($reasonLower, '冲突') !== false)) {
                            $reason = self::actionText(
                                'root_verify.reason_conflict',
                                '记录冲突：请检查同名不同类型是否已存在（如 NS 与 A/CNAME 冲突）或开启强制替换后重试。'
                            );
                        }
                        $msg = self::actionText('root_verify.create_failed', '创建验证记录失败：%s', [$reason]);
                        $msg_type = 'danger';
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'rootVerifyModal',
            ];
        }

        if ($_POST['action'] === 'root_verify_finish' || $_POST['action'] === 'root_verify_cancel' || $_POST['action'] === 'root_verify_force_delete') {
            try {
                self::ensureRootVerifyTable();
                $taskId = max(0, intval($_POST['root_verify_task_id'] ?? 0));
                $isFinish = $_POST['action'] === 'root_verify_finish';
                $isForceDelete = $_POST['action'] === 'root_verify_force_delete';
                $requestedStatus = $isFinish ? 'completed' : ($isForceDelete ? 'active' : 'cancelled');
                $remoteDeleted = false;
                Capsule::transaction(function () use ($userid, $taskId, $requestedStatus, $module_settings, &$remoteDeleted) {
                    $task = Capsule::table('mod_cloudflare_root_verify_tasks')->where('id', $taskId)->where('client_id', $userid)->lockForUpdate()->first();
                    if (!$task) {
                        throw new \RuntimeException('task_not_found');
                    }
                    if (!in_array((string) $task->status, ['active', 'pending'], true)) {
                        throw new \RuntimeException('task_closed');
                    }
                    $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', intval($task->subdomain_id))->first();
                    if ($sub) {
                        list($cf,,) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                        if ($cf) {
                            $zoneId = (string) ($sub->cloudflare_zone_id ?? $task->rootdomain);
                            $providerClass = is_object($cf) ? get_class($cf) : '';
                            if (stripos($providerClass, 'PowerDNS') !== false) {
                                $zoneId = (string) ($task->rootdomain ?? '');
                            }
                            $remoteDeleted = self::deleteRootVerifyTxtRecord($cf, $zoneId, (string) ($task->rootdomain ?? ''), (string) ($task->host ?? ''), (string) ($task->record_id ?? ''), (string) ($task->txt_value ?? ''));
                        }
                    }
                    $finalStatus = $remoteDeleted ? $requestedStatus : 'cleanup_failed';
                    Capsule::table('mod_cloudflare_root_verify_tasks')->where('id', $taskId)->update(['status' => $finalStatus, 'locked_until' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    cloudflare_subdomain_log('root_verify_' . $finalStatus, ['task_id' => $taskId, 'remote_deleted' => $remoteDeleted ? 1 : 0], $userid, intval($task->subdomain_id));
                });
                if ($remoteDeleted) {
                    if ($isForceDelete) {
                        $msg = self::actionText('root_verify.force_delete_success', '已执行远端强制删除并清理验证记录。');
                    } else {
                        $msg = $isFinish
                            ? self::actionText('root_verify.finish_success', '验证已完成并删除记录。')
                            : self::actionText('root_verify.cancel_success', '验证已取消并删除记录。');
                    }
                    $msg_type = 'success';
                } else {
                    $msg = self::actionText('root_verify.remote_delete_failed', '远端删除失败，任务已标记为 cleanup_failed，请稍后重试“强制删除记录”。');
                    $msg_type = 'warning';
                }
            } catch (\Throwable $e) {
                $msg = self::actionText('root_verify.process_failed', '处理失败：%s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'rootVerifyModal',
            ];
        }

        // 处理 Telegram 静默准入验证
        if ($_POST['action'] === 'invite_registration_telegram_unlock') {
            $inviteRegistrationEnabled = CfInviteRegistrationService::isGateEnabled($module_settings);
            $telegramOptionEnabled = CfInviteRegistrationService::isTelegramOptionEnabled($module_settings);
            if (!$inviteRegistrationEnabled) {
                $msg = self::actionText('invite_registration.disabled', '当前未启用邀请注册功能。');
                $msg_type = 'warning';
            } elseif (!$telegramOptionEnabled) {
                $msg = self::actionText('invite_registration.telegram_option_disabled', '当前准入模式未启用 Telegram 静默验证，或管理员尚未完成 Telegram 配置。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $authPayload = [
                    'id' => trim((string) ($_POST['telegram_auth_id'] ?? $_POST['telegram_id'] ?? '')),
                    'username' => trim((string) ($_POST['telegram_auth_username'] ?? $_POST['telegram_username'] ?? '')),
                    'first_name' => trim((string) ($_POST['telegram_auth_first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['telegram_auth_last_name'] ?? '')),
                    'photo_url' => trim((string) ($_POST['telegram_auth_photo_url'] ?? '')),
                    'auth_date' => trim((string) ($_POST['telegram_auth_date'] ?? '')),
                    'hash' => trim((string) ($_POST['telegram_auth_hash'] ?? '')),
                ];

                try {
                    if ($authPayload['id'] === '' || $authPayload['hash'] === '' || $authPayload['auth_date'] === '') {
                        CfInviteRegistrationService::unlockByTelegramBotBinding((int) $userid, $module_settings);
                    } else {
                        CfInviteRegistrationService::unlockByTelegram((int) $userid, $module_settings, $authPayload);
                    }
                    $msg = self::actionText('invite_registration.telegram_success', 'Telegram 准入验证成功，现在可以正常使用。');
                    $msg_type = 'success';
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'telegram_auth_expired') {
                        $msg = self::actionText('invite_registration.telegram_auth_expired', 'Telegram 授权已过期，请重新点击 Telegram 按钮授权。');
                    } elseif ($reason === 'telegram_auth_invalid') {
                        $msg = self::actionText('invite_registration.telegram_auth_invalid', 'Telegram 授权信息无效，请重新授权后重试。');
                    } elseif ($reason === 'telegram_used') {
                        $msg = self::actionText('invite_registration.telegram_used', '该 Telegram 账号已被其他用户绑定，请使用自己的 Telegram 账号。');
                    } elseif ($reason === 'telegram_auth_required') {
                        $msg = self::actionText('invite_registration.telegram_auth_required', '请先在 Telegram Bot 对话中完成账号绑定后再重试。');
                    } elseif ($reason === 'telegram_group_required') {
                        $msg = self::actionText('invite_registration.telegram_group_required', '当前准入要求完成进群验证，请先加入官方群并在 Bot 对话完成校验。');
                    } elseif ($reason === 'telegram_invalid_config' || $reason === 'telegram_not_enabled') {
                        $msg = self::actionText('invite_registration.telegram_invalid_config', '管理员尚未完成 Telegram 准入配置，请稍后再试。');
                    } elseif ($reason === 'invalid_user') {
                        $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                    } else {
                        $msg = self::actionText('invite_registration.telegram_error', 'Telegram 验证失败：%s', [$reason]);
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('invite_registration.telegram_error', 'Telegram 验证失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        // 处理邀请注册解锁
        if ($_POST['action'] === 'invite_registration_unlock') {
            $inviteRegistrationEnabled = CfInviteRegistrationService::isGateEnabled($module_settings);
            $inviteOptionEnabled = CfInviteRegistrationService::isInviteOptionEnabled($module_settings);
            if (!$inviteRegistrationEnabled) {
                $msg = self::actionText('invite_registration.disabled', '当前未启用邀请注册功能。');
                $msg_type = 'warning';
            } elseif (!$inviteOptionEnabled) {
                $msg = self::actionText('invite_registration.invite_option_disabled', '当前准入模式不支持邀请码验证，请使用可用的 GitHub / Telegram 认证方式。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $inputCode = strtoupper(trim($_POST['invite_reg_code'] ?? ''));
                if ($inputCode === '') {
                    $msg = self::actionText('invite_registration.code_required', '请输入邀请码后再提交。');
                    $msg_type = 'warning';
                } else {
                    try {
                        $email = self::resolveClientEmail($userid);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        CfInviteRegistrationService::unlockForUser($userid, $inputCode, $email, $ip);
                        unset($_SESSION['cfmod_invite_registration_pending_code']);
                        @setcookie('cfmod_invite_registration_pending_code', '', time() - 3600, '/', '', false, false);
                        $msg = self::actionText('invite_registration.success', '邀请注册验证成功，现在可以正常使用。');
                        $msg_type = 'success';
                    } catch (\InvalidArgumentException $e) {
                        $reason = $e->getMessage();
                        if ($reason === 'self_code') {
                            $msg = self::actionText('invite_registration.self', '不能使用自己的邀请码。');
                        } elseif ($reason === 'invalid_code') {
                            $msg = self::actionText('invite_registration.invalid', '邀请码不正确，请核对后重试。');
                        } elseif ($reason === 'inviter_banned') {
                            $msg = self::actionText('invite_registration.inviter_banned', '该邀请码已失效，请联系管理员。');
                        } elseif ($reason === 'inviter_age_insufficient') {
                            $requiredMonths = class_exists('CfInviteRegistrationService')
                                ? CfInviteRegistrationService::getInviterMinMonthsRequirement()
                                : 0;
                            $requiredMonths = max(0, intval($requiredMonths));
                            $msg = self::actionText('invite_registration.inviter_age_insufficient', '该邀请码发放者未满足最低月龄要求（至少 %s 个月），请使用其他邀请码。', [$requiredMonths]);
                        } elseif ($reason === 'already_unlocked') {
                            $msg = self::actionText('invite_registration.already', '您已完成邀请注册验证，无需再次操作。');
                        } elseif ($reason === 'inviter_limit_reached') {
                            $msg = self::actionText('invite_registration.inviter_limit', '该邀请码发放者已达到邀请上限，请使用其他邀请码。');
                        } elseif ($reason === 'invalid_user') {
                            $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                        } else {
                            $msg = self::actionText('invite_registration.error', '验证失败：%s', [$reason]);
                        }
                        $msg_type = 'danger';
                    } catch (\Throwable $e) {
                        $msg = self::actionText('invite_registration.error', '验证失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        if ($_POST['action'] === 'invite_registration_generate_codes') {
            if ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $count = max(1, (int) ($_POST['invite_generate_count'] ?? 1));
                $inviteMode = strtolower(trim((string) ($_POST['invite_mode'] ?? 'one_time')));
                $customCode = strtoupper(trim((string) ($_POST['invite_custom_code'] ?? '')));
                try {
                    if ($inviteMode === 'fixed') {
                        if (CfInviteRegistrationService::isFixedInviteModeLocked((int) $userid)) {
                            $msg = self::actionTextByLanguage(
                                'invite_registration.fixed_mode_already_exists',
                                '已生成过固定邀请码，请在邀请码中查看。',
                                'A fixed invite code already exists. Please check it in the invite code list.'
                            );
                            $msg_type = 'warning';
                        } else {
                            $fixed = $customCode !== ''
                                ? CfInviteRegistrationService::enableFixedInviteModeWithCustomCode((int) $userid, $customCode)
                                : CfInviteRegistrationService::enableFixedInviteMode((int) $userid);
                            $msg = self::actionTextByLanguage(
                                'invite_registration.fixed_mode_success',
                                '已启用固定邀请码模式，固定邀请码：%s',
                                'Fixed invite code mode enabled. Code: %s',
                                [strtoupper((string) ($fixed['invite_code'] ?? ''))]
                            );
                            $msg_type = 'success';
                        }
                    } elseif ($customCode !== '') {
                        $created = CfInviteRegistrationService::generateCustomInviteCode((int) $userid, $customCode);
                        $msg = self::actionTextByLanguage(
                            'invite_registration.custom_generate_success',
                            '自定义邀请码生成成功：%s',
                            'Custom invite code generated: %s',
                            [strtoupper((string) ($created['invite_code'] ?? ''))]
                        );
                        $msg_type = 'success';
                    } else {
                        $created = CfInviteRegistrationService::generateInviteCodes((int) $userid, $count);
                        if ($created > 0) {
                            $msg = self::actionTextByLanguage(
                                'invite_registration.generate_success',
                                '已成功生成 %s 个邀请码。',
                                'Successfully generated %s invite code(s).',
                                [$created]
                            );
                            $msg_type = 'success';
                        } else {
                            $msg = self::actionTextByLanguage(
                                'invite_registration.generate_none',
                                '未生成邀请码，请检查剩余额度或账号资格。',
                                'No invite codes were generated. Please check remaining quota or account eligibility.'
                            );
                            $msg_type = 'warning';
                        }
                    }
                } catch (\InvalidArgumentException $e) {
                    if ($e->getMessage() === 'fixed_mode_locked') {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.fixed_mode_locked',
                            '当前账号已启用固定邀请码模式，不能再生成一次性邀请码。',
                            'Fixed invite mode is enabled for this account. One-time invite code generation is disabled.'
                        );
                    } elseif ($e->getMessage() === 'count_exceeds_remaining') {
                        $remaining = max(0, (int) CfInviteRegistrationService::getInviterRemainingQuota((int) $userid));
                        $msg = self::actionTextByLanguage(
                            'invite_registration.generate_exceeds',
                            '生成数量超过剩余额度（当前剩余 %s）。',
                            'Generation quantity exceeds remaining quota (currently %s left).',
                            [$remaining]
                        );
                    } elseif ($e->getMessage() === 'count_exceeds_batch_max') {
                        $batchMax = max(1, (int) CfInviteRegistrationService::getGenerateBatchMax());
                        $msg = self::actionTextByLanguage(
                            'invite_registration.generate_batch_exceeds',
                            '单次最多可生成 %s 个邀请码，请调整后重试。',
                            'You can generate at most %s invite codes per request.',
                            [$batchMax]
                        );
                    } elseif ($e->getMessage() === 'custom_not_allowed') {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.custom_not_allowed',
                            '当前账号未开放自定义邀请码权限。',
                            'Custom invite code is not allowed for your account.'
                        );
                    } elseif ($e->getMessage() === 'custom_invalid_format') {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.custom_invalid_format',
                            '自定义邀请码格式无效：仅支持 6-20 位大写字母与数字。',
                            'Invalid custom code format: only 6-20 uppercase letters and digits are allowed.'
                        );
                    } elseif ($e->getMessage() === 'custom_code_exists') {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.custom_code_exists',
                            '该邀请码已存在，请更换后重试。',
                            'This invite code already exists. Please try another one.'
                        );
                    } elseif ($e->getMessage() === 'inviter_not_eligible') {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.custom_inviter_not_eligible',
                            '当前账号暂不满足发码条件，请检查账户资格或剩余额度。',
                            'Current account is not eligible to issue invite codes yet.'
                        );
                    } else {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.generate_error',
                            '生成失败：%s',
                            'Generation failed: %s',
                            [$e->getMessage()]
                        );
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionTextByLanguage(
                        'invite_registration.generate_error',
                        '生成失败：%s',
                        'Generation failed: %s',
                        [$e->getMessage()]
                    );
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'inviteRegistrationModal',
            ];
        }
        if ($_POST['action'] === 'invite_registration_invalidate_fixed_code') {
            if ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                try {
                    $changed = CfInviteRegistrationService::invalidateFixedInviteCode((int) $userid);
                    if ($changed) {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.fixed_invalidate_success',
                            '固定邀请码已作废。',
                            'Fixed invite code has been invalidated.'
                        );
                        $msg_type = 'success';
                    } else {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.fixed_invalidate_noop',
                            '固定邀请码已为空，无需重复作废。',
                            'Fixed invite code is already empty.'
                        );
                        $msg_type = 'warning';
                    }
                } catch (\InvalidArgumentException $e) {
                    if ($e->getMessage() === 'fixed_mode_not_enabled') {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.fixed_mode_not_enabled',
                            '当前账号未启用固定邀请码模式。',
                            'Fixed invite mode is not enabled for this account.'
                        );
                    } else {
                        $msg = self::actionTextByLanguage(
                            'invite_registration.generate_error',
                            '操作失败：%s',
                            'Action failed: %s',
                            [$e->getMessage()]
                        );
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionTextByLanguage(
                        'invite_registration.generate_error',
                        '操作失败：%s',
                        'Action failed: %s',
                        [$e->getMessage()]
                    );
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'inviteRegistrationModal',
            ];
        }
        if ($_POST['action'] === 'invite_registration_invalidate_one_time_code') {
            if ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } else {
                $codeId = max(0, (int) ($_POST['invite_code_id'] ?? 0));
                try {
                    $changed = CfInviteRegistrationService::invalidateOneTimeInviteCode((int) $userid, $codeId);
                    if ($changed) {
                        $msg = self::actionTextByLanguage('invite_registration.one_time_invalidate_success', '一次性邀请码已作废。', 'One-time invite code has been invalidated.');
                        $msg_type = 'success';
                    } else {
                        $msg = self::actionTextByLanguage('invite_registration.one_time_invalidate_noop', '邀请码已被使用或已作废。', 'Invite code was already used or invalidated.');
                        $msg_type = 'warning';
                    }
                } catch (\Throwable $e) {
                    $msg = self::actionTextByLanguage('invite_registration.generate_error', '操作失败：%s', 'Action failed: %s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'inviteRegistrationModal',
            ];
        }

        if ($_POST['action'] === 'invite_registration_balance_unlock') {
            $inviteBalanceUnlockRedirect = '';
            $inviteRegistrationEnabled = CfInviteRegistrationService::isGateEnabled($module_settings);
            if (!$inviteRegistrationEnabled) {
                $msg = self::actionText('invite_registration.disabled', '当前未启用邀请注册功能。');
                $msg_type = 'warning';
            } elseif (!$inviteBalanceUnlockEnabled) {
                $msg = self::actionText('invite_registration.balance_disabled', '当前未启用余额准入解锁。');
                $msg_type = 'warning';
            } elseif (function_exists('cfmod_rootdomain_gray_hit') && !cfmod_rootdomain_gray_hit((int) $userid, 'invite_registration_balance_unlock', $inviteBalanceUnlockGrayEnabled, $inviteBalanceUnlockGrayRatio, 'invite_balance_unlock_v1')) {
                $msg = self::actionText('invite_registration.balance_gray_denied', '余额准入解锁当前处于灰度开放中，您暂未被纳入本轮范围。');
                $msg_type = 'warning';
            } elseif ($inviteBalanceUnlockPrice <= 0) {
                $msg = self::actionText('invite_registration.balance_invalid_price', '余额准入价格未配置。');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionText('invite_registration.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif (CfInviteRegistrationService::userHasUnlocked((int) $userid)) {
                $msg = self::actionText('invite_registration.already', '您已完成准入验证，无需重复解锁。');
                $msg_type = 'info';
            } else {
                try {
                    $unlockResult = Capsule::transaction(function () use ($userid, $inviteBalanceUnlockPrice, &$inviteBalanceUnlockRedirect) {
                        $client = Capsule::table('tblclients')->where('id', $userid)->lockForUpdate()->first();
                        if (!$client) {
                            throw new \RuntimeException('client_missing');
                        }
                        $credit = (float) ($client->credit ?? 0);
                        $now = date('Y-m-d H:i:s');
                        $today = date('Y-m-d');
                        if (($credit + 1e-6) < $inviteBalanceUnlockPrice) {
                            $invoiceDesc = self::actionText('invite_registration.balance_invoice_desc', 'Invitation registration gate unlock');
                            $existingPendingInvoice = Capsule::table('tblinvoices as i')
                                ->join('tblinvoiceitems as it', 'it.invoiceid', '=', 'i.id')
                                ->where('i.userid', (int) $userid)
                                ->whereIn('i.status', ['Unpaid', 'Draft', 'Payment Pending'])
                                ->where('it.userid', (int) $userid)
                                ->where('it.relid', 0)
                                ->where('it.description', $invoiceDesc)
                                ->whereRaw('ABS(it.amount - ?) < 0.00001', [number_format($inviteBalanceUnlockPrice, 2, '.', '')])
                                ->orderBy('i.id', 'desc')
                                ->select('i.id')
                                ->first();
                            if ($existingPendingInvoice) {
                                $existingInvoiceId = (int) ($existingPendingInvoice->id ?? 0);
                                $inviteBalanceUnlockRedirect = 'viewinvoice.php?id=' . $existingInvoiceId;
                                return ['status' => 'invoice_created', 'invoice_id' => $existingInvoiceId, 'reused' => true];
                            }
                            $invoiceId = Capsule::table('tblinvoices')->insertGetId([
                                'userid' => (int) $userid,
                                'date' => $today,
                                'duedate' => date('Y-m-d', strtotime('+7 days')),
                                'subtotal' => $inviteBalanceUnlockPrice,
                                'total' => $inviteBalanceUnlockPrice,
                                'tax' => 0,
                                'tax2' => 0,
                                'status' => 'Unpaid',
                            ]);
                            Capsule::table('tblinvoiceitems')->insert([
                                'invoiceid' => $invoiceId,
                                'userid' => (int) $userid,
                                'type' => 'Item',
                                'relid' => 0,
                                'description' => $invoiceDesc,
                                'amount' => number_format($inviteBalanceUnlockPrice, 2, '.', ''),
                                'taxed' => 0,
                                'duedate' => date('Y-m-d', strtotime('+7 days')),
                                'paymentmethod' => '',
                            ]);
                            $inviteBalanceUnlockRedirect = 'viewinvoice.php?id=' . (int) $invoiceId;
                            return ['status' => 'invoice_created', 'invoice_id' => (int) $invoiceId];
                        } else {
                            $invoiceId = Capsule::table('tblinvoices')->insertGetId([
                                'userid' => (int) $userid,
                                'date' => $today,
                                'duedate' => $today,
                                'datepaid' => $now,
                                'subtotal' => $inviteBalanceUnlockPrice,
                                'total' => $inviteBalanceUnlockPrice,
                                'tax' => 0,
                                'tax2' => 0,
                                'status' => 'Paid',
                            ]);
                            Capsule::table('tblinvoiceitems')->insert([
                                'invoiceid' => $invoiceId,
                                'userid' => (int) $userid,
                                'type' => '',
                                'relid' => 0,
                                'description' => self::actionText('invite_registration.balance_invoice_desc', 'Invitation registration gate unlock'),
                                'amount' => $inviteBalanceUnlockPrice,
                                'taxed' => 0,
                                'duedate' => $today,
                                'paymentmethod' => '',
                            ]);
                            $newCredit = $credit - $inviteBalanceUnlockPrice;
                            Capsule::table('tblclients')->where('id', $userid)->update([
                                'credit' => number_format($newCredit, 2, '.', ''),
                            ]);
                            if (Capsule::schema()->hasTable('tblcredit')) {
                                Capsule::table('tblcredit')->insert([
                                    'clientid' => (int) $userid,
                                    'date' => $now,
                                    'description' => self::actionText('invite_registration.balance_credit_desc', 'Invitation registration gate unlock'),
                                    'amount' => 0 - $inviteBalanceUnlockPrice,
                                    'relid' => $invoiceId,
                                    'refundid' => 0,
                                ]);
                            }
                            $email = self::resolveClientEmail((int) $userid);
                            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                            CfInviteRegistrationService::unlockForUser((int) $userid, 'BALANCE_UNLOCK', $email, $ip);
                            return ['status' => 'unlocked'];
                        }
                    });
                    if (($unlockResult['status'] ?? '') === 'invoice_created') {
                        $msg = self::actionText('invite_registration.balance_invoice_created', '余额不足，已自动生成待支付账单，请完成支付后自动解锁。');
                        $msg_type = 'warning';
                    } else {
                        $msg = self::actionText('invite_registration.balance_success', '已完成余额解锁并生成账单。');
                        $msg_type = 'success';
                    }
                } catch (\RuntimeException $e) {
                    $msg = self::actionText('invite_registration.balance_error', '余额解锁失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('invite_registration.balance_error', '余额解锁失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'inviteRegistrationModal',
                'redirectTo' => $inviteBalanceUnlockRedirect,
            ];
        }


        if ($_POST['action'] === 'create_domain_permanent_upgrade_request') {
            if (!class_exists('CfDomainPermanentUpgradeService')) {
                $msg = self::actionText('domain_permanent_upgrade.service_missing', '服务暂不可用，请稍后再试。');
                $msg_type = 'danger';
            } elseif (!CfDomainPermanentUpgradeService::isEnabled($module_settings)) {
                $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                $msg_type = 'warning';
            } else {
                $subdomainId = intval($_POST['perm_upgrade_subdomain_id'] ?? ($_POST['subdomain_id'] ?? 0));
                try {
                    $result = CfDomainPermanentUpgradeService::createOrGetRequest((int) ($userid ?? 0), $subdomainId, $module_settings);
                    $domainName = (string) ($result['domain'] ?? '');
                    $assistCount = intval($result['assist_count'] ?? 0);
                    $targetAssists = intval($result['target_assists'] ?? 0);
                    if (!empty($result['created'])) {
                        $msg = self::actionText(
                            'domain_permanent_upgrade.create.success',
                            '已创建助力任务：%1$s（%2$s/%3$s），请复制助力码邀请好友协助。',
                            [$domainName, $assistCount, $targetAssists]
                        );
                        $msg_type = 'success';
                    } else {
                        $msg = self::actionText(
                            'domain_permanent_upgrade.create.exists',
                            '该域名已有进行中的助力任务：%1$s（%2$s/%3$s），请直接复制现有助力码分享。',
                            [$domainName, $assistCount, $targetAssists]
                        );
                        $msg_type = 'info';
                    }
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('client_domain_permanent_upgrade_request', [
                            'request_id' => intval($result['request_id'] ?? 0),
                            'subdomain_id' => intval($result['subdomain_id'] ?? 0),
                            'domain' => (string) ($result['domain'] ?? ''),
                            'created' => !empty($result['created']),
                        ], (int) ($userid ?? 0), intval($result['subdomain_id'] ?? 0));
                    }
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'invalid_subdomain') {
                        $msg = self::actionText('domain_permanent_upgrade.create.invalid_subdomain', '请选择有效域名后再提交。');
                    } elseif ($reason === 'already_permanent') {
                        $msg = self::actionText('domain_permanent_upgrade.create.already', '该域名已是永久有效，无需重复发起。');
                    } elseif ($reason === 'invalid_status') {
                        $msg = self::actionText('domain_permanent_upgrade.create.invalid_status', '该域名当前状态不支持永久升级。');
                    } elseif ($reason === 'feature_disabled') {
                        $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                    } elseif ($reason === 'invoice_pending_exists') {
                        $msg = self::actionText('domain_permanent_upgrade.create.invoice_pending_exists', '该域名存在待支付的升级订单，请完成支付或取消订单后再试。');
                    } else {
                        $msg = self::actionText('domain_permanent_upgrade.create.failed', '发起失败：%s', [$reason]);
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('domain_permanent_upgrade.create.failed', '发起失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'domainPermanentUpgradeModal',
            ];
        }

        if ($_POST['action'] === 'assist_domain_permanent_upgrade') {
            if (!class_exists('CfDomainPermanentUpgradeService')) {
                $msg = self::actionText('domain_permanent_upgrade.service_missing', '服务暂不可用，请稍后再试。');
                $msg_type = 'danger';
            } elseif (!CfDomainPermanentUpgradeService::isEnabled($module_settings)) {
                $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                $msg_type = 'warning';
            } else {
                $assistCodeRaw = trim((string) ($_POST['perm_upgrade_assist_code'] ?? ''));
                $assistCode = self::extractPermanentUpgradeAssistCode($assistCodeRaw);
                if ($assistCode === '') {
                    $msg = self::actionText('domain_permanent_upgrade.assist.code_required', '请粘贴助力码或完整分享文案。');
                    $msg_type = 'warning';
                } else {
                    try {
                        $helperEmail = self::resolveClientEmail((int) ($userid ?? 0));
                        $helperIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                        $result = CfDomainPermanentUpgradeService::assistByCode(
                            (int) ($userid ?? 0),
                            $assistCode,
                            $helperEmail,
                            $helperIp,
                            $module_settings
                        );
                        $domainDisplay = self::maskDomainForClientMessage((string) ($result['domain'] ?? ''));
                        if (!empty($result['upgraded'])) {
                            $msg = self::actionText(
                                'domain_permanent_upgrade.assist.upgraded',
                                '助力成功！域名 %s 已升级为永久有效。',
                                [$domainDisplay]
                            );
                        } else {
                            $msg = self::actionText(
                                'domain_permanent_upgrade.assist.success',
                                '助力成功：%1$s（%2$s/%3$s）',
                                [
                                    $domainDisplay,
                                    intval($result['assist_count'] ?? 0),
                                    intval($result['target_assists'] ?? 0),
                                ]
                            );
                        }
                        $msg_type = 'success';
                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_domain_permanent_upgrade_assist', [
                                'request_id' => intval($result['request_id'] ?? 0),
                                'owner_userid' => intval($result['owner_userid'] ?? 0),
                                'domain' => (string) ($result['domain'] ?? ''),
                                'assist_count' => intval($result['assist_count'] ?? 0),
                                'target_assists' => intval($result['target_assists'] ?? 0),
                                'upgraded' => !empty($result['upgraded']) ? 1 : 0,
                            ], (int) ($userid ?? 0), null);
                        }
                    } catch (\InvalidArgumentException $e) {
                        $reason = trim((string) $e->getMessage());
                        if ($reason === 'invalid_code') {
                            $msg = self::actionText('domain_permanent_upgrade.assist.invalid_code', '助力码无效，请检查后重试。');
                        } elseif ($reason === 'self_assist') {
                            $msg = self::actionText('domain_permanent_upgrade.assist.self', '不能使用自己的助力码。');
                        } elseif ($reason === 'already_assisted') {
                            $msg = self::actionText('domain_permanent_upgrade.assist.already_assisted', '您已助力过该任务，无需重复提交。');
                        } elseif ($reason === 'already_upgraded' || $reason === 'already_permanent') {
                            $msg = self::actionText('domain_permanent_upgrade.assist.already_upgraded', '该域名已完成永久升级。');
                        } elseif ($reason === 'request_closed') {
                            $msg = self::actionText('domain_permanent_upgrade.assist.request_closed', '该助力任务已关闭，请联系发起人重新生成。');
                        } elseif ($reason === 'helper_limit_reached') {
                            $msg = self::actionText('domain_permanent_upgrade.assist.limit_reached', '您已达到助力次数上限，暂时无法继续助力。');
                        } elseif ($reason === 'feature_disabled') {
                            $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                        } else {
                            $msg = self::actionText('domain_permanent_upgrade.assist.failed', '助力失败：%s', [$reason]);
                        }
                        $msg_type = 'danger';
                    } catch (\Throwable $e) {
                        $msg = self::actionText('domain_permanent_upgrade.assist.failed', '助力失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'domainPermanentUpgradeModal',
            ];
        }

        if ($_POST['action'] === 'cancel_domain_permanent_upgrade_invoice') {
            if (!class_exists('CfDomainPermanentUpgradeService')) {
                $msg = self::actionText('domain_permanent_upgrade.service_missing', '服务暂不可用，请稍后再试。');
                $msg_type = 'danger';
            } elseif (!CfDomainPermanentUpgradeService::isEnabled($module_settings)) {
                $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                $msg_type = 'warning';
            } else {
                $requestId = intval($_POST['perm_upgrade_request_id'] ?? 0);
                try {
                    CfDomainPermanentUpgradeService::cancelInvoicePendingOrder((int) ($userid ?? 0), $requestId);
                    $msg = self::actionText('domain_permanent_upgrade.invoice_pending.cancel.success', '已取消待支付订单，账单已作废。');
                    $msg_type = 'success';
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'request_not_found') {
                        $msg = self::actionText('domain_permanent_upgrade.invoice_pending.cancel.not_found', '未找到待支付订单，请刷新后重试。');
                    } elseif ($reason === 'request_not_invoice_pending') {
                        $msg = self::actionText('domain_permanent_upgrade.invoice_pending.cancel.invalid_status', '该订单当前不可取消。');
                    } else {
                        $msg = self::actionText('domain_permanent_upgrade.invoice_pending.cancel.failed', '取消失败：%s', [$reason]);
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('domain_permanent_upgrade.invoice_pending.cancel.failed', '取消失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'domainPermanentUpgradeModal',
            ];
        }

        if ($_POST['action'] === 'cancel_domain_permanent_upgrade_request') {
            if (!class_exists('CfDomainPermanentUpgradeService')) {
                $msg = self::actionText('domain_permanent_upgrade.service_missing', '服务暂不可用，请稍后再试。');
                $msg_type = 'danger';
            } elseif (!CfDomainPermanentUpgradeService::isEnabled($module_settings)) {
                $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                $msg_type = 'warning';
            } else {
                $requestId = intval($_POST['perm_upgrade_request_id'] ?? 0);
                try {
                    $result = CfDomainPermanentUpgradeService::cancelRequest((int) ($userid ?? 0), $requestId);
                    $msg = self::actionText('domain_permanent_upgrade.cancel.success', '已取消当前升级任务，可重新发起新的助力任务。');
                    $msg_type = 'success';
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('client_domain_permanent_upgrade_cancel', [
                            'request_id' => intval($result['request_id'] ?? 0),
                            'subdomain_id' => intval($result['subdomain_id'] ?? 0),
                            'assist_code' => (string) ($result['assist_code'] ?? ''),
                        ], (int) ($userid ?? 0), intval($result['subdomain_id'] ?? 0));
                    }
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'request_not_found' || $reason === 'invalid_request') {
                        $msg = self::actionText('domain_permanent_upgrade.cancel.invalid', '未找到可取消的升级任务，请刷新后重试。');
                    } elseif ($reason === 'request_not_pending') {
                        $msg = self::actionText('domain_permanent_upgrade.cancel.invalid_status', '仅进行中的任务支持取消。');
                    } elseif ($reason === 'feature_disabled') {
                        $msg = self::actionText('domain_permanent_upgrade.disabled', '当前未启用域名永久升级功能。');
                    } else {
                        $msg = self::actionText('domain_permanent_upgrade.cancel.failed', '取消失败：%s', [$reason]);
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('domain_permanent_upgrade.cancel.failed', '取消失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'domainPermanentUpgradeModal',
            ];
        }

        if ($_POST['action'] === 'pay_domain_permanent_upgrade') {
            if (!class_exists('CfDomainPermanentUpgradeService')) {
                $msg = self::actionText('domain_permanent_upgrade.service_missing', '服务暂不可用，请稍后再试。');
                $msg_type = 'danger';
            } elseif (!CfDomainPermanentUpgradeService::isEnabled($module_settings) || !CfDomainPermanentUpgradeService::isPaidEnabled($module_settings)) {
                $msg = self::actionText('domain_permanent_upgrade.pay.disabled', '当前未启用付费永久升级功能。');
                $msg_type = 'warning';
            } else {
                $subdomainId = intval($_POST['perm_upgrade_subdomain_id'] ?? ($_POST['subdomain_id'] ?? 0));
                try {
                    $result = CfDomainPermanentUpgradeService::payUpgrade((int) ($userid ?? 0), $subdomainId, $module_settings);
                    if (!empty($result['upgraded'])) {
                        $msg = self::actionText('domain_permanent_upgrade.pay.success', '已通过余额完成永久升级。');
                        $msg_type = 'success';
                    } else {
                        $invoiceId = intval($result['invoice_id'] ?? 0);
                        if ($invoiceId > 0) {
                            $msg = self::actionText('domain_permanent_upgrade.pay.invoice', '余额不足，已为您生成账单，请完成支付后自动生效。');
                            $msg_type = 'info';
                            $invoiceUrl = 'viewinvoice.php?id=' . $invoiceId;
                            header('Location: ' . $invoiceUrl);
                            exit;
                        } else {
                            $msg = self::actionText('domain_permanent_upgrade.pay.failed', '处理失败，请稍后重试。');
                            $msg_type = 'danger';
                        }
                    }
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'already_permanent') {
                        $msg = self::actionText('domain_permanent_upgrade.create.already', '该域名已是永久有效，无需重复发起。');
                    } elseif ($reason === 'invalid_subdomain') {
                        $msg = self::actionText('domain_permanent_upgrade.create.invalid_subdomain', '请选择有效域名后再提交。');
                    } elseif ($reason === 'invalid_price') {
                        $msg = self::actionText('domain_permanent_upgrade.pay.invalid_price', '付费价格未配置，请联系管理员。');
                    } else {
                        $msg = self::actionText('domain_permanent_upgrade.pay.failed', '处理失败：%s', [$reason]);
                    }
                    $msg_type = 'warning';
                } catch (\Throwable $e) {
                    $msg = self::actionText('domain_permanent_upgrade.pay.failed', '处理失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'domainPermanentUpgradeModal',
            ];
        }

        if ($_POST['action'] === 'check_and_upgrade_domain_permanent_incentive') {
            if (!class_exists('CfDomainPermanentIncentiveService')) {
                $msg = self::actionText('domain_permanent_incentive.service_missing', '服务暂不可用，请稍后重试。');
                $msg_type = 'danger';
            } elseif (!CfDomainPermanentIncentiveService::isEnabled($module_settings)) {
                $msg = self::actionText('domain_permanent_incentive.disabled', '当前未启用域名永久激励中心。');
                $msg_type = 'warning';
            } elseif (!CfDomainPermanentIncentiveService::isInCampaignWindow($module_settings)) {
                $msg = self::actionText('domain_permanent_incentive.out_of_window', '当前不在活动时间范围内。');
                $msg_type = 'warning';
            } elseif (!CfDomainPermanentIncentiveService::isGrayHit((int) ($userid ?? 0), $module_settings)) {
                $msg = self::actionText('domain_permanent_incentive.gray_denied', '当前活动处于灰度开放中，您暂未被纳入本轮范围。');
                $msg_type = 'warning';
            } else {
                $subdomainId = intval($_POST['perm_incentive_subdomain_id'] ?? 0);
                try {
                    $result = CfDomainPermanentIncentiveService::checkAndUpgrade((int) ($userid ?? 0), $subdomainId, $module_settings, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                    if (!empty($result['success'])) {
                        $msg = self::actionText('domain_permanent_incentive.check.success', '检测通过，域名 %s 已升级为永久有效。', [(string) ($result['domain'] ?? '')]);
                        $msg_type = 'success';
                    } else {
                        $msg = self::actionText(
                            'domain_permanent_incentive.check.failed',
                            '检测未通过（模式：%s，SSL：%s，站点可访问：%s）。',
                            [
                                (string)($result['mode'] ?? 'any'),
                                !empty($result['ssl_ok']) ? self::actionText('common.yes', '是') : self::actionText('common.no', '否'),
                                !empty($result['site_ok']) ? self::actionText('common.yes', '是') : self::actionText('common.no', '否'),
                            ]
                        );
                        $msg_type = 'warning';
                    }
                } catch (\InvalidArgumentException $e) {
                    $reason = trim((string) $e->getMessage());
                    if ($reason === 'invalid_subdomain') {
                        $msg = self::actionText('domain_permanent_incentive.invalid_subdomain', '请选择有效域名后再提交。');
                    } elseif ($reason === 'already_permanent') {
                        $msg = self::actionText('domain_permanent_incentive.already_permanent', '该域名已是永久有效，无需重复升级。');
                    } elseif ($reason === 'rootdomain_not_allowed') {
                        $msg = self::actionText('domain_permanent_incentive.rootdomain_not_allowed', '该域名根域名不在活动白名单内。');
                    } elseif ($reason === 'user_upgrade_limit_reached') {
                        $msg = self::actionText('domain_permanent_incentive.user_upgrade_limit_reached', '您已达到“域名永久激励中心（限时）”可成功升级域名数量上限，请选择其他方式进行升级操作。');
                    } elseif (strpos($reason, 'rate_limited:') === 0) {
                        $remainSeconds = max(1, intval(substr($reason, strlen('rate_limited:'))));
                        $msg = self::actionText('domain_permanent_incentive.rate_limited', '提交过于频繁，请 %s 秒后再试。', [(string) $remainSeconds]);
                    } else {
                        $msg = self::actionText('domain_permanent_incentive.check.error', '检测失败：%s', [$reason]);
                    }
                    $msg_type = 'danger';
                } catch (\Throwable $e) {
                    $msg = self::actionText('domain_permanent_incentive.check.error', '检测失败：%s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
                'openClientModal' => 'domainPermanentIncentiveModal',
            ];
        }


        if ($_POST['action'] === 'submit_partner_application') {
            if ($userid <= 0) {
                $msg = self::actionText('partner.invalid_user', '未找到登录信息，请刷新页面后重试。');
                $msg_type = 'danger';
            } elseif ($isUserBannedOrInactive) {
                $msg = self::actionText('partner.banned', '您的账号已被封禁或停用，暂无法提交合作申请。') . ($banReasonText ? (' ' . $banReasonText) : '');
                $msg_type = 'danger';
            } else {
                $websiteRaw = trim((string) ($_POST['partner_website'] ?? ''));
                $reasonRaw = trim((string) ($_POST['partner_reason'] ?? ''));

                $website = self::normalizePartnerWebsite($websiteRaw);
                if ($website === '') {
                    $msg = self::actionText('partner.invalid_website', '请填写有效的网站地址（例如 https://example.com）。');
                    $msg_type = 'warning';
                } elseif (function_exists('mb_strlen') ? mb_strlen($reasonRaw, 'UTF-8') < 10 : strlen($reasonRaw) < 10) {
                    $msg = self::actionText('partner.invalid_reason', '申请原因过短，请至少填写 10 个字符。');
                    $msg_type = 'warning';
                } else {
                    $recipients = self::resolvePartnerAdminRecipients($module_settings);
                    if (empty($recipients)) {
                        $msg = self::actionText('partner.email_not_configured', '管理员尚未配置合作伙伴计划接收邮箱，请稍后再试。');
                        $msg_type = 'warning';
                    } else {
                        try {
                            $clientEmail = self::resolveClientEmail((int) $userid);
                            $client = Capsule::table('tblclients')
                                ->select('firstname', 'lastname')
                                ->where('id', (int) $userid)
                                ->first();
                            $clientName = trim((string) (($client->firstname ?? '') . ' ' . ($client->lastname ?? '')));
                            $submittedAt = date('Y-m-d H:i:s');
                            $subject = self::actionText('partner.email_subject', '[DNSHE] 新合作伙伴申请 - %s', [$website]);
                            $body = self::buildPartnerApplicationEmailBody([
                                'userid' => (int) $userid,
                                'client_name' => $clientName,
                                'client_email' => $clientEmail,
                                'website' => $website,
                                'reason' => $reasonRaw,
                                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                                'submitted_at' => $submittedAt,
                            ]);
                            self::sendPartnerApplicationEmail($recipients, $subject, $body, $clientEmail);

                            if (function_exists('cloudflare_subdomain_log')) {
                                cloudflare_subdomain_log('partner_plan_application', [
                                    'website' => $website,
                                    'reason' => $reasonRaw,
                                    'submit_to' => $recipients,
                                ], (int) $userid, null);
                            }

                            $msg = self::actionText('partner.submit_success', '申请已提交成功，我们会在审核后尽快与您联系。');
                            $msg_type = 'success';
                        } catch (\Throwable $e) {
                            $msg = self::actionText('partner.submit_failed', '申请提交失败：%s', [$e->getMessage()]);
                            $msg_type = 'danger';
                        }
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'claim_github_star_reward') {
            try {
                if (!class_exists('CfGithubStarRewardService')) {
                    throw new \RuntimeException('service_missing');
                }
                $githubUsername = trim((string) ($_POST['github_username'] ?? ''));
                $result = CfGithubStarRewardService::claim(
                    intval($userid ?? 0),
                    $module_settings,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    $githubUsername
                );
                $msg = self::actionTextByLanguage('github_star_reward.success', '感谢支持！已奖励 %s 个注册额度。', 'Thanks for your support! You have received %s additional registration quota.', [intval($result['reward_amount'] ?? 0)]);
                $msg_type = 'success';
            } catch (CfGithubStarRewardException $e) {
                $reason = $e->getReason();
                if ($reason === 'disabled') {
                    $msg = self::actionTextByLanguage('github_star_reward.disabled', '当前未启用 GitHub 点赞奖励功能。', 'GitHub star reward is currently disabled.');
                } elseif ($reason === 'invalid_repo') {
                    $msg = self::actionTextByLanguage('github_star_reward.invalid_repo', '管理员尚未配置有效的 GitHub 仓库地址。', 'The administrator has not configured a valid GitHub repository URL yet.');
                } elseif ($reason === 'invalid_username') {
                    $msg = self::actionTextByLanguage('github_star_reward.invalid_username', '请先完成 GitHub 授权绑定用户名后再领取。', 'Please authorize and bind your GitHub username before claiming.');
                } elseif ($reason === 'username_used') {
                    $msg = self::actionTextByLanguage('github_star_reward.username_used', '该 GitHub 用户名已被其他账号用于领取奖励，请使用您自己的账号。', 'This GitHub username has already been used by another account. Please use your own GitHub account.');
                } elseif ($reason === 'not_starred') {
                    $msg = self::actionTextByLanguage('github_star_reward.not_starred', '系统未核验到该账号已 Star 指定仓库，请完成点赞后再领取（需公开可见）。', 'We could not verify that this account has starred the configured repository. Please star it first and then claim again (must be publicly visible).');
                } elseif ($reason === 'verify_rate_limited') {
                    $msg = self::actionTextByLanguage('github_star_reward.verify_rate_limited', 'GitHub 核验请求过于频繁，请稍后再试。', 'Too many GitHub verification requests. Please try again later.');
                } elseif ($reason === 'verify_failed') {
                    $msg = self::actionTextByLanguage('github_star_reward.verify_failed', '暂时无法完成 GitHub 核验，请稍后再试。', 'GitHub verification is temporarily unavailable. Please try again later.');
                } elseif ($reason === 'already_claimed') {
                    $msg = self::actionTextByLanguage('github_star_reward.already', '您已经领取过该 GitHub 仓库的点赞奖励。', 'You have already claimed the star reward for this repository.');
                } elseif ($reason === 'quota_unavailable') {
                    $msg = self::actionTextByLanguage('github_star_reward.quota_error', '配额信息读取失败，请稍后重试。', 'Unable to load quota data. Please try again later.');
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('github_star_reward.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh the page and try again.');
                } else {
                    $msg = self::actionTextByLanguage('github_star_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                }
                $msg_type = 'danger';
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('github_star_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'claim_telegram_group_reward') {
            try {
                if (!class_exists('CfTelegramGroupRewardService')) {
                    throw new \RuntimeException('service_missing');
                }
                $authPayload = [
                    'id' => trim((string) ($_POST['telegram_auth_id'] ?? $_POST['telegram_id'] ?? '')),
                    'username' => trim((string) ($_POST['telegram_auth_username'] ?? $_POST['telegram_username'] ?? '')),
                    'first_name' => trim((string) ($_POST['telegram_auth_first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['telegram_auth_last_name'] ?? '')),
                    'photo_url' => trim((string) ($_POST['telegram_auth_photo_url'] ?? '')),
                    'auth_date' => trim((string) ($_POST['telegram_auth_date'] ?? '')),
                    'hash' => trim((string) ($_POST['telegram_auth_hash'] ?? '')),
                ];
                $result = CfTelegramGroupRewardService::claim(
                    intval($userid ?? 0),
                    $module_settings,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    $authPayload
                );
                $msg = self::actionTextByLanguage(
                    'telegram_group_reward.success',
                    '验证成功！已奖励 %s 个注册额度。',
                    'Verification passed! You have received %s additional registration quota.',
                    [intval($result['reward_amount'] ?? 0)]
                );
                $msg_type = 'success';
            } catch (CfTelegramGroupRewardException $e) {
                $reason = $e->getReason();
                if ($reason === 'disabled') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.disabled', '当前未启用 Telegram 社群奖励功能。', 'Telegram group reward is currently disabled.');
                } elseif ($reason === 'invalid_group_link' || $reason === 'invalid_chat_id' || $reason === 'invalid_bot_token') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.invalid_config', '管理员尚未完成 Telegram 奖励配置，请稍后再试。', 'The Telegram reward settings are incomplete. Please try again later.');
                } elseif ($reason === 'auth_required') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.auth_required', '请先在 Telegram Bot 对话中完成账号绑定后再领取奖励。', 'Please complete Telegram bot-chat account binding before claiming the reward.');
                } elseif ($reason === 'auth_invalid') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.auth_invalid', 'Telegram 绑定信息无效，请重新通过 Bot 完成绑定。', 'Telegram binding data is invalid. Please bind again through the bot.');
                } elseif ($reason === 'auth_expired') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.auth_expired', 'Telegram 绑定口令已过期，请重新生成并在 Bot 中确认。', 'Telegram bind token expired. Please generate a new one and confirm in bot chat.');
                } elseif ($reason === 'already_claimed') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.already', '您已经领取过 Telegram 社群奖励。', 'You have already claimed the Telegram group reward.');
                } elseif ($reason === 'telegram_used') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.telegram_used', '该 Telegram 账号已被其他用户领取奖励，请使用自己的账号。', 'This Telegram account has already been used by another user to claim rewards.');
                } elseif ($reason === 'not_joined' || $reason === 'member_not_found') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.not_joined', '未检测到您已加入指定社群，请加入后再重试。', 'We could not verify that you joined the required group. Please join and try again.');
                } elseif ($reason === 'verify_rate_limited') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.verify_rate_limited', 'Telegram 校验请求过于频繁，请稍后再试。', 'Too many Telegram verification requests. Please try again later.');
                } elseif ($reason === 'verify_failed') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.verify_failed', '暂时无法完成 Telegram 校验，请稍后再试。', 'Telegram verification is temporarily unavailable. Please try again later.');
                } elseif ($reason === 'quota_unavailable') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.quota_error', '配额信息读取失败，请稍后重试。', 'Unable to load quota data. Please try again later.');
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh the page and try again.');
                } else {
                    $msg = self::actionTextByLanguage('telegram_group_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                }
                $msg_type = 'danger';
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('telegram_group_reward.error', '领取失败：%s', 'Claim failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'unbind_telegram_binding') {
            try {
                if (!class_exists('CfTelegramGroupRewardService')) {
                    throw new \RuntimeException('service_missing');
                }

                CfTelegramGroupRewardService::unbindUser((int) ($userid ?? 0));
                $msg = self::actionTextByLanguage(
                    'telegram_group_reward.unbind_success',
                    'Telegram 账户已解绑，同时已关闭到期提醒。',
                    'Telegram account unbound successfully, and expiry reminders have been disabled.'
                );
                $msg_type = 'success';
            } catch (CfTelegramGroupRewardException $e) {
                $reason = $e->getReason();
                if ($reason === 'not_bound') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.unbind_not_bound', '当前没有可解绑的 Telegram 账户。', 'There is no Telegram account bound to this user.');
                    $msg_type = 'warning';
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('telegram_group_reward.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh the page and try again.');
                    $msg_type = 'danger';
                } else {
                    $msg = self::actionTextByLanguage('telegram_group_reward.unbind_failed', '解绑失败：%s', 'Unbind failed: %s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('telegram_group_reward.unbind_failed', '解绑失败：%s', 'Unbind failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }

            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }


        if ($_POST['action'] === 'update_expiry_telegram_reminder') {
            try {
                if (!class_exists('CfTelegramExpiryReminderService')) {
                    throw new \RuntimeException('service_missing');
                }

                $enableReminder = isset($_POST['expiry_telegram_reminder_enabled'])
                    && (string) $_POST['expiry_telegram_reminder_enabled'] === '1';
                $authPayload = [
                    'id' => trim((string) ($_POST['telegram_auth_id'] ?? $_POST['telegram_id'] ?? '')),
                    'username' => trim((string) ($_POST['telegram_auth_username'] ?? $_POST['telegram_username'] ?? '')),
                    'first_name' => trim((string) ($_POST['telegram_auth_first_name'] ?? '')),
                    'last_name' => trim((string) ($_POST['telegram_auth_last_name'] ?? '')),
                    'photo_url' => trim((string) ($_POST['telegram_auth_photo_url'] ?? '')),
                    'auth_date' => trim((string) ($_POST['telegram_auth_date'] ?? '')),
                    'hash' => trim((string) ($_POST['telegram_auth_hash'] ?? '')),
                ];

                $state = CfTelegramExpiryReminderService::updateUserSubscription(
                    intval($userid ?? 0),
                    $module_settings,
                    $enableReminder,
                    $authPayload
                );

                if (!empty($state['subscribed'])) {
                    $msg = self::actionTextByLanguage(
                        'expiry_telegram_reminder.enabled',
                        'Telegram 到期提醒已开启。',
                        'Telegram expiry reminders are now enabled.'
                    );
                } else {
                    $msg = self::actionTextByLanguage(
                        'expiry_telegram_reminder.disabled',
                        'Telegram 到期提醒已关闭。',
                        'Telegram expiry reminders have been disabled.'
                    );
                }
                $msg_type = 'success';
            } catch (CfTelegramExpiryReminderException $e) {
                $reason = $e->getReason();
                if ($reason === 'feature_disabled') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.feature_disabled', '管理员暂未启用 Telegram 到期提醒。', 'Telegram expiry reminders are currently disabled by the administrator.');
                    $msg_type = 'warning';
                } elseif ($reason === 'auth_required') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.auth_required', '请先完成 Telegram 授权绑定后再开启提醒。', 'Please complete Telegram authorization before enabling reminders.');
                    $msg_type = 'warning';
                } elseif ($reason === 'auth_invalid') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.auth_invalid', 'Telegram 授权信息无效，请重新授权后再试。', 'Telegram authorization data is invalid. Please authorize again.');
                    $msg_type = 'danger';
                } elseif ($reason === 'auth_expired') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.auth_expired', 'Telegram 授权已过期，请重新授权后再试。', 'Telegram authorization has expired. Please authorize again.');
                    $msg_type = 'danger';
                } elseif ($reason === 'telegram_used') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.telegram_used', '该 Telegram 账号已被其他用户绑定，请使用自己的账号。', 'This Telegram account has already been bound by another user. Please use your own Telegram account.');
                    $msg_type = 'danger';
                } elseif ($reason === 'invalid_bot_token') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.invalid_config', '管理员尚未完成 Telegram Bot 配置，请稍后再试。', 'The Telegram bot settings are incomplete. Please try again later.');
                    $msg_type = 'warning';
                } elseif ($reason === 'invalid_user') {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user was found. Please refresh and try again.');
                    $msg_type = 'danger';
                } else {
                    $msg = self::actionTextByLanguage('expiry_telegram_reminder.error', '设置失败：%s', 'Update failed: %s', [$e->getMessage()]);
                    $msg_type = 'danger';
                }
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('expiry_telegram_reminder.error', '设置失败：%s', 'Update failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }


        if ($_POST['action'] === 'request_ssl_certificate') {
            try {
                if (!class_exists('CfSslCertificateService')) {
                    throw new \RuntimeException('ssl_service_missing');
                }
                if ($isUserBannedOrInactive) {
                    $msg = self::actionTextByLanguage(
                        'ssl.request.banned',
                        '您的账号已被封禁或停用，暂无法申请 SSL 证书。',
                        'Your account is banned or inactive, so SSL requests are unavailable.'
                    ) . ($banReasonText ? (' ' . $banReasonText) : '');
                    $msg_type = 'danger';
                } else {
                    $sslSubdomainId = intval($_POST['ssl_subdomain_id'] ?? 0);
                    $result = CfSslCertificateService::requestCertificate(
                        intval($userid ?? 0),
                        $sslSubdomainId,
                        $module_settings,
                        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
                    );
                    $msg = self::actionTextByLanguage(
                        'ssl.request.success',
                        'SSL 申请成功：%s，证书已签发并保存。',
                        'SSL request succeeded: %s. The certificate has been issued and saved.',
                        [($result['domain'] ?? '')]
                    );
                    $msg_type = 'success';
                }
            } catch (CfSslCertificateException $e) {
                $reason = $e->getReason();
                if ($reason === 'disabled') {
                    $msg = self::actionTextByLanguage('ssl.request.disabled', '当前未启用 SSL 申请功能。', 'SSL request feature is currently disabled.');
                } elseif ($reason === 'invalid_subdomain' || $reason === 'subdomain_not_found') {
                    $msg = self::actionTextByLanguage('ssl.request.subdomain_invalid', '请选择有效域名后再提交 SSL 申请。', 'Please select a valid domain before submitting the SSL request.');
                } elseif ($reason === 'request_exists') {
                    $msg = self::actionTextByLanguage('ssl.request.exists', '该域名已有进行中的 SSL 申请，请稍后刷新查看结果。', 'An SSL request for this domain is already in progress. Please refresh later.');
                } elseif ($reason === 'subdomain_inactive') {
                    $msg = self::actionTextByLanguage('ssl.request.domain_inactive', '该域名当前状态不支持申请 SSL。', 'The current domain status does not support SSL requests.');
                } elseif ($reason === 'acme_library_missing') {
                    $msg = self::actionTextByLanguage(
                        'ssl.request.acme_library_missing',
                        '未检测到可用 ACME PHP 库，请安装 acme-php/core 或 yourivw/leclient。',
                        'No available ACME PHP library detected. Please install acme-php/core or yourivw/leclient.'
                    );
                } elseif ($reason === 'email_missing') {
                    $msg = self::actionTextByLanguage('ssl.request.email_missing', '管理员尚未配置 Let\'s Encrypt 邮箱，暂无法申请。', 'The administrator has not configured a Let\'s Encrypt email yet.');
                } elseif ($reason === 'acme_issue_failed' || $reason === 'dns_challenge_create_failed' || $reason === 'dns_challenge_missing') {
                    $msg = self::actionTextByLanguage('ssl.request.acme_issue_failed', 'Let\'s Encrypt 申请失败，请稍后重试。', 'Let\'s Encrypt issuance failed. Please try again later.');
                } else {
                    $msg = self::actionTextByLanguage('ssl.request.failed', 'SSL 申请失败：%s', 'SSL request failed: %s', [$e->getMessage()]);
                }
                $msg_type = 'danger';
            } catch (\Throwable $e) {
                $msg = self::actionTextByLanguage('ssl.request.failed', 'SSL 申请失败：%s', 'SSL request failed: %s', [$e->getMessage()]);
                $msg_type = 'danger';
            }

            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($_POST['action'] === 'cleanup_orphan_dns_records') {
            if (!cfmod_setting_enabled($module_settings['client_orphan_dns_cleanup_enabled'] ?? '0')) {
                $msg = self::actionTextByLanguage('orphan.cleanup.disabled', '当前未启用域名记录冲突清理功能。', 'Domain DNS conflict cleanup is currently disabled.');
                $msg_type = 'warning';
            } elseif ($userid <= 0) {
                $msg = self::actionTextByLanguage('orphan.cleanup.invalid_user', '未找到登录用户，请刷新页面后重试。', 'No logged-in user found. Please refresh and try again.');
                $msg_type = 'danger';
            } else {
                $subdomainId = intval($_POST['orphan_cleanup_subdomain_id'] ?? 0);
                $confirmDomain = strtolower(trim((string) ($_POST['orphan_cleanup_confirm_domain'] ?? '')));
                if ($subdomainId <= 0 || $confirmDomain === '') {
                    $msg = self::actionTextByLanguage('orphan.cleanup.invalid_input', '参数不完整，请选择域名并手动输入确认域名。', 'Incomplete parameters. Please select a domain and type it manually for confirmation.');
                    $msg_type = 'danger';
                } else {
                    $sub = Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->where('userid', $userid)
                        ->first();
                    $ownedDomain = strtolower(trim((string) ($sub->subdomain ?? '')));
                    if (!$sub || $ownedDomain === '') {
                        $msg = self::actionTextByLanguage('orphan.cleanup.not_found', '未找到可操作的域名。', 'No operable domain was found.');
                        $msg_type = 'danger';
                    } elseif ($ownedDomain !== $confirmDomain) {
                        $msg = self::actionTextByLanguage('orphan.cleanup.confirm_mismatch', '手动输入域名与选择域名不一致，已拒绝执行。', 'Typed domain does not match the selected domain. Request rejected.');
                        $msg_type = 'danger';
                    } else {
                        $dailyLimit = max(0, intval($module_settings['client_orphan_dns_cleanup_daily_limit_per_user'] ?? 3));
                        if ($dailyLimit > 0) {
                            $windowStart = date('Y-m-d H:i:s', time() - 86400);
                            $recentSubmitCount = (int) Capsule::table('mod_cloudflare_jobs')
                                ->where('type', 'client_cleanup_orphan_dns_remote')
                                ->where('created_at', '>=', $windowStart)
                                ->whereRaw('JSON_EXTRACT(payload_json, "$.userid") = ?', [intval($userid)])
                                ->count();
                            if ($recentSubmitCount >= $dailyLimit) {
                                $msg = self::actionTextByLanguage(
                                    'orphan.cleanup.daily_limit_reached',
                                    '24小时内提交次数已达上限（%s 次），请稍后再试。',
                                    'You have reached the 24-hour submission limit (%s). Please try again later.',
                                    [$dailyLimit]
                                );
                                $msg_type = 'warning';
                                return [
                                    'msg' => $msg,
                                    'msg_type' => $msg_type,
                                    'registerError' => $registerError,
                                ];
                            }
                        }

                        $dedupeMinutes = max(0, intval($module_settings['client_orphan_dns_cleanup_same_domain_dedupe_minutes'] ?? 10));
                        if ($dedupeMinutes > 0) {
                            $dedupeStart = date('Y-m-d H:i:s', time() - ($dedupeMinutes * 60));
                            $recentSameDomainSubmit = Capsule::table('mod_cloudflare_jobs')
                                ->where('type', 'client_cleanup_orphan_dns_remote')
                                ->whereIn('status', ['pending', 'running'])
                                ->where('created_at', '>=', $dedupeStart)
                                ->whereRaw('JSON_EXTRACT(payload_json, "$.userid") = ?', [intval($userid)])
                                ->whereRaw('JSON_EXTRACT(payload_json, "$.subdomain_id") = ?', [$subdomainId])
                                ->exists();
                            if ($recentSameDomainSubmit) {
                                $msg = self::actionTextByLanguage(
                                    'orphan.cleanup.duplicate_domain_recent',
                                    '该域名清理任务近期已提交，请勿重复操作（约%s分钟后可重试）。',
                                    'A cleanup job for this domain was recently submitted. Please avoid duplicate requests (retry in about %s minutes).',
                                    [$dedupeMinutes]
                                );
                                $msg_type = 'warning';
                                return [
                                    'msg' => $msg,
                                    'msg_type' => $msg_type,
                                    'registerError' => $registerError,
                                ];
                            }
                        }

                        $perDomainDailyWindowStart = date('Y-m-d 00:00:00');
                        $perDomainDailySubmitted = false;
                        try {
                            $perDomainDailySubmitted = Capsule::table('mod_cloudflare_jobs')
                                ->where('type', 'client_cleanup_orphan_dns_remote')
                                ->where('created_at', '>=', $perDomainDailyWindowStart)
                                ->whereRaw('JSON_EXTRACT(payload_json, "$.userid") = ?', [intval($userid)])
                                ->whereRaw('JSON_EXTRACT(payload_json, "$.subdomain_id") = ?', [$subdomainId])
                                ->exists();
                        } catch (\Throwable $e) {
                            $perDomainDailySubmitted = false;
                        }
                        if (!$perDomainDailySubmitted) {
                            try {
                                if (Capsule::schema()->hasTable('mod_cloudflare_logs')) {
                                    $perDomainDailySubmitted = Capsule::table('mod_cloudflare_logs')
                                        ->where('action', 'client_orphan_cleanup_submit')
                                        ->where('userid', intval($userid))
                                        ->where('subdomain_id', $subdomainId)
                                        ->where('created_at', '>=', $perDomainDailyWindowStart)
                                        ->exists();
                                }
                            } catch (\Throwable $e) {
                                $perDomainDailySubmitted = false;
                            }
                        }
                        if ($perDomainDailySubmitted) {
                            $msg = self::actionTextByLanguage(
                                'orphan.cleanup.domain_daily_once',
                                '该域名今天已提交过清理任务，每个域名每天仅允许提交一次。',
                                'A cleanup request has already been submitted for this domain today. Each domain can only be submitted once per day.'
                            );
                            $msg_type = 'warning';
                            return [
                                'msg' => $msg,
                                'msg_type' => $msg_type,
                                'registerError' => $registerError,
                            ];
                        }

                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_orphan_cleanup_submit', [
                                'subdomain' => (string) ($sub->subdomain ?? ''),
                                'mode' => strtolower(trim((string) ($module_settings['client_orphan_dns_cleanup_mode'] ?? 'queue'))),
                            ], intval($userid), $subdomainId);
                        }

                        $mode = strtolower(trim((string) ($module_settings['client_orphan_dns_cleanup_mode'] ?? 'queue')));
                        if (!in_array($mode, ['queue', 'sync'], true)) {
                            $mode = 'queue';
                        }
                        $forceQueueByLargeZone = false;
                        if ($mode === 'sync') {
                            try {
                                list($syncCf, $syncProviderError, $syncProviderContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                                if ($syncCf) {
                                    $providerType = strtolower(trim((string) ($syncProviderContext['account']['provider_type'] ?? ($syncProviderContext['provider_type'] ?? ''))));
                                    $isPdns = ($providerType === 'powerdns' || $providerType === 'pdns')
                                        || (is_object($syncCf) && stripos(get_class($syncCf), 'powerdns') !== false);
                                    if ($isPdns && method_exists($syncCf, 'getZoneRrsetCount')) {
                                        $zoneThreshold = max(10000, intval($module_settings['client_sync_cleanup_pdns_zone_rrset_threshold'] ?? 50000));
                                        $zoneId = $sub->cloudflare_zone_id ?: ($sub->rootdomain ?? '');
                                        if ($zoneId !== '') {
                                            $rrsetCount = intval($syncCf->getZoneRrsetCount($zoneId));
                                            if ($rrsetCount > $zoneThreshold) {
                                                $forceQueueByLargeZone = true;
                                                if (function_exists('cloudflare_subdomain_log')) {
                                                    cloudflare_subdomain_log('client_orphan_cleanup_force_queue_large_zone', [
                                                        'subdomain' => (string) ($sub->subdomain ?? ''),
                                                        'provider_type' => $providerType,
                                                        'zone_id' => $zoneId,
                                                        'rrset_count' => $rrsetCount,
                                                        'threshold' => $zoneThreshold,
                                                    ], intval($userid), $subdomainId);
                                                }
                                            }
                                        }
                                    }
                                }
                            } catch (\Throwable $e) {
                            }
                        }

                        if ($mode === 'queue' || $forceQueueByLargeZone) {
                            $jobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                                'type' => 'client_cleanup_orphan_dns_remote',
                                'payload_json' => json_encode([
                                    'subdomain_id' => $subdomainId,
                                    'userid' => intval($userid),
                                ], JSON_UNESCAPED_UNICODE),
                                'priority' => 15,
                                'status' => 'pending',
                                'attempts' => 0,
                                'next_run_at' => null,
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                            if (class_exists('CfQueueTriggerService')) {
                                CfQueueTriggerService::trigger();
                            }
                            $msg = self::actionTextByLanguage('orphan.cleanup.queue_submitted', '域名记录冲突清理任务已提交，系统将删除该域名云端全部 DNS 记录（请在10分钟后查看是否成功）。', 'Domain DNS conflict cleanup job submitted. The system will delete all provider-side DNS records for this domain (please check back in 10 minutes).');
                            if ($forceQueueByLargeZone) {
                                $msg .= ' ' . self::actionTextByLanguage('orphan.cleanup.large_zone_force_queue', '检测到当前根域名记录量较大，已自动切换为后台异步执行以避免页面卡顿。', 'A large zone was detected. The request has been switched to asynchronous queue mode automatically to avoid page stalls.');
                            }
                            $msg_type = 'success';
                        } else {
                            $deletedCount = self::cleanupAllRemoteDnsForSubdomain($sub, $module_settings);
                            $msg = self::actionTextByLanguage('orphan.cleanup.sync_done', '已同步完成清理，云端 DNS 删除 %s 条。', 'Synchronous cleanup completed. %s provider-side DNS records were deleted.', [$deletedCount]);
                            $msg_type = 'success';
                        }
                    }
                }
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

// 处理填写他人邀请码以解锁额度
if($_POST['action'] == 'claim_invite') {
    if ($hideInviteFeature) { $msg = self::actionText('invite.closed', '当前邀请功能已关闭'); $msg_type = 'warning'; }
    else {
    $inputCode = strtoupper(trim($_POST['invite_code'] ?? ''));
    // 动态刷新最新的全局邀请上限，并在每次填码前若用户配额未自定义上限，则对其 invite_bonus_limit 进行同步
    try {
        $inviteLimitGlobalSetting = Capsule::table('tbladdonmodules')
            ->where('module', defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub')
            ->where('setting','invite_bonus_limit_global')
            ->value('value');
        if ($inviteLimitGlobalSetting === null) {
            $inviteLimitGlobalSetting = Capsule::table('tbladdonmodules')
                ->where('module', defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain')
                ->where('setting','invite_bonus_limit_global')
                ->value('value');
        }
        $inviteLimitGlobal = intval($inviteLimitGlobalSetting ?? 5);
        $q = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
        if ($q) {
            $currentLimit = intval($q->invite_bonus_limit ?? 0);
            // 仅在用户当前上限等于默认值（或为0）时，同步为最新全局值，避免覆盖管理员为个别用户单独设置的上限
            if ($currentLimit <= 0 || $currentLimit === 5 || $currentLimit === intval($module_settings['invite_bonus_limit_global'] ?? 5)) {
                Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->update([
                    'invite_bonus_limit' => $inviteLimitGlobal,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
            }
        }
    } catch (Exception $e) { /* 忽略 */ }
    if ($isUserBannedOrInactive) { $msg = self::actionText('invite.banned', '您的账号已被封禁或停用，无法解锁额度。') . ($banReasonText ? (' ' . $banReasonText) : ''); $msg_type = 'danger'; }
    elseif ($inputCode === '') { $msg = self::actionText('invite.input_empty', '请输入邀请码'); $msg_type = 'danger'; }
    else {
        try {
            $result = Capsule::connection()->transaction(function() use ($userid, $inputCode, $max, $inviteLimitGlobal) {
                $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->where('code', $inputCode)->first();
                if (!$codeRow) { throw new Exception(self::actionText('invite.invalid_code', '邀请码无效')); }
                if (intval($codeRow->userid) === intval($userid)) { throw new Exception(self::actionText('invite.self', '不能使用自己的邀请码')); }

                // 受邀者不可重复使用同一个邀请码
                $claimedSameCode = Capsule::table('mod_cloudflare_invitation_claims')
                    ->where('invitee_userid', $userid)
                    ->where('code', $inputCode)
                    ->first();
                if ($claimedSameCode) { throw new Exception(self::actionText('invite.used', '您已使用过该邀请码')); }

                $now = date('Y-m-d H:i:s');
                // 统一额度发放入口：先在同事务+行锁下确保双方配额存在
                $inviterQuota = CfQuotaRewardService::ensureQuotaRow((int) $codeRow->userid, (int) $max, ($inviteLimitGlobal > 0 ? (int) $inviteLimitGlobal : 5), $now);
                $inviteeQuota = CfQuotaRewardService::ensureQuotaRow((int) $userid, (int) $max, ($inviteLimitGlobal > 0 ? (int) $inviteLimitGlobal : 5), $now);

                $inviterLimit = intval($inviterQuota->invite_bonus_limit ?? 5);
                $inviteeLimit = intval($inviteeQuota->invite_bonus_limit ?? 5);
                $inviterBonus = intval($inviterQuota->invite_bonus_count ?? 0);
                $inviteeBonus = intval($inviteeQuota->invite_bonus_count ?? 0);

                // 若受邀者已达上限，则双方均不可获得加成
                if ($inviteeBonus >= $inviteeLimit) {
                    throw new Exception(self::actionText('invite.limit_reached', '达到额度上限，无法再增加'));
                }

                $inviterAdded = 0;
                $inviteeAdded = 0;

                // 邀请方加成（不超过上限）
                if ($inviterBonus < $inviterLimit) {
                    CfQuotaRewardService::grantSingleReward((int) $codeRow->userid, $now);
                    $inviterAdded = 1;
                }

                // 受邀方加成（不超过上限）
                if ($inviteeBonus < $inviteeLimit) {
                    CfQuotaRewardService::grantSingleReward((int) $userid, $now);
                    $inviteeAdded = 1;
                }

                // 记录本次使用
                Capsule::table('mod_cloudflare_invitation_claims')->insert([
                    'inviter_userid' => $codeRow->userid,
                    'invitee_userid' => $userid,
                    'code' => $inputCode,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                return ['inviterAdded' => $inviterAdded, 'inviteeAdded' => $inviteeAdded];
            });

            // 刷新本地配额信息
            try {
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
            } catch (Exception $e) {}

            if ($result['inviteeAdded'] && $result['inviterAdded']) {
                $msg = self::actionText('invite.success_both', '解锁成功，您与邀请方各增加 1 个注册额度');
                $msg_type = 'success';
            } elseif ($result['inviteeAdded'] && !$result['inviterAdded']) {
                $msg = self::actionText('invite.success_self', '解锁成功，您增加 1 个注册额度（邀请方已达上限）');
                $msg_type = 'success';
            } else {
                $msg = self::actionText('invite.success_none', '未增加注册额度');
                $msg_type = 'warning';
            }
        } catch (Exception $e) {
            // 邀请码相关错误不应使用 cfmod_format_provider_error，直接显示原始错误信息
            $msg = $e->getMessage();
            $msg_type = 'danger';
        }
    }}
}

if (!function_exists('cfmod_is_subdomain_deletion_locked_for_user')) {
    function cfmod_is_subdomain_deletion_locked_for_user(int $subdomainId, int $userId): bool
    {
        if ($subdomainId <= 0 || $userId <= 0) {
            return false;
        }
        try {
            $row = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userId)
                ->select('id', 'status', 'expires_at', 'never_expires')
                ->first();
            if (!$row) {
                return false;
            }
            $statusLower = strtolower(trim((string) ($row->status ?? '')));
            $lockedStatuses = [
                'pending_delete',
                'pending_remove',
                'expired_pending_remote_cleanup',
                'auto_pending_delete',
                'delete_pending',
                'deleting',
            ];
            if (in_array($statusLower, $lockedStatuses, true)) {
                return true;
            }
            // Fallback lock for overdue domains that exceeded grace + redemption window
            // even when status has not yet been switched by async cleanup worker.
            if (intval($row->never_expires ?? 0) !== 1) {
                $expiresTs = strtotime((string) ($row->expires_at ?? ''));
                if ($expiresTs !== false) {
                    $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
                    $graceDaysRaw = $settings['domain_grace_period_days'] ?? ($settings['domain_auto_delete_grace_days'] ?? 45);
                    $graceDays = is_numeric($graceDaysRaw) ? (int) $graceDaysRaw : 45;
                    if ($graceDays < 0) { $graceDays = 0; }
                    $redemptionDaysRaw = $settings['domain_redemption_days'] ?? 0;
                    $redemptionDays = is_numeric($redemptionDaysRaw) ? (int) $redemptionDaysRaw : 0;
                    if ($redemptionDays < 0) { $redemptionDays = 0; }
                    $deadlineTs = $expiresTs + (($graceDays + $redemptionDays) * 86400);
                    if (time() > $deadlineTs) {
                        return true;
                    }
                }
            }
            $hasDeleteJob = Capsule::table('mod_cloudflare_jobs')
                ->whereIn('type', ['delete_subdomain', 'cleanup_expired_subdomains', 'client_cleanup_orphan_dns_remote'])
                ->whereIn('status', ['pending', 'running'])
                ->where(function ($q) use ($subdomainId) {
                    $q->where('subdomain_id', $subdomainId)
                      ->orWhere('payload_json', 'like', '%"subdomain_id":' . $subdomainId . '%')
                      ->orWhere('payload_json', 'like', '%"subdomain_id":"' . $subdomainId . '"%');
                })
                ->exists();
            return $hasDeleteJob;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cfmod_deletion_locked_response_text')) {
    function cfmod_deletion_locked_response_text(): string
    {
        return CfClientActionService::actionText('delete.locked_pending_cleanup', '域名已进入删除队列，暂不可执行此操作，请等待清理完成。');
    }
}

// 兑换礼品申请
if($_POST['action'] == 'request_invite_reward') {
    try {
        // 计算上期结算的周期：支持自定义周期开始
        if ($inviteCycleStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inviteCycleStart)) {
            $startTs = strtotime($inviteCycleStart);
            $todayTs = strtotime(date('Y-m-d'));
            if ($todayTs >= $startTs) {
                $k = (int) floor((($todayTs - $startTs) / 86400) / $inviteLeaderboardDays);
                // 计算上期的开始和结束日期
                $periodStart = date('Y-m-d', strtotime('+' . (($k - 1) * $inviteLeaderboardDays) . ' days', $startTs));
                $periodEnd = date('Y-m-d', strtotime('+' . (($k * $inviteLeaderboardDays) - 1) . ' days', $startTs));
            } else {
                // 如果当前日期在周期开始日期之前，则无法申请
                $periodStart = date('Y-m-d', strtotime('yesterday'));
                $periodEnd = date('Y-m-d', strtotime('yesterday'));
            }
        } else {
            // 默认按周计算，上期结束日为昨天
            $periodEnd = date('Y-m-d', strtotime('yesterday'));
            $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . ($inviteLeaderboardDays - 1) . ' days'));
        }
        // 只查奖励表（历史快照数据），不查实时统计
        $winners = Capsule::table('mod_cloudflare_invite_rewards as r')
            ->select('r.inviter_userid','r.rank','r.count','r.code')
            ->where('r.period_start', $periodStart)
            ->where('r.period_end', $periodEnd)
            ->orderBy('r.rank','asc')
            ->limit(5)
            ->get();
        // 如果没有历史快照数据，则无法申请兑换
        $rank = null; $count = 0; $codeVal = '';
        $i = 1; foreach ($winners as $w) {
            $thisRank = isset($w->rank) ? intval($w->rank) : $i;
            $thisCount = isset($w->count) ? intval($w->count) : intval($w->cnt ?? 0);
            if (intval($w->inviter_userid) === intval($userid)) { $rank = $thisRank; $count = $thisCount; break; }
            $i++;
        }
        if ($rank === null) { $msg = self::actionText('invite.reward.not_ranked', '上期未上榜，无法申请兑换'); $msg_type = 'warning'; }
        else {
            $codeRow = Capsule::table('mod_cloudflare_invitation_codes')->select('code')->where('userid', $userid)->first();
            $codeVal = $codeRow ? ($codeRow->code ?? '') : '';
            $existing = Capsule::table('mod_cloudflare_invite_rewards')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->where('inviter_userid', $userid)
                ->first();
            if ($existing) {
                if ($existing->status === 'claimed') { $msg = self::actionText('invite.reward.already_claimed', '本期奖励已领取'); $msg_type = 'success'; }
                else {
                    Capsule::table('mod_cloudflare_invite_rewards')
                        ->where('id', $existing->id)
                        ->update(['status' => 'pending', 'requested_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    $msg = self::actionText('invite.reward.submitted', '兑换申请已提交，请等待处理'); $msg_type = 'success';
                }
            } else {
                Capsule::table('mod_cloudflare_invite_rewards')->insert([
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'inviter_userid' => $userid,
                    'code' => $codeVal,
                    'rank' => $rank,
                    'count' => $count,
                    'status' => 'pending',
                    'requested_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $msg = self::actionText('invite.reward.submitted', '兑换申请已提交，请等待处理'); $msg_type = 'success';
            }
        }
    } catch (Exception $e) {
        // 邀请奖励相关错误不应使用 cfmod_format_provider_error，直接显示原始错误信息
        $errorText = $e->getMessage();
        if (trim($errorText) === '') {
            $errorText = self::actionText('invite.reward.retry', '申请失败，请稍后重试。');
        }
        $msg = self::actionText('invite.reward.failed', '申请失败：%s', [$errorText]);
        $msg_type = 'danger';
    }

}

// 处理注册请求 - 不创建解析，仅记录保存
if($_POST['action'] == "register") {
    if ($pauseFreeRegistration) {
        $msg = self::actionText('register.paused', '当前已暂停免费域名注册，请稍后再试。');
        $msg_type = 'warning';
        $registerError = $msg;
    } else {
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('register.banned', '您的账号已被封禁或停用，禁止注册新域名。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
            $registerError = $msg;
        } else {
            // VPN/代理检测
            $vpnCheckPassed = true;
            $vpnCheckResult = null;
            if (class_exists('CfVpnDetectionService') && CfVpnDetectionService::isEnabled($module_settings)) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                $vpnCheckResult = CfVpnDetectionService::shouldBlockRegistration($clientIp, $module_settings);
                if (!empty($vpnCheckResult['blocked'])) {
                    $vpnCheckPassed = false;
                    $msg = self::actionText('register.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再尝试注册域名。');
                    $msg_type = 'warning';
                    $registerError = $msg;
                    // 记录日志
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('vpn_detection_blocked', [
                            'ip' => $clientIp,
                            'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                            'is_vpn' => $vpnCheckResult['is_vpn'] ?? false,
                            'is_proxy' => $vpnCheckResult['is_proxy'] ?? false,
                            'is_hosting' => $vpnCheckResult['is_hosting'] ?? false,
                        ], $userid ?? 0, null);
                    }
                }
            }

            if ($vpnCheckPassed) {
            $inviteGateBlocked = false;
            if (($userid ?? 0) > 0 && CfInviteRegistrationService::isGateEnabled($module_settings)) {
                try {
                    $inviteGateBlocked = !CfInviteRegistrationService::userHasUnlocked((int) $userid);
                } catch (\Throwable $e) {
                    $inviteGateBlocked = true;
                }
            }

            if ($inviteGateBlocked) {
                $msg = self::actionText('invite_registration.gate_locked', '首次使用前请先完成准入验证。');
                $msg_type = 'warning';
                $registerError = $msg;
            } else {
            $subprefix = trim($_POST['subdomain']);
            $rootdomain = trim($_POST['rootdomain']);
            $subprefixLen = strlen($subprefix);

            // 根域名邀请码验证 - 提前验证
            $rootdomainInviteVerified = true;
            $rootdomainInviteRequired = false;
            try {
                if (class_exists('CfRootdomainInviteService') && $rootdomain !== '') {
                    $rootdomainInviteRequired = CfRootdomainInviteService::isInviteRequired($rootdomain);
                }
            } catch (\Throwable $e) {
                $rootdomainInviteRequired = false;
            }
            
            if ($rootdomainInviteRequired && $subprefix !== '' && $rootdomain !== '') {
                $rootdomainInviteCode = trim($_POST['rootdomain_invite_code'] ?? '');
                if ($rootdomainInviteCode === '') {
                    $msg = self::actionText('rootdomain_invite.code_required', '该根域名需要邀请码才能注册，请输入邀请码。');
                    $msg_type = 'warning';
                    $registerError = $msg;
                    $rootdomainInviteVerified = false;
                } else {
                    // 立即验证邀请码（但不记录使用，等注册成功后再记录）
                    try {
                        if (!class_exists('CfRootdomainInviteService')) {
                            throw new \Exception('邀请服务不可用');
                        }
                        
                        $clientEmail = self::resolveClientEmail($userid);
                        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                        $fullsub = strtolower($subprefix) . '.' . strtolower($rootdomain);
                        
                        // 先进行预验证（不使用邀请码）
                        $codeRow = Capsule::table('mod_cloudflare_rootdomain_invite_codes')
                            ->where('invite_code', strtoupper($rootdomainInviteCode))
                            ->where('rootdomain', $rootdomain)
                            ->first();

                        if (!$codeRow) {
                            throw new \InvalidArgumentException('invalid_code');
                        }

                        $inviterId = (int) ($codeRow->userid ?? 0);

                        // 不能使用自己的邀请码
                        if ($inviterId === $userid) {
                            throw new \InvalidArgumentException('self_code');
                        }

                        // 检查邀请人状态
                        $inviterStatus = Capsule::table('tblclients')->where('id', $inviterId)->value('status');
                        if ($inviterStatus !== null && strtolower((string) $inviterStatus) !== 'active') {
                            throw new \InvalidArgumentException('inviter_banned');
                        }

                        // 检查封禁状态
                        if (function_exists('cfmod_resolve_user_ban_state')) {
                            $banState = cfmod_resolve_user_ban_state($inviterId);
                            if (!empty($banState['is_banned'])) {
                                throw new \InvalidArgumentException('inviter_banned');
                            }
                        }

                        // 检查邀请人是否达到邀请上限
                        if (!CfRootdomainInviteService::checkInviterLimit($inviterId, $rootdomain)) {
                            throw new \InvalidArgumentException('inviter_limit_reached');
                        }

                        // 验证通过，存储待记录信息，在注册成功后才调用
                        $_POST['__rootdomain_invite_verified__'] = [
                            'userid' => $userid,
                            'rootdomain' => $rootdomain,
                            'code' => $rootdomainInviteCode,
                            'subdomain' => $fullsub,
                            'email' => $clientEmail,
                            'ip' => $clientIp,
                        ];
                        $rootdomainInviteVerified = true;
                    } catch (\InvalidArgumentException $e) {
                        $errorKey = $e->getMessage();
                        $errorMessages = [
                            'invalid_code' => '邀请码无效，请检查后重试。',
                            'self_code' => '不能使用自己的邀请码。',
                            'inviter_banned' => '邀请人账户状态异常，无法使用该邀请码。',
                            'inviter_limit_reached' => '该邀请码已达使用上限。',
                        ];
                        $msg = self::actionText('rootdomain_invite.' . $errorKey, $errorMessages[$errorKey] ?? '邀请码验证失败。');
                        $msg_type = 'danger';
                        $registerError = $msg;
                        $rootdomainInviteVerified = false;
                    } catch (\Throwable $e) {
                        $msg = self::actionText('rootdomain_invite.error', '邀请码验证失败：%s', [$e->getMessage()]);
                        $msg_type = 'danger';
                        $registerError = $msg;
                        $rootdomainInviteVerified = false;
                    }
                }
            }

            if ($subprefix === '' || $rootdomain === '' || ($rootdomainInviteRequired && !$rootdomainInviteVerified)) {
                if ($subprefix === '' || $rootdomain === '') {
                    $msg = self::actionText('register.missing_fields', '请填写完整信息');
                } else {
                    $msg = self::actionText('rootdomain_invite.verification_required', '邀请码验证未通过，无法继续注册。');
                }
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif (is_object($quota) && intval($quota->max_count ?? 0) > 0 && intval($quota->used_count ?? 0) >= intval($quota->max_count ?? 0)) {
                $msg = self::actionText('register.limit_reached', '已达到最大注册数量限制 (%s)', [intval($quota->max_count ?? 0)]);
                $msg_type = 'warning';
                $registerError = $msg;
            } elseif (in_array(strtolower($subprefix), array_map('strtolower', $forbidden))) {
                $msg = self::actionText('register.forbidden_prefix', "该前缀 '%s' 禁止使用", [$subprefix]);
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif (!preg_match('/^[a-zA-Z0-9\-]+$/', $subprefix)) {
                $msg = self::actionText('register.invalid_chars', '子域名前缀只能包含字母、数字和连字符');
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif (cfmod_has_invalid_edge_character($subprefix)) {
                $msg = self::actionText('register.edge_error', "子域名前缀不能以 '.' 或 '-' 开头或结尾");
                $msg_type = 'danger';
                $registerError = $msg;
            } elseif ($subprefixLen < $subPrefixMinLength || $subprefixLen > $subPrefixMaxLength) {
                $msg = self::actionText('register.length_error', '子域名前缀长度必须在%1$s-%2$s个字符之间', [$subPrefixMinLength, $subPrefixMaxLength]);
                $msg_type = 'danger';
                $registerError = $msg;
            } else {
                $fullsub = strtolower($subprefix) . '.' . strtolower($rootdomain);

                $isForbidden = Capsule::table('mod_cloudflare_forbidden_domains')->where('domain', $fullsub)->count() > 0;
                if ($isForbidden) {
                    $msg = self::actionText('register.forbidden_domain', '该域名已被禁止注册');
                    $msg_type = 'danger';
                    $registerError = $msg;
                } elseif (Capsule::table('mod_cloudflare_subdomain')->where('subdomain', $fullsub)->count() > 0) {
                    $msg = self::actionText('register.duplicate', "域名 '%s' 已被注册,请更换后重试.", [$fullsub]);
                    $msg_type = 'danger';
                    $registerError = $msg;
                } else {
                    try {
                        $rootAllowed = in_array($rootdomain, $roots);
                        if (!$rootAllowed) {
                            $msg = self::actionText('register.root_not_allowed', '根域名未被允许注册');
                            $msg_type = 'danger';
                            $registerError = $msg;
                        } else {
                            $rootRegistrationBlocked = false;
                            try {
                                $dbHasRoots = Capsule::table('mod_cloudflare_rootdomains')->count() > 0;
                                if ($dbHasRoots) {
                                    $st = Capsule::table('mod_cloudflare_rootdomains')
                                        ->select('status', 'gray_enabled', 'gray_ratio')
                                        ->whereRaw('LOWER(domain)=?', [strtolower($rootdomain)])
                                        ->first();
                                    $rootStatus = strtolower((string)($st->status ?? ''));
                                    if ($st && $rootStatus !== 'active' && !$privilegedAllowRegisterSuspendedRoot) {
                                        $msg = self::actionText('register.root_suspended', '该根域名已停止新注册');
                                        $msg_type = 'danger';
                                        $registerError = $msg;
                                        $rootRegistrationBlocked = true;
                                    }
                                    if (!$rootRegistrationBlocked && $st && function_exists('cfmod_rootdomain_gray_hit')
                                        && !cfmod_rootdomain_gray_hit($userid, $rootdomain, (int)($st->gray_enabled ?? 0), (int)($st->gray_ratio ?? 100))
                                    ) {
                                        $msg = self::actionText('register.root_gray_denied', '当前根域名处于灰度开放中，您暂未被纳入本轮开放范围');
                                        $msg_type = 'warning';
                                        $registerError = $msg;
                                        $rootRegistrationBlocked = true;
                                    }
                                }
                            } catch (\Throwable $e) {
                                if (function_exists('cloudflare_subdomain_log')) {
                                    cloudflare_subdomain_log('client_register_rootdomain_gate_check_failed', [
                                        'rootdomain' => $rootdomain,
                                        'error' => $e->getMessage(),
                                    ], $userid ?? 0, null);
                                }
                            }

                            if (!$rootRegistrationBlocked) {
                                $limitCheck = function_exists('cfmod_check_rootdomain_user_limit')
                                    ? cfmod_check_rootdomain_user_limit($userid, $rootdomain, 1)
                                    : ['allowed' => true, 'limit' => 0];

                                if (!$limitCheck['allowed']) {
                                    $limitMessage = cfmod_format_rootdomain_limit_message($rootdomain, $limitCheck['limit']);
                                    if ($limitMessage === '') {
                                        $limitValueText = max(1, intval($limitCheck['limit'] ?? 0));
                                        $limitMessage = self::actionText('register.root_user_limit', '%1$s 每个账号最多注册 %2$s 个，您已达到上限', [$rootdomain, $limitValueText]);
                                    }
                                    $msg = $limitMessage;
                                    $msg_type = 'warning';
                                    $registerError = $msg;
                                } else {
                                $providerContext = cfmod_make_provider_client(null, $rootdomain, null, $module_settings);
                                if (!$providerContext || empty($providerContext['client'])) {
                                    $msg = self::actionText('register.provider_missing', '当前根域未配置有效的 DNS 供应商，请联系管理员');
                                    $msg_type = 'danger';
                                    $registerError = $msg;
                                } else {
                                    $cf = $providerContext['client'];
                                    $selectedProviderId = intval($providerContext['provider_account_id'] ?? 0);
                                    $zone_id = $cf->getZoneId($rootdomain);

                                    if ($zone_id) {
                                        $skipProviderExistsCheck = self::shouldSkipProviderExistsCheck($providerContext, $module_settings, $rootdomain);
                                        $existsOnCF = false;
                                        if (!$skipProviderExistsCheck) {
                                            $existsOnCF = $cf->checkDomainExists($zone_id, $fullsub);
                                        }
                                        if ($existsOnCF) {
                                            $msg = self::actionText('register.provider_exists', '该域名在阿里云DNS上已存在解析记录，无法注册');
                                            $msg_type = 'danger';
                                            $registerError = $msg;
                                        } else {
                                            $created = null;
                                            try {
                                                $created = cf_atomic_register_subdomain(
                                                    $userid,
                                                    $fullsub,
                                                    $rootdomain,
                                                    $zone_id,
                                                    $module_settings,
                                                    [
                                                        'dns_record_id' => null,
                                                        'notes' => '已注册，等待解析设置',
                                                        'provider_account_id' => $selectedProviderId
                                                    ]
                                                );
                                            } catch (CfAtomicQuotaExceededException $e) {
                                                $totalLimit = null;
                                                if (is_object($quota) && isset($quota->max_count)) {
                                                    $totalLimit = $quota->max_count;
                                                } elseif (isset($module_settings['max_subdomain_per_user'])) {
                                                    $totalLimit = $module_settings['max_subdomain_per_user'];
                                                }
                                                $limitText = $totalLimit !== null ? intval($totalLimit) : self::actionText('common.configured_limit', '已配置的上限');
                                                $msg = self::actionText('register.limit_reached', '已达到最大注册数量限制 (%s)', [$limitText]);
                                                $msg_type = 'warning';
                                                $registerError = $msg;
                                            } catch (CfAtomicAlreadyRegisteredException $e) {
                                                $msg = self::actionText('register.duplicate', "域名 '%s' 已被注册,请更换后重试.", [$fullsub]);
                                                $msg_type = 'danger';
                                                $registerError = $msg;
                                            } catch (CfAtomicInvalidPrefixLengthException $e) {
                                                $msg = self::actionText('register.length_error', '子域名前缀长度必须在%1$s-%2$s个字符之间', [$subPrefixMinLength, $subPrefixMaxLength]);
                                                $msg_type = 'danger';
                                                $registerError = $msg;
                                            }

                                            if ($created) {
                                                if (is_object($quota)) {
                                                    $quota->used_count = $created['used_count'];
                                                    $quota->max_count = $created['max_count'];
                                                } else {
                                                    $quota = (object) [
                                                        'used_count' => $created['used_count'],
                                                        'max_count' => $created['max_count'],
                                                        'invite_bonus_count' => 0,
                                                        'invite_bonus_limit' => intval($module_settings['invite_bonus_limit_global'] ?? 5)
                                                    ];
                                                }

                                                if (function_exists('cloudflare_subdomain_log')) {
                                                    cloudflare_subdomain_log('client_register_subdomain', ['subdomain' => $fullsub, 'root' => $rootdomain], $userid, $created['id']);
                                                }

                                                // 处理根域名邀请码（注册成功后）
                                                if (isset($_POST['__rootdomain_invite_verified__']) && is_array($_POST['__rootdomain_invite_verified__'])) {
                                                    try {
                                                        $inviteData = $_POST['__rootdomain_invite_verified__'];
                                                        if (class_exists('CfRootdomainInviteService')) {
                                                            CfRootdomainInviteService::validateAndUseInviteCode(
                                                                (int) $inviteData['userid'],
                                                                (string) $inviteData['rootdomain'],
                                                                (string) $inviteData['code'],
                                                                (string) $inviteData['subdomain'],
                                                                (string) $inviteData['email'],
                                                                (string) $inviteData['ip']
                                                            );
                                                        }
                                                    } catch (\Throwable $e) {
                                                        // 邀请码记录失败不影响注册结果，仅记录日志
                                                        if (function_exists('cloudflare_subdomain_log')) {
                                                            cloudflare_subdomain_log('rootdomain_invite_code_log_failed', ['error' => $e->getMessage()], $userid, $created['id']);
                                                        }
                                                    }
                                                    unset($_POST['__rootdomain_invite_verified__']);
                                                }

                                                $msg = self::actionText('register.success_detail', "注册成功！域名 '%s' 已创建，现在您可以设置解析了", [$fullsub]);
                                                $msg_type = 'success';
                                                $registerError = '';

                                                list($existing, $existing_total, $domainTotalPages, $domainPage) = cfmod_client_load_subdomains_paginated(
                                                    $userid,
                                                    1,
                                                    $domainPageSize,
                                                    $domainSearchTerm
                                                );
                                            }
                                        }
                                    } else {
                                        $msg = self::actionText('register.error_generic', '错误：错误代码#1001,请稍后重试。');
                                        $msg_type = 'danger';
                                        $registerError = $msg;
                                    }
                                }
                            }
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
            }
            } // end if ($vpnCheckPassed)
        }
    }
}
// 处理续期请求
if($_POST['action'] == "renew" && isset($_POST['subdomain_id'])) {
    $subdomainId = intval($_POST['subdomain_id']);
    $nowTs = time();
    $renewRedirectTo = '';
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    } catch (Exception $e) {}

    $renewSig = 'renew|' . $subdomainId;
    if (!empty($_SESSION['cfmod_last_renew_sig']) && $_SESSION['cfmod_last_renew_sig'] === $renewSig && isset($_SESSION['cfmod_last_renew_time']) && ($nowTs - intval($_SESSION['cfmod_last_renew_time'])) < 5) {
        $msg = self::actionText('common.duplicate_submit', '操作已提交，请勿重复点击');
        $msg_type = 'warning';
    } else {
        $_SESSION['cfmod_last_renew_sig'] = $renewSig;
        $_SESSION['cfmod_last_renew_time'] = $nowTs;

        $termYearsRaw = $module_settings['domain_registration_term_years'] ?? 1;
        $termYears = is_numeric($termYearsRaw) ? (int)$termYearsRaw : 1;
        if ($termYears <= 0) {
            $msg = self::actionText('renew.term_missing', '当前未配置有效的续期年限，请联系管理员');
            $msg_type = 'danger';
            unset($_SESSION['cfmod_last_renew_sig'], $_SESSION['cfmod_last_renew_time']);
        } else {
            $freeWindowDays = max(0, intval($module_settings['domain_free_renew_window_days'] ?? 30));
            $graceDaysRaw = $module_settings['domain_grace_period_days'] ?? ($module_settings['domain_auto_delete_grace_days'] ?? 45);
            $graceDays = is_numeric($graceDaysRaw) ? (int)$graceDaysRaw : 45;
            if ($graceDays < 0) { $graceDays = 0; }
            $redemptionDaysRaw = $module_settings['domain_redemption_days'] ?? 0;
            $redemptionDays = is_numeric($redemptionDaysRaw) ? (int)$redemptionDaysRaw : 0;
            if ($redemptionDays < 0) { $redemptionDays = 0; }
            $freeWindowSeconds = $freeWindowDays * 86400;
            $graceSeconds = $graceDays * 86400;
            $redemptionSeconds = $redemptionDays * 86400;

            try {
                $renewResult = Capsule::transaction(function () use ($subdomainId, $userid, $termYears, $freeWindowSeconds, $graceSeconds, $redemptionSeconds, $nowTs, $redemptionModeSetting, $redemptionFeeSetting, $freeWindowDays) {
                    $nowStr = date('Y-m-d H:i:s');
                    $subdomain = Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->where('userid', $userid)
                        ->lockForUpdate()
                        ->first();

                    if (!$subdomain) {
                        throw new \RuntimeException(self::actionText('renew.not_found', '未找到该域名或无权操作'));
                    }
                    if (strtolower((string) ($subdomain->status ?? '')) === 'suspended') {
                        throw new \RuntimeException(self::actionText('domain.server_hold.locked', '域名当前为 ServerHold 状态，禁止操作。'));
                    }

                    if (intval($subdomain->never_expires ?? 0) === 1) {
                        throw new \RuntimeException(self::actionText('renew.never_expires', '此域名为永久有效，无需续期'));
                    }

                    $statusLower = strtolower($subdomain->status ?? '');
                    if (!in_array($statusLower, ['active', 'pending'], true)) {
                        throw new \RuntimeException(self::actionText('renew.status_invalid', '当前状态不允许续期'));
                    }

                    $expiresRaw = $subdomain->expires_at ?? null;
                    if (!$expiresRaw) {
                        throw new \RuntimeException(self::actionText('renew.no_expiry', '尚未设置到期时间，请联系管理员'));
                    }

                    $expiresTs = strtotime($expiresRaw);
                    if ($expiresTs === false) {
                        throw new \RuntimeException(self::actionText('renew.parse_failed', '无法解析当前到期时间'));
                    }

                    if ($freeWindowSeconds > 0 && $nowTs < ($expiresTs - $freeWindowSeconds)) {
                        $remainingSeconds = max(0, ($expiresTs - $freeWindowSeconds) - $nowTs);
                        $remainingDays = (int) ceil($remainingSeconds / 86400);
                        throw new \RuntimeException(self::actionText(
                            'renew.not_in_window',
                            '尚未进入免费续期窗口，还需 %1$s 天（免费窗口：到期前 %2$s 天）。',
                            [$remainingDays, $freeWindowDays]
                        ));
                    }

                    $graceDeadlineTs = $expiresTs + $graceSeconds;
                    $chargeAmount = 0.0;
                    if ($nowTs > $graceDeadlineTs) {
                        $redemptionDeadlineTs = $graceDeadlineTs + $redemptionSeconds;
                        if ($redemptionSeconds > 0 && $nowTs <= $redemptionDeadlineTs) {
                            if ($redemptionModeSetting === 'auto_charge') {
                                if ($redemptionFeeSetting > 0) {
                                    $clientRow = Capsule::table('tblclients')
                                        ->where('id', $userid)
                                        ->lockForUpdate()
                                        ->first();
                                    if (!$clientRow) {
                                        throw new \RuntimeException(self::actionText('renew.balance_unavailable', '无法读取账户余额信息，请稍后重试'));
                                    }
                                    $currentCredit = (float) ($clientRow->credit ?? 0.0);
                                    if ($currentCredit + 1e-8 < $redemptionFeeSetting) {
                                        $invoiceDesc = self::actionText('renew.redemption_invoice_desc', '赎回期续费：%1$s（ID:%2$s）', [(string) ($subdomain->subdomain ?? ''), (string) intval($subdomain->id ?? 0)]);
                                        $existingPendingInvoice = Capsule::table('tblinvoices as i')
                                            ->join('tblinvoiceitems as it', 'it.invoiceid', '=', 'i.id')
                                            ->where('i.userid', (int) $userid)
                                            ->whereIn('i.status', ['Unpaid', 'Draft', 'Payment Pending'])
                                            ->where('it.userid', (int) $userid)
                                            ->where('it.relid', intval($subdomain->id ?? 0))
                                            ->where('it.description', $invoiceDesc)
                                            ->whereRaw('ABS(it.amount - ?) < 0.00001', [number_format($redemptionFeeSetting, 2, '.', '')])
                                            ->orderBy('i.id', 'desc')
                                            ->select('i.id')
                                            ->first();
                                        if ($existingPendingInvoice) {
                                            $existingInvoiceId = (int) ($existingPendingInvoice->id ?? 0);
                                            if (class_exists('CfRenewalInvoiceService')) {
                                                CfRenewalInvoiceService::registerPendingInvoice(
                                                    intval($subdomain->id ?? 0),
                                                    (int) $userid,
                                                    $existingInvoiceId,
                                                    $termYears,
                                                    (float) $redemptionFeeSetting
                                                );
                                            }
                                            return [
                                                'need_invoice_payment' => true,
                                                'invoice_id' => $existingInvoiceId,
                                                'new_expires_at' => null,
                                                'previous_expires_at' => $expiresRaw,
                                                'subdomain' => $subdomain->subdomain,
                                                'charged_amount' => 0.0,
                                            ];
                                        }
                                        $today = date('Y-m-d');
                                        $invoiceId = Capsule::table('tblinvoices')->insertGetId([
                                            'userid' => (int) $userid,
                                            'date' => $today,
                                            'duedate' => date('Y-m-d', strtotime('+7 days')),
                                            'subtotal' => $redemptionFeeSetting,
                                            'total' => $redemptionFeeSetting,
                                            'tax' => 0,
                                            'tax2' => 0,
                                            'status' => 'Unpaid',
                                        ]);
                                        Capsule::table('tblinvoiceitems')->insert([
                                            'invoiceid' => $invoiceId,
                                            'userid' => (int) $userid,
                                            'type' => 'Item',
                                            'relid' => intval($subdomain->id ?? 0),
                                            'description' => $invoiceDesc,
                                            'amount' => number_format($redemptionFeeSetting, 2, '.', ''),
                                            'taxed' => 0,
                                            'duedate' => date('Y-m-d', strtotime('+7 days')),
                                            'paymentmethod' => '',
                                        ]);
                                        if (class_exists('CfRenewalInvoiceService')) {
                                            CfRenewalInvoiceService::registerPendingInvoice(
                                                intval($subdomain->id ?? 0),
                                                (int) $userid,
                                                (int) $invoiceId,
                                                $termYears,
                                                (float) $redemptionFeeSetting
                                            );
                                        }
                                        return [
                                            'need_invoice_payment' => true,
                                            'invoice_id' => (int) $invoiceId,
                                            'new_expires_at' => null,
                                            'previous_expires_at' => $expiresRaw,
                                            'subdomain' => $subdomain->subdomain,
                                            'charged_amount' => 0.0,
                                        ];
                                    }
                                    $newCredit = round($currentCredit - $redemptionFeeSetting, 2);
                                    Capsule::table('tblclients')
                                        ->where('id', $userid)
                                        ->update([
                                            'credit' => number_format($newCredit, 2, '.', ''),
                                        ]);

                                    static $creditSchemaInfoLocal = null;
                                    if ($creditSchemaInfoLocal === null) {
                                        $creditSchemaInfoLocal = [
                                            'has_table' => false,
                                            'has_relid' => false,
                                            'has_refundid' => false,
                                        ];
                                        try {
                                            $creditSchemaInfoLocal['has_table'] = Capsule::schema()->hasTable('tblcredit');
                                            if ($creditSchemaInfoLocal['has_table']) {
                                                $creditSchemaInfoLocal['has_relid'] = Capsule::schema()->hasColumn('tblcredit', 'relid');
                                                $creditSchemaInfoLocal['has_refundid'] = Capsule::schema()->hasColumn('tblcredit', 'refundid');
                                            }
                                        } catch (\Throwable $ignored) {
                                            $creditSchemaInfoLocal = [
                                                'has_table' => false,
                                                'has_relid' => false,
                                                'has_refundid' => false,
                                            ];
                                        }
                                    }
                                    if ($creditSchemaInfoLocal['has_table']) {
                                        $creditInsert = [
                                            'clientid' => $userid,
                                            'date' => date('Y-m-d H:i:s', $nowTs),
                                            'description' => '赎回期续费自动扣费',
                                            'amount' => 0 - $redemptionFeeSetting,
                                        ];
                                        if ($creditSchemaInfoLocal['has_relid']) {
                                            $creditInsert['relid'] = 0;
                                        }
                                        if ($creditSchemaInfoLocal['has_refundid']) {
                                            $creditInsert['refundid'] = 0;
                                        }
                                        Capsule::table('tblcredit')->insert($creditInsert);
                                    }
                                    $chargeAmount = $redemptionFeeSetting;
                                }
                            } else {
                                throw new \RuntimeException(self::actionText('renew.redemption_contact_admin', '域名处于赎回期，需要联系管理员续期'));
                            }
                        } else {
                            throw new \RuntimeException($redemptionSeconds > 0 ? self::actionText('renew.redemption_expired', '域名已超过赎回期，无法续期') : self::actionText('renew.grace_expired', '已超过续期宽限期，无法续期'));
                        }
                    }

                    $baseTs = max($expiresTs, $nowTs);
                    $newExpiryTs = strtotime('+' . $termYears . ' years', $baseTs);
                    if ($newExpiryTs === false) {
                        throw new \RuntimeException(self::actionText('renew.calculation_failed', '续期计算失败，请稍后重试'));
                    }

                    $newExpiry = date('Y-m-d H:i:s', $newExpiryTs);

                    Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomainId)
                        ->update([
                            'expires_at' => $newExpiry,
                            'renewed_at' => $nowStr,
                            'never_expires' => 0,
                            'updated_at' => $nowStr
                        ]);

                    return [
                        'new_expires_at' => $newExpiry,
                        'previous_expires_at' => $expiresRaw,
                        'subdomain' => $subdomain->subdomain,
                        'charged_amount' => $chargeAmount,
                    ];
                });

                if (!empty($renewResult['need_invoice_payment'])) {
                    $invoiceId = intval($renewResult['invoice_id'] ?? 0);
                    if ($invoiceId > 0) {
                        $renewRedirectTo = 'viewinvoice.php?id=' . $invoiceId;
                        $msg = self::actionText('renew.redemption_invoice_created', '余额不足，已自动生成赎回续费账单，请先完成支付后再发起续期。');
                        $msg_type = 'warning';
                        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError, 'redirectTo' => $renewRedirectTo];
                    }
                }

                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('client_renew_subdomain', [
                        'subdomain' => $renewResult['subdomain'],
                        'previous_expires_at' => $renewResult['previous_expires_at'],
                        'new_expires_at' => $renewResult['new_expires_at'],
                        'charged_amount' => $renewResult['charged_amount'] ?? 0,
                    ], $userid, $subdomainId);
                }

                $chargedAmount = isset($renewResult['charged_amount']) ? (float)$renewResult['charged_amount'] : 0.0;
                $msg = self::actionText('renew.success', '续期成功，新到期时间：%s', [date('Y-m-d H:i', strtotime($renewResult['new_expires_at']))]);
                if ($chargedAmount > 0) {
                    $msg .= self::actionText('renew.success_charge_suffix', '（已扣除 ￥%s）', [number_format($chargedAmount, 2)]);
                }
                $msg_type = 'success';

                $existing = Capsule::table('mod_cloudflare_subdomain')
                    ->where('userid', $userid)
                    ->where('status', 'active')
                    ->orderBy('created_at', 'desc')
                    ->get();
                if (!is_array($existing)) {
                    $existing = [];
                }
            } catch (\Throwable $e) {
                unset($_SESSION['cfmod_last_renew_sig'], $_SESSION['cfmod_last_renew_time']);
                $rawMessage = trim((string) $e->getMessage());
                $isProviderScene = false;
                if (function_exists('cfmod_normalize_provider_error')) {
                    $normalized = cfmod_normalize_provider_error($rawMessage, self::actionText('renew.failed_default', '续期失败，请稍后再试。'));
                    $providerClass = (string) ($normalized['error_class'] ?? '');
                    $isProviderScene = in_array($providerClass, [
                        'provider_5xx',
                        'provider_conflict',
                        'provider_invalid',
                        'provider_permission',
                        'provider_quota',
                        'provider_not_found',
                        'provider_timeout',
                    ], true);
                }
                if ($rawMessage !== '' && !$isProviderScene) {
                    $msg = self::actionText('renew.failed_detail', '续期失败：%s', [$rawMessage]);
                } else {
                    $errorText = cfmod_format_provider_error($rawMessage, self::actionText('renew.failed_default', '续期失败，请稍后再试。'));
                    $msg = self::actionText('renew.failed_detail', '续期失败：%s', [$errorText]);
                }
                $msg_type = 'danger';
            }
        }
    }
}

// 处理删除请求（禁用：用户不能删除自己的域名）
if($_POST['action'] == "delete" && isset($_POST['subdomain_id'])) {
    $subdomain_id = intval($_POST['subdomain_id']);
    $msg = self::actionText('delete.not_supported', '成功注册的免费域名暂不支持删除。如需处理，请提交工单获取支持。');
    $msg_type = "warning";
    if (function_exists('cloudflare_subdomain_log')) {
        cloudflare_subdomain_log('client_attempt_delete_subdomain', ['subdomain_id' => $subdomain_id], $userid, $subdomain_id);
    }
}

// 处理DNS记录创建请求（支持记录表持久化）
if($_POST['action'] == "create_dns" && isset($_POST['subdomain_id'])) {
    $createDnsSubdomainId = intval($_POST['subdomain_id']);
    if (cfmod_is_subdomain_deletion_locked_for_user($createDnsSubdomainId, intval($userid ?? 0))) {
        $msg = cfmod_deletion_locked_response_text();
        $msg_type = 'warning';
        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError];
    }
    $createDnsRootdomain = self::getSubdomainRootdomain($createDnsSubdomainId);
    $record_type = trim($_POST['record_type'] ?? '');
    if ($record_type === '') {
        $record_type = 'A';
    }
    $record_type_upper = strtoupper($record_type);
    if (self::isRootdomainInMaintenance($createDnsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$createDnsRootdomain]);
        $msg_type = 'warning';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } elseif ($record_type_upper === 'NS' && self::isRootdomainNsManagementDisabled($createDnsRootdomain)) {
        $msg = self::actionText('dns.ns.disabled', '已禁止设置 DNS 服务器（NS）。');
        $msg_type = 'warning';
    } else {
        if ($enableDnsUnlockFeature && $record_type_upper === 'NS' && !CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
            $msg = self::actionText('dns.unlock.required', '请先完成 DNS 解锁后再设置 NS 记录。');
            $msg_type = 'warning';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        // VPN/代理检测（仅NS记录）
        if ($record_type_upper === 'NS' && class_exists('CfVpnDetectionService') && CfVpnDetectionService::isDnsCheckEnabled($module_settings)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $module_settings);
            if (!empty($vpnCheckResult['blocked'])) {
                $msg = self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。');
                $msg_type = 'warning';
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('vpn_detection_blocked_dns', [
                        'action' => 'create_dns',
                        'type' => 'NS',
                        'ip' => $clientIp,
                        'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                    ], $userid ?? 0, null);
                }
                return [
                    'msg' => $msg,
                    'msg_type' => $msg_type,
                    'registerError' => $registerError,
                ];
            }
        }
        if ($record_type_upper !== 'NS' && self::isSubdomainUsingExternalDns($createDnsSubdomainId, intval($userid ?? 0), $module_settings)) {
            $msg = self::actionText('dns.external_dns_blocked', '域名正在使用外部DNS解析，请先将NS修改为本站DNS后再试');
            $msg_type = 'danger';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        $record_content = trim($_POST['record_content'] ?? '');
        if ($record_type_upper === 'NS' && self::isDefaultNameserverContent($record_content, $module_settings)) {
            $msg = self::actionText('dns.ns.default_hint_use_switch', '该 NS 属于系统默认 DNS，请使用“DNS服务器（域名委派）”弹窗中的“切换为默认DNS地址”按钮。');
            $msg_type = 'warning';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.create.banned', '您的账号已被封禁或停用，禁止创建DNS记录。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        } elseif (self::shouldUseAsyncDns('create_dns', $module_settings, $isAsyncReplay)) {
            $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'create_dns');
            $msg = self::formatAsyncQueuedMessage($jobId);
            $msg_type = 'info';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $record_ttl = self::resolveSubmittedDnsTtl($_POST['record_ttl'] ?? 600, $record_type_upper);
        $record_priority_raw = $_POST['record_priority'] ?? null;
        $record_priority = is_numeric($record_priority_raw) ? intval($record_priority_raw) : 0;
        if ($record_priority < 0) {
            $record_priority = 0;
        }
        if ($record_priority > 65535) {
            $record_priority = 65535;
        }
        $line = self::normalizeLineValue($_POST['line'] ?? 'default');
        $record_name = trim($_POST['record_name'] ?? '@');
        if ($record_name === '') {
            $record_name = '@';
        }
        $record_weight = intval($_POST['record_weight'] ?? 0);
        if ($record_weight < 0) {
            $record_weight = 0;
        }
        if ($record_weight > 65535) {
            $record_weight = 65535;
        }
        $record_port = intval($_POST['record_port'] ?? 0);
        $record_target = trim($_POST['record_target'] ?? '');
        if ($record_type_upper === 'MX' && $record_priority === 0) {
            $record_priority = 10;
        }
        $shouldProceedDnsCreate = true;
        if ($record_port < 0) {
            $record_port = 0;
        }
        if ($record_port > 65535) {
            $record_port = 65535;
        }

        if ($shouldProceedDnsCreate && $record_type_upper === 'SRV') {
            if ($record_port < 1 || $record_port > 65535) {
                $msg = self::actionText('dns.validation.srv_port', 'SRV记录的端口必须在1-65535之间');
                $msg_type = 'danger';
                $shouldProceedDnsCreate = false;
            }
            $record_priority = max(0, min(65535, $record_priority));
            $record_weight = max(0, min(65535, $record_weight));
            $record_target_clean = rtrim($record_target, '.');
            $record_target_clean = trim($record_target_clean);
            if ($shouldProceedDnsCreate && $record_target_clean === '') {
                $msg = self::actionText('dns.validation.srv_target_required', 'SRV记录的目标地址不能为空');
                $msg_type = 'danger';
                $shouldProceedDnsCreate = false;
            }
            if ($shouldProceedDnsCreate && !cfmod_is_valid_hostname($record_target_clean)) {
                $msg = self::actionText('dns.validation.srv_target_invalid', '请输入有效的SRV目标主机名');
                $msg_type = 'danger';
                $shouldProceedDnsCreate = false;
            }
            if ($shouldProceedDnsCreate) {
                $record_target = $record_target_clean;
                $record_content = $record_priority . ' ' . $record_weight . ' ' . $record_port . ' ' . $record_target;
            }
        }

        if (cfmod_dns_name_has_invalid_edges($record_name)) {
            $msg = self::actionText('dns.validation.name_invalid', "解析名称不能以 '.' 或 '-' 开头或结尾，也不能包含连续的 '.'");
            $msg_type = "danger";
            $shouldProceedDnsCreate = false;
        }

        if ($shouldProceedDnsCreate) {
            // 简单幂等：短时间内相同参数的重复提交直接忽略
            try {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
            } catch (Exception $e) {}

            $idemSig = 'create|' . implode('|', [
                $subdomain_id,
                $record_type_upper,
                $record_name,
                $record_content,
                $record_ttl,
                $record_priority,
                $line
            ]);
            $nowTs = time();

            if (!empty($_SESSION['cfmod_last_dns_sig'])
                && $_SESSION['cfmod_last_dns_sig'] === $idemSig
                && isset($_SESSION['cfmod_last_dns_time'])
                && ($nowTs - intval($_SESSION['cfmod_last_dns_time'])) < 5
            ) {
                $msg = self::actionText('common.duplicate_submit', '操作已提交，请勿重复点击');
                $msg_type = 'warning';
            } else {
                $_SESSION['cfmod_last_dns_sig'] = $idemSig;
                $_SESSION['cfmod_last_dns_time'] = $nowTs;

                try {
                    $record = Capsule::table('mod_cloudflare_subdomain')
                        ->where('id', $subdomain_id)
                        ->where('userid', $userid)
                        ->first();

                    if ($record) {
                        if ($record->status === 'suspended') {
                            $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                            $msg_type = "warning";
                        } else {
                            list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($record, $module_settings);
                            if (!$cf) {
                                $msg = $providerError;
                                $msg_type = 'danger';
                            } else {
                                $limitPerSub = intval($module_settings['max_dns_records_per_subdomain'] ?? 0);
                                $final_name = $record_name === '@' ? $record->subdomain : ($record_name . '.' . $record->subdomain);
                                if (!self::passesCnameMutexRule((int) $record->id, (string) $final_name, (string) $record_type_upper, null)) {
                                    $msg = self::actionTextByLanguage(
                                        'dns.cname_mutex_conflict',
                                        '同名记录与 CNAME 互斥：同名已有 CNAME 时不能新增其他类型；同名已有其他类型时不能新增 CNAME。',
                                        'CNAME conflict for the same host: when a CNAME exists, other record types cannot be added; when other record types exist, CNAME cannot be added.'
                                    );
                                    $msg_type = 'warning';
                                    return [
                                        'msg' => $msg,
                                        'msg_type' => $msg_type,
                                        'registerError' => $registerError,
                                    ];
                                }
                                $isRootNs = ($record_type_upper === 'NS' && $final_name === $record->subdomain);
                                $effectiveLimit = $isRootNs ? 0 : $limitPerSub;
                                $creation = null;

                                $effectiveSettings = is_array($module_settings) ? $module_settings : [];
                                $repairTypes = CfDnsConflictRepairService::conflictAutoRepairTypes($effectiveSettings);
                                $replaceTypes = CfDnsConflictRepairService::replaceModeTypes($effectiveSettings);
                                $mode = CfDnsConflictRepairService::createSemanticsMode($effectiveSettings);
                                $postUpdateVerifyEnabled = CfDnsConflictRepairService::postUpdateVerifyEnabled($effectiveSettings);

                                $proactiveRepairFailed = false;
                                $proactiveRepairFailedMessage = '';
                                // Proactive replace path: local-empty + replace mode => try remote upsert BEFORE create
                                try {
                                    $localPreCount = (int) Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $record->id)
                                        ->where('name', strtolower($final_name))
                                        ->where('type', $record_type_upper)
                                        ->whereRaw('COALESCE(`line`, "") = ?', [$line])
                                        ->count();
                                    $allowProactiveReplace = $mode === 'local_empty_add_as_replace'
                                        && $localPreCount === 0
                                        && in_array($record_type_upper, array_filter((array)$replaceTypes), true)
                                        && in_array($record_type_upper, array_filter((array)$repairTypes), true)
                                        && method_exists($cf, 'getDnsRecords');

                                    if ($allowProactiveReplace) {
                                        $listRes = $cf->getDnsRecords($record->cloudflare_zone_id, $final_name, ['per_page' => 1000, 'type' => $record_type_upper]);
                                        $candidates = [];
                                        if (($listRes['success'] ?? false) && is_array($listRes['result'] ?? null)) {
                                            foreach (($listRes['result'] ?? []) as $rr) {
                                                if (!is_array($rr)) { continue; }
                                                $n = CfDnsConflictRepairService::normalizeDnsName((string)($rr['name'] ?? ''));
                                                $t = CfDnsConflictRepairService::normalizeType((string)($rr['type'] ?? ''));
                                                if ($n === CfDnsConflictRepairService::normalizeDnsName($final_name) && $t === $record_type_upper && trim((string)($rr['id'] ?? '')) !== '') {
                                                    $candidates[] = $rr;
                                                }
                                            }
                                        }

                                        $target = null;
                                        $contentMatchedCandidate = null;
                                        foreach ($candidates as $cand) {
                                            if (CfDnsConflictRepairService::normalizeDnsContent((string)($cand['content'] ?? ''), $record_type_upper) === CfDnsConflictRepairService::normalizeDnsContent((string)$record_content, $record_type_upper)) {
                                                if ($contentMatchedCandidate === null) {
                                                    $contentMatchedCandidate = $cand;
                                                }
                                                if (CfDnsConflictRepairService::verifyRemoteRecord($cand, $record_type_upper, $final_name, $record_content, intval($record_ttl), intval($record_priority), (string)$line)) {
                                                    $target = $cand; // strict noop mapping repair
                                                    break;
                                                }
                                            }
                                        }

                                        // same content but different ttl/line/priority => force update + verify before local heal
                                        if (!$target && $contentMatchedCandidate && trim((string)($contentMatchedCandidate['id'] ?? '')) !== '') {
                                            $rid = trim((string)($contentMatchedCandidate['id'] ?? ''));
                                            $upPayload = ['type'=>$record_type_upper,'name'=>CfDnsConflictRepairService::normalizeDnsName($final_name),'content'=>$record_content,'ttl'=>$record_ttl,'line'=>$line,'priority'=>$record_priority];
                                            $up = method_exists($cf, 'updateDnsRecordRaw')
                                                ? $cf->updateDnsRecordRaw($record->cloudflare_zone_id, $rid, $upPayload)
                                                : $cf->updateDnsRecord($record->cloudflare_zone_id, $rid, $upPayload);

                                            if (($up['success'] ?? false) && method_exists($cf, 'getDnsRecord')) {
                                                $verify = $cf->getDnsRecord($record->cloudflare_zone_id, $rid);
                                                if (($verify['success'] ?? false) && is_array($verify['result'] ?? null)) {
                                                    $vr = $verify['result'];
                                                    if (CfDnsConflictRepairService::verifyRemoteRecord($vr, $record_type_upper, $final_name, $record_content, intval($record_ttl), intval($record_priority), (string)$line)) {
                                                        $target = $contentMatchedCandidate;
                                                    } else {
                                                        $proactiveRepairFailed = true;
                                                        $proactiveRepairFailedMessage = self::actionText('dns.repair.unconfirmed', '检测到云端记录与目标值不一致，已拒绝本地自愈，请稍后重试或联系管理员。');
                                                        if (function_exists('cloudflare_subdomain_log')) {
                                                            cloudflare_subdomain_log('client_create_dns_repair_failed', [
                                                                'reason' => 'ttl_or_fields_not_applied',
                                                                'record_id' => $rid,
                                                                'expected_ttl' => intval($record_ttl),
                                                                'observed_ttl' => intval($vr['ttl'] ?? 0),
                                                            ], $userid, $record->id);
                                                        }
                                                    }
                                                }
                                            } elseif (!($up['success'] ?? false)) {
                                                $proactiveRepairFailed = true;
                                                $proactiveRepairFailedMessage = self::actionText('dns.repair.unconfirmed', '检测到云端记录未能确认更新，已拒绝本地自愈，请稍后重试或联系管理员。');
                                                if (function_exists('cloudflare_subdomain_log')) {
                                                    cloudflare_subdomain_log('client_create_dns_repair_failed', [
                                                        'reason' => 'provider_update_failed',
                                                        'record_id' => $rid,
                                                        'errors' => $up['errors'] ?? '',
                                                    ], $userid, $record->id);
                                                }
                                            }
                                        }

                                        if (!$target) {
                                            $repairRes = CfDnsConflictRepairService::tryRepairViaUpsert(
                                                $cf, $record->cloudflare_zone_id, $record_type_upper, $final_name, $record_content, intval($record_ttl), intval($record_priority), (string)$line, true, $postUpdateVerifyEnabled
                                            );
                                            if (($repairRes['success'] ?? false) && !empty($repairRes['record_id'])) {
                                                foreach ($candidates as $cand) {
                                                    if (trim((string)($cand['id'] ?? '')) === trim((string)$repairRes['record_id'])) { $target = $cand; break; }
                                                }
                                                if (!$target) { $target = ['id' => (string)$repairRes['record_id'], 'name' => $final_name]; }
                                            }
                                        }

if ($target) {
                                            $now = date('Y-m-d H:i:s');
                                            $rid = (string)($target['id'] ?? '');
                                            $payload = [
                                                'subdomain_id' => $record->id,
                                                'zone_id' => $record->cloudflare_zone_id,
                                                'record_id' => $rid !== '' ? $rid : null,
                                                'name' => strtolower(rtrim($final_name,'.')),
                                                'type' => $record_type_upper,
                                                'content' => $record_content,
                                                'ttl' => $record_ttl,
                                                'proxied' => 0,
                                                'line' => $line,
                                                'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                                'status' => 'active',
                                                'updated_at' => $now,
                                            ];
                                            $existing = Capsule::table('mod_cloudflare_dns_records')
                                                ->where('subdomain_id', $record->id)
                                                ->where('name', strtolower(rtrim($final_name, '.')))
                                                ->where('type', $record_type_upper)
                                                ->where('content', $record_content)
                                                ->whereRaw('COALESCE(`line`, "") = ?', [$line])
                                                ->where(function ($query) use ($record_type_upper, $record_priority) {
                                                    if (in_array($record_type_upper, ['MX', 'SRV'], true)) {
                                                        $query->where('priority', $record_priority);
                                                    } else {
                                                        $query->whereNull('priority');
                                                    }
                                                })
                                                ->orderBy('id', 'desc')
                                                ->first();
                                            if ($existing) {
                                                Capsule::table('mod_cloudflare_dns_records')->where('id', $existing->id)->update($payload);
                                            } else {
                                                $payload['created_at'] = $now;
                                                Capsule::table('mod_cloudflare_dns_records')->insert($payload);
                                            }
                                            CfSubdomainService::markHasDnsHistory($record->id);
                                            CfSubdomainService::syncDnsHistoryFlag($record->id);
                                            $creation = ['record_id' => $rid !== '' ? $rid : null, 'result' => ['success'=>true], 'final_name' => $final_name];
                                        }
                                    }
                                } catch (\Throwable $ignoreProactive) {
                                    CfDnsConflictRepairService::logCatch('client_proactive_repair', [
                                        'userid' => $userid,
                                        'subdomain_id' => $record->id ?? 0,
                                        'zone_id' => $record->cloudflare_zone_id ?? '',
                                        'type' => $record_type_upper,
                                        'name' => $final_name,
                                        'line' => $line,
                                        'allowReplace' => !empty($allowProactiveReplace) ? 1 : 0,
                                    ], $ignoreProactive);
                                }
                                if ($proactiveRepairFailed) {
                                    $msg = $proactiveRepairFailedMessage !== '' ? $proactiveRepairFailedMessage : self::actionText('dns.repair.unconfirmed', '云端未确认，操作已中止。');
                                    $msg_type = 'warning';
                                    return [
                                        'msg' => $msg,
                                        'msg_type' => $msg_type,
                                        'registerError' => $registerError,
                                    ];
                                }

                                try {
                                    if ($creation === null) {
                                    $creation = cf_atomic_run_with_dns_limit(
                                        $record->id,
                                        $effectiveLimit,
                                        function () use (
                                            $cf,
                                            $record,
                                            $record_type_upper,
                                            $record_content,
                                            $record_priority,
                                            $record_ttl,
                                            $line,
                                            $final_name,
                                            $nsMaxPerDomain,
                                            $isRootNs,
                                            $record_weight,
                                            $record_port,
                                            $record_target
                                        ) {
                                            if ($isRootNs) {
                                                $currentNs = Capsule::table('mod_cloudflare_dns_records')
                                                    ->where('subdomain_id', $record->id)
                                                    ->where('type', 'NS')
                                                    ->where('name', $record->subdomain)
                                                    ->lockForUpdate()
                                                    ->count();
                                                if ($currentNs >= $nsMaxPerDomain) {
                                                    throw new CfAtomicRecordLimitException('ns_limit');
                                                }
                                            }

                                            switch ($record_type_upper) {
                                                case 'MX':
                                                    $res = $cf->createMXRecord($record->cloudflare_zone_id, $final_name, $record_content, $record_priority, $record_ttl);
                                                    break;
                                                case 'SRV':
                                                    $res = $cf->createSRVRecord($record->cloudflare_zone_id, $final_name, $record_target, $record_port, $record_priority, $record_weight, $record_ttl);
                                                    break;
                                                case 'CAA':
                                                    $caa_flag = intval($_POST['caa_flag'] ?? 0);
                                                    $caa_tag = trim($_POST['caa_tag'] ?? 'issue');
                                                    $caa_value = trim($_POST['caa_value'] ?? '');
                                                    if ($caa_value === '') {
                                                        throw new \RuntimeException(self::actionText('dns.validation.caa_value_required', 'CAA记录的Value不能为空'));
                                                    }
                                                    $res = $cf->createCAARecord($record->cloudflare_zone_id, $final_name, $caa_flag, $caa_tag, $caa_value, $record_ttl);
                                                    break;
                                                default:
                                                    $res = $cf->createDnsRecordRaw($record->cloudflare_zone_id, [
                                                        'type' => $record_type_upper,
                                                        'name' => $final_name,
                                                        'content' => $record_content,
                                                        'ttl' => $record_ttl,
                                                        'line' => $line
                                                    ]);
                                                    break;
                                            }

                                            if (!($res['success'] ?? false)) {
                                                $message = $res['errors'][0] ?? ($res['errors'] ?? 'create failed');
                                                if (is_array($message)) {
                                                    $message = json_encode($message, JSON_UNESCAPED_UNICODE);
                                                }
                                                throw new \RuntimeException((string) $message);
                                            }

                                            $cfRecordId = $res['result']['id'] ?? ($res['RecordId'] ?? null);
                                            $now = date('Y-m-d H:i:s');

                                            $existsSame = Capsule::table('mod_cloudflare_dns_records')
                                                ->where('subdomain_id', $record->id)
                                                ->where('name', $final_name)
                                                ->where('type', $record_type_upper)
                                                ->where('content', $record_content)
                                                ->whereRaw('COALESCE(`line`, "") = ?', [$line])
                                                ->first();
                                            if ($existsSame) {
                                                Capsule::table('mod_cloudflare_dns_records')->where('id', $existsSame->id)->update([
                                                    'record_id' => $cfRecordId !== null ? (string) $cfRecordId : null,
                                                    'ttl' => $record_ttl,
                                                    'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                                    'updated_at' => $now,
                                                ]);
                                            } else {
                                                Capsule::table('mod_cloudflare_dns_records')->insert([
                                                    'subdomain_id' => $record->id,
                                                    'zone_id' => $record->cloudflare_zone_id,
                                                    'record_id' => $cfRecordId !== null ? (string) $cfRecordId : null,
                                                    'name' => $final_name,
                                                    'type' => $record_type_upper,
                                                    'content' => $record_content,
                                                    'ttl' => $record_ttl,
                                                    'proxied' => 0,
                                                    'line' => $line,
                                                    'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                                    'status' => 'active',
                                                    'created_at' => $now,
                                                    'updated_at' => $now
                                                ]);
                                            }
                                            CfSubdomainService::markHasDnsHistory($record->id);

                                            $updateData = [
                                                'notes' => '已解析',
                                                'updated_at' => $now
                                            ];
                                            if ($final_name === $record->subdomain) {
                                                $updateData['dns_record_id'] = $cfRecordId !== null ? (string) $cfRecordId : null;
                                            }
                                            Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $record->id)
                                                ->update($updateData);

                                            return [
                                                'record_id' => $cfRecordId !== null ? (string) $cfRecordId : null,
                                                'result' => $res,
                                                'final_name' => $final_name
                                            ];
                                        }
                                    );
                                    }
                                } catch (CfAtomicRecordLimitException $e) {
                                    if ($e->getMessage() === 'ns_limit') {
                                        $msg = self::actionText('dns.ns.limit_reached', 'NS 服务器最多允许 %s 条，当前已达到上限', [$nsMaxPerDomain]);
                                        $msg_type = 'warning';
                                    } else {
                                        $limitText = $limitPerSub > 0 ? $limitPerSub : self::actionText('common.configured_limit', '配置的上限');
                                        $msg = self::actionText('dns.limit_reached', '已达到该域名的解析数量上限（%s）', [$limitText]);
                                        $msg_type = 'warning';
                                    }
                                    throw new Exception('__handled_limit__');
                                } catch (\RuntimeException $e) {
                                    $providerError = (string) $e->getMessage();
                                    $repaired = false;
                                    $isConflict = stripos($providerError, 'conflict') !== false
                                        || stripos($providerError, 'exists') !== false
                                        || stripos($providerError, 'duplicate') !== false
                                        || stripos($providerError, 'not unique') !== false
                                        || strpos($providerError, '冲突') !== false
                                        || strpos($providerError, '已存在') !== false
                                        || strpos($providerError, '重复') !== false;
                                    $effectiveSettings = is_array($module_settings) ? $module_settings : [];
                                    $conflictEnabled = !array_key_exists('dns_conflict_auto_repair_enabled', $effectiveSettings)
                                        || in_array(strtolower(trim((string)($effectiveSettings['dns_conflict_auto_repair_enabled'] ?? '1'))), ['1','on','yes','true','enabled'], true);
                                    $repairTypes = CfDnsConflictRepairService::conflictAutoRepairTypes($effectiveSettings);
                                    $replaceTypes = CfDnsConflictRepairService::replaceModeTypes($effectiveSettings);
                                    $mode = CfDnsConflictRepairService::createSemanticsMode($effectiveSettings);
                                    $postUpdateVerifyEnabled = CfDnsConflictRepairService::postUpdateVerifyEnabled($effectiveSettings);
                                    if ($isConflict && $conflictEnabled && in_array($record_type_upper, array_filter((array)$repairTypes), true) && method_exists($cf, 'getDnsRecords')) {
                                        try {
                                            $localPreCount = (int) Capsule::table('mod_cloudflare_dns_records')
                                                ->where('subdomain_id', $record->id)
                                                ->where('name', strtolower($final_name))
                                                ->where('type', $record_type_upper)
                                                ->whereRaw('COALESCE(`line`, "") = ?', [$line])
                                                ->count();
                                            $allowReplace = $mode === 'local_empty_add_as_replace'
                                                && $localPreCount === 0
                                                && in_array($record_type_upper, array_filter((array)$replaceTypes), true);

                                            $aggressiveRes = self::tryAggressiveConflictRepair($cf, $record, $userid, [
                                                'type' => $record_type_upper,
                                                'name' => $final_name,
                                                'content' => $record_content,
                                                'ttl' => intval($record_ttl),
                                                'priority' => intval($record_priority),
                                                'line' => (string) $line,
                                                'local_pre_count' => $localPreCount,
                                                'provider_error' => $providerError,
                                                'settings' => $effectiveSettings,
                                            ]);
                                            if (!empty($aggressiveRes['success'])) {
                                                $repairRes = $aggressiveRes['repair'];
                                            } else {
                                                $repairRes = CfDnsConflictRepairService::tryRepairViaUpsert(
                                                    $cf, $record->cloudflare_zone_id, $record_type_upper, $final_name, $record_content, intval($record_ttl), intval($record_priority), (string)$line, $allowReplace, $postUpdateVerifyEnabled
                                                );
                                            }
                                            if (($repairRes['success'] ?? false)) {
                                                $now = date('Y-m-d H:i:s');
                                                $rid = (string)($repairRes['record_id'] ?? '');
                                                $payload = [
                                                    'subdomain_id' => $record->id,
                                                    'zone_id' => $record->cloudflare_zone_id,
                                                    'record_id' => $rid !== '' ? $rid : null,
                                                    'name' => strtolower(rtrim($final_name,'.')),
                                                    'type' => $record_type_upper,
                                                    'content' => $record_content,
                                                    'ttl' => $record_ttl,
                                                    'proxied' => 0,
                                                    'line' => $line,
                                                    'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                                    'status' => 'active',
                                                    'updated_at' => $now,
                                                ];
                                                $existing = Capsule::table('mod_cloudflare_dns_records')
                                                    ->where('subdomain_id', $record->id)
                                                    ->where('name', strtolower(rtrim($final_name, '.')))
                                                    ->where('type', $record_type_upper)
                                                    ->where('content', $record_content)
                                                    ->whereRaw('COALESCE(`line`, "") = ?', [$line])
                                                    ->where(function ($query) use ($record_type_upper, $record_priority) {
                                                        if (in_array($record_type_upper, ['MX', 'SRV'], true)) {
                                                            $query->where('priority', $record_priority);
                                                        } else {
                                                            $query->whereNull('priority');
                                                        }
                                                    })
                                                    ->orderBy('id', 'desc')
                                                    ->first();
                                                if ($existing) {
                                                    Capsule::table('mod_cloudflare_dns_records')->where('id', $existing->id)->update($payload);
                                                } else {
                                                    $payload['created_at'] = $now;
                                                    Capsule::table('mod_cloudflare_dns_records')->insert($payload);
                                                }
                                                CfSubdomainService::markHasDnsHistory($record->id);
                                                CfSubdomainService::syncDnsHistoryFlag($record->id);
                                                $msg = self::actionTextByLanguage('dns.create.success', 'DNS记录创建成功！', 'DNS record created successfully!');
                                                $msg_type = 'success';
                                                if (function_exists('cloudflare_subdomain_log')) {
                                                    cloudflare_subdomain_log('dns_conflict_auto_repaired', [
                                                        'decision_path' => (string)($repairRes['decision_path'] ?? ($allowReplace ? 'update' : 'noop')),
                                                        'local_pre_state' => $localPreCount > 0 ? 'non-empty' : 'empty',
                                                        'remote_candidates_count' => intval($repairRes['remote_candidates_count'] ?? 0),
                                                        'reconcile_mode' => $mode,
                                                        'managed_record_key' => strtolower(rtrim($final_name,'.') . '|' . $record_type_upper . '|' . ($line === '' ? 'default' : $line)),
                                                        'line_normalized' => $line,
                                                    ], $userid, $subdomain_id);
                                                }
                                                $repaired = true;
                                            }
                                        } catch (\Throwable $repairEx) {
                                            CfDnsConflictRepairService::logCatch('client_conflict_repair', [
                                                'userid' => $userid,
                                                'subdomain_id' => $record->id ?? 0,
                                                'zone_id' => $record->cloudflare_zone_id ?? '',
                                                'type' => $record_type_upper,
                                                'name' => $final_name,
                                                'line' => $line,
                                                'allowReplace' => !empty($allowReplace) ? 1 : 0,
                                            ], $repairEx);
                                        }
                                    }

                                    if (!$repaired) {
                                        $normalizedProviderError = function_exists('cfmod_normalize_provider_error')
                                            ? cfmod_normalize_provider_error($providerError)
                                            : ['error_class' => 'provider_unavailable', 'admin_detail' => $providerError, 'user_message' => cfmod_format_provider_error($providerError)];
                                        $errorText = (string) ($normalizedProviderError['user_message'] ?? cfmod_format_provider_error($providerError));
                                        $msg = self::actionText('dns.create.failed', 'DNS记录创建失败：%s', [$errorText]);
                                        $msg_type = "danger";
                                        if (function_exists('cloudflare_subdomain_log')) {
                                            cloudflare_subdomain_log('client_create_dns_error', [
                                                'error_class' => (string) ($normalizedProviderError['error_class'] ?? 'provider_unavailable'),
                                                'admin_detail' => (string) ($normalizedProviderError['admin_detail'] ?? $providerError),
                                            ], $userid, $subdomain_id);
                                        }
                                        throw new Exception('__handled_error__');
                                    }
                                }

                                if ($creation) {
                                    $createdRecordId = trim((string) ($creation['record_id'] ?? ''));
                                    $createdNameNormalized = strtolower(rtrim((string) ($creation['final_name'] ?? $final_name), '.'));
                                    $createdTypeNormalized = strtoupper(trim((string) $record_type_upper));
                                    $createdContentNormalized = self::normalizeDnsContent((string) $record_content, $createdTypeNormalized);
                                    $createdLineNormalized = self::normalizeLineValue($line);
                                    $createdPriorityNormalized = in_array($createdTypeNormalized, ['MX', 'SRV'], true) ? intval($record_priority) : null;

                                    try {
                                        $fresh = $cf->getDnsRecords($record->cloudflare_zone_id, $createdNameNormalized, [
                                            'type' => $createdTypeNormalized,
                                            'per_page' => 1000,
                                        ]);
                                        if (($fresh['success'] ?? false) && is_array($fresh['result'] ?? null)) {
                                            $now = date('Y-m-d H:i:s');
                                            $managedRows = Capsule::table('mod_cloudflare_dns_records')
                                                ->where('subdomain_id', $subdomain_id)
                                                ->where('name', $createdNameNormalized)
                                                ->where('type', $createdTypeNormalized)
                                                ->whereRaw('COALESCE(`line`, "") = ?', [$createdLineNormalized])
                                                ->get();
                                            $managedKeys = [];
                                            foreach ($managedRows as $mr) {
                                                $mrContent = self::normalizeDnsContent((string) ($mr->content ?? ''), $createdTypeNormalized);
                                                $mrPriority = in_array($createdTypeNormalized, ['MX', 'SRV'], true) ? intval($mr->priority ?? 0) : null;
                                                $managedKeys[strtolower($mrContent . '|' . (string) ($mrPriority === null ? 'null' : $mrPriority))] = true;
                                            }

                                            foreach (($fresh['result'] ?? []) as $remoteItem) {
                                                if (!is_array($remoteItem)) {
                                                    continue;
                                                }
                                                $remoteId = trim((string) ($remoteItem['id'] ?? ''));
                                                $remoteName = strtolower(rtrim(trim((string) ($remoteItem['name'] ?? '')), '.'));
                                                $remoteType = strtoupper(trim((string) ($remoteItem['type'] ?? '')));
                                                if ($remoteName !== $createdNameNormalized || $remoteType !== $createdTypeNormalized) {
                                                    continue;
                                                }
                                                $remoteContent = self::normalizeDnsContent((string) ($remoteItem['content'] ?? ''), $remoteType);
                                                $remoteLine = self::normalizeLineValue($remoteItem['line'] ?? '');
                                                if ($remoteLine !== $createdLineNormalized) {
                                                    continue;
                                                }
                                                $remotePriority = in_array($remoteType, ['MX', 'SRV'], true) ? intval($remoteItem['priority'] ?? 0) : null;
                                                $remoteKey = strtolower($remoteContent . '|' . (string) ($remotePriority === null ? 'null' : $remotePriority));
                                                $isCreatedTarget = (
                                                    $remoteContent === $createdContentNormalized
                                                    && $remotePriority === $createdPriorityNormalized
                                                    && ($createdRecordId === '' || $remoteId === $createdRecordId)
                                                );
                                                if ($isCreatedTarget || isset($managedKeys[$remoteKey])) {
                                                    if ($remoteId !== '') {
                                                        $localCandidates = Capsule::table('mod_cloudflare_dns_records')
                                                            ->where('subdomain_id', $subdomain_id)
                                                            ->where('name', $createdNameNormalized)
                                                            ->where('type', $createdTypeNormalized)
                                                            ->whereRaw('COALESCE(`line`, "") = ?', [$createdLineNormalized])
                                                            ->orderBy('id', 'desc')
                                                            ->get();
                                                        $matchedLocalId = null;
                                                        foreach ($localCandidates as $candidateRow) {
                                                            $candidateContent = self::normalizeDnsContent((string) ($candidateRow->content ?? ''), $createdTypeNormalized);
                                                            $candidatePriority = in_array($createdTypeNormalized, ['MX', 'SRV'], true)
                                                                ? intval($candidateRow->priority ?? 0)
                                                                : null;
                                                            if ($candidateContent === $remoteContent && $candidatePriority === $remotePriority) {
                                                                $matchedLocalId = intval($candidateRow->id ?? 0);
                                                                break;
                                                            }
                                                        }

                                                        if ($matchedLocalId > 0) {
                                                            Capsule::table('mod_cloudflare_dns_records')
                                                                ->where('id', $matchedLocalId)
                                                                ->update([
                                                                    'record_id' => $remoteId,
                                                                    'ttl' => intval($remoteItem['ttl'] ?? $record_ttl),
                                                                    'zone_id' => $record->cloudflare_zone_id,
                                                                    'updated_at' => $now,
                                                                ]);
                                                        }
                                                    }
                                                    continue;
                                                }

                                                if ($remoteId !== '' && method_exists($cf, 'deleteSubdomain')) {
                                                    $deleteRes = $cf->deleteSubdomain($record->cloudflare_zone_id, $remoteId, [
                                                        'name' => $remoteName,
                                                        'type' => $remoteType,
                                                        'content' => (string) ($remoteItem['content'] ?? $remoteContent),
                                                    ]);
                                                    $verifyRes = null;
                                                    $verifyAttempts = 0;
                                                    $verifyDeleted = null;
                                                    for ($attempt = 1; $attempt <= 2; $attempt++) {
                                                        $verifyAttempts = $attempt;
                                                        $verifyRes = $cf->getDnsRecord($record->cloudflare_zone_id, $remoteId);
                                                        $verifyDeleted = !($verifyRes['success'] ?? false);
                                                        if ($verifyDeleted) {
                                                            break;
                                                        }
                                                        usleep(200000);
                                                    }
                                                    if (function_exists('cloudflare_subdomain_log')) {
                                                        cloudflare_subdomain_log('create_dns_orphan_cleanup', [
                                                            'deleted_record_id' => $remoteId,
                                                            'name' => $remoteName,
                                                            'type' => $remoteType,
                                                            'content' => $remoteContent,
                                                            'line' => $remoteLine,
                                                            'reason' => 'remote_orphan_not_managed_locally',
                                                            'cleanup_success' => (bool) ($deleteRes['success'] ?? false),
                                                            'cleanup_error' => $deleteRes['errors'] ?? '',
                                                            'verify_attempts' => $verifyAttempts,
                                                            'verify_deleted' => $verifyDeleted,
                                                            'verify_success' => (bool) (($verifyRes['success'] ?? false) === false),
                                                            'verify_error' => is_array($verifyRes) ? ($verifyRes['errors'] ?? '') : '',
                                                        ], $userid, $subdomain_id);
                                                    }
                                                }
                                            }
                                        }
                                    } catch (\Throwable $cleanupEx) {
                                        if (function_exists('cloudflare_subdomain_log')) {
                                            cloudflare_subdomain_log('create_dns_orphan_cleanup_exception', [
                                                'error' => $cleanupEx->getMessage(),
                                                'name' => $createdNameNormalized,
                                                'type' => $createdTypeNormalized,
                                            ], $userid, $subdomain_id);
                                        }
                                    }

                                    CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log(
                                            'client_create_dns',
                                            ['type' => $record_type_upper, 'content' => $record_content, 'ttl' => $record_ttl, 'line' => $line, 'line_normalized' => $line],
                                            $userid,
                                            $subdomain_id
                                        );
                                    }

                                $msg = self::actionTextByLanguage('dns.create.success', 'DNS记录创建成功！', 'DNS record created successfully!');
                                $msg_type = "success";
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    if (!in_array($e->getMessage(), ['__handled_limit__', '__handled_error__'], true)) {
                        $normalizedProviderError = function_exists('cfmod_normalize_provider_error')
                            ? cfmod_normalize_provider_error($e->getMessage())
                            : ['error_class' => 'provider_unavailable', 'admin_detail' => $e->getMessage(), 'user_message' => cfmod_format_provider_error($e->getMessage())];
                        $errorText = (string) ($normalizedProviderError['user_message'] ?? cfmod_format_provider_error($e->getMessage()));
                        $msg = self::actionText('dns.create.failed', 'DNS记录创建失败：%s', [$errorText]);
                        $msg_type = "danger";
                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_create_dns_error', [
                                'error_class' => (string) ($normalizedProviderError['error_class'] ?? 'provider_unavailable'),
                                'admin_detail' => (string) ($normalizedProviderError['admin_detail'] ?? $e->getMessage()),
                            ], $userid, $subdomain_id);
                        }
                    }
                }
            }
        }
    }
}

// 处理域名自助删除
if($_POST['action'] == 'delete_subdomain' && isset($_POST['subdomain_id'])) {
    if (empty($clientDeleteEnabled)) {
        $msg = self::actionText('delete.not_supported', '注册的域名暂不支持自助删除，如需协助请提交工单。');
        $msg_type = 'warning';
    } elseif ($isUserBannedOrInactive) {
        $msg = self::actionText('delete.banned', '您的账号已被封禁或停用，暂无法提交删除申请。') . ($banReasonText ? (' ' . $banReasonText) : '');
        $msg_type = 'danger';
    } else {
        $subdomainId = intval($_POST['subdomain_id']);
        if ($subdomainId <= 0) {
            $msg = self::actionText('delete.invalid_subdomain', '未找到该域名，请刷新后重试。');
            $msg_type = 'warning';
        } else {
            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userid)
                ->first();
            if (!$subdomain) {
                $msg = self::actionText('delete.invalid_subdomain', '未找到该域名，请刷新后重试。');
                $msg_type = 'warning';
            } else {
                $statusLower = strtolower((string)($subdomain->status ?? ''));
                $everHadDns = intval($subdomain->has_dns_history ?? 0) === 1;
                if (!$everHadDns) {
                    try {
                        $currentDnsExists = Capsule::table('mod_cloudflare_dns_records')
                            ->where('subdomain_id', $subdomainId)
                            ->exists();
                        if ($currentDnsExists) {
                            $everHadDns = true;
                            CfSubdomainService::markHasDnsHistory($subdomainId);
                        }
                    } catch (\Throwable $e) {
                    }
                }

                if (in_array($statusLower, ['pending_delete', 'pending_remove'], true)) {
                    $msg = self::actionText('delete.pending', '删除申请已提交，系统稍后会自动处理。');
                    $msg_type = 'info';
                } elseif ($statusLower === 'suspended') {
                    $msg = self::actionText('domain.server_hold.locked', '域名当前为 ServerHold 状态，禁止操作。');
                    $msg_type = 'warning';
                } elseif ($statusLower === 'deleted') {
                    $msg = self::actionText('delete.already_deleted', '该域名已被清理，无需重复操作。');
                    $msg_type = 'info';
                } elseif (intval($subdomain->gift_lock_id ?? 0) > 0) {
                    $msg = self::actionText('delete.gift_locked', '域名当前处于转赠/锁定状态，请先取消后再尝试删除。');
                    $msg_type = 'warning';
                } else {
                    $modeDenied = false;
                    if (!$privilegedAllowDeleteWithDnsHistory) {
                        if ($clientDeleteMode === 'never_had_dns_only' && $everHadDns) {
                            $msg = self::actionText('delete.history_blocked', '仅允许从未设置解析记录的域名自助删除，如需协助请提交工单。');
                            $msg_type = 'warning';
                            $modeDenied = true;
                        } elseif ($clientDeleteMode === 'no_current_dns_only' && $everHadDns) {
                            // everHadDns 可能仅由历史标记触发；为了安全再查一次当前记录
                            $currentDnsExists = false;
                            try {
                                $currentDnsExists = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $subdomainId)
                                    ->exists();
                            } catch (\Throwable $e) {
                            }
                            if ($currentDnsExists) {
                                $msg = self::actionText('delete.current_dns_blocked', '仅允许当前无解析记录的域名自助删除，请先删除所有解析记录后再试。');
                                $msg_type = 'warning';
                                $modeDenied = true;
                            }
                        }
                    }

                    if ($modeDenied) {
                        // do nothing
                    } else {
                        $now = date('Y-m-d H:i:s');
                        $deleteNote = '[client_delete ' . $now . '] 用户提交自助删除';
                        $existingNotes = trim((string)($subdomain->notes ?? ''));
                        $noteToStore = $existingNotes === '' ? $deleteNote : ($existingNotes . "\n" . $deleteNote);
                        Capsule::table('mod_cloudflare_subdomain')
                            ->where('id', $subdomainId)
                            ->update([
                                'status' => 'pending_delete',
                                'expires_at' => '1999-12-31 00:00:00',
                                'never_expires' => 0,
                                'auto_deleted_at' => null,
                                'notes' => $noteToStore,
                                'updated_at' => $now,
                            ]);
                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_request_delete', [
                                'subdomain' => $subdomain->subdomain ?? '',
                                'userid' => $userid,
                                'requested_at' => $now,
                            ], $userid, $subdomainId);
                        }
                        $msg = self::actionText('delete.request_submitted', '删除申请已提交，系统将在稍后自动清理该域名。');
                        $msg_type = 'success';
                    }
                }
            }
        }
    }

    return [
        'msg' => $msg,
        'msg_type' => $msg_type,
        'registerError' => $registerError,
    ];
}

// 处理DNS记录更新请求（同步记录表）
if($_POST['action'] == "update_dns" && isset($_POST['subdomain_id'])) {
    $updateDnsSubdomainId = intval($_POST['subdomain_id']);
    if (cfmod_is_subdomain_deletion_locked_for_user($updateDnsSubdomainId, intval($userid ?? 0))) {
        $msg = cfmod_deletion_locked_response_text();
        $msg_type = 'warning';
        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError];
    }
    $updateDnsRootdomain = self::getSubdomainRootdomain($updateDnsSubdomainId);
    $record_type = trim($_POST['record_type'] ?? '');
    if ($record_type === '') {
        $record_type = 'A';
    }
    $record_type_upper = strtoupper($record_type);
    if (self::isRootdomainInMaintenance($updateDnsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$updateDnsRootdomain]);
        $msg_type = 'warning';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } elseif ($record_type_upper === 'NS' && self::isRootdomainNsManagementDisabled($updateDnsRootdomain)) {
        $msg = self::actionText('dns.ns.disabled', '已禁止设置 DNS 服务器（NS）。');
        $msg_type = 'warning';
    } else {
        if ($enableDnsUnlockFeature && $record_type_upper === 'NS' && !CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
            $msg = self::actionText('dns.unlock.required', '请先完成 DNS 解锁后再设置 NS 记录。');
            $msg_type = 'warning';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        // VPN/代理检测（仅NS记录）
        if ($record_type_upper === 'NS' && class_exists('CfVpnDetectionService') && CfVpnDetectionService::isDnsCheckEnabled($module_settings)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $module_settings);
            if (!empty($vpnCheckResult['blocked'])) {
                $msg = self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。');
                $msg_type = 'warning';
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('vpn_detection_blocked_dns', [
                        'action' => 'update_dns',
                        'type' => 'NS',
                        'ip' => $clientIp,
                        'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                    ], $userid ?? 0, null);
                }
                return [
                    'msg' => $msg,
                    'msg_type' => $msg_type,
                    'registerError' => $registerError,
                ];
            }
        }
        if ($record_type_upper !== 'NS' && self::isSubdomainUsingExternalDns($updateDnsSubdomainId, intval($userid ?? 0), $module_settings)) {
            $msg = self::actionText('dns.external_dns_blocked', '域名正在使用外部DNS解析，请先将NS修改为本站DNS后再试');
            $msg_type = 'danger';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
        $record_content = trim($_POST['record_content'] ?? '');
        if ($record_type_upper === 'NS' && self::isDefaultNameserverContent($record_content, $module_settings)) {
            $msg = self::actionText('dns.ns.default_hint_use_switch', '该 NS 属于系统默认 DNS，请使用“DNS服务器（域名委派）”弹窗中的“切换为默认DNS地址”按钮。');
            $msg_type = 'warning';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.update.banned', '您的账号已被封禁或停用，禁止更新DNS记录。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        } elseif (self::shouldUseAsyncDns('update_dns', $module_settings, $isAsyncReplay)) {
            $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'update_dns');
            $msg = self::formatAsyncQueuedMessage($jobId);
            $msg_type = 'info';
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $record_ttl = self::resolveSubmittedDnsTtl($_POST['record_ttl'] ?? 600, $record_type_upper);
        $record_priority_raw = $_POST['record_priority'] ?? null;
        $record_priority = is_numeric($record_priority_raw) ? intval($record_priority_raw) : 0;
        if ($record_priority < 0) {
            $record_priority = 0;
        }
        if ($record_priority > 65535) {
            $record_priority = 65535;
        }
        $line = self::normalizeLineValue($_POST['line'] ?? 'default');
        $record_id = trim($_POST['record_id'] ?? '');
        $record_name = trim($_POST['record_name'] ?? '@');
        if ($record_name === '') {
            $record_name = '@';
        }
        $record_weight = intval($_POST['record_weight'] ?? 0);
        if ($record_weight < 0) {
            $record_weight = 0;
        }
        if ($record_weight > 65535) {
            $record_weight = 65535;
        }
        $record_port = intval($_POST['record_port'] ?? 0);
        $record_target = trim($_POST['record_target'] ?? '');
        if ($record_type_upper === 'MX' && $record_priority === 0) {
            $record_priority = 10;
        }
        $shouldProceedDnsUpdate = true;
        if ($record_port < 0) {
            $record_port = 0;
        }
        if ($record_port > 65535) {
            $record_port = 65535;
        }

        if ($shouldProceedDnsUpdate && $record_type_upper === 'SRV') {
            if ($record_port < 1 || $record_port > 65535) {
                $msg = self::actionText('dns.validation.srv_port', 'SRV记录的端口必须在1-65535之间');
                $msg_type = 'danger';
                $shouldProceedDnsUpdate = false;
            }
            $record_priority = max(0, min(65535, $record_priority));
            $record_weight = max(0, min(65535, $record_weight));
            $record_target_clean = rtrim($record_target, '.');
            $record_target_clean = trim($record_target_clean);
            if ($shouldProceedDnsUpdate && $record_target_clean === '') {
                $msg = self::actionText('dns.validation.srv_target_required', 'SRV记录的目标地址不能为空');
                $msg_type = 'danger';
                $shouldProceedDnsUpdate = false;
            }
            if ($shouldProceedDnsUpdate && !cfmod_is_valid_hostname($record_target_clean)) {
                $msg = self::actionText('dns.validation.srv_target_invalid', '请输入有效的SRV目标主机名');
                $msg_type = 'danger';
                $shouldProceedDnsUpdate = false;
            }
            if ($shouldProceedDnsUpdate) {
                $record_target = $record_target_clean;
                $record_content = $record_priority . ' ' . $record_weight . ' ' . $record_port . ' ' . $record_target;
            }
        }

        if (cfmod_dns_name_has_invalid_edges($record_name)) {
            $msg = self::actionText('dns.validation.name_invalid', "解析名称不能以 '.' 或 '-' 开头或结尾，也不能包含连续的 '.'");
            $msg_type = "danger";
            $shouldProceedDnsUpdate = false;
        }

        if ($shouldProceedDnsUpdate) {
            try {
                $record = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->where('userid', $userid)
                    ->first();

                if ($record) {
                    if ($record->status === 'suspended') {
                        $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                        $msg_type = "warning";
                    } else {
                        $targetRecord = null;
                        if ($record_id) {
                            $targetRecord = Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->where('record_id', $record_id)
                                ->first();
                        } elseif ($record_name) {
                            $normalizedRecordName = strtolower(trim((string) $record_name));
                            $normalizedSubdomain = strtolower(trim((string) $record->subdomain));
                            if ($normalizedRecordName === '' || $normalizedRecordName === '@') {
                                $fullName = $record->subdomain;
                            } elseif ($normalizedSubdomain !== '' && $normalizedRecordName === $normalizedSubdomain) {
                                $fullName = $record->subdomain;
                            } elseif ($normalizedSubdomain !== '' && substr($normalizedRecordName, -strlen('.' . $normalizedSubdomain)) === '.' . $normalizedSubdomain) {
                                $fullName = $normalizedRecordName;
                            } else {
                                $fullName = $record_name . '.' . $record->subdomain;
                            }
                            $targetRecord = Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->where('name', $fullName)
                                ->first();
                        }

                        if ($targetRecord) {
                            list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($record, $module_settings);
                            if (!$cf) {
                                $msg = $providerError;
                                $msg_type = 'danger';
                            } else {
                                $normalizedRecordName = strtolower(trim((string) $record_name));
                                $normalizedSubdomain = strtolower(trim((string) $record->subdomain));
                                if ($normalizedRecordName === '' || $normalizedRecordName === '@') {
                                    $newFullName = $record->subdomain;
                                } elseif ($normalizedSubdomain !== '' && $normalizedRecordName === $normalizedSubdomain) {
                                    $newFullName = $record->subdomain;
                                } elseif ($normalizedSubdomain !== '' && substr($normalizedRecordName, -strlen('.' . $normalizedSubdomain)) === '.' . $normalizedSubdomain) {
                                    $newFullName = $normalizedRecordName;
                                } else {
                                    $newFullName = $record_name . '.' . $record->subdomain;
                                }
                                $caa_content = null;
                                $finalContentForCompare = $record_content;

                                if ($record_type_upper === 'CAA') {
                                    $caa_flag = intval($_POST['caa_flag'] ?? 0);
                                    $caa_tag = trim($_POST['caa_tag'] ?? 'issue');
                                    $caa_value = trim($_POST['caa_value'] ?? '');
                                    if ($caa_value === '') {
                                        $msg = self::actionText('dns.validation.caa_value_required', 'CAA记录的Value不能为空');
                                        $msg_type = 'warning';
                                        throw new Exception($msg);
                                    }
                                    $caa_content = $caa_flag . ' ' . $caa_tag . ' "' . str_replace('"', '\"', $caa_value) . '"';
                                    $finalContentForCompare = $caa_content;
                                }

                                $currentType = strtoupper(trim((string) ($targetRecord->type ?? '')));
                                $currentName = strtolower(trim((string) ($targetRecord->name ?? '')));
                                $currentContent = trim((string) ($targetRecord->content ?? ''));
                                $currentTtl = intval($targetRecord->ttl ?? 600);
                                $currentLine = strtolower(trim((string) ($targetRecord->line ?? 'default')));
                                if ($currentLine === '') {
                                    $currentLine = 'default';
                                }
                                $currentPriority = intval($targetRecord->priority ?? 0);

                                $newType = $record_type_upper;
                                $newName = strtolower(trim((string) $newFullName));
                                if (!self::passesCnameMutexRule((int) $record->id, (string) $newName, (string) $newType, intval($targetRecord->id ?? 0))) {
                                    $msg = self::actionTextByLanguage(
                                        'dns.cname_mutex_conflict',
                                        '同名记录与 CNAME 互斥：同名已有 CNAME 时不能新增其他类型；同名已有其他类型时不能新增 CNAME。',
                                        'CNAME conflict for the same host: when a CNAME exists, other record types cannot be added; when other record types exist, CNAME cannot be added.'
                                    );
                                    $msg_type = 'warning';
                                    return [
                                        'msg' => $msg,
                                        'msg_type' => $msg_type,
                                        'registerError' => $registerError,
                                    ];
                                }
                                $newContent = trim((string) $finalContentForCompare);
                                $newTtl = intval($record_ttl);
                                $newLine = strtolower(trim((string) $line));
                                if ($newLine === '') {
                                    $newLine = 'default';
                                }
                                $newPriority = in_array($record_type_upper, ['MX', 'SRV'], true)
                                    ? intval($record_priority)
                                    : 0;

                                $isNoopUpdate = $currentType === $newType
                                    && $currentName === $newName
                                    && $currentContent === $newContent
                                    && $currentTtl === $newTtl
                                    && $currentLine === $newLine
                                    && $currentPriority === $newPriority;

                                if ($isNoopUpdate) {
                                    $msg = self::actionText('dns.update.no_change', '未检测到变更，已跳过更新。');
                                    $msg_type = 'info';
                                    return [
                                        'msg' => $msg,
                                        'msg_type' => $msg_type,
                                        'registerError' => $registerError,
                                    ];
                                }

                                if ($record_type_upper === 'CAA') {
                                    $res = $cf->updateDnsRecord($record->cloudflare_zone_id, $targetRecord->record_id, [
                                        'type' => $record_type_upper,
                                        'name' => $newFullName,
                                        'content' => $caa_content,
                                        'ttl' => $record_ttl
                                    ]);
                                } else {
                                    $res = $cf->updateDnsRecordRaw($record->cloudflare_zone_id, $targetRecord->record_id, [
                                        'type' => $record_type_upper,
                                        'name' => $newFullName,
                                        'content' => $record_content,
                                        'ttl' => $record_ttl,
                                        'line' => $line,
                                        'priority' => $record_priority
                                    ]);
                                }

                                if ($res['success']) {
                                    $verifyAfterWriteEnabled = !isset($module_settings['dns_update_verify_after_write'])
                                        ? true
                                        : cfmod_setting_enabled($module_settings['dns_update_verify_after_write']);
                                    $verifyMismatch = false;
                                    $verifyObserved = null;
                                    $verifyExpected = [
                                        'name' => strtolower(rtrim(trim((string) $newFullName), '.')),
                                        'type' => strtoupper((string) $record_type_upper),
                                        'content' => trim((string) ($record_type_upper === 'CAA' && $caa_content !== null ? $caa_content : $record_content)),
                                        'ttl' => intval($record_ttl),
                                        'line' => self::normalizeLineValue($line),
                                        'priority' => in_array($record_type_upper, ['MX', 'SRV'], true) ? intval($record_priority) : null,
                                    ];
                                    if ($verifyAfterWriteEnabled && method_exists($cf, 'getDnsRecord')) {
                                        try {
                                            $verifyRecordId = isset($res['result']['id']) ? trim((string) $res['result']['id']) : trim((string) ($targetRecord->record_id ?? ''));
                                            if ($verifyRecordId !== '') {
                                                $verifyRes = $cf->getDnsRecord($record->cloudflare_zone_id, $verifyRecordId);
                                                if (($verifyRes['success'] ?? false) && is_array($verifyRes['result'] ?? null)) {
                                                    $vr = $verifyRes['result'];
                                                    $verifyObserved = [
                                                        'name' => strtolower(rtrim(trim((string) ($vr['name'] ?? '')), '.')),
                                                        'type' => strtoupper(trim((string) ($vr['type'] ?? ''))),
                                                        'content' => trim((string) ($vr['content'] ?? '')),
                                                        'ttl' => intval($vr['ttl'] ?? 0),
                                                        'line' => self::normalizeLineValue($vr['line'] ?? ''),
                                                        'priority' => in_array($record_type_upper, ['MX', 'SRV'], true) ? intval($vr['priority'] ?? 0) : null,
                                                    ];
                                                    $verifyMismatch = $verifyObserved['name'] !== $verifyExpected['name']
                                                        || $verifyObserved['type'] !== $verifyExpected['type']
                                                        || $verifyObserved['content'] !== $verifyExpected['content']
                                                        || $verifyObserved['ttl'] !== $verifyExpected['ttl']
                                                        || $verifyObserved['line'] !== $verifyExpected['line']
                                                        || $verifyObserved['priority'] !== $verifyExpected['priority'];
                                                }
                                            }
                                        } catch (Exception $verifyEx) {
                                            if (function_exists('cloudflare_subdomain_log')) {
                                                cloudflare_subdomain_log('client_update_dns_verify_exception', [
                                                    'record_id' => $targetRecord->record_id ?? '',
                                                    'error' => $verifyEx->getMessage(),
                                                ], $userid, $subdomain_id);
                                            }
                                        }
                                    }
                                    if ($verifyMismatch) {
                                        if (function_exists('cloudflare_subdomain_log')) {
                                            cloudflare_subdomain_log(
                                                'client_update_dns_verify_mismatch',
                                                [
                                                    'record_id' => $targetRecord->record_id ?? '',
                                                    'expected' => $verifyExpected,
                                                    'observed' => $verifyObserved,
                                                ],
                                                $userid,
                                                $subdomain_id
                                            );
                                        }
                                        $msg = self::actionText('dns.update.verify_mismatch', 'DNS记录更新请求已提交，但云端校验未通过，请稍后刷新重试。');
                                        $msg_type = 'warning';
                                        return [
                                            'msg' => $msg,
                                            'msg_type' => $msg_type,
                                            'registerError' => $registerError,
                                        ];
                                    }

                                    $final_content = $record_content;
                                    if ($record_type_upper === 'CAA' && $caa_content !== null) {
                                        $final_content = $caa_content;
                                    }

                                    $previousRecordId = trim((string) ($targetRecord->record_id ?? ''));
                                    $updatedRecordId = isset($res['result']['id']) ? (string) $res['result']['id'] : $previousRecordId;

                                    $updatePayload = [
                                        'type' => $record_type_upper,
                                        'name' => $newFullName,
                                        'content' => $final_content,
                                        'ttl' => $record_ttl,
                                        'proxied' => 0,
                                        'line' => $line,
                                        'priority' => in_array($record_type_upper, ['MX','SRV']) ? $record_priority : null,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ];
                                    if ($updatedRecordId !== null && $updatedRecordId !== '') {
                                        $updatePayload['record_id'] = $updatedRecordId;
                                        $targetRecord->record_id = $updatedRecordId;
                                    }

                                    Capsule::table('mod_cloudflare_dns_records')
                                        ->where('id', $targetRecord->id)
                                        ->update($updatePayload);
                                    $targetRecord->name = $newFullName;
                                    $targetRecord->type = $record_type_upper;
                                    $targetRecord->content = $final_content;
                                    $targetRecord->line = $line;
                                    $targetRecord->priority = in_array($record_type_upper, ['MX', 'SRV'], true) ? $record_priority : null;

                                    if (
                                        $previousRecordId !== ''
                                        && $updatedRecordId !== ''
                                        && $updatedRecordId !== $previousRecordId
                                        && method_exists($cf, 'deleteSubdomain')
                                    ) {
                                        try {
                                            $cleanupRes = $cf->deleteSubdomain($record->cloudflare_zone_id, $previousRecordId, [
                                                'name' => $currentName !== '' ? $currentName : ($targetRecord->name ?? $record->subdomain),
                                                'type' => $currentType !== '' ? $currentType : ($targetRecord->type ?? $record_type_upper),
                                                'content' => $currentContent !== '' ? $currentContent : null,
                                            ]);
                                            if (!($cleanupRes['success'] ?? false) && function_exists('cloudflare_subdomain_log')) {
                                                cloudflare_subdomain_log('client_update_dns_orphan_cleanup_failed', [
                                                    'old_record_id' => $previousRecordId,
                                                    'new_record_id' => $updatedRecordId,
                                                    'error' => $cleanupRes['errors'] ?? '',
                                                ], $userid, $subdomain_id);
                                            }
                                        } catch (Exception $cleanupEx) {
                                            if (function_exists('cloudflare_subdomain_log')) {
                                                cloudflare_subdomain_log('client_update_dns_orphan_cleanup_exception', [
                                                    'old_record_id' => $previousRecordId,
                                                    'new_record_id' => $updatedRecordId,
                                                    'error' => $cleanupEx->getMessage(),
                                                ], $userid, $subdomain_id);
                                            }
                                        }
                                    }

                                    try {
                                        $fresh = $cf->getDnsRecords($record->cloudflare_zone_id, $record->subdomain);
                                        if (($fresh['success'] ?? false)) {
                                            $now = date('Y-m-d H:i:s');
                                            foreach (($fresh['result'] ?? []) as $fr) {
                                                if (!is_array($fr)) {
                                                    continue;
                                                }
                                                $remoteRecordId = trim((string) ($fr['id'] ?? ''));
                                                $remoteName = strtolower(rtrim(trim((string) ($fr['name'] ?? $record->subdomain)), '.'));
                                                $remoteType = strtoupper(trim((string) ($fr['type'] ?? 'A')));
                                                $remoteContent = trim((string) ($fr['content'] ?? ''));
                                                $remoteLine = self::normalizeLineValue($fr['line'] ?? '');
                                                $remotePriority = in_array($remoteType, ['MX', 'SRV'], true) ? intval($fr['priority'] ?? 0) : null;

                                                $exists = self::findLocalRecordByRemote($subdomain_id, $fr);
                                                if ($exists) {
                                                    continue;
                                                }

                                                $sameValue = Capsule::table('mod_cloudflare_dns_records')
                                                    ->where('subdomain_id', $subdomain_id)
                                                    ->where('name', $remoteName)
                                                    ->where('type', $remoteType)
                                                    ->where('content', $remoteContent)
                                                    ->whereRaw('COALESCE(`line`, "") = ?', [$remoteLine])
                                                    ->where(function ($query) use ($remoteType, $remotePriority) {
                                                        if (in_array($remoteType, ['MX', 'SRV'], true)) {
                                                            $query->where('priority', $remotePriority);
                                                        } else {
                                                            $query->whereNull('priority');
                                                        }
                                                    })
                                                    ->orderBy('id', 'desc')
                                                    ->first();
                                                if ($sameValue) {
                                                    if ($remoteRecordId !== '') {
                                                        Capsule::table('mod_cloudflare_dns_records')
                                                            ->where('id', $sameValue->id)
                                                            ->update([
                                                                'record_id' => $remoteRecordId,
                                                                'zone_id' => $record->cloudflare_zone_id,
                                                                'ttl' => intval($fr['ttl'] ?? 600),
                                                                'updated_at' => $now,
                                                            ]);
                                                    }
                                                    continue;
                                                }

                                                Capsule::table('mod_cloudflare_dns_records')->insert([
                                                    'subdomain_id' => $subdomain_id,
                                                    'zone_id' => $record->cloudflare_zone_id,
                                                    'record_id' => $remoteRecordId !== '' ? $remoteRecordId : null,
                                                    'name' => $remoteName,
                                                    'type' => $remoteType,
                                                    'content' => $remoteContent,
                                                    'ttl' => intval($fr['ttl'] ?? 600),
                                                    'proxied' => 0,
                                                    'line' => $remoteLine,
                                                    'priority' => $remotePriority,
                                                    'status' => 'active',
                                                    'created_at' => $now,
                                                    'updated_at' => $now
                                                ]);
                                            }
                                        }
                                    } catch (Exception $e) {
                                    }

                                    CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

                                    if ($newFullName === $record->subdomain) {
                                        Capsule::table('mod_cloudflare_subdomain')
                                            ->where('id', $subdomain_id)
                                            ->update([
                                                'notes' => '已解析',
                                                'updated_at' => date('Y-m-d H:i:s')
                                            ]);
                                    }

                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log(
                                            'client_update_dns',
                                            ['record_id' => $targetRecord->record_id, 'type' => $record_type_upper, 'content' => $record_content, 'ttl' => $record_ttl, 'line' => $line],
                                            $userid,
                                            $subdomain_id
                                        );
                                    }

                                    if ($record_name === '@') {
                                        $msg = self::actionText('dns.update.success', 'DNS记录更新成功！域名解析已更新');
                                    } else {
                                        $msg = self::actionText('dns.update.success', 'DNS记录更新成功！域名解析已更新');
                                    }
                                    $msg_type = "success";
                                } else {
                                    $errorText = cfmod_format_provider_error($res['errors'] ?? '');
                                    $msg = self::actionText('dns.update.failed', 'DNS记录更新失败：%s', [$errorText]);
                                    $msg_type = "danger";
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $errorText = cfmod_format_provider_error($e->getMessage());
                $msg = self::actionText('dns.update.failed', 'DNS记录更新失败：%s', [$errorText]);
                $msg_type = "danger";
            }
        }
    }
}

// 处理CDN控制请求（同步记录表）
if($_POST['action'] == "toggle_cdn" && isset($_POST['subdomain_id'])) {
    $toggleCdnSubdomainId = intval($_POST['subdomain_id']);
    if (cfmod_is_subdomain_deletion_locked_for_user($toggleCdnSubdomainId, intval($userid ?? 0))) {
        $msg = cfmod_deletion_locked_response_text();
        $msg_type = 'warning';
        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError];
    }
    $toggleCdnRootdomain = self::getSubdomainRootdomain($toggleCdnSubdomainId);
    if (self::isRootdomainInMaintenance($toggleCdnRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$toggleCdnRootdomain]);
        $msg_type = 'warning';
    } elseif (!$disableDnsWrite && !$isUserBannedOrInactive && self::shouldUseAsyncDns('toggle_cdn', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'toggle_cdn');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.cdn.domain.banned', '您的账号已被封禁或停用，禁止更改CDN代理状态。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $proxied = $_POST['proxied'] == '1';

        try {
            $record = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomain_id)
                ->where('userid', $userid)
                ->first();

            if ($record && $record->dns_record_id) {
                if ($record->status === 'suspended') {
                    $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                    $msg_type = "warning";
                } else {
                    list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($record, $module_settings);
                    if (!$cf) {
                        $msg = $providerError;
                        $msg_type = 'danger';
                    } else {
                        $res = $cf->toggleProxy($record->cloudflare_zone_id, $record->dns_record_id, $proxied);

                        if ($res['success']) {
                            Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->where('record_id', $record->dns_record_id)
                                ->update([
                                    'proxied' => $proxied ? 1 : 0,
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]);

                            if (function_exists('cloudflare_subdomain_log')) {
                                cloudflare_subdomain_log('client_toggle_cdn', ['proxied' => $proxied], $userid, $subdomain_id);
                            }

                            $statusLabel = $proxied ? self::actionText('common.enabled', '启用') : self::actionText('common.disabled', '禁用');
                            $msg = self::actionText('dns.cdn.domain.status', 'CDN状态已%s', [$statusLabel]);
                            $msg_type = "success";
                        } else {
                            $msg = self::actionText('dns.cdn.domain.update_failed', 'CDN状态更新失败');
                            $msg_type = "danger";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('dns.cdn.domain.retry', 'CDN状态更新失败，请稍后再试。'));
            $msg = self::actionText('dns.cdn.domain.control_failed', 'CDN控制失败：%s', [$errorText]);
            $msg_type = "danger";
        }
    }
}

// 处理单条记录的CDN代理开关
if($_POST['action'] == "toggle_record_cdn" && isset($_POST['subdomain_id']) && isset($_POST['record_id'])) {
    $toggleRecordCdnSubdomainId = intval($_POST['subdomain_id']);
    if (cfmod_is_subdomain_deletion_locked_for_user($toggleRecordCdnSubdomainId, intval($userid ?? 0))) {
        $msg = cfmod_deletion_locked_response_text();
        $msg_type = 'warning';
        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError];
    }
    $toggleRecordCdnRootdomain = self::getSubdomainRootdomain($toggleRecordCdnSubdomainId);
    if (self::isRootdomainInMaintenance($toggleRecordCdnRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$toggleRecordCdnRootdomain]);
        $msg_type = 'warning';
    } elseif (!$disableDnsWrite && !$isUserBannedOrInactive && self::shouldUseAsyncDns('toggle_record_cdn', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'toggle_record_cdn');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
    } elseif ($disableDnsWrite) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        if ($isUserBannedOrInactive) {
            $msg = self::actionText('dns.cdn.record.banned', '您的账号已被封禁或停用，禁止更改记录的CDN代理状态。') . ($banReasonText ? (' ' . $banReasonText) : '');
            $msg_type = 'danger';
        }

        $subdomain_id = intval($_POST['subdomain_id']);
        $record_id = trim($_POST['record_id']);
        $proxied = $_POST['proxied'] == '1';

        try {
            $sub = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomain_id)
                ->where('userid', $userid)
                ->first();

            if ($sub) {
                if ($sub->status === 'suspended') {
                    $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                    $msg_type = "warning";
                } else {
                    $rec = Capsule::table('mod_cloudflare_dns_records')
                        ->where('subdomain_id', $subdomain_id)
                        ->where('record_id', $record_id)
                        ->first();

                    if ($rec && in_array($rec->type, ['A','AAAA','CNAME'])) {
                        list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                        if (!$cf) {
                            $msg = $providerError;
                            $msg_type = 'danger';
                        } else {
                            $res = $cf->toggleProxy($sub->cloudflare_zone_id, $record_id, $proxied);
                            if ($res['success']) {
                                Capsule::table('mod_cloudflare_dns_records')
                                    ->where('id', $rec->id)
                                    ->update([
                                        'proxied' => $proxied ? 1 : 0,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ]);

                                if (function_exists('cloudflare_subdomain_log')) {
                                    cloudflare_subdomain_log('client_toggle_record_cdn', ['record_id' => $record_id, 'proxied' => $proxied], $userid, $subdomain_id);
                                }

                                $statusLabel = $proxied ? self::actionText('common.enabled', '启用') : self::actionText('common.disabled', '禁用');
                                $msg = self::actionText('dns.cdn.record.status', '记录CDN状态已%s', [$statusLabel]);
                                $msg_type = "success";
                            } else {
                                $msg = self::actionText('dns.cdn.record.update_failed', '记录CDN状态更新失败');
                                $msg_type = "danger";
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errorText = cfmod_format_provider_error($e->getMessage());
            $msg = self::actionText('dns.cdn.record.control_failed', '记录CDN控制失败：%s', [$errorText]);
            $msg_type = "danger";
        }
    }
}

// 处理DNS记录删除请求（仅删除某条记录）- 删除操作不检测VPN
if($_POST['action'] == "delete_dns_record" && isset($_POST['record_id']) && isset($_POST['subdomain_id'])) {
    if (cfmod_is_subdomain_deletion_locked_for_user(intval($_POST['subdomain_id']), intval($userid ?? 0))) {
        $msg = cfmod_deletion_locked_response_text();
        $msg_type = 'warning';
        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError];
    }
    $deleteDnsSubdomainId = intval($_POST['subdomain_id']);
    $deleteDnsRootdomain = self::getSubdomainRootdomain($deleteDnsSubdomainId);
    if (self::isRootdomainInMaintenance($deleteDnsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$deleteDnsRootdomain]);
        $msg_type = 'warning';
    } elseif ($isUserBannedOrInactive) {
        $msg = self::actionText('dns.delete.banned', '您的账号已被封禁或停用，禁止删除DNS记录。') . ($banReasonText ? (' ' . $banReasonText) : '');
        $msg_type = 'danger';
    } elseif (self::shouldUseAsyncDns('delete_dns_record', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'delete_dns_record');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }

    $subdomain_id = intval($_POST['subdomain_id']);
    $record_id = trim($_POST['record_id']);

    try {
        $sub = Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomain_id)
            ->where('userid', $userid)
            ->first();
        if (!$sub) {
            throw new \RuntimeException('subdomain_not_found');
        }

        $rec = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomain_id)
            ->where('record_id', $record_id)
            ->first();
        if (!$rec && ctype_digit($record_id)) {
            $rec = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomain_id)
                ->where('id', intval($record_id))
                ->first();
        }
        if (!$rec) {
            throw new \RuntimeException('record_not_found');
        }

        list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
        if (!$cf) {
            throw new \RuntimeException($providerError ?: 'provider_unavailable');
        }

        $effectiveZone = (string) ($rec->zone_id ?: ($sub->cloudflare_zone_id ?: $sub->rootdomain));
        $effectiveRecordId = trim((string) ($rec->record_id ?? ''));
        $deleteFailed = null;

        if ($effectiveRecordId === '') {
            $resolvedRecordId = self::findRemoteRecordIdForLocal($cf, $effectiveZone, $rec);
            if ($resolvedRecordId === null) {
                $deleteFailed = 'provider lookup failed';
            } else {
                $effectiveRecordId = $resolvedRecordId;
            }
        }

        if ($deleteFailed === null && $effectiveRecordId !== '') {
            try {
                $delRes = $cf->deleteSubdomain($effectiveZone, $effectiveRecordId, [
                    'name' => $rec->name ?? null,
                    'type' => $rec->type ?? null,
                    'content' => $rec->content ?? null,
                ]);
                if (!($delRes['success'] ?? false)) {
                    $errorCode = $delRes['code'] ?? null;
                    $errorMessage = $delRes['errors'] ?? ($delRes['message'] ?? '');
                    if (is_array($errorMessage)) {
                        $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE);
                    }
                    $errorMessage = (string) $errorMessage;
                    $isNotFound = $errorCode === 404
                        || $errorCode === '404'
                        || stripos($errorMessage, 'not found') !== false
                        || stripos($errorMessage, '不存在') !== false
                        || stripos($errorMessage, 'does not exist') !== false
                        || stripos($errorMessage, 'record not found') !== false;
                    if (!$isNotFound) {
                        $deleteFailed = $errorMessage;
                    }
                }
            } catch (\Throwable $e) {
                $deleteFailed = $e->getMessage();
            }
        }

        if ($deleteFailed === null) {
            $remoteExists = self::remoteRecordExistsForLocal($cf, $effectiveZone, $rec);
            if ($remoteExists === null) {
                $deleteFailed = 'provider verify failed';
            } elseif ($remoteExists === true) {
                $deleteFailed = 'remote delete not confirmed';
            }
        }

        if ($deleteFailed !== null) {
            throw new \RuntimeException('dns_delete_failed: ' . (string) $deleteFailed);
        }

        $result = Capsule::transaction(function () use ($subdomain_id, $userid, $rec, $effectiveRecordId) {
            $sub = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomain_id)
                ->where('userid', $userid)
                ->lockForUpdate()
                ->first();
            if (!$sub) {
                throw new \RuntimeException('subdomain_not_found');
            }

            $deletedRows = Capsule::table('mod_cloudflare_dns_records')
                ->where('id', intval($rec->id))
                ->where('subdomain_id', $subdomain_id)
                ->delete();
            if ($deletedRows <= 0) {
                throw new \RuntimeException('record_not_found');
            }

            $subDnsRecordId = trim((string) ($sub->dns_record_id ?? ''));
            $localRecordId = trim((string) ($rec->record_id ?? ''));
            if ((string) ($rec->name ?? '') === (string) ($sub->subdomain ?? '')
                && ($subDnsRecordId !== '' && ($subDnsRecordId === $localRecordId || $subDnsRecordId === $effectiveRecordId))) {
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->update([
                        'dns_record_id' => null,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            $remainingRecords = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomain_id)
                ->count();

            if ($remainingRecords == 0) {
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->update([
                        'notes' => '已注册，等待解析设置',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            CfSubdomainService::syncDnsHistoryFlag($subdomain_id);

            return [
                'subdomain_id' => $subdomain_id,
                'record_id' => (string) ($effectiveRecordId !== '' ? $effectiveRecordId : ($rec->record_id ?? '')),
                'record_name' => $rec->name ?? '',
                'record_type' => $rec->type ?? '',
            ];
        });

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('client_delete_dns_record', [
                'record_id' => $result['record_id'],
                'name' => $result['record_name'],
                'type' => $result['record_type']
            ], $userid, $result['subdomain_id']);
        }

        $msg = self::actionText('dns.delete.success', '已删除DNS记录');
        $msg_type = "success";

    } catch (\Throwable $e) {
        $errorMessage = $e->getMessage();

        if (strpos($errorMessage, 'subdomain_not_found') !== false) {
            $msg = self::actionText('dns.delete.subdomain_not_found', '域名不存在或已被删除，请刷新页面');
            $msg_type = 'warning';
        } elseif (strpos($errorMessage, 'record_not_found') !== false) {
            $msg = self::actionText('dns.delete.record_not_found', 'DNS记录不存在或已被删除，请刷新页面');
            $msg_type = 'warning';
        } elseif (strpos($errorMessage, 'provider_unavailable') !== false) {
            $msg = self::actionText('dns.delete.provider_error', 'DNS供应商暂时不可用，请稍后再试');
            $msg_type = 'danger';
        } elseif (strpos($errorMessage, 'dns_delete_failed:') !== false) {
            $errorDetail = cfmod_format_provider_error(str_replace('dns_delete_failed: ', '', $errorMessage));
            $msg = self::actionText('dns.delete.failed_detail', '删除DNS记录失败：%s', [$errorDetail]);
            $msg_type = 'danger';
        } else {
            $errorDetail = cfmod_format_provider_error($errorMessage);
            $msg = self::actionText('dns.delete.failed_detail', '删除DNS记录失败：%s', [$errorDetail]);
            $msg_type = 'danger';
        }
    }
}

// 一键替换入整组 NS（域名委派）
if($_POST['action'] == 'replace_ns_group' && isset($_POST['subdomain_id'])) {
    $replaceNsSubdomainId = intval($_POST['subdomain_id']);
    if (cfmod_is_subdomain_deletion_locked_for_user($replaceNsSubdomainId, intval($userid ?? 0))) {
        $msg = cfmod_deletion_locked_response_text();
        $msg_type = 'warning';
        return ['msg' => $msg, 'msg_type' => $msg_type, 'registerError' => $registerError];
    }
    $replaceNsRootdomain = self::getSubdomainRootdomain($replaceNsSubdomainId);
    if (self::isRootdomainInMaintenance($replaceNsRootdomain)) {
        $msg = self::actionText('dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$replaceNsRootdomain]);
        $msg_type = 'warning';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }
    if ($enableDnsUnlockFeature && !CfDnsUnlockService::userHasUnlocked($userid ?? 0)) {
        $msg = self::actionText('dns.unlock.required', '请先完成 DNS 解锁后再设置 DNS 服务器。');
        $msg_type = 'warning';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }
    // VPN/代理检测（NS替换操作）
    if (class_exists('CfVpnDetectionService') && CfVpnDetectionService::isDnsCheckEnabled($module_settings)) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $module_settings);
        if (!empty($vpnCheckResult['blocked'])) {
            $msg = self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。');
            $msg_type = 'warning';
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('vpn_detection_blocked_dns', [
                    'action' => 'replace_ns_group',
                    'ip' => $clientIp,
                    'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                ], $userid ?? 0, null);
            }
            return [
                'msg' => $msg,
                'msg_type' => $msg_type,
                'registerError' => $registerError,
            ];
        }
    }
    $isRootNsDisabled = self::isRootdomainNsManagementDisabled($replaceNsRootdomain);
    if (!$disableNsManagement && !$disableDnsWrite && !$isRootNsDisabled && self::shouldUseAsyncDns('replace_ns_group', $module_settings, $isAsyncReplay)) {
        $jobId = self::enqueueAsyncDnsJob(intval($userid ?? 0), 'replace_ns_group');
        $msg = self::formatAsyncQueuedMessage($jobId);
        $msg_type = 'info';
        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }

    if ($disableNsManagement || $disableDnsWrite || $isRootNsDisabled) {
        $msg = self::actionText('dns.operations_disabled', '当前已禁止新增/修改 DNS 记录');
        $msg_type = 'warning';
    } else {
        if ($disableNsManagement) {
            $msg = self::actionText('dns.ns.disabled', '已禁止设置 DNS 服务器（NS）。');
            $msg_type = 'warning';
        } else {
            $subdomain_id = intval($_POST['subdomain_id']);
            $lines = trim($_POST['ns_lines'] ?? '');
            $forceReplace = isset($_POST['force_replace']) && $_POST['force_replace'] == '1';

            $preList = array_filter(
                array_unique(array_map(function ($x) {
                    return strtolower(trim($x));
                }, explode("\n", $lines))),
                function ($v) {
                    return !empty($v);
                }
            );
            if (count($preList) > $nsMaxPerDomain) {
                $msg = self::actionText('dns.ns.submission_limit', 'NS 服务器最多允许 %1$s 条；当前提交 %2$s 条，请删减后重试', [$nsMaxPerDomain, count($preList)]);
                $msg_type = 'warning';
                throw new Exception($msg);
            }

            try {
                $sub = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $subdomain_id)
                    ->where('userid', $userid)
                    ->first();

                if ($sub) {
                    if ($sub->status === 'suspended') {
                        $msg = self::actionText('dns.domain_suspended', '该域名已被暂停，无法进行解析操作');
                        $msg_type = "warning";
                    } else {
                        list($cf, $providerError, $providerContext) = cfmod_client_acquire_provider_for_subdomain($sub, $module_settings);
                        if (!$cf) {
                            $msg = $providerError;
                            $msg_type = 'danger';
                        } else {
                            $zoneId = (string) ($sub->cloudflare_zone_id ?: $sub->rootdomain);
                            if ($zoneId === '') {
                                throw new Exception(self::actionText('dns.ns.zone_missing', '缺少 Zone 信息，请联系管理员检查域名配置。'));
                            }

                            $list = array_filter(
                                array_unique(array_map(function ($x) {
                                    return strtolower(trim($x));
                                }, explode("\n", $lines))),
                                function ($v) {
                                    return !empty($v);
                                }
                            );
                            $validList = [];
                            $invalidList = [];
                            $domainRegex = '/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+\.?$/i';
                            foreach ($list as $ns) {
                                if (preg_match($domainRegex, $ns)) {
                                    $validList[] = rtrim($ns, '.');
                                } else {
                                    $invalidList[] = $ns;
                                }
                            }
                            $defaultNsList = self::resolveDefaultNsList($module_settings);

                            $normalizedValidList = $validList;
                            sort($normalizedValidList);
                            $useDefaultNsMode = count($validList) === 0
                                || (!empty($defaultNsList) && $normalizedValidList === $defaultNsList);

                            if (!$useDefaultNsMode && count($validList) === 0) {
                                $msg = self::actionText('dns.ns.no_valid_servers', '未检测到有效的 NS 服务器，请检查格式（每行一个完整域名）');
                                $msg_type = 'warning';
                                throw new Exception($msg);
                            }

                            $hasLocalNsRecords = Capsule::table('mod_cloudflare_dns_records')
                                ->where('subdomain_id', $subdomain_id)
                                ->whereRaw('LOWER(name) = ?', [strtolower((string) $sub->subdomain)])
                                ->whereRaw('UPPER(type) = ?', ['NS'])
                                ->exists();
                            $subdomainHasDefaultNsModeColumn = false;
                            try {
                                $subdomainHasDefaultNsModeColumn = Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'default_ns_mode');
                            } catch (\Throwable $ignored) {}

                            if ($useDefaultNsMode && !$hasLocalNsRecords) {
                                $orphanLocalNsDeleted = 0;
                                try {
                                    $orphanLocalNsDeleted = (int) Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $subdomain_id)
                                        ->whereRaw('UPPER(type) = ?', ['NS'])
                                        ->delete();
                                } catch (\Throwable $cleanupEx) {
                                    $orphanLocalNsDeleted = 0;
                                }
                                if ($subdomainHasDefaultNsModeColumn) {
                                    Capsule::table('mod_cloudflare_subdomain')
                                        ->where('id', $subdomain_id)
                                        ->update([
                                            'default_ns_mode' => 1,
                                            'updated_at' => date('Y-m-d H:i:s'),
                                        ]);
                                }
                                CfSubdomainService::syncDnsHistoryFlag($subdomain_id);
                                $msgParts = [];
                                if ($orphanLocalNsDeleted > 0) {
                                    $msgParts[] = self::actionText('dns.ns.summary.orphan_ns_cleaned', '已清理本地残留 NS %s 条', [$orphanLocalNsDeleted]);
                                }
                                $msgParts[] = self::actionText('dns.ns.summary.default_mode', '已切换为本站默认解析（系统默认NS）');
                                $msg = implode(self::actionText('common.list_separator', '，'), $msgParts);
                                $msg_type = 'success';
                                $searchTermSafe = is_string($searchTerm ?? null) ? $searchTerm : '';
                                list($existing, $existing_total, $domainTotalPages, $domainPage) = cfmod_client_load_subdomains_paginated(
                                    $userid,
                                    $domainPage,
                                    $domainPageSize,
                                    $searchTermSafe
                                );
                                $existingNsMap = [];
                                $existingRootMaintMap = [];
                                $existingRootSuspendMap = [];
                                $existingVerifyMap = [];
                                $existingRootProviderFlags = [];
                                $existingSubdomainRootdomainMap = [];
                                $searchTerm = $searchTermSafe;
                                return compact('existing', 'existing_total', 'msg', 'msg_type', 'existingNsMap', 'domainTotalPages', 'domainPage', 'domainPageSize', 'searchTerm', 'existingRootMaintMap', 'existingRootSuspendMap', 'existingVerifyMap', 'existingSubdomainRootdomainMap', 'existingRootProviderFlags');
                            }

                            $deleteLocalByRemote = static function (int $targetSubdomainId, array $remoteRecord): void {
                                $recordId = trim((string) ($remoteRecord['id'] ?? ''));
                                if ($recordId !== '') {
                                    $deleted = Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $targetSubdomainId)
                                        ->where('record_id', $recordId)
                                        ->delete();
                                    if ($deleted > 0) {
                                        return;
                                    }
                                }

                                $name = strtolower(trim((string) ($remoteRecord['name'] ?? '')));
                                $type = strtoupper(trim((string) ($remoteRecord['type'] ?? '')));
                                $content = trim((string) ($remoteRecord['content'] ?? ''));
                                if ($name === '' || $type === '') {
                                    return;
                                }

                                $match = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $targetSubdomainId)
                                    ->whereRaw('LOWER(name) = ?', [$name])
                                    ->whereRaw('UPPER(type) = ?', [$type])
                                    ->where(function ($query) use ($content) {
                                        $query->where('content', $content)
                                            ->orWhereRaw('LOWER(content) = ?', [strtolower($content)]);
                                    })
                                    ->orderBy('id', 'asc')
                                    ->first();
                                if ($match) {
                                    Capsule::table('mod_cloudflare_dns_records')
                                        ->where('id', intval($match->id ?? 0))
                                        ->delete();
                                }
                            };

                            $resolveDeleteErrorText = static function ($response): string {
                                if (!is_array($response)) {
                                    return '';
                                }
                                $errorMessage = $response['errors'] ?? ($response['message'] ?? ($response['error'] ?? ''));
                                if (is_array($errorMessage)) {
                                    $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE);
                                }
                                return trim((string) $errorMessage);
                            };

                            $isProviderDeleteSuccess = static function ($response) use ($resolveDeleteErrorText): bool {
                                if (!is_array($response)) {
                                    return false;
                                }
                                if (!empty($response['success'])) {
                                    return true;
                                }
                                $errorCode = $response['code'] ?? ($response['http_code'] ?? null);
                                if ($errorCode === 404 || $errorCode === '404') {
                                    return true;
                                }
                                $errorText = strtolower($resolveDeleteErrorText($response));
                                if ($errorText === '') {
                                    return false;
                                }
                                return strpos($errorText, 'not found') !== false
                                    || strpos($errorText, 'record not found') !== false
                                    || strpos($errorText, 'does not exist') !== false
                                    || strpos($errorText, '不存在') !== false;
                            };

                            $limitPerSub = intval($module_settings['max_dns_records_per_subdomain'] ?? 0);
                            if ($limitPerSub > 0) {
                                $currentCount = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $subdomain_id)
                                    ->count();
                                $plannedNsRemove = Capsule::table('mod_cloudflare_dns_records')
                                    ->where('subdomain_id', $subdomain_id)
                                    ->whereRaw('LOWER(name) = ?', [strtolower((string) $sub->subdomain)])
                                    ->whereRaw('UPPER(type) = ?', ['NS'])
                                    ->count();
                                $plannedConflictRemove = 0;
                                if ($forceReplace) {
                                    $plannedConflictRemove = Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $subdomain_id)
                                        ->whereRaw('LOWER(name) = ?', [strtolower((string) $sub->subdomain)])
                                        ->whereRaw('UPPER(type) <> ?', ['NS'])
                                        ->count();
                                }
                                $projectedCount = max(0, intval($currentCount) - intval($plannedNsRemove) - intval($plannedConflictRemove)) + count($validList);
                                if ($projectedCount > $limitPerSub) {
                                    $msg = self::actionText('dns.ns.limit_exceeded', '此次替换将超出该域名的解析数量上限（%s），已取消操作', [$limitPerSub]);
                                    $msg_type = 'warning';
                                    throw new Exception($msg);
                                }
                            }

                            $deletedCount = 0;
                            $conflictDeleted = 0;
                            $cleanupErrors = [];

                            $existing = $cf->getDnsRecords($zoneId, $sub->subdomain, ['type' => 'NS', 'per_page' => 1000]);
                            if (!($existing['success'] ?? false)) {
                                $errorText = $resolveDeleteErrorText($existing);
                                throw new Exception(self::actionText('dns.ns.fetch_failed', '读取现有 NS 记录失败：%s', [$errorText !== '' ? $errorText : self::actionText('errors.unknown', '未知错误')]));
                            }
                            $desiredNsMap = [];
                            foreach ($validList as $nsItem) {
                                $normNs = rtrim(strtolower(trim((string) $nsItem)), '.');
                                if ($normNs === '') {
                                    continue;
                                }
                                if (!isset($desiredNsMap[$normNs])) {
                                    $desiredNsMap[$normNs] = rtrim(trim((string) $nsItem), '.');
                                }
                            }

                            $remoteNsRecords = [];
                            $remoteNsNormalizedMap = [];
                            foreach (($existing['result'] ?? []) as $r) {
                                $recordType = strtoupper((string) ($r['type'] ?? ''));
                                $recordName = strtolower((string) ($r['name'] ?? ''));
                                if ($recordType !== 'NS' || $recordName !== strtolower((string) $sub->subdomain)) {
                                    continue;
                                }
                                $remoteNsRecords[] = $r;
                                $remoteNormNs = rtrim(strtolower(trim((string) ($r['content'] ?? ''))), '.');
                                if ($remoteNormNs !== '') {
                                    $remoteNsNormalizedMap[$remoteNormNs] = true;
                                }
                            }

                            $toDeleteRecords = [];
                            foreach ($remoteNsRecords as $remoteRecord) {
                                $remoteNormNs = rtrim(strtolower(trim((string) ($remoteRecord['content'] ?? ''))), '.');
                                if (!$useDefaultNsMode && isset($desiredNsMap[$remoteNormNs])) {
                                    continue;
                                }
                                $toDeleteRecords[] = $remoteRecord;
                            }

                            foreach ($toDeleteRecords as $r) {
                                $recordId = trim((string) ($r['id'] ?? ''));
                                if ($recordId === '') {
                                    $cleanupErrors[] = ['record' => 'NS:?', 'error' => 'missing_record_id'];
                                    continue;
                                }
                                $delRes = $cf->deleteSubdomain($zoneId, $recordId, [
                                    'name' => $r['name'] ?? $sub->subdomain,
                                    'type' => $r['type'] ?? 'NS',
                                    'content' => $r['content'] ?? null,
                                ]);
                                if ($isProviderDeleteSuccess($delRes)) {
                                    $deletedCount++;
                                    $deleteLocalByRemote($subdomain_id, $r);
                                } else {
                                    $cleanupErrors[] = [
                                        'record' => 'NS:' . $recordId,
                                        'error' => $resolveDeleteErrorText($delRes),
                                    ];
                                }
                            }

                            if ($forceReplace) {
                                $allAt = $cf->getDnsRecords($zoneId, $sub->subdomain, ['per_page' => 1000]);
                                if (!($allAt['success'] ?? false)) {
                                    $errorText = $resolveDeleteErrorText($allAt);
                                    throw new Exception(self::actionText('dns.ns.fetch_failed', '读取现有 NS 记录失败：%s', [$errorText !== '' ? $errorText : self::actionText('errors.unknown', '未知错误')]));
                                }
                                foreach (($allAt['result'] ?? []) as $r) {
                                    $recordType = strtoupper((string) ($r['type'] ?? ''));
                                    $recordName = strtolower((string) ($r['name'] ?? ''));
                                    if ($recordName !== strtolower((string) $sub->subdomain) || $recordType === 'NS') {
                                        continue;
                                    }
                                    $recordId = trim((string) ($r['id'] ?? ''));
                                    if ($recordId === '') {
                                        $cleanupErrors[] = ['record' => $recordType . ':?', 'error' => 'missing_record_id'];
                                        continue;
                                    }
                                    $delRes = $cf->deleteSubdomain($zoneId, $recordId, [
                                        'name' => $r['name'] ?? $sub->subdomain,
                                        'type' => $r['type'] ?? $recordType,
                                        'content' => $r['content'] ?? null,
                                    ]);
                                    if ($isProviderDeleteSuccess($delRes)) {
                                        $conflictDeleted++;
                                        $deleteLocalByRemote($subdomain_id, $r);
                                    } else {
                                        $cleanupErrors[] = [
                                            'record' => $recordType . ':' . $recordId,
                                            'error' => $resolveDeleteErrorText($delRes),
                                        ];
                                    }
                                }
                            }

                            if (!empty($cleanupErrors)) {
                                $preview = [];
                                foreach (array_slice($cleanupErrors, 0, 3) as $entry) {
                                    $preview[] = ($entry['record'] ?? '?') . ' => ' . (($entry['error'] ?? '') !== '' ? $entry['error'] : self::actionText('errors.unknown', '未知错误'));
                                }
                                throw new Exception(self::actionText('dns.ns.cleanup_failed', '清理旧记录失败：%s', [implode('; ', $preview)]));
                            }

                            $created = [];
                            $errors = [];
                            if (!$useDefaultNsMode) {
                                $toAddNs = [];
                                foreach ($desiredNsMap as $normalizedNs => $originNs) {
                                    if (!isset($remoteNsNormalizedMap[$normalizedNs])) {
                                        $toAddNs[] = $originNs;
                                    }
                                }
                                foreach ($toAddNs as $ns) {
                                    $res = $cf->createDnsRecord($zoneId, $sub->subdomain, 'NS', $ns, 86400, false);
                                    if ($res['success']) {
                                        $rid = $res['result']['id'];
                                        Capsule::table('mod_cloudflare_dns_records')->insert([
                                            'subdomain_id' => $subdomain_id,
                                            'zone_id' => $zoneId,
                                            'record_id' => $rid !== null ? (string) $rid : null,
                                            'name' => $sub->subdomain,
                                            'type' => 'NS',
                                            'content' => $ns,
                                            'ttl' => 86400,
                                            'proxied' => 0,
                                            'line' => null,
                                            'status' => 'active',
                                            'created_at' => date('Y-m-d H:i:s'),
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ]);
                                        $created[] = $ns;
                                    } else {
                                        $errMsg = self::actionText('errors.unknown', '未知错误');
                                        if (!empty($res['errors'])) {
                                            $errMsg = is_array($res['errors'])
                                                ? json_encode($res['errors'], JSON_UNESCAPED_UNICODE)
                                                : (string) $res['errors'];
                                        }
                                        $errors[] = ['ns' => $ns, 'error' => $errMsg];
                                    }
                                }
                            }
                            CfSubdomainService::syncDnsHistoryFlag($subdomain_id);
                            if ($subdomainHasDefaultNsModeColumn) {
                                Capsule::table('mod_cloudflare_subdomain')
                                    ->where('id', $subdomain_id)
                                    ->update([
                                        'default_ns_mode' => $useDefaultNsMode ? 1 : 0,
                                        'updated_at' => date('Y-m-d H:i:s'),
                                    ]);
                            }

                            if (function_exists('cloudflare_subdomain_log')) {
                                cloudflare_subdomain_log('client_replace_ns_group', [
                                    'deletedCount' => $deletedCount,
                                    'conflictDeleted' => $conflictDeleted,
                                    'created' => $created,
                                    'invalid' => $invalidList,
                                    'errors' => $errors
                                ], $userid, $subdomain_id);
                            }

                            $parts = [];
                            $parts[] = self::actionText('dns.ns.summary.deleted', '已删除旧 NS %s 条', [$deletedCount]);
                            if ($forceReplace) {
                                $parts[] = self::actionText('dns.ns.summary.conflicts', '已清理冲突记录 %s 条', [$conflictDeleted]);
                            }
                            if ($useDefaultNsMode) {
                                $parts[] = self::actionText('dns.ns.summary.default_mode', '已切换为本站默认解析（系统默认NS）');
                            } else {
                                $parts[] = self::actionText('dns.ns.summary.created', '新增成功 %s 条', [count($created)]);
                            }
                            if (count($invalidList) > 0) {
                                $preview = implode(', ', array_slice($invalidList, 0, 3));
                                if ($preview) {
                                    $parts[] = self::actionText('dns.ns.summary.invalid_preview', '忽略无效格式 %1$s 条（示例：%2$s）', [count($invalidList), $preview]);
                                } else {
                                    $parts[] = self::actionText('dns.ns.summary.invalid', '忽略无效格式 %s 条', [count($invalidList)]);
                                }
                            }
                            if (count($errors) > 0) {
                                $previewErr = [];
                                foreach (array_slice($errors, 0, 3) as $er) {
                                    $previewErr[] = ($er['ns'] ?? '?') . ' => ' . ($er['error'] ?? '');
                                }
                                if (count($previewErr)) {
                                    $parts[] = self::actionText('dns.ns.summary.failed_preview', '新增失败 %1$s 条（示例：%2$s）', [count($errors), implode('; ', $previewErr)]);
                                } else {
                                    $parts[] = self::actionText('dns.ns.summary.failed', '新增失败 %s 条', [count($errors)]);
                                }
                            }
                            $separator = self::actionText('common.list_separator', '，');
                            $msg = implode($separator, $parts);
                            if (count($errors) === 0 && (count($created) > 0 || $useDefaultNsMode)) {
                                $msg_type = 'success';
                            } elseif (count($created) > 0) {
                                $msg_type = 'warning';
                            } else {
                                $msg_type = 'danger';
                            }

                            list($existing, $existing_total, $domainTotalPages, $domainPage) = cfmod_client_load_subdomains_paginated(
                                $userid,
                                $domainPage,
                                $domainPageSize,
                                $domainSearchTerm
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                if (!isset($msg) || !$msg) {
                    $errorText = cfmod_format_provider_error($e->getMessage());
                    $msg = self::actionText('dns.ns.bulk_failed', '批量替换 NS 失败：%s', [$errorText]);
                }
                $msg_type = isset($msg_type) && $msg_type ? $msg_type : 'danger';
            }
        }
    }
}



        return [
            'msg' => $msg,
            'msg_type' => $msg_type,
            'registerError' => $registerError,
        ];
    }

    private static function enforceClientRateLimit(string $action, array $settings, int $userid): void
    {
        $scope = self::resolveClientRateLimitScope($action);
        if ($scope === null) {
            return;
        }
        $limit = CfRateLimiter::resolveLimit($scope, $settings);
        CfRateLimiter::enforce($scope, $limit, [
            'userid' => $userid,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'identifier' => $action,
        ]);
    }

    private static function resolveClientRateLimitScope(string $action): ?string
    {
        static $dnsActions = ['create_dns', 'update_dns', 'delete_dns_record', 'toggle_cdn', 'toggle_record_cdn', 'delete_subdomain'];
        static $quotaActions = ['claim_invite', 'request_invite_reward', 'claim_github_star_reward', 'claim_telegram_group_reward', 'unbind_telegram_binding', 'update_expiry_telegram_reminder', 'submit_partner_application', 'create_domain_permanent_upgrade_request', 'assist_domain_permanent_upgrade', 'cancel_domain_permanent_upgrade_request'];
        if ($action === 'register' || $action === 'renew') {
            return CfRateLimiter::SCOPE_REGISTER;
        }
        if (in_array($action, $dnsActions, true)) {
            return CfRateLimiter::SCOPE_DNS;
        }
        if (in_array($action, $quotaActions, true)) {
            return CfRateLimiter::SCOPE_QUOTA_GIFT;
        }
        if ($action === 'check_and_upgrade_domain_permanent_incentive') {
            return CfRateLimiter::SCOPE_PERM_INCENTIVE;
        }
        if ($action === 'dns_unlock' || $action === 'purchase_dns_unlock') {
            return CfRateLimiter::SCOPE_DNS_UNLOCK;
        }
        return null;
    }

    private static function shouldUseAsyncDns(string $action, array $settings, bool $isAsyncReplay): bool
    {
        if ($isAsyncReplay) {
            return false;
        }
        if (!cfmod_setting_enabled($settings['enable_async_dns_operations'] ?? '0')) {
            return false;
        }
        static $supported = ['create_dns', 'update_dns', 'delete_dns_record', 'replace_ns_group', 'toggle_cdn', 'toggle_record_cdn'];
        return in_array($action, $supported, true);
    }

    private static function shouldSkipProviderExistsCheck(array $providerContext, array $settings, string $rootdomain = ''): bool
    {
        $providerType = strtolower(trim((string) ($providerContext['account']['provider_type'] ?? ($providerContext['provider_type'] ?? ''))));
        if ($providerType !== '') {
            $isPdns = ($providerType === 'powerdns');
        } else {
            $client = $providerContext['client'] ?? null;
            $isPdns = is_object($client) && stripos(get_class($client), 'powerdns') !== false;
        }

        if (!$isPdns) {
            return false;
        }

        $strategy = strtolower(trim((string) ($settings['pdns_register_strategy'] ?? '')));
        if (!in_array($strategy, ['local_only', 'hybrid', 'strict_remote'], true)) {
            $legacyRaw = trim((string) ($settings['pdns_register_local_check_only'] ?? '1'));
            if ($legacyRaw === '') {
                $strategy = 'local_only';
            } else {
                $strategy = cfmod_setting_enabled($legacyRaw) ? 'local_only' : 'strict_remote';
            }
        }

        if ($strategy === 'local_only') {
            return true;
        }
        if ($strategy === 'strict_remote') {
            return false;
        }

        $thresholdRaw = trim((string) ($settings['pdns_register_hybrid_local_threshold'] ?? '800'));
        $threshold = is_numeric($thresholdRaw) ? (int) $thresholdRaw : 800;
        $threshold = max(100, min(50000, $threshold));

        $normalizedRoot = strtolower(trim($rootdomain));
        if ($normalizedRoot === '') {
            return false;
        }

        try {
            $localSubdomains = (int) Capsule::table('mod_cloudflare_subdomain')
                ->whereRaw('LOWER(rootdomain) = ?', [$normalizedRoot])
                ->count();
        } catch (\Throwable $e) {
            return true;
        }

        return $localSubdomains >= $threshold;
    }

    private static function enqueueAsyncDnsJob(int $userid, string $action): ?int
    {
        if (!class_exists('CfAsyncDnsJobService')) {
            return null;
        }
        if ($userid <= 0) {
            return null;
        }
        $payload = self::buildAsyncPostPayload($_POST);
        return CfAsyncDnsJobService::enqueue($userid, $action, $payload);
    }

    private static function buildAsyncPostPayload(array $input): array
    {
        $payload = $input;
        unset($payload['token'], $payload['cfmod_csrf_token']);
        $payload['__cf_async_dns'] = 1;
        return $payload;
    }

    private static function formatAsyncQueuedMessage(?int $jobId): string
    {
        $message = self::actionText('dns.async.queued', '操作已提交到后台队列，将在稍后自动执行。');
        if ($jobId) {
            $message .= ' ' . self::actionText('dns.async.job', '任务编号：#%s', [$jobId]);
        }
        return $message;
    }

    private static function cleanupAllRemoteDnsForSubdomain($sub, array $settings): int
    {
        $context = cfmod_acquire_provider_client_for_subdomain($sub, $settings);
        if (!$context || empty($context['client'])) {
            throw new \RuntimeException('provider unavailable');
        }
        $client = $context['client'];
        $zoneId = $sub->cloudflare_zone_id ?: ($sub->rootdomain ?? '');
        if ($zoneId === '') {
            throw new \RuntimeException('zone missing');
        }
        $targetDomain = strtolower(trim((string) ($sub->subdomain ?? '')));
        $listPerPage = max(100, min(5000, intval($settings['client_cleanup_remote_scan_per_page'] ?? 1000)));
        $maxProcess = max(100, min(20000, intval($settings['client_cleanup_remote_max_process'] ?? 2000)));
        $timeBudgetMs = max(500, min(10000, intval($settings['client_cleanup_remote_time_budget_ms'] ?? 4000)));
        $deadline = microtime(true) + ($timeBudgetMs / 1000.0);

        $remote = $client->getDnsRecords($zoneId, null, ['per_page' => $listPerPage]);
        if (!($remote['success'] ?? false)) {
            throw new \RuntimeException('remote list failed');
        }
        $deletedCount = 0;
        $processed = 0;
        $truncated = false;
        foreach (($remote['result'] ?? []) as $record) {
            if ($processed >= $maxProcess || microtime(true) >= $deadline) {
                $truncated = true;
                break;
            }
            $processed++;
            $recordName = strtolower(trim((string) ($record['name'] ?? '')));
            if (!self::recordBelongsToDomain($recordName, $targetDomain)) {
                continue;
            }
            $recordId = trim((string) ($record['id'] ?? ''));
            if ($recordId === '') {
                continue;
            }
            $res = $client->deleteSubdomain($zoneId, $recordId, [
                'name' => $record['name'] ?? null,
                'type' => $record['type'] ?? null,
                'content' => $record['content'] ?? null,
            ]);
            if (!($res['success'] ?? false) && !api_provider_not_found($res)) {
                continue;
            }
            $deletedCount++;
        }
        if ($truncated && function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('client_orphan_cleanup_truncated', [
                'subdomain' => (string) ($sub->subdomain ?? ''),
                'processed' => $processed,
                'deleted' => $deletedCount,
                'max_process' => $maxProcess,
                'time_budget_ms' => $timeBudgetMs,
                'note' => 'partial run finished; schedule next worker run to continue',
            ], null, intval($sub->id ?? 0));
        }
        if (!$truncated) {
            try {
                Capsule::table('mod_cloudflare_dns_records')->where('subdomain_id', intval($sub->id ?? 0))->delete();
                if (class_exists('CfSubdomainService')) {
                    CfSubdomainService::syncDnsHistoryFlag((int) $sub->id);
                }
            } catch (\Throwable $e) {}
        }
        return $deletedCount;
    }

    private static function recordBelongsToDomain(string $recordName, string $targetDomain): bool
    {
        if ($recordName === '' || $targetDomain === '') {
            return false;
        }
        if ($recordName === $targetDomain) {
            return true;
        }
        return substr($recordName, -strlen('.' . $targetDomain)) === ('.' . $targetDomain);
    }

    private static function normalizePartnerWebsite(string $websiteRaw): string
    {
        $website = trim($websiteRaw);
        if ($website === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $website = mb_substr($website, 0, 255, 'UTF-8');
        } else {
            $website = substr($website, 0, 255);
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            return '';
        }
        $host = parse_url($website, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            return '';
        }
        return $website;
    }

    private static function resolvePartnerAdminRecipients(array $settings): array
    {
        $raw = trim((string) ($settings['partner_plan_admin_email'] ?? ''));
        if ($raw === '') {
            $raw = self::resolveSystemFromEmail();
        }
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $result = [];
        foreach ($parts as $item) {
            $email = trim((string) $item);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $result[strtolower($email)] = $email;
        }
        return array_values($result);
    }

    private static function resolveSystemFromEmail(): string
    {
        try {
            $candidates = Capsule::table('tblconfiguration')
                ->whereIn('setting', ['SystemEmailsFromEmail', 'Email', 'SupportEmail'])
                ->get();
            foreach ($candidates as $row) {
                $value = trim((string) ($row->value ?? ''));
                if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private static function buildPartnerApplicationEmailBody(array $payload): string
    {
        $lines = [
            '收到新的合作伙伴计划申请：',
            '',
            '提交时间：' . (string) ($payload['submitted_at'] ?? ''),
            '用户ID：' . (string) ($payload['userid'] ?? 0),
            '用户姓名：' . (string) ($payload['client_name'] ?? ''),
            '用户邮箱：' . (string) ($payload['client_email'] ?? ''),
            '网站地址：' . (string) ($payload['website'] ?? ''),
            '提交IP：' . (string) ($payload['ip'] ?? ''),
            '',
            '申请原因：',
            (string) ($payload['reason'] ?? ''),
        ];

        return implode("\n", $lines);
    }

    private static function sendPartnerApplicationEmail(array $recipients, string $subject, string $body, string $replyTo = ''): void
    {
        if (empty($recipients)) {
            throw new \RuntimeException('missing_recipient');
        }

        $normalizedSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
        if ($normalizedSubject === '') {
            $normalizedSubject = 'Partner Application';
        }

        if (function_exists('mb_encode_mimeheader')) {
            $encodedSubject = mb_encode_mimeheader($normalizedSubject, 'UTF-8', 'B', "\r\n");
        } else {
            $encodedSubject = $normalizedSubject;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $fromEmail = self::resolveSystemFromEmail();
        if ($fromEmail !== '') {
            $headers[] = 'From: ' . $fromEmail;
        }

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $headerString = implode("\r\n", $headers);
        $sent = 0;

        foreach ($recipients as $recipient) {
            $target = trim((string) $recipient);
            if ($target === '') {
                continue;
            }
            if (@mail($target, $encodedSubject, $body, $headerString)) {
                $sent++;
            }
        }

        if ($sent <= 0) {
            throw new \RuntimeException('邮件发送失败，请检查服务器 mail 配置。');
        }
    }

    private static function actionTextByLanguage(string $key, string $zhDefault, string $enDefault, array $params = []): string
    {
        $default = self::isChineseLanguage() ? $zhDefault : $enDefault;
        return self::actionText($key, $default, $params);
    }

    private static function isChineseLanguage(): bool
    {
        try {
            if (function_exists('cfmod_resolve_language_preference')) {
                $meta = cfmod_resolve_language_preference();
                $normalized = strtolower(trim((string) ($meta['normalized'] ?? '')));
                if ($normalized !== '') {
                    return $normalized === 'chinese';
                }
            }
        } catch (\Throwable $e) {
        }

        $sessionLanguage = strtolower(trim((string) ($_SESSION['Language'] ?? ($_SESSION['language'] ?? 'english'))));
        return $sessionLanguage === 'chinese';
    }

    private static function actionText(string $key, string $default, array $params = []): string
    {
        $text = cfmod_trans('cfclient.actions.' . $key, $default);
        if (!empty($params)) {
            try {
                $text = vsprintf($text, $params);
            } catch (\Throwable $e) {
                // ignore formatting errors
            }
        }
        return $text;
    }

    private static function resolveClientEmail(int $userid): string
    {
        if ($userid <= 0) {
            return '';
        }
        try {
            $row = Capsule::table('tblclients')->select('email')->where('id', $userid)->first();
            return trim((string) ($row->email ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function ensureUserPreferencesTable(): void
    {
        if (!Capsule::schema()->hasTable('mod_cloudflare_user_preferences')) {
            Capsule::schema()->create('mod_cloudflare_user_preferences', function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned()->unique();
                $table->string('renew_button_display_mode', 32)->default('window_only');
                $table->timestamps();
                $table->index(['renew_button_display_mode']);
            });
        } elseif (!Capsule::schema()->hasColumn('mod_cloudflare_user_preferences', 'renew_button_display_mode')) {
            Capsule::schema()->table('mod_cloudflare_user_preferences', function ($table) {
                $table->string('renew_button_display_mode', 32)->default('window_only');
            });
        }
    }

    private static function maskDomainForClientMessage(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return $domain;
        }
        $host = array_shift($parts);
        $suffix = implode('.', $parts);
        if ($host === '') {
            return '*.' . $suffix;
        }
        if (strlen($host) <= 2) {
            $maskedHost = substr($host, 0, 1) . '*';
        } else {
            $maskedHost = substr($host, 0, 1)
                . str_repeat('*', max(1, strlen($host) - 2))
                . substr($host, -1);
        }
        return $maskedHost . '.' . $suffix;
    }

    private static function normalizeDnsContent(string $content, string $type = ''): string
    {
        return CfDnsConflictRepairService::normalizeDnsContent($content, $type);
    }

    private static function remoteRecordMatchesLocal(array $remoteRecord, $localRecord): bool
    {
        $remoteName = self::normalizeDnsNameForCompare((string) ($remoteRecord['name'] ?? ''));
        $remoteType = strtoupper(trim((string) ($remoteRecord['type'] ?? '')));
        $remoteContent = self::normalizeDnsContent((string) ($remoteRecord['content'] ?? ''), $remoteType);

        $localName = self::normalizeDnsNameForCompare((string) ($localRecord->name ?? ''));
        $localType = strtoupper(trim((string) ($localRecord->type ?? '')));
        $localContent = self::normalizeDnsContent((string) ($localRecord->content ?? ''), $localType);

        return $remoteName === $localName
            && $remoteType === $localType
            && $remoteContent === $localContent;
    }

    private static function normalizeDnsNameForCompare(string $name): string
    {
        return CfDnsConflictRepairService::normalizeDnsName($name);
    }

    private static function isSubdomainUsingExternalDns(int $subdomainId, int $userId, array $moduleSettings): bool
    {
        if ($subdomainId <= 0 || $userId <= 0) {
            return false;
        }
        try {
            $sub = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userId)
                ->first();
            if (!$sub) {
                return false;
            }
            $currentNsRows = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomainId)
                ->whereRaw('UPPER(type) = ?', ['NS'])
                ->whereRaw('LOWER(name) = ?', [strtolower((string) ($sub->subdomain ?? ''))])
                ->pluck('content');
            $currentNsList = [];
            foreach ($currentNsRows as $value) {
                $ns = rtrim(strtolower(trim((string) $value)), '.');
                if ($ns !== '' && !in_array($ns, $currentNsList, true)) {
                    $currentNsList[] = $ns;
                }
            }
            sort($currentNsList);
            if (empty($currentNsList)) {
                return false;
            }
            $defaultNsList = self::resolveDefaultNsList($moduleSettings);
            if (empty($defaultNsList)) {
                return false;
            }
            return $currentNsList !== $defaultNsList;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    private static function isDefaultNameserverContent(string $nsContent, array $moduleSettings): bool
    {
        $normalized = rtrim(strtolower(trim($nsContent)), '.');
        if ($normalized === '') {
            return false;
        }
        $defaultNsList = self::resolveDefaultNsList($moduleSettings);
        if (empty($defaultNsList)) {
            return false;
        }
        return in_array($normalized, $defaultNsList, true);
    }

    private static function resolveDefaultNsList(array $moduleSettings): array
    {
        static $cache = [];

        $raw = trim((string) ($moduleSettings['whois_default_nameservers'] ?? ($moduleSettings['whois_default_ns_list'] ?? '')));
        if ($raw === '') {
            return [];
        }

        $cacheKey = md5($raw);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $defaultNsList = array_values(array_filter(array_unique(array_map(static function ($item) {
            return rtrim(strtolower(trim((string) $item)), '.');
        }, preg_split('/[\r\n,;]+/', $raw) ?: [])), static function ($item) {
            return $item !== '';
        }));
        sort($defaultNsList);

        $cache[$cacheKey] = $defaultNsList;
        return $defaultNsList;
    }


    private static function normalizeLineValue($line): string
    {
        return CfDnsConflictRepairService::normalizeLineValue($line);
    }

    private static function resolveSubmittedDnsTtl($rawTtl, string $recordTypeUpper): int
    {
        $ttlRaw = strtolower(trim((string) $rawTtl));
        if ($ttlRaw === 'auto' || $ttlRaw === '') {
            if ($recordTypeUpper === 'NS') {
                return cfmod_normalize_ttl(86400);
            }
            return cfmod_normalize_ttl(600);
        }
        return cfmod_normalize_ttl($rawTtl);
    }

    private static function passesCnameMutexRule(int $subdomainId, string $fullName, string $recordTypeUpper, ?int $excludeLocalRecordId = null): bool
    {
        $nameLower = strtolower(rtrim(trim($fullName), '.'));
        if ($subdomainId <= 0 || $nameLower === '') {
            return true;
        }
        $typeUpper = strtoupper(trim($recordTypeUpper));
        try {
            $query = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomainId)
                ->whereRaw('LOWER(name) = ?', [$nameLower]);
            if (($excludeLocalRecordId ?? 0) > 0) {
                $query->where('id', '<>', intval($excludeLocalRecordId));
            }
            $rows = $query->select('type')->get();
        } catch (\Throwable $e) {
            return true;
        }
        $hasCname = false;
        $hasNonCname = false;
        foreach ($rows as $row) {
            $t = strtoupper(trim((string) ($row->type ?? '')));
            if ($t === 'CNAME') {
                $hasCname = true;
            } elseif ($t !== '') {
                $hasNonCname = true;
            }
        }
        if ($typeUpper === 'CNAME') {
            return !$hasNonCname;
        }
        return !$hasCname;
    }

    private static function fetchRemoteRecordsForLocal($providerClient, string $zoneId, $localRecord): ?array
    {
        if (!$providerClient || !method_exists($providerClient, 'getDnsRecords')) {
            return null;
        }

        try {
            $name = (string) ($localRecord->name ?? '');
            $type = strtoupper(trim((string) ($localRecord->type ?? '')));
            $params = ['per_page' => 1000];
            if ($type !== '') {
                $params['type'] = $type;
            }
            $listRes = $providerClient->getDnsRecords($zoneId, $name, $params);
            if (!($listRes['success'] ?? false)) {
                return null;
            }
            return is_array($listRes['result'] ?? null) ? ($listRes['result'] ?? []) : [];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function findRemoteRecordIdForLocal($providerClient, string $zoneId, $localRecord): ?string
    {
        $records = self::fetchRemoteRecordsForLocal($providerClient, $zoneId, $localRecord);
        if ($records === null) {
            return null;
        }
        foreach ($records as $remoteRecord) {
            if (!is_array($remoteRecord)) {
                continue;
            }
            if (!self::remoteRecordMatchesLocal($remoteRecord, $localRecord)) {
                continue;
            }
            $id = isset($remoteRecord['id']) ? trim((string) $remoteRecord['id']) : '';
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    private static function remoteRecordExistsForLocal($providerClient, string $zoneId, $localRecord): ?bool
    {
        $records = self::fetchRemoteRecordsForLocal($providerClient, $zoneId, $localRecord);
        if ($records === null) {
            return null;
        }
        foreach ($records as $remoteRecord) {
            if (!is_array($remoteRecord)) {
                continue;
            }
            if (self::remoteRecordMatchesLocal($remoteRecord, $localRecord)) {
                return true;
            }
        }
        return false;
    }

    private static function findLocalRecordByRemote(int $subdomainId, array $remoteRecord)
    {
        if ($subdomainId <= 0) {
            return null;
        }
        $remoteRecordId = $remoteRecord['id'] ?? ($remoteRecord['record_id'] ?? null);
        if ($remoteRecordId !== null && $remoteRecordId !== '') {
            $match = Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subdomainId)
                ->where('record_id', (string) $remoteRecordId)
                ->first();
            if ($match) {
                return $match;
            }
        }

        $remoteNameLower = strtolower(trim((string)($remoteRecord['name'] ?? '')));
        $remoteTypeUpper = strtoupper(trim((string)($remoteRecord['type'] ?? '')));
        $remoteContent = (string)($remoteRecord['content'] ?? '');
        if ($remoteNameLower === '' || $remoteTypeUpper === '') {
            return null;
        }
        return Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomainId)
            ->whereRaw('LOWER(name) = ?', [$remoteNameLower])
            ->whereRaw('UPPER(type) = ?', [$remoteTypeUpper])
            ->where(function ($query) use ($remoteContent) {
                $query->where('content', $remoteContent)
                    ->orWhereRaw('LOWER(content) = ?', [strtolower($remoteContent)]);
            })
            ->first();
    }

    private static function tryAggressiveConflictRepair($providerClient, $record, int $userId, array $ctx): array
    {
        $settings = is_array($ctx['settings'] ?? null) ? $ctx['settings'] : [];
        if (!in_array(strtolower(trim((string) ($settings['dns_conflict_micro_aggressive_enabled'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true)) {
            return ['success' => false];
        }
        if (!in_array(strtoupper((string) ($ctx['type'] ?? '')), ['A', 'AAAA'], true)) { return ['success' => false]; }
        if (intval($ctx['local_pre_count'] ?? 1) !== 0) { return ['success' => false]; }
        if (self::normalizeLineValue((string) ($ctx['line'] ?? '')) !== '') { return ['success' => false]; }
        if (!self::microAggressiveAllowForUser($userId, $settings)) { return ['success' => false]; }
        $maxExtraApi = max(1, min(3, intval($settings['dns_conflict_micro_aggressive_max_extra_api'] ?? 3)));
        $used = 0;
        try {
            if ($used >= $maxExtraApi) { return ['success' => false]; }
            $used++;
            $listRes = $providerClient->getDnsRecords($record->cloudflare_zone_id, (string) $ctx['name'], ['per_page' => 1000, 'type' => (string) $ctx['type']]);
            if (!($listRes['success'] ?? false) || !is_array($listRes['result'] ?? null)) { return ['success' => false]; }
            $candidates = [];
            foreach ((array) $listRes['result'] as $rr) {
                if (!is_array($rr)) { continue; }
                if (CfDnsConflictRepairService::normalizeDnsName((string) ($rr['name'] ?? '')) !== CfDnsConflictRepairService::normalizeDnsName((string) $ctx['name'])) { continue; }
                if (CfDnsConflictRepairService::normalizeType((string) ($rr['type'] ?? '')) !== CfDnsConflictRepairService::normalizeType((string) $ctx['type'])) { continue; }
                $id = trim((string) ($rr['id'] ?? ''));
                if ($id === '') { continue; }
                $candidates[] = $rr;
            }
            if (count($candidates) === 0 || count($candidates) > max(1, min(2, intval($settings['dns_conflict_micro_aggressive_max_candidates'] ?? 2)))) {
                return ['success' => false];
            }
            $target = $candidates[0];
            if ($used >= $maxExtraApi) { return ['success' => false]; }
            $used++;
            $payload = ['type' => $ctx['type'], 'name' => CfDnsConflictRepairService::normalizeDnsName((string) $ctx['name']), 'content' => (string) $ctx['content'], 'ttl' => intval($ctx['ttl'])];
            $upRes = method_exists($providerClient, 'updateDnsRecordRaw')
                ? $providerClient->updateDnsRecordRaw($record->cloudflare_zone_id, (string) $target['id'], $payload)
                : $providerClient->updateDnsRecord($record->cloudflare_zone_id, (string) $target['id'], $payload);
            if (!($upRes['success'] ?? false)) {
                self::microAggressiveMarkFailure($userId, $settings);
                return ['success' => false];
            }
            if (count($candidates) > 1 && $used < $maxExtraApi && method_exists($providerClient, 'deleteDnsRecord')) {
                $used++;
                $providerClient->deleteDnsRecord($record->cloudflare_zone_id, (string) ($candidates[1]['id'] ?? ''));
            }
            self::microAggressiveMarkSuccess($userId, $settings);
            return ['success' => true, 'repair' => [
                'success' => true,
                'record_id' => (string) ($target['id'] ?? ''),
                'decision_path' => 'micro_aggressive',
                'remote_candidates_count' => count($candidates),
            ]];
        } catch (\Throwable $e) {
            self::microAggressiveMarkFailure($userId, $settings);
            return ['success' => false];
        }
    }

    private static function microAggressiveAllowForUser(int $userId, array $settings): bool
    {
        if ($userId <= 0 || !Capsule::schema()->hasTable('mod_cloudflare_rate_limits')) { return false; }
        $cooldown = max(60, intval($settings['dns_conflict_micro_aggressive_circuit_cooldown_seconds'] ?? 600));
        $maxPerMinute = max(1, intval($settings['dns_conflict_micro_aggressive_per_user_per_minute'] ?? 3));
        $failThreshold = max(1, intval($settings['dns_conflict_micro_aggressive_circuit_failure_threshold'] ?? 5));
        $now = time();
        $circuitBucket = 'micro_aggr_fail:user:' . $userId;
        $failHits = (int) Capsule::table('mod_cloudflare_rate_limits')->where('scope', 'dns_micro_aggressive_fail')->where('bucket', $circuitBucket)->value('hits');
        $updatedAt = (string) Capsule::table('mod_cloudflare_rate_limits')->where('scope', 'dns_micro_aggressive_fail')->where('bucket', $circuitBucket)->value('updated_at');
        if ($failHits >= $failThreshold && $updatedAt !== '' && strtotime($updatedAt) > ($now - $cooldown)) { return false; }
        $minuteBucket = 'micro_aggr:user:' . $userId . ':m' . date('YmdHi', $now);
        $minuteHits = (int) Capsule::table('mod_cloudflare_rate_limits')->where('scope', 'dns_micro_aggressive')->where('bucket', $minuteBucket)->value('hits');
        return $minuteHits < $maxPerMinute;
    }

    private static function microAggressiveMarkSuccess(int $userId, array $settings): void
    {
        if ($userId <= 0 || !Capsule::schema()->hasTable('mod_cloudflare_rate_limits')) { return; }
        $now = date('Y-m-d H:i:s');
        $minuteBucket = 'micro_aggr:user:' . $userId . ':m' . date('YmdHi');
        Capsule::statement('INSERT INTO `mod_cloudflare_rate_limits` (`scope`,`bucket`,`hits`,`expires_at`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `hits`=`hits`+1,`updated_at`=VALUES(`updated_at`)', ['dns_micro_aggressive', $minuteBucket, 1, date('Y-m-d H:i:s', time() + 120), $now, $now]);
        Capsule::table('mod_cloudflare_rate_limits')->where('scope', 'dns_micro_aggressive_fail')->where('bucket', 'micro_aggr_fail:user:' . $userId)->delete();
    }

    private static function microAggressiveMarkFailure(int $userId, array $settings): void
    {
        if ($userId <= 0 || !Capsule::schema()->hasTable('mod_cloudflare_rate_limits')) { return; }
        $now = date('Y-m-d H:i:s');
        $bucket = 'micro_aggr_fail:user:' . $userId;
        Capsule::statement('INSERT INTO `mod_cloudflare_rate_limits` (`scope`,`bucket`,`hits`,`expires_at`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `hits`=`hits`+1,`updated_at`=VALUES(`updated_at`)', ['dns_micro_aggressive_fail', $bucket, 1, date('Y-m-d H:i:s', time() + 86400), $now, $now]);
    }

    private static function isRootdomainInMaintenance(string $rootdomain): bool
    {
        if ($rootdomain === '') {
            return false;
        }
        try {
            $row = Capsule::table('mod_cloudflare_rootdomains')
                ->select('maintenance')
                ->whereRaw('LOWER(domain) = ?', [strtolower($rootdomain)])
                ->first();
            if ($row) {
                return intval($row->maintenance ?? 0) === 1;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return false;
    }

    private static function getSubdomainRootdomain(int $subdomainId): string
    {
        if ($subdomainId <= 0) {
            return '';
        }
        try {
            $row = Capsule::table('mod_cloudflare_subdomain')
                ->select('rootdomain')
                ->where('id', $subdomainId)
                ->first();
            return (string)($row->rootdomain ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function isRootdomainNsManagementDisabled(string $rootdomain): bool
    {
        if ($rootdomain === '') {
            return false;
        }
        try {
            $row = Capsule::table('mod_cloudflare_rootdomains')
                ->select('disable_ns_management')
                ->whereRaw('LOWER(domain) = ?', [strtolower($rootdomain)])
                ->first();
            if ($row) {
                return intval($row->disable_ns_management ?? 0) === 1;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return false;
    }

    private static function ensureRootVerifyTable(): void
    {
        if (!Capsule::schema()->hasTable('mod_cloudflare_root_verify_tasks')) {
            Capsule::schema()->create('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->unsigned();
                $table->integer('subdomain_id')->unsigned();
                $table->string('rootdomain', 255);
                $table->string('provider', 16);
                $table->string('host', 64);
                $table->text('txt_value');
                $table->integer('ttl')->default(600);
                $table->string('record_id', 100)->nullable();
                $table->string('status', 20)->default('active');
                $table->dateTime('locked_until')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->timestamps();
                $table->index(['rootdomain', 'status'], 'idx_root_status');
                $table->index('expires_at');
                $table->index('client_id');
            });
            return;
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'locked_until')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->dateTime('locked_until')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'expires_at')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->dateTime('expires_at')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'host')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->string('host', 64)->default('alidnscheck');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'client_id')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->integer('client_id')->unsigned()->default(0);
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'subdomain_id')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->integer('subdomain_id')->unsigned()->default(0);
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'rootdomain')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->string('rootdomain', 255)->default('');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'provider')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->string('provider', 16)->default('alidns');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'txt_value')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->text('txt_value')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'ttl')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->integer('ttl')->default(600);
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'record_id')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->string('record_id', 100)->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'status')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->string('status', 20)->default('active');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'created_at')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->dateTime('created_at')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cloudflare_root_verify_tasks', 'updated_at')) {
            Capsule::schema()->table('mod_cloudflare_root_verify_tasks', function ($table) {
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    private static function cleanupExpiredRootVerifyTasks(array $moduleSettings, int $limit = 20): void
    {
        try {
            self::ensureRootVerifyTable();
            $now = date('Y-m-d H:i:s');
            $rows = Capsule::table('mod_cloudflare_root_verify_tasks')
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->orderBy('id', 'asc')
                ->limit(max(1, min(200, $limit)))
                ->get();
            foreach ($rows as $row) {
                try {
                    $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', intval($row->subdomain_id ?? 0))->first();
                    $deleteOk = true;
                    if ($sub) {
                        list($cf,,) = cfmod_client_acquire_provider_for_subdomain($sub, $moduleSettings);
                        if ($cf) {
                            $zoneId = (string) ($sub->cloudflare_zone_id ?? ($row->rootdomain ?? ''));
                            $providerClass = is_object($cf) ? get_class($cf) : '';
                            if (stripos($providerClass, 'PowerDNS') !== false) {
                                $zoneId = (string) ($row->rootdomain ?? '');
                            }
                            $deleteOk = self::deleteRootVerifyTxtRecord(
                                $cf,
                                $zoneId,
                                (string) ($row->rootdomain ?? ''),
                                (string) ($row->host ?? ''),
                                (string) ($row->record_id ?? ''),
                                (string) ($row->txt_value ?? '')
                            );
                        }
                    }
                    Capsule::table('mod_cloudflare_root_verify_tasks')->where('id', intval($row->id))->update([
                        'status' => $deleteOk ? 'expired' : 'cleanup_failed',
                        'locked_until' => $now,
                        'updated_at' => $now,
                    ]);
                } catch (\Throwable $inner) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private static function deleteRootVerifyTxtRecord($providerClient, string $zoneId, string $rootdomain, string $host, string $recordId, string $txtValue): bool
    {
        $host = trim($host);
        $rootdomain = trim($rootdomain);
        $txtValue = trim($txtValue);
        $txtCandidates = array_values(array_unique(array_filter([
            $txtValue,
            '"' . str_replace('"', '\\"', $txtValue) . '"',
        ], function ($v) {
            return $v !== '';
        })));
        $nameCandidates = array_values(array_unique(array_filter([
            $host,
            $host !== '' && $rootdomain !== '' ? ($host . '.' . $rootdomain) : '',
            $host !== '' && $rootdomain !== '' ? ($host . '.' . $rootdomain . '.') : '',
        ], function ($v) {
            return $v !== '';
        })));

        $discoveredRecordIds = [];
        if (is_object($providerClient) && method_exists($providerClient, 'getDnsRecords')) {
            foreach ($nameCandidates as $nameTry) {
                try {
                    $listRes = $providerClient->getDnsRecords($zoneId, $nameTry, ['type' => 'TXT']);
                    if (!($listRes['success'] ?? false)) {
                        continue;
                    }
                    foreach (($listRes['result'] ?? []) as $item) {
                        $content = trim((string) ($item['content'] ?? ''));
                        if (!in_array($content, $txtCandidates, true)) {
                            continue;
                        }
                        $foundId = trim((string) ($item['record_id'] ?? ($item['id'] ?? '')));
                        if ($foundId !== '') {
                            $discoveredRecordIds[] = $foundId;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        $discoveredRecordIds = array_values(array_unique(array_filter($discoveredRecordIds, static function ($id) {
            return is_string($id) && $id !== '';
        })));

        $candidates = [];
        if ($recordId !== '') {
            foreach ($nameCandidates as $name) {
                foreach ($txtCandidates as $txt) {
                    $candidates[] = ['record_id' => $recordId, 'name' => $name, 'type' => 'TXT', 'content' => $txt];
                }
            }
        }
        foreach ($discoveredRecordIds as $discoveredId) {
            foreach ($nameCandidates as $name) {
                foreach ($txtCandidates as $txt) {
                    $candidates[] = ['record_id' => $discoveredId, 'name' => $name, 'type' => 'TXT', 'content' => $txt];
                }
            }
        }
        foreach ($nameCandidates as $name) {
            foreach ($txtCandidates as $txt) {
                $candidates[] = ['record_id' => '', 'name' => $name, 'type' => 'TXT', 'content' => $txt];
            }
        }

        foreach ($candidates as $record) {
            $res = cfmod_pdns_delete_record_on_provider($providerClient, $zoneId, $record);
            if (($res['success'] ?? false) === true) {
                if (!self::rootVerifyTxtExistsOnProvider($providerClient, $zoneId, $rootdomain, $host, $txtValue)) {
                    return true;
                }
            }
        }
        return !self::rootVerifyTxtExistsOnProvider($providerClient, $zoneId, $rootdomain, $host, $txtValue);
    }

    private static function rootVerifyTxtExistsOnProvider($providerClient, string $zoneId, string $rootdomain, string $host, string $txtValue): bool
    {
        if (!is_object($providerClient) || !method_exists($providerClient, 'getDnsRecords')) {
            return false;
        }
        $rootdomain = trim($rootdomain);
        $host = trim($host);
        $txtValue = trim($txtValue);
        if ($rootdomain === '' || $host === '' || $txtValue === '') {
            return false;
        }
        $nameCandidates = array_values(array_unique([$host, $host . '.' . $rootdomain, $host . '.' . $rootdomain . '.']));
        $txtCandidates = array_values(array_unique([$txtValue, '"' . str_replace('"', '\\"', $txtValue) . '"']));
        foreach ($nameCandidates as $nameTry) {
            try {
                $res = $providerClient->getDnsRecords($zoneId, $nameTry, ['type' => 'TXT']);
                if (!($res['success'] ?? false)) {
                    continue;
                }
                foreach (($res['result'] ?? []) as $record) {
                    $content = trim((string) ($record['content'] ?? ''));
                    if (in_array($content, $txtCandidates, true)) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        return false;
    }

    private static function cleanupExistingRootVerifyTxtRecords($providerClient, string $zoneId, string $rootdomain, string $host, string $expectedTxtValue = ''): void
    {
        if (!is_object($providerClient) || !method_exists($providerClient, 'getDnsRecords')) {
            return;
        }

        $host = trim($host);
        $rootdomain = trim($rootdomain);
        $expectedTxtValue = trim($expectedTxtValue);
        if ($host === '' || $rootdomain === '') {
            return;
        }

        $nameCandidates = array_values(array_unique(array_filter([
            $host,
            $host . '.' . $rootdomain,
            $host . '.' . $rootdomain . '.',
        ])));

        $txtCandidates = array_values(array_unique(array_filter([
            $expectedTxtValue,
            '"' . str_replace('"', '\\"', $expectedTxtValue) . '"',
        ], function ($v) {
            return $v !== '';
        })));

        foreach ($nameCandidates as $nameTry) {
            try {
                $res = $providerClient->getDnsRecords($zoneId, $nameTry, ['type' => 'TXT']);
                if (!($res['success'] ?? false)) {
                    continue;
                }
                foreach (($res['result'] ?? []) as $record) {
                    $recordName = trim((string) ($record['name'] ?? $nameTry));
                    $recordType = strtoupper(trim((string) ($record['type'] ?? 'TXT')));
                    if ($recordType !== 'TXT') {
                        continue;
                    }
                    $recordContent = trim((string) ($record['content'] ?? ''));
                    $recordId = trim((string) ($record['id'] ?? $record['record_id'] ?? ''));

                    // 预期值已存在时，保留该记录，避免无意义删改
                    if (!empty($txtCandidates) && in_array($recordContent, $txtCandidates, true)) {
                        continue;
                    }

                    cfmod_pdns_delete_record_on_provider($providerClient, $zoneId, [
                        'record_id' => $recordId,
                        'name' => $recordName !== '' ? $recordName : $nameTry,
                        'type' => 'TXT',
                        'content' => $recordContent,
                    ]);
                }
            } catch (\Throwable $e) {
                // best-effort cleanup only
            }
        }
    }

    private static function isRootVerifyProviderEnabled(string $provider, array $moduleSettings): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'alidns') {
            return in_array(strtolower((string) ($moduleSettings['root_verify_enable_alidns'] ?? 'yes')), ['1', 'on', 'yes', 'true'], true);
        }
        if ($provider === 'dnspod') {
            return in_array(strtolower((string) ($moduleSettings['root_verify_enable_dnspod'] ?? 'yes')), ['1', 'on', 'yes', 'true'], true);
        }
        return false;
    }

    private static function isRootdomainBlockedForVerify(string $rootdomain, array $moduleSettings): bool
    {
        $rootdomain = strtolower(trim($rootdomain));
        if ($rootdomain === '') {
            return false;
        }
        $raw = (string) ($moduleSettings['root_verify_blocked_rootdomains'] ?? '');
        if ($raw === '') {
            return false;
        }
        $parts = preg_split('/[\r\n,]+/', $raw);
        if (!is_array($parts)) {
            return false;
        }
        foreach ($parts as $item) {
            $val = strtolower(trim((string) $item));
            if ($val !== '' && $val === $rootdomain) {
                return true;
            }
        }
        return false;
    }

    private static function extractPermanentUpgradeAssistCode(string $input): string
    {
        $raw = trim($input);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/assist\s*code\s*[:：]\s*([A-Z0-9\-]{4,32})/iu', $raw, $match)) {
            return strtoupper(trim((string) ($match[1] ?? '')));
        }
        if (preg_match('/助力码\s*[:：]\s*([A-Z0-9\-]{4,32})/u', $raw, $match)) {
            return strtoupper(trim((string) ($match[1] ?? '')));
        }

        if (preg_match('/([A-Z0-9\-]{4,32})$/u', strtoupper($raw), $match)) {
            return strtoupper(trim((string) ($match[1] ?? '')));
        }

        return strtoupper($raw);
    }

    private static function normalizePostedSubdomainIdentifiers(): void
    {
        $keys = ['subdomain_id', 'root_verify_subdomain_id', 'perm_upgrade_subdomain_id', 'perm_incentive_subdomain_id', 'ssl_subdomain_id', 'orphan_cleanup_subdomain_id'];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $resolved = self::resolveIncomingSubdomainIdentifier($_POST[$key]);
            if ($resolved > 0) {
                $_POST[$key] = $resolved;
            }
        }
    }

    private static function resolveIncomingSubdomainIdentifier($rawId): int
    {
        return CfSubdomainIdResolver::resolveToInternal($rawId);
    }
}
