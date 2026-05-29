<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/autoload.php';
CfModuleSettings::bootstrap();

if (!function_exists('cfmod_setting_enabled')) {
    function cfmod_setting_enabled($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }
}

if (!function_exists('cfmod_capture_pending_invite_code')) {
    function cfmod_capture_pending_invite_code(): void
    {
        try {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                return;
            }
            $inviteCode = strtoupper(trim((string) ($_REQUEST['invite_code'] ?? '')));
            if ($inviteCode !== '' && preg_match('/^[A-Z0-9]{6,20}$/', $inviteCode)) {
                $_SESSION['cfmod_invite_registration_pending_code'] = $inviteCode;
                @setcookie('cfmod_invite_registration_pending_code', $inviteCode, time() + 30 * 86400, '/', '', false, false);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

cfmod_capture_pending_invite_code();

add_hook('ClientAreaPage', 1, function ($vars) {
    cfmod_capture_pending_invite_code();
});

add_hook('ClientAreaPageLogin', 1, function ($vars) {
    cfmod_capture_pending_invite_code();
});

add_hook('InvoicePaid', 1, function ($vars) {
    try {
        if (!class_exists('CfDomainPermanentUpgradeService')) {
            return;
        }
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }
        CfDomainPermanentUpgradeService::settlePaidInvoice($invoiceId);
        if (class_exists('CfRenewalInvoiceService')) {
            CfRenewalInvoiceService::settlePaidInvoice($invoiceId);
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('DomainHub settlePaidInvoice failed for invoice #' . (int) ($vars['invoiceid'] ?? 0) . ': ' . $e->getMessage());
        }
    }
});



if (!function_exists('cfmod_should_run_inline_queue')) {
    function cfmod_should_run_inline_queue(array $settings): bool {
        $raw = strtolower((string)($settings['run_inline_worker'] ?? 'auto'));
        if (in_array($raw, ['0', 'off', 'no', 'false', 'disabled'], true)) {
            return false;
        }
        if (in_array($raw, ['auto', 'default', ''], true)) {
            return true;
        }
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }
}

if (!function_exists('cfmod_hook_job_type_disabled')) {
    function cfmod_hook_job_type_disabled(string $type, array $settings): bool {
        $type = strtolower(trim($type));
        if ($type === '') {
            return false;
        }
        $raw = strtolower((string) ($settings['queue_disabled_job_types'] ?? ''));
        if ($raw === '') {
            return false;
        }
        $tokens = preg_split('/[\s,;|]+/', $raw) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), function ($v) { return $v !== ''; }));
        return in_array($type, $tokens, true);
    }
}

if (!function_exists('cfmod_hook_has_active_job')) {
    function cfmod_hook_has_active_job(string $type): bool {
        return Capsule::table('mod_cloudflare_jobs')
            ->where('type', $type)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }
}

if (!function_exists('cfmod_hook_should_enqueue_interval_job')) {
    function cfmod_hook_should_enqueue_interval_job(string $type, int $intervalSeconds): bool {
        if (cfmod_hook_has_active_job($type)) {
            return false;
        }
        $last = Capsule::table('mod_cloudflare_jobs')
            ->where('type', $type)
            ->whereIn('status', ['failed', 'done', 'cancelled'])
            ->orderBy('id', 'desc')
            ->first();
        if (!$last) {
            return true;
        }
        $lastTime = $last->updated_at ?? $last->created_at;
        return !$lastTime || strtotime((string) $lastTime) <= (time() - max(60, $intervalSeconds));
    }
}

// Auto sync via WHMCS cron
add_hook('AfterCronJob', 1, function($vars) {
    try {
        // Load addon settings
        $rows = Capsule::table('tbladdonmodules')->where('module', CF_MODULE_NAME)->get();
        if (count($rows) === 0) {
            $rows = Capsule::table('tbladdonmodules')->where('module', CF_MODULE_NAME_LEGACY)->get();
        }
        $settings = [];
        foreach ($rows as $r) { $settings[$r->setting] = $r->value; }

        $now = date('Y-m-d H:i:s');

        // Scheduler master switch: when disabled, stop all enqueue/inline execution.
        $cronSchedulerEnabled = cfmod_setting_enabled($settings['enable_cron_scheduler'] ?? '1');
        if (!$cronSchedulerEnabled) {
            return;
        }

        // Auto sync switch: only controls calibrate/sync style jobs.
        $autoSyncEnabled = cfmod_setting_enabled($settings['enable_auto_sync'] ?? '1');
        if ($autoSyncEnabled) {
            $intervalMin = intval($settings['sync_interval'] ?? 60);
            $intervalMin = max(5, min(999999, $intervalMin));

            $last = Capsule::table('mod_cloudflare_jobs')
                ->where('type','calibrate_all')
                ->orderBy('id','desc')->first();

            $shouldEnqueue = false;
            if (!$last) { $shouldEnqueue = true; }
            else {
                if (in_array($last->status, ['failed','done','cancelled'])) {
                    $lastTime = $last->updated_at ?? $last->created_at;
                    if (!$lastTime || strtotime($lastTime) <= time() - $intervalMin * 60) {
                        $shouldEnqueue = true;
                    }
                }
            }

            if ($shouldEnqueue) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'calibrate_all',
                    'payload_json' => json_encode(['mode' => 'fix'], JSON_UNESCAPED_UNICODE),
                    'priority' => 10,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        // Risk scan enqueue
        $scanEnabled = ($settings['risk_scan_enabled'] ?? 'on') === 'on' || ($settings['risk_scan_enabled'] ?? '1') == '1';
        if ($scanEnabled) {
            $scanIntervalMin = intval($settings['risk_scan_interval'] ?? 120);
            $scanIntervalMin = max(15, min(1440, $scanIntervalMin));
            $lastRisk = Capsule::table('mod_cloudflare_jobs')
                ->where('type','risk_scan_all')
                ->orderBy('id','desc')->first();
            $shouldRisk = false;
            if (!$lastRisk) { $shouldRisk = true; }
            else {
                if (in_array($lastRisk->status, ['failed','done','cancelled'])) {
                    $lastTime = $lastRisk->updated_at ?? $lastRisk->created_at;
                    if (!$lastTime || strtotime($lastTime) <= time() - $scanIntervalMin * 60) {
                        $shouldRisk = true;
                    }
                }
            }
            if ($shouldRisk) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'risk_scan_all',
                    'payload_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'priority' => 20,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }
        // Safe Browsing independent scan enqueue
        $safeBrowsingEnabled = ($settings['safe_browsing_enabled'] ?? 'off') === 'on' || ($settings['safe_browsing_enabled'] ?? '0') == '1';
        $safeIndependentEnabled = ($settings['safe_browsing_independent_enabled'] ?? 'off') === 'on' || ($settings['safe_browsing_independent_enabled'] ?? '0') == '1';
        if ($safeBrowsingEnabled && $safeIndependentEnabled) {
            $safeIntervalMin = intval($settings['safe_browsing_scan_interval'] ?? 120);
            $safeIntervalMin = max(15, min(1440, $safeIntervalMin));
            $lastSafe = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'safe_browsing_scan_all')
                ->orderBy('id', 'desc')->first();
            $shouldSafe = false;
            if (!$lastSafe) { $shouldSafe = true; }
            else {
                if (in_array($lastSafe->status, ['failed', 'done', 'cancelled'])) {
                    $lastTime = $lastSafe->updated_at ?? $lastSafe->created_at;
                    if (!$lastTime || strtotime($lastTime) <= time() - $safeIntervalMin * 60) {
                        $shouldSafe = true;
                    }
                }
            }
            if ($shouldSafe) {
                $safeBatch = intval($settings['safe_browsing_scan_batch_size'] ?? 50);
                $safeBatch = max(10, min(1000, $safeBatch));
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'safe_browsing_scan_all',
                    'payload_json' => json_encode(['batch_size' => $safeBatch, 'safe_browsing_only' => 1], JSON_UNESCAPED_UNICODE),
                    'priority' => 20,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        $autoUnbanIntervalMin = 60;
        $hasPendingAutoUnban = Capsule::table('mod_cloudflare_jobs')
            ->where('type', 'auto_unban_due')
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if (!$hasPendingAutoUnban && !cfmod_hook_job_type_disabled('auto_unban_due', $settings)) {
            $lastAutoUnban = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'auto_unban_due')
                ->orderBy('id', 'desc')
                ->first();
            $shouldAutoUnban = false;
            if (!$lastAutoUnban) {
                $shouldAutoUnban = true;
            } elseif (in_array($lastAutoUnban->status, ['failed', 'done', 'cancelled'], true)) {
                $lastTime = $lastAutoUnban->updated_at ?? $lastAutoUnban->created_at;
                if (!$lastTime || strtotime($lastTime) <= time() - $autoUnbanIntervalMin * 60) {
                    $shouldAutoUnban = true;
                }
            }

            if ($shouldAutoUnban) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'auto_unban_due',
                    'payload_json' => json_encode(['auto' => true], JSON_UNESCAPED_UNICODE),
                    'priority' => 4,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (class_exists('CfAdminStatsSnapshotService')) {
            CfAdminStatsSnapshotService::enqueueRefreshIfNeeded($settings, false, 'cron');
        }

        $worker = __DIR__ . '/worker.php';
        if (file_exists($worker)) {
            require_once $worker;
            if (function_exists('cfmod_recover_stalled_running_jobs')) {
                cfmod_recover_stalled_running_jobs($settings);
            }
        }

        // Try to execute a couple of jobs each cron pass（可选）
        if (cfmod_should_run_inline_queue($settings)) {
            if (file_exists($worker)) {
                if (function_exists('run_cf_queue_once')) {
                    $maxJobs = intval($settings['cron_max_jobs_per_pass'] ?? 2);
                    if ($maxJobs <= 0) {
                        $maxJobs = 2;
                    }
                    $maxJobs = max(1, min(50, $maxJobs));
                    run_cf_queue_once($maxJobs);
                }
            }
        }
        
        // 检查是否需要创建风险事件清理任务（优化查询）
        $shouldCleanup = cfmod_hook_should_enqueue_interval_job('cleanup_risk_events', 24 * 60 * 60);
        if ($shouldCleanup) {
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'cleanup_risk_events',
                'payload_json' => json_encode(['auto' => true], JSON_UNESCAPED_UNICODE),
                'priority' => 5,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // 检查是否需要创建 API 日志清理任务（每日一次）
        $apiRetention = intval($settings['api_logs_retention_days'] ?? 30);
        if ($apiRetention > 0) {
            $shouldApiCleanup = cfmod_hook_should_enqueue_interval_job('cleanup_api_logs', 24 * 60 * 60);
            if ($shouldApiCleanup) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_api_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $apiRetention,
                        'auto' => true
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        $generalRetention = intval($settings['general_logs_retention_days'] ?? 0);
        if ($generalRetention > 0) {
            $hasPendingGeneralCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_general_logs')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            $lastGeneralCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_general_logs')
                ->whereIn('status', ['failed','done','cancelled'])
                ->orderBy('id','desc')
                ->first();
            $shouldGeneralCleanup = false;
            if ($hasPendingGeneralCleanup) {
                $shouldGeneralCleanup = false;
            } elseif (!$lastGeneralCleanup) {
                $shouldGeneralCleanup = true;
            } else {
                $lastTime = $lastGeneralCleanup->updated_at ?? $lastGeneralCleanup->created_at;
                if (!$lastTime || strtotime($lastTime) <= time() - 24 * 60 * 60) {
                    $shouldGeneralCleanup = true;
                }
            }
            if ($shouldGeneralCleanup) {
                $generalBatchLimit = intval($settings['general_logs_cleanup_batch_limit'] ?? 2000);
                if ($generalBatchLimit <= 0) { $generalBatchLimit = 2000; }
                $generalBatchLimit = max(1, min(9999999, $generalBatchLimit));
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_general_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $generalRetention,
                        'batch_limit' => $generalBatchLimit,
                        'auto' => true,
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        $digRetention = intval($settings['dig_logs_retention_days'] ?? 30);
        $digLogMode = strtolower(trim((string) ($settings['dig_log_mode'] ?? 'meta')));
        if ($digLogMode !== 'off' && $digRetention > 0) {
            $shouldDigCleanup = cfmod_hook_should_enqueue_interval_job('cleanup_dig_logs', 24 * 60 * 60);
            if ($shouldDigCleanup) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_dig_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $digRetention,
                        'batch_limit' => 2000,
                        'auto' => true,
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        $syncRetention = intval($settings['sync_logs_retention_days'] ?? 0);
        if ($syncRetention > 0) {
            $shouldSyncCleanup = cfmod_hook_should_enqueue_interval_job('cleanup_sync_logs', 24 * 60 * 60);
            if ($shouldSyncCleanup) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_sync_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $syncRetention,
                        'batch_limit' => 2000,
                        'auto' => true,
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 6,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        $giftEnabledValue = strtolower(trim((string)($settings['enable_domain_gift'] ?? '0')));
        $giftEnabled = in_array($giftEnabledValue, ['1','on','yes','true','enabled'], true);
        if ($giftEnabled) {
            $hasPendingGiftCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_domain_gifts')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            $lastGiftCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_domain_gifts')
                ->whereIn('status', ['failed','done','cancelled'])
                ->orderBy('id','desc')
                ->first();
            $giftInterval = 60; // minutes
            $shouldGiftCleanup = false;
            if ($hasPendingGiftCleanup) {
                $shouldGiftCleanup = false;
            } elseif (!$lastGiftCleanup) {
                $shouldGiftCleanup = true;
            } else {
                $lastTime = $lastGiftCleanup->updated_at ?? $lastGiftCleanup->created_at;
                if (!$lastTime || strtotime($lastTime) <= time() - $giftInterval * 60) {
                    $shouldGiftCleanup = true;
                }
            }
            if ($shouldGiftCleanup) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_domain_gifts',
                    'payload_json' => json_encode([
                        'batch_size' => 200,
                        'auto' => true
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
        }

        // 根域名验证任务超时回收（默认每1分钟检查一次）
        if (in_array(strtolower(trim((string) ($settings['enable_rootdomain_verify'] ?? '0'))), ['1','on','yes','true','enabled'], true)) {
            $hasPendingRootVerifyCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_root_verify_tasks')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            $lastRootVerifyCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_root_verify_tasks')
                ->whereIn('status', ['failed', 'done', 'cancelled'])
                ->orderBy('id', 'desc')
                ->first();
            $shouldRootVerifyCleanup = false;
            if (!$hasPendingRootVerifyCleanup) {
                if (!$lastRootVerifyCleanup) {
                    $shouldRootVerifyCleanup = true;
                } else {
                    $lastTime = $lastRootVerifyCleanup->updated_at ?? $lastRootVerifyCleanup->created_at;
                    $shouldRootVerifyCleanup = !$lastTime || strtotime((string) $lastTime) <= time() - 60;
                }
            }
            if ($shouldRootVerifyCleanup) {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_root_verify_tasks',
                    'payload_json' => json_encode(['batch_size' => 50, 'auto' => true], JSON_UNESCAPED_UNICODE),
                    'priority' => 4,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $gracePeriodRaw = $settings['domain_grace_period_days'] ?? ($settings['domain_auto_delete_grace_days'] ?? 45);
        $cleanupGraceDays = is_numeric($gracePeriodRaw) ? intval($gracePeriodRaw) : 45;

        if ($cleanupGraceDays < 0) {
            $cleanupGraceDays = 0;
        }
        if ($cleanupGraceDays >= 0) {
            $hasPendingExpiredCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_expired_subdomains')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            $cleanupBatch = intval($settings['domain_cleanup_batch_size'] ?? 50);
            if ($cleanupBatch <= 0) { $cleanupBatch = 50; }
            $cleanupBatch = max(1, min(5000, $cleanupBatch));
            $cleanupDeep = in_array(($settings['domain_cleanup_deep_delete'] ?? 'yes'), ['1','on','yes','true'], true);
            $cleanupIntervalMinutesRaw = $settings['domain_cleanup_interval_minutes']
                ?? ((is_numeric($settings['domain_cleanup_interval_hours'] ?? null) ? intval($settings['domain_cleanup_interval_hours']) * 60 : null) ?? 1440);
            $cleanupIntervalMinutes = is_numeric($cleanupIntervalMinutesRaw) ? (int) $cleanupIntervalMinutesRaw : 1440;
            if ($cleanupIntervalMinutes < 5) {
                $cleanupIntervalMinutes = 5;
            } elseif ($cleanupIntervalMinutes > 9999) {
                $cleanupIntervalMinutes = 9999;
            }
            $cleanupIntervalSeconds = $cleanupIntervalMinutes * 60;

            $lastExpiredCleanup = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_expired_subdomains')
                ->whereIn('status', ['failed','done','cancelled'])
                ->orderBy('id', 'desc')
                ->first();

            $shouldExpiredCleanup = false;
            if ($hasPendingExpiredCleanup) {
                $shouldExpiredCleanup = false;
            } elseif (!$lastExpiredCleanup) {
                $shouldExpiredCleanup = true;
            } else {
                $lastTime = $lastExpiredCleanup->updated_at ?? $lastExpiredCleanup->created_at;
                if (!$lastTime || strtotime($lastTime) <= time() - $cleanupIntervalSeconds) {
                    $shouldExpiredCleanup = true;
                }
            }

            if ($shouldExpiredCleanup && !cfmod_hook_job_type_disabled('cleanup_expired_subdomains', $settings)) {
                $shardTotal = intval($settings['domain_cleanup_shard_total'] ?? 1);
                $shardTotal = max(1, min(32, $shardTotal));
                for ($shardIndex = 0; $shardIndex < $shardTotal; $shardIndex++) {
                    Capsule::table('mod_cloudflare_jobs')->insert([
                        'type' => 'cleanup_expired_subdomains',
                        'payload_json' => json_encode([
                            'cursor_id' => 0,
                            'batch_size' => $cleanupBatch,
                            'deep_delete' => $cleanupDeep ? 1 : 0,
                            'auto' => true,
                            'shard_total' => $shardTotal,
                            'shard_index' => $shardIndex,
                        ], JSON_UNESCAPED_UNICODE),
                        'priority' => 9,
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_run_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                }
            }
            try {
                Capsule::table('mod_cloudflare_jobs')
                    ->where('type', 'cleanup_expired_subdomains')
                    ->where('status', 'pending')
                    ->where('priority', '>', 9)
                    ->update([
                        'priority' => 9,
                        'updated_at' => $now,
                    ]);
            } catch (\Throwable $e) {
                cfmod_report_exception('cleanup_expired_priority_rebalance', $e);
            }

            // Quota refund fallback scheduler:
            // ensure pending refund records are processed even when event-driven enqueue was missed.
            try {
                $refundIntervalMinRaw = $settings['quota_refund_interval_minutes'] ?? 30;
                $refundIntervalMin = is_numeric($refundIntervalMinRaw) ? intval($refundIntervalMinRaw) : 30;
                $refundIntervalMin = max(5, min(1440, $refundIntervalMin));
                $refundPendingExists = Capsule::schema()->hasTable('mod_cloudflare_quota_refunds')
                    ? Capsule::table('mod_cloudflare_quota_refunds')
                        ->where('status', 'quota_refund_pending')
                        ->exists()
                    : false;
                if ($refundPendingExists) {
                    $shouldRefundJob = cfmod_hook_should_enqueue_interval_job('process_quota_refunds', $refundIntervalMin * 60);
                    if ($shouldRefundJob && !cfmod_hook_job_type_disabled('process_quota_refunds', $settings)) {
                        $refundBatchSizeRaw = $settings['quota_refund_batch_size'] ?? 100;
                        $refundBatchSize = is_numeric($refundBatchSizeRaw) ? intval($refundBatchSizeRaw) : 100;
                        $refundBatchSize = max(10, min(500, $refundBatchSize));
                        Capsule::table('mod_cloudflare_jobs')->insert([
                            'type' => 'process_quota_refunds',
                            'payload_json' => json_encode([
                                'batch_size' => $refundBatchSize,
                                'auto' => true,
                            ], JSON_UNESCAPED_UNICODE),
                            'priority' => 8,
                            'status' => 'pending',
                            'attempts' => 0,
                            'next_run_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                cfmod_report_exception('quota_refund_scheduler', $e);
            }

            $noticeEnabled = CfRenewalNoticeService::isEnabled($settings);
            $noticeTemplate = trim((string)($settings['renewal_notice_template'] ?? ''));
            $noticeDays = CfRenewalNoticeService::parseConfiguredDays($settings);
            if ($noticeEnabled && $noticeTemplate !== '' && !empty($noticeDays)) {
                $noticeIntervalHours = 24;
                $shouldNotice = cfmod_hook_should_enqueue_interval_job('send_expiry_notices', $noticeIntervalHours * 3600);
                if ($shouldNotice && !cfmod_hook_job_type_disabled('send_expiry_notices', $settings)) {
                    Capsule::table('mod_cloudflare_jobs')->insert([
                        'type' => 'send_expiry_notices',
                        'payload_json' => json_encode([
                            'days' => $noticeDays,
                            'auto' => true,
                        ], JSON_UNESCAPED_UNICODE),
                        'priority' => 18,
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_run_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (class_exists('CfTelegramExpiryReminderService')) {
                $tgNoticeEnabled = CfTelegramExpiryReminderService::isEnabled($settings);
                $tgNoticeConfigured = CfTelegramExpiryReminderService::isConfigured($settings);
                $tgNoticeDays = CfTelegramExpiryReminderService::parseConfiguredDays($settings);
                if ($tgNoticeEnabled && $tgNoticeConfigured && !empty($tgNoticeDays)) {
                    $tgNoticeIntervalHours = 24;
                    $shouldTgNotice = cfmod_hook_should_enqueue_interval_job('send_expiry_telegram_notices', $tgNoticeIntervalHours * 3600);

                    if ($shouldTgNotice && !cfmod_hook_job_type_disabled('send_expiry_telegram_notices', $settings)) {
                        Capsule::table('mod_cloudflare_jobs')->insert([
                            'type' => 'send_expiry_telegram_notices',
                            'payload_json' => json_encode([
                                'days' => $tgNoticeDays,
                                'batch_size' => 100,
                                'auto' => true,
                            ], JSON_UNESCAPED_UNICODE),
                            'priority' => 19,
                            'status' => 'pending',
                            'attempts' => 0,
                            'next_run_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            // 夜间 02:00 抽样补偿巡检（针对本地无DNS直删路径）
            try {
                $compEnabled = in_array(strtolower((string) ($settings['domain_cleanup_compensation_enabled'] ?? '1')), ['1', 'on', 'yes', 'true'], true);
                if ($compEnabled && !cfmod_hook_job_type_disabled('cleanup_local_only_compensation', $settings)) {
                    $currentHour = intval(date('G'));
                    $hasPendingComp = Capsule::table('mod_cloudflare_jobs')
                        ->where('type', 'cleanup_local_only_compensation')
                        ->whereIn('status', ['pending', 'running'])
                        ->exists();
                    $lastComp = Capsule::table('mod_cloudflare_jobs')
                        ->where('type', 'cleanup_local_only_compensation')
                        ->whereIn('status', ['done', 'failed', 'cancelled'])
                        ->orderBy('id', 'desc')
                        ->first();
                    $lastTs = 0;
                    if ($lastComp) {
                        $lastTime = $lastComp->updated_at ?? $lastComp->created_at;
                        $lastTs = $lastTime ? strtotime((string) $lastTime) : 0;
                    }
                    $shouldComp = ($currentHour === 2) && !$hasPendingComp && ($lastTs <= 0 || $lastTs <= time() - 20 * 3600);
                    if ($shouldComp) {
                        $sampleRate = intval($settings['domain_cleanup_compensation_sample_rate'] ?? 10);
                        $sampleRate = max(1, min(100, $sampleRate));
                        $sampleLimit = intval($settings['domain_cleanup_compensation_sample_limit'] ?? 200);
                        $sampleLimit = max(10, min(2000, $sampleLimit));
                        $residualThresholdPercent = intval($settings['domain_cleanup_compensation_residual_threshold_percent'] ?? 40);
                        $residualThresholdPercent = max(1, min(100, $residualThresholdPercent));
                        $scanCap = intval($settings['domain_cleanup_compensation_force_full_scan_cap'] ?? 10000);
                        $scanCap = max(100, min(500000, $scanCap));
                        Capsule::table('mod_cloudflare_jobs')->insert([
                            'type' => 'cleanup_local_only_compensation',
                            'payload_json' => json_encode([
                                'sample_rate' => $sampleRate,
                                'sample_limit' => $sampleLimit,
                                'residual_threshold_percent' => $residualThresholdPercent,
                                'scan_cap' => $scanCap,
                                'auto' => true,
                            ], JSON_UNESCAPED_UNICODE),
                            'priority' => 11,
                            'status' => 'pending',
                            'attempts' => 0,
                            'next_run_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                cfmod_report_exception('compensation_scheduler', $e);
            }
        }
    } catch (\Throwable $e) {
        cfmod_report_exception('after_cron_job', $e);
    }
});
