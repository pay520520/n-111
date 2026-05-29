<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfAdminStatsSnapshotService
{
    public const JOB_TYPE = 'precompute_admin_stats';

    private const CACHE_DATA_SETTING = 'admin_heavy_stats_cache_json';
    private const CACHE_META_SETTING = 'admin_heavy_stats_cache_meta_json';
    private const DEFAULT_CACHE_TTL_SECONDS = 3600;
    private const DEFAULT_REFRESH_INTERVAL_SECONDS = 3600;

    public static function getSnapshot(array $settings = []): array
    {
        $record = self::readSnapshotFromSettings();
        $generatedAt = (int) ($record['generated_at'] ?? 0);
        $data = is_array($record['data'] ?? null) ? $record['data'] : [];

        $ttl = self::resolveCacheTtlSeconds($settings);
        $stale = empty($data) || $generatedAt <= 0 || ($generatedAt + $ttl) < time();
        $pending = $stale ? self::hasPendingRefreshJob() : false;

        return [
            'data' => self::normalizeStatsPayload($data),
            'generated_at' => $generatedAt,
            'stale' => $stale,
            'pending' => $pending,
            'ttl' => $ttl,
        ];
    }

    public static function storeSnapshot(array $stats): bool
    {
        $module = self::resolveWriteModule();
        $normalized = self::normalizeStatsPayload($stats);
        $meta = [
            'generated_at' => time(),
            'version' => 1,
        ];

        try {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $module, 'setting' => self::CACHE_DATA_SETTING],
                ['value' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $module, 'setting' => self::CACHE_META_SETTING],
                ['value' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            if (function_exists('cf_clear_settings_cache')) {
                cf_clear_settings_cache();
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function enqueueRefreshIfNeeded(array $settings = [], bool $force = false, string $source = 'auto'): bool
    {
        if (!self::jobsTableExists()) {
            return false;
        }

        if (self::hasPendingRefreshJob()) {
            return false;
        }

        if (!$force && !in_array($source, ['cron', 'admin_action'], true)) {
            return false;
        }

        if (!$force) {
            $interval = self::resolveRefreshIntervalSeconds($settings);
            $latestTs = self::latestCompletedJobTimestamp();
            if ($latestTs > 0 && ($latestTs + $interval) > time()) {
                return false;
            }
        }

        $now = date('Y-m-d H:i:s');
        try {
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => self::JOB_TYPE,
                'payload_json' => json_encode([
                    'source' => $source,
                    'requested_at' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'priority' => 25,
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::triggerQueueInBackground();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasPendingRefreshJob(): bool
    {
        if (!self::jobsTableExists()) {
            return false;
        }

        try {
            return Capsule::table('mod_cloudflare_jobs')
                ->where('type', self::JOB_TYPE)
                ->whereIn('status', ['pending', 'running'])
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function clearSessionCacheOnly(): void
    {
        if (session_status() === PHP_SESSION_DISABLED) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            try {
                @session_start();
            } catch (\Throwable $e) {
            }
        }
        if (isset($_SESSION['cfmod_admin_stats_cache_v1'])) {
            unset($_SESSION['cfmod_admin_stats_cache_v1']);
        }
    }

    private static function readSnapshotFromSettings(): array
    {
        $modules = self::resolveReadModules();
        if (empty($modules)) {
            return [];
        }

        try {
            $rows = Capsule::table('tbladdonmodules')
                ->whereIn('module', $modules)
                ->whereIn('setting', [self::CACHE_DATA_SETTING, self::CACHE_META_SETTING])
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $data = [];
        $meta = [];
        foreach ($rows as $row) {
            $setting = (string) ($row->setting ?? '');
            $value = (string) ($row->value ?? '');
            if ($value === '') {
                continue;
            }
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($setting === self::CACHE_DATA_SETTING) {
                $data = $decoded;
            } elseif ($setting === self::CACHE_META_SETTING) {
                $meta = $decoded;
            }
        }

        return [
            'data' => is_array($data) ? $data : [],
            'generated_at' => (int) ($meta['generated_at'] ?? 0),
        ];
    }

    private static function normalizeStatsPayload(array $stats): array
    {
        $defaults = [
            'totalSubdomains' => 0,
            'activeSubdomains' => 0,
            'registeredUsers' => 0,
            'subdomainsCreated' => 0,
            'dnsOperations' => 0,
            'registrationTrend' => [],
            'popularRootdomains' => [],
            'dnsRecordTypes' => [],
            'usagePatterns' => [],
        ];

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $stats)) {
                $stats[$key] = $default;
                continue;
            }
            if (is_int($default)) {
                $stats[$key] = (int) $stats[$key];
            } elseif (is_array($default) && !is_array($stats[$key])) {
                $stats[$key] = $default;
            }
        }

        return $stats;
    }

    private static function resolveCacheTtlSeconds(array $settings): int
    {
        $defaultMinutes = max(1, (int) floor(self::DEFAULT_CACHE_TTL_SECONDS / 60));
        $minutes = (int) ($settings['admin_heavy_stats_cache_ttl_minutes'] ?? $defaultMinutes);
        $minutes = max(5, min(120, $minutes));
        return $minutes * 60;
    }

    private static function resolveRefreshIntervalSeconds(array $settings): int
    {
        $defaultMinutes = max(1, (int) floor(self::DEFAULT_REFRESH_INTERVAL_SECONDS / 60));
        $minutes = (int) ($settings['admin_heavy_stats_refresh_interval_minutes'] ?? $defaultMinutes);
        $minutes = max(5, min(240, $minutes));
        return $minutes * 60;
    }

    private static function latestCompletedJobTimestamp(): int
    {
        try {
            $job = Capsule::table('mod_cloudflare_jobs')
                ->where('type', self::JOB_TYPE)
                ->where('status', 'done')
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            return 0;
        }

        if (!$job) {
            return 0;
        }

        $timeText = (string) ($job->updated_at ?? $job->created_at ?? '');
        $ts = strtotime($timeText);
        return $ts !== false ? (int) $ts : 0;
    }

    private static function jobsTableExists(): bool
    {
        try {
            return Capsule::schema()->hasTable('mod_cloudflare_jobs');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolveWriteModule(): string
    {
        $module = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
        return trim((string) $module) !== '' ? (string) $module : 'domain_hub';
    }

    private static function resolveReadModules(): array
    {
        $modules = [self::resolveWriteModule()];
        $legacy = defined('CF_MODULE_NAME_LEGACY') ? (string) CF_MODULE_NAME_LEGACY : '';
        if ($legacy !== '' && !in_array($legacy, $modules, true)) {
            $modules[] = $legacy;
        }
        return $modules;
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
                cloudflare_subdomain_log('queue_trigger_missing_worker', ['context' => 'admin_stats_snapshot', 'max_jobs' => $maxJobs]);
            }
            return false;
        }
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $maxJobs = max(1, (int) $maxJobs);
        if (!function_exists('exec')) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('queue_trigger_exec_unavailable', ['context' => 'admin_stats_snapshot', 'max_jobs' => $maxJobs]);
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
                'context' => 'admin_stats_snapshot',
                'max_jobs' => $maxJobs,
                'exit_code' => $exitCode,
                'pid' => $pid,
                'spawn_log' => $spawnLog,
                'success' => $success ? 1 : 0,
            ]);
        }
        return $success;
    }
}
