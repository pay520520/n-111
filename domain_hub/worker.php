<?php
if (!defined('WHMCS')) {
    // Try to bootstrap WHMCS when run via CLI
    $cwd = getcwd();
    $dirs = [
        $cwd,
        dirname($cwd),
        dirname(dirname($cwd)),
        dirname(dirname(dirname($cwd)))
    ];
    foreach ($dirs as $dir) {
        if (file_exists($dir . '/init.php')) {
            require_once $dir . '/init.php';
            break;
        }
    }
}

use WHMCS\Database\Capsule;
use Illuminate\Support\Collection;

require_once __DIR__ . '/lib/autoload.php';
CfModuleSettings::bootstrap();

if (!function_exists('cfmod_setting_enabled')) {
    require_once __DIR__ . '/domain_hub.php';
}

require_once __DIR__ . '/lib/CloudflareAPI.php';
require_once __DIR__ . '/lib/ExternalRiskAPI.php';
require_once __DIR__ . '/lib/TtlHelper.php';
require_once __DIR__ . '/lib/RootDomainLimitHelper.php';
require_once __DIR__ . '/lib/CollectionHelper.php';
require_once __DIR__ . '/lib/ProviderResolver.php';
require_once __DIR__ . '/lib/AdminMaintenance.php';
require_once __DIR__ . '/lib/Services/SafeBrowsingService.php';


// PHP 7.4 兼容：提供 ends_with 辅助函数
if (!function_exists('cf_str_ends_with')) {
    function cf_str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') { return true; }
        $len = strlen($needle);
        if (strlen($haystack) < $len) { return false; }
        return substr($haystack, -$len) === $needle;
    }
}

if (!function_exists('cfmod_get_settings')) {
    function cfmod_get_settings(): array {
        static $settingsCache = null;
        
        // 使用静态缓存，避免重复查询
        if ($settingsCache !== null) {
            return $settingsCache;
        }
        
        $settings = [];
        $moduleSlug = CF_MODULE_NAME;
        $legacySlug = CF_MODULE_NAME_LEGACY;
        try {
            $rows = Capsule::table('tbladdonmodules')->where('module', $moduleSlug)->get();
            if (count($rows) === 0 && $legacySlug !== $moduleSlug) {
                $rows = Capsule::table('tbladdonmodules')->where('module', $legacySlug)->get();
                foreach ($rows as $row) {
                    Capsule::table('tbladdonmodules')->updateOrInsert(
                        ['module' => $moduleSlug, 'setting' => $row->setting],
                        ['value' => $row->value]
                    );
                }
                $rows = Capsule::table('tbladdonmodules')->where('module', $moduleSlug)->get();
            }
            foreach ($rows as $row) {
                $settings[$row->setting] = $row->value;
            }
            $settingsCache = $settings; // 缓存结果
        } catch (\Exception $e) {
            $settings = [];
            $settingsCache = $settings;
        }
        return $settings;
    }
}


function cfmod_job_metrics_supported(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    try {
        $supported = Capsule::schema()->hasTable('mod_cloudflare_jobs')
            && Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'started_at')
            && Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'finished_at')
            && Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'duration_seconds')
            && Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'stats_json');
    } catch (\Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function cfmod_job_control_columns(): array {
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }
    $columns = [
        'lease_token' => false,
        'worker_id' => false,
        'heartbeat_at' => false,
        'cancel_requested' => false,
        'cancel_requested_at' => false,
    ];
    try {
        if (Capsule::schema()->hasTable('mod_cloudflare_jobs')) {
            foreach (array_keys($columns) as $col) {
                $columns[$col] = Capsule::schema()->hasColumn('mod_cloudflare_jobs', $col);
            }
        }
    } catch (\Throwable $e) {
    }
    return $columns;
}

function cfmod_jobs_support_subdomain_id(): bool {
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    try {
        $supported = Capsule::schema()->hasTable('mod_cloudflare_jobs')
            && Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'subdomain_id');
    } catch (\Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function cfmod_job_worker_id(): string {
    static $workerId = null;
    if ($workerId !== null) {
        return $workerId;
    }
    $host = function_exists('gethostname') ? (string) gethostname() : 'worker';
    $seed = $host . '|' . microtime(true) . '|' . mt_rand();
    $workerId = substr($host, 0, 20) . '-' . substr(sha1($seed), 0, 12);
    return $workerId;
}

function cfmod_job_new_lease_token(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (\Throwable $e) {
        return substr(sha1((string) microtime(true) . '|' . mt_rand()), 0, 32);
    }
}

function cfmod_worker_set_active_job_context(?array $context): void {
    if ($context === null) {
        unset($GLOBALS['cfmod_worker_active_job_context']);
        return;
    }
    $GLOBALS['cfmod_worker_active_job_context'] = $context;
}

function cfmod_worker_get_active_job_context(): ?array {
    $ctx = $GLOBALS['cfmod_worker_active_job_context'] ?? null;
    return is_array($ctx) ? $ctx : null;
}

function cfmod_worker_lock_file_path(): string {
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $base = function_exists('sys_get_temp_dir') ? (string) sys_get_temp_dir() : '/tmp';
    if ($base === '') {
        $base = '/tmp';
    }
    $path = rtrim($base, '/\\') . '/domain_hub_worker.lock';
    return $path;
}

function cfmod_worker_slot_lock_file_path(int $slot): string {
    $slot = max(1, min(999, $slot));
    $basePath = cfmod_worker_lock_file_path();
    return $basePath . '.' . $slot;
}

function cfmod_worker_acquire_process_lock(int $waitSeconds = 0): bool {
    $waitSeconds = max(0, min(30, $waitSeconds));
    $lockPath = cfmod_worker_lock_file_path();
    $handle = @fopen($lockPath, 'c+');
    if (!$handle) {
        return false;
    }

    $start = time();
    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            $GLOBALS['cfmod_worker_process_lock_handle'] = $handle;
            return true;
        }
        usleep(200000);
    } while ((time() - $start) < $waitSeconds);

    @fclose($handle);
    return false;
}

function cfmod_worker_acquire_process_slot_lock(int $maxWorkers = 1, int $waitSeconds = 0): bool {
    $maxWorkers = max(1, min(99, $maxWorkers));
    $waitSeconds = max(0, min(30, $waitSeconds));
    $start = time();
    do {
        for ($slot = 1; $slot <= $maxWorkers; $slot++) {
            $lockPath = cfmod_worker_slot_lock_file_path($slot);
            $handle = @fopen($lockPath, 'c+');
            if (!$handle) {
                continue;
            }
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $GLOBALS['cfmod_worker_process_lock_handle'] = $handle;
                $GLOBALS['cfmod_worker_process_lock_slot'] = $slot;
                return true;
            }
            @fclose($handle);
        }
        usleep(200000);
    } while ((time() - $start) < $waitSeconds);
    return false;
}

function cfmod_worker_release_process_lock(): void {
    $handle = $GLOBALS['cfmod_worker_process_lock_handle'] ?? null;
    if (!$handle) {
        return;
    }
    @flock($handle, LOCK_UN);
    @fclose($handle);
    unset($GLOBALS['cfmod_worker_process_lock_handle']);
}

function cfmod_worker_register_shutdown_guard(): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(function () {
        $ctx = cfmod_worker_get_active_job_context();
        if (!$ctx || empty($ctx['job_id'])) {
            return;
        }

        $lastError = error_get_last();
        if (!$lastError || !is_array($lastError)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        $errorType = intval($lastError['type'] ?? 0);
        if (!in_array($errorType, $fatalTypes, true)) {
            return;
        }

        $jobId = intval($ctx['job_id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        $columns = cfmod_job_control_columns();
        $metricsSupported = cfmod_job_metrics_supported();
        $now = date('Y-m-d H:i:s');
        $leaseToken = (string) ($ctx['lease_token'] ?? '');

        try {
            $rowQuery = Capsule::table('mod_cloudflare_jobs')
                ->where('id', $jobId)
                ->where('status', 'running');
            if (!empty($columns['lease_token']) && $leaseToken !== '') {
                $rowQuery->where('lease_token', $leaseToken);
            }
            $row = $rowQuery->first();
            if (!$row) {
                return;
            }

            $attempts = intval($row->attempts ?? 0);
            if ($attempts <= 0) {
                $attempts = 1;
            }
            $nextStatus = $attempts >= 5 ? 'failed' : 'pending';
            $backoffMinutes = min(60, pow(2, min(6, $attempts - 1)));
            $nextRunAt = $nextStatus === 'pending'
                ? date('Y-m-d H:i:s', time() + $backoffMinutes * 60)
                : null;

            $message = trim((string) ($lastError['message'] ?? 'worker fatal shutdown'));
            if ($message === '') {
                $message = 'worker fatal shutdown';
            }
            $line = intval($lastError['line'] ?? 0);
            $file = trim((string) ($lastError['file'] ?? ''));
            $detail = 'worker_shutdown_fatal:' . $message;
            if ($file !== '') {
                $detail .= ' @' . basename($file) . ':' . $line;
            }

            $updateData = [
                'status' => $nextStatus,
                'next_run_at' => $nextRunAt,
                'updated_at' => $now,
                'last_error' => substr($detail, 0, 1000),
            ];
            if (!empty($columns['lease_token'])) {
                $updateData['lease_token'] = null;
            }
            if (!empty($columns['worker_id'])) {
                $updateData['worker_id'] = null;
            }
            if (!empty($columns['heartbeat_at'])) {
                $updateData['heartbeat_at'] = null;
            }
            if (!empty($columns['cancel_requested'])) {
                $updateData['cancel_requested'] = 0;
            }
            if (!empty($columns['cancel_requested_at'])) {
                $updateData['cancel_requested_at'] = null;
            }
            if ($metricsSupported) {
                $updateData['finished_at'] = $now;
                if (!empty($row->started_at)) {
                    $duration = time() - strtotime((string) $row->started_at);
                    $updateData['duration_seconds'] = max(0, intval($duration));
                }
                $updateData['stats_json'] = null;
            }

            $updateQuery = Capsule::table('mod_cloudflare_jobs')
                ->where('id', $jobId)
                ->where('status', 'running');
            if (!empty($columns['lease_token']) && $leaseToken !== '') {
                $updateQuery->where('lease_token', $leaseToken);
            }
            $updateQuery->update($updateData);
        } catch (\Throwable $e) {
            // noop: shutdown阶段避免再次抛错
        }
    });
}

function cfmod_worker_touch_progress(bool $force = false): void {
    $ctx = cfmod_worker_get_active_job_context();
    if (!$ctx || empty($ctx['job_id'])) {
        return;
    }

    $nowTs = time();
    $nextAt = intval($ctx['next_touch_at'] ?? 0);
    if (!$force && $nextAt > $nowTs) {
        return;
    }

    $jobId = intval($ctx['job_id']);
    if ($jobId <= 0) {
        return;
    }

    $columns = cfmod_job_control_columns();
    $now = date('Y-m-d H:i:s');
    $updateData = ['updated_at' => $now];
    if (!empty($columns['heartbeat_at'])) {
        $updateData['heartbeat_at'] = $now;
    }

    $query = Capsule::table('mod_cloudflare_jobs')
        ->where('id', $jobId)
        ->where('status', 'running');
    if (!empty($columns['lease_token']) && !empty($ctx['lease_token'])) {
        $query->where('lease_token', (string) $ctx['lease_token']);
    }
    $updated = $query->update($updateData);
    if ($updated <= 0) {
        // 修复：检查是否是因为更新时间戳相同导致的 0 行受影响（假阳性）
        $stillExists = Capsule::table('mod_cloudflare_jobs')
            ->where('id', $jobId)
            ->where('status', 'running');
        if (!empty($columns['lease_token']) && !empty($ctx['lease_token'])) {
            $stillExists->where('lease_token', (string) $ctx['lease_token']);
        }
        // 如果数据库中确实找不到这个运行中的任务了，才认为是租约丢失
        if (!$stillExists->exists()) {
            throw new \RuntimeException('__job_lease_lost__');
        }
    }
    if (!empty($columns['cancel_requested'])) {
        $stateQuery = Capsule::table('mod_cloudflare_jobs')->where('id', $jobId);
        if (!empty($columns['lease_token']) && !empty($ctx['lease_token'])) {
            $stateQuery->where('lease_token', (string) $ctx['lease_token']);
        }
        $state = $stateQuery->first();
        if (!$state || strtolower((string) ($state->status ?? '')) !== 'running') {
            throw new \RuntimeException('__job_lease_lost__');
        }
        if (intval($state->cancel_requested ?? 0) === 1) {
            throw new \RuntimeException('__job_cancelled__');
        }
    }

    $interval = max(5, min(120, intval($ctx['heartbeat_interval'] ?? 20)));
    $ctx['next_touch_at'] = $nowTs + $interval;
    $GLOBALS['cfmod_worker_active_job_context'] = $ctx;
}

function cfmod_build_stats_summary(array $stats): string {
    if (empty($stats)) {
        return 'OK';
    }
    $parts = [];
    if (isset($stats['processed_subdomains'])) {
        $parts[] = 'processed ' . intval($stats['processed_subdomains']) . ' subs';
    }
    if (isset($stats['processed_records'])) {
        $parts[] = 'records ' . intval($stats['processed_records']);
    }
    if (!empty($stats['differences_total'])) {
        $parts[] = 'diff ' . intval($stats['differences_total']);
    }
    if (!empty($stats['records_updated_local'])) {
        $parts[] = 'upd_local ' . intval($stats['records_updated_local']);
    }
    if (!empty($stats['records_imported_local'])) {
        $parts[] = 'add_local ' . intval($stats['records_imported_local']);
    }
    if (!empty($stats['records_updated_on_cf'])) {
        $parts[] = 'cf_upd ' . intval($stats['records_updated_on_cf']);
    }
    if (!empty($stats['records_created_on_cf'])) {
        $parts[] = 'cf_add ' . intval($stats['records_created_on_cf']);
    }
    if (!empty($stats['records_deleted_on_cf'])) {
        $parts[] = 'cf_del ' . intval($stats['records_deleted_on_cf']);
    }
    if (!empty($stats['unbanned'])) {
        $parts[] = 'unbanned ' . intval($stats['unbanned']);
    }
    if (!empty($stats['deleted'])) {
        $parts[] = 'deleted ' . intval($stats['deleted']);
    }
    if (!empty($stats['high_risk_deleted'])) {
        $parts[] = 'high_del ' . intval($stats['high_risk_deleted']);
    }
    if (!empty($stats['duplicate_deleted'])) {
        $parts[] = 'dup_del ' . intval($stats['duplicate_deleted']);
    }
    if (!empty($stats['warnings'])) {
        $warnings = is_array($stats['warnings']) ? $stats['warnings'] : [$stats['warnings']];
        $normalized = [];
        foreach ($warnings as $warning) {
            if (is_array($warning) || is_object($warning)) {
                $warning = json_encode($warning, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $warning = trim((string) $warning);
            if ($warning === '') {
                continue;
            }
            if (function_exists('mb_strlen')) {
                if (mb_strlen($warning, 'UTF-8') > 80) {
                    $warning = mb_substr($warning, 0, 77, 'UTF-8') . '…';
                }
            } elseif (strlen($warning) > 80) {
                $warning = substr($warning, 0, 77) . '…';
            }
            $normalized[] = $warning;
        }
        $warnCount = max(1, count($normalized));
        $parts[] = 'warnings ' . $warnCount;
        if (!empty($normalized)) {
            $preview = array_slice($normalized, 0, 2);
            $parts[] = 'warn ' . implode('; ', $preview) . ($warnCount > 2 ? '; …' : '');
        }
    }
    if (!empty($stats['has_more'])) {
        $parts[] = 'continuation queued';
    }
    if (!empty($stats['message'])) {
        $parts[] = trim((string)$stats['message']);
    }
    $parts = array_filter(array_map('trim', $parts));
    if (empty($parts)) {
        return 'OK';
    }
    return 'OK: ' . implode(', ', $parts);
}

function cfmod_track_sync_stat(array &$stats, string $kind, string $action): void {
    $stats['differences_total'] = ($stats['differences_total'] ?? 0) + 1;
    if (!isset($stats['difference_breakdown'][$kind])) {
        $stats['difference_breakdown'][$kind] = 0;
    }
    $stats['difference_breakdown'][$kind]++;
    if (!isset($stats['action_breakdown'][$action])) {
        $stats['action_breakdown'][$action] = 0;
    }
    $stats['action_breakdown'][$action]++;
    if ($action === 'created_on_cf') {
        $stats['records_created_on_cf'] = ($stats['records_created_on_cf'] ?? 0) + 1;
    } elseif ($action === 'updated_on_cf') {
        $stats['records_updated_on_cf'] = ($stats['records_updated_on_cf'] ?? 0) + 1;
    } elseif ($action === 'imported_local') {
        $stats['records_imported_local'] = ($stats['records_imported_local'] ?? 0) + 1;
    } elseif ($action === 'deleted_on_cf') {
        $stats['records_deleted_on_cf'] = ($stats['records_deleted_on_cf'] ?? 0) + 1;
    }
}

function cfmod_worker_normalize_record_content($content, ?string $type = null): string {
    if (is_array($content) || is_object($content)) {
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $content = $encoded === false ? '' : $encoded;
    }
    $value = trim((string) $content);
    if ($value === '') {
        return '';
    }

    if (strlen($value) >= 2 && substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
        $value = substr($value, 1, -1);
    }
    if (strpos($value, ' ') === false && substr($value, -1) === '.') {
        $value = rtrim($value, '.');
    }

    $recordType = strtoupper((string) ($type ?? ''));
    if ($recordType === 'TXT') {
        return $value;
    }

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function cfmod_worker_group_records_by_content(array $records): array {
    $grouped = [];
    foreach ($records as $record) {
        if (is_array($record)) {
            $content = $record['content'] ?? '';
            $type = $record['type'] ?? null;
        } else {
            $content = $record->content ?? '';
            $type = $record->type ?? null;
        }
        $key = cfmod_worker_normalize_record_content($content, is_string($type) ? $type : null);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $record;
    }
    return $grouped;
}

function cfmod_worker_provider_error_text($result): string {
    if (is_string($result)) {
        return trim($result);
    }
    if (!is_array($result)) {
        return '';
    }

    $parts = [];
    if (isset($result['code'])) {
        $parts[] = 'code:' . $result['code'];
    }
    if (isset($result['http_code'])) {
        $parts[] = 'http:' . $result['http_code'];
    }

    $errors = $result['errors'] ?? null;
    if (is_array($errors) && !empty($errors)) {
        $parts[] = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (is_string($errors) && trim($errors) !== '') {
        $parts[] = $errors;
    }

    $message = $result['message'] ?? ($result['error'] ?? null);
    if (is_array($message) && !empty($message)) {
        $parts[] = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (is_string($message) && trim($message) !== '') {
        $parts[] = $message;
    }

    return trim(implode(' | ', array_filter($parts)));
}

function cfmod_worker_provider_not_found($result): bool {
    if (is_array($result)) {
        $code = $result['code'] ?? ($result['http_code'] ?? null);
        if ($code === 404 || $code === '404') {
            return true;
        }
    }

    $message = strtolower(cfmod_worker_provider_error_text($result));
    if ($message === '') {
        return false;
    }

    return strpos($message, 'not found') !== false
        || strpos($message, 'record not found') !== false
        || strpos($message, 'does not exist') !== false
        || strpos($message, 'no such') !== false
        || strpos($message, '不存在') !== false;
}

function cfmod_worker_normalize_transfer_mode($mode): string {
    $mode = strtolower(trim((string) $mode));
    if (in_array($mode, ['local', 'local-only', 'local_only'], true)) {
        return 'local_only';
    }
    if (in_array($mode, ['cloud', 'cloud-only', 'remote', 'cloud_only'], true)) {
        return 'cloud_only';
    }
    return 'mixed';
}

function cfmod_worker_is_rate_limited_text(string $text): bool {
    $text = strtolower(trim($text));
    if ($text === '') {
        return false;
    }

    $keywords = [
        'http:429',
        ' 429',
        'too many requests',
        'rate limit',
        'throttl',
        'over limit',
        'quota exceeded',
        'frequency limit',
        '请求过于频繁',
        '限流',
        '频率超限',
        '配额超限',
    ];

    foreach ($keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

function cfmod_worker_is_rate_limited_response($response): bool {
    if (is_array($response)) {
        $httpCode = intval($response['http_code'] ?? ($response['code'] ?? 0));
        if ($httpCode === 429) {
            return true;
        }
        $errors = $response['errors'] ?? null;
        if (is_array($errors)) {
            $encoded = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && cfmod_worker_is_rate_limited_text($encoded)) {
                return true;
            }
        } elseif (is_string($errors) && cfmod_worker_is_rate_limited_text($errors)) {
            return true;
        }
    }

    return cfmod_worker_is_rate_limited_text(cfmod_worker_provider_error_text($response));
}

function cfmod_worker_is_rate_limited_exception($exception): bool {
    if (!($exception instanceof \Throwable)) {
        return false;
    }
    if (intval($exception->getCode()) === 429) {
        return true;
    }
    return cfmod_worker_is_rate_limited_text((string) $exception->getMessage());
}

function cfmod_worker_rate_limit_backoff_seconds(int $attempt): int {
    $attempt = max(1, $attempt);
    $seconds = 1;
    if ($attempt > 1) {
        $seconds = 1 << ($attempt - 1);
    }
    return max(1, min(16, $seconds));
}

function cfmod_worker_consume_remote_match(array &$remoteIndex, string $name, string $type, string $content): ?array {
    $n = strtolower($name);
    $t = strtoupper($type);
    $target = cfmod_worker_normalize_record_content($content, $t);

    if (empty($remoteIndex[$n][$t]) || !is_array($remoteIndex[$n][$t])) {
        return null;
    }

    foreach ($remoteIndex[$n][$t] as $idx => $record) {
        $recordContent = cfmod_worker_normalize_record_content($record['content'] ?? '', $t);
        if ($recordContent !== $target) {
            continue;
        }
        $matched = $record;
        unset($remoteIndex[$n][$t][$idx]);
        if (empty($remoteIndex[$n][$t])) {
            unset($remoteIndex[$n][$t]);
            if (empty($remoteIndex[$n])) {
                unset($remoteIndex[$n]);
            }
        } else {
            $remoteIndex[$n][$t] = array_values($remoteIndex[$n][$t]);
        }
        return $matched;
    }

    return null;
}

function cfmod_worker_payload_bool(array $payload, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $payload)) {
        return $default;
    }
    $value = $payload[$key];
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return false;
    }
    return in_array($normalized, ['1', 'on', 'yes', 'true', 'enabled'], true);
}


function cfmod_worker_parse_type_csv(string $raw): array
{
    $parts = preg_split('/[\s,;|]+/', strtoupper(trim($raw)));
    $types = [];
    foreach ((array) $parts as $part) {
        $value = trim((string) $part);
        if ($value !== '') { $types[$value] = true; }
    }
    return array_keys($types);
}

function cfmod_worker_get_replace_mode_types(array $settings): array
{
    if (class_exists('CfDnsConflictRepairService')) {
        return CfDnsConflictRepairService::replaceModeTypes($settings);
    }
    $raw = trim((string) ($settings['replace_mode_types'] ?? 'A,AAAA,CNAME'));
    $types = cfmod_worker_parse_type_csv($raw);
    return empty($types) ? ['A','AAAA','CNAME'] : $types;
}

function cfmod_worker_reconcile_mode(array $settings): string
{
    if (class_exists('CfDnsConflictRepairService')) {
        return CfDnsConflictRepairService::createSemanticsMode($settings);
    }
    $raw = strtolower(trim((string) ($settings['dns_create_semantics_mode'] ?? 'local_empty_add_as_replace')));
    return $raw === 'append' ? 'append' : 'local_empty_add_as_replace';
}

function cfmod_worker_resolve_fix_scope(array $payload): array {
    $hasAny = array_key_exists('fix_ttl', $payload)
        || array_key_exists('fix_missing', $payload)
        || array_key_exists('fix_extra', $payload);

    $defaults = [
        'ttl' => true,
        'missing' => true,
        'extra' => true,
    ];

    if (!$hasAny) {
        return $defaults;
    }

    return [
        'ttl' => cfmod_worker_payload_bool($payload, 'fix_ttl', false),
        'missing' => cfmod_worker_payload_bool($payload, 'fix_missing', false),
        'extra' => cfmod_worker_payload_bool($payload, 'fix_extra', false),
    ];
}

function cfmod_worker_apply_shard_filter($query, array $payload)
{
    $shardTotal = intval($payload['shard_total'] ?? 0);
    $shardIndex = intval($payload['shard_index'] ?? 0);
    if ($shardTotal <= 1) {
        return [$query, 0, 0];
    }
    $shardTotal = max(2, min(256, $shardTotal));
    if ($shardIndex < 0 || $shardIndex >= $shardTotal) {
        $shardIndex = 0;
    }
    // Use stable hash partitioning by subdomain fqdn to avoid full-zone one-shot fetch pressure.
    $query->whereRaw('MOD(CRC32(LOWER(subdomain)), ?) = ?', [$shardTotal, $shardIndex]);
    return [$query, $shardTotal, $shardIndex];
}


function cfmod_worker_is_sensitive_dns_type(string $recordType): bool {
    return in_array(strtoupper(trim($recordType)), ['NS', 'MX', 'SRV'], true);
}

function cfmod_worker_allow_sensitive_delete(array $payload, array $settings = []): bool {
    if (cfmod_worker_payload_bool($payload, 'allow_sensitive_delete', false)) {
        return true;
    }

    $settingRaw = strtolower(trim((string) ($settings['sync_allow_sensitive_delete'] ?? '0')));
    if ($settingRaw === '') {
        return false;
    }

    return in_array($settingRaw, ['1', 'on', 'yes', 'true', 'enabled'], true);
}

function cfmod_worker_import_remote_record_to_local(int $subdomainId, string $zoneId, string $name, string $type, array $remoteRecord): bool
{
    try {
        $recordId = isset($remoteRecord['id']) ? (string) $remoteRecord['id'] : '';
        $content = (string) ($remoteRecord['content'] ?? '');
        $ttl = intval($remoteRecord['ttl'] ?? 600);

        $existsQuery = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomainId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->whereRaw('UPPER(type) = ?', [strtoupper($type)])
            ->where('content', $content);

        if ($recordId !== '') {
            $existsQuery->where(function ($query) use ($recordId) {
                $query->where('record_id', $recordId)->orWhereNull('record_id');
            });
        }

        $exists = $existsQuery->exists();
        if ($exists) {
            return true;
        }

        Capsule::table('mod_cloudflare_dns_records')->insert([
            'subdomain_id' => $subdomainId,
            'zone_id' => $zoneId,
            'record_id' => $recordId !== '' ? $recordId : null,
            'name' => strtolower($name),
            'type' => strtoupper($type),
            'content' => $content,
            'ttl' => $ttl > 0 ? $ttl : 600,
            'proxied' => 0,
            'status' => 'active',
            'priority' => null,
            'line' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        CfSubdomainService::markHasDnsHistory($subdomainId);
        return true;
    } catch (\Throwable $e) {
        cfmod_report_exception('sync_import_sensitive_local', $e);
        return false;
    }
}


function cfmod_risk_scan_response_error($response): string
{
    if (!is_array($response)) {
        return 'invalid_response_type';
    }

    $parts = [];
    $httpCode = intval($response['http_code'] ?? 0);
    if ($httpCode > 0) {
        $parts[] = 'http:' . $httpCode;
    }

    $errors = $response['errors'] ?? null;
    if (is_array($errors) && !empty($errors)) {
        $encoded = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && $encoded !== '') {
            $parts[] = 'errors:' . $encoded;
        }
    }

    if (empty($parts)) {
        $raw = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($raw) && $raw !== '') {
            $parts[] = 'raw:' . (strlen($raw) > 260 ? substr($raw, 0, 260) . '...' : $raw);
        }
    }

    return empty($parts) ? 'scan_failed_unknown' : implode(' | ', $parts);
}

function cfmod_worker_resolve_provider_account_id_for_subdomain($subdomainRow, array $settings): ?int
{
    if (is_array($subdomainRow)) {
        $providerId = $subdomainRow['provider_account_id'] ?? null;
        $rootdomain = $subdomainRow['rootdomain'] ?? null;
        $subId = $subdomainRow['id'] ?? null;
    } else {
        $providerId = $subdomainRow->provider_account_id ?? null;
        $rootdomain = $subdomainRow->rootdomain ?? null;
        $subId = $subdomainRow->id ?? null;
    }
    return cfmod_resolve_provider_account_id($providerId, $rootdomain, $subId, $settings);
}

function cfmod_worker_resolve_provider_account_id_for_rootdomain($rootdomainRow, array $settings): ?int
{
    if (is_array($rootdomainRow)) {
        $providerId = $rootdomainRow['provider_account_id'] ?? null;
        $rootdomain = $rootdomainRow['domain'] ?? ($rootdomainRow['rootdomain'] ?? null);
    } elseif (is_object($rootdomainRow)) {
        $providerId = $rootdomainRow->provider_account_id ?? null;
        $rootdomain = $rootdomainRow->domain ?? ($rootdomainRow->rootdomain ?? null);
    } else {
        $providerId = null;
        $rootdomain = $rootdomainRow;
    }
    return cfmod_resolve_provider_account_id($providerId, $rootdomain, null, $settings);
}

function cfmod_worker_acquire_provider_client_cached($providerAccountId, array $settings, array &$cache, array &$stats, string $context): ?array
{
    $key = $providerAccountId ?: 0;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $clientContext = cfmod_make_provider_client($providerAccountId, null, null, $settings);
    } catch (\Throwable $e) {
        $clientContext = null;
    }
    if (!$clientContext || empty($clientContext['client'])) {
        $cache[$key] = null;
        $stats['warnings'][] = $context . '_provider_unavailable:' . $key;
        return null;
    }
    $providerType = strtolower((string) (($clientContext['provider_type'] ?? $clientContext['provider']['provider_type'] ?? '') ?: ''));
    if (in_array($providerType, ['powerdns', 'pdns'], true) && is_object($clientContext['client'])) {
        $pdnsClient = $clientContext['client'];
        if (method_exists($pdnsClient, 'setFullZoneFallbackRrsetThreshold')) {
            $fallbackThreshold = intval($settings['sync_pdns_full_zone_fallback_rrset_threshold'] ?? 3000);
            $pdnsClient->setFullZoneFallbackRrsetThreshold(max(200, min(500000, $fallbackThreshold)));
        }
        if (method_exists($pdnsClient, 'setZoneCacheLimits')) {
            $cacheMaxEntries = intval($settings['sync_pdns_zone_cache_max_entries'] ?? 8);
            $cacheMaxRrsets = intval($settings['sync_pdns_zone_cache_max_rrsets'] ?? 120000);
            $pdnsClient->setZoneCacheLimits(
                max(1, min(128, $cacheMaxEntries)),
                max(5000, min(2000000, $cacheMaxRrsets))
            );
        }
    }
    $cache[$key] = $clientContext;
    return $clientContext;
}

function cfmod_recover_stalled_running_jobs(array $settings = []): int
{
    $timeoutMinutes = intval($settings['job_running_timeout_minutes'] ?? 120);
    if ($timeoutMinutes <= 0) {
        $timeoutMinutes = 120;
    }
    $timeoutMinutes = max(5, min(10080, $timeoutMinutes));

    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', time() - $timeoutMinutes * 60);
    $metricsSupported = cfmod_job_metrics_supported();
    $columns = cfmod_job_control_columns();

    try {
        $stalledQuery = Capsule::table('mod_cloudflare_jobs')
            ->where('status', 'running');
        if (!empty($columns['heartbeat_at'])) {
            $stalledQuery->where(function ($q) use ($cutoff) {
                $q->whereNull('heartbeat_at')->where('updated_at', '<=', $cutoff)
                  ->orWhere('heartbeat_at', '<=', $cutoff);
            });
        } else {
            $stalledQuery->where('updated_at', '<=', $cutoff);
        }
        $stalledJobs = $stalledQuery
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get();
    } catch (\Throwable $e) {
        return 0;
    }

    $recovered = 0;
    foreach ($stalledJobs as $job) {
        try {
            $attempts = intval($job->attempts ?? 0);
            if ($attempts <= 0) {
                $attempts = 1;
            }
            $cancelRequested = !empty($columns['cancel_requested']) && intval($job->cancel_requested ?? 0) === 1;
            $toFailed = !$cancelRequested && $attempts >= 5;
            $reason = $cancelRequested
                ? ('Recovered stalled running job as cancelled after ' . $timeoutMinutes . ' minutes')
                : ('Recovered stalled running job after ' . $timeoutMinutes . ' minutes');
            $prevError = trim((string) ($job->last_error ?? ''));
            $combinedError = $prevError !== '' ? ($prevError . ' | ' . $reason) : $reason;

            $nextStatus = $cancelRequested ? 'cancelled' : ($toFailed ? 'failed' : 'pending');
            $updateData = [
                'status' => $nextStatus,
                'next_run_at' => ($nextStatus === 'pending') ? $now : null,
                'updated_at' => $now,
                'last_error' => substr($combinedError, 0, 1000),
            ];

            if (!empty($columns['lease_token'])) {
                $updateData['lease_token'] = null;
            }
            if (!empty($columns['worker_id'])) {
                $updateData['worker_id'] = null;
            }
            if (!empty($columns['heartbeat_at'])) {
                $updateData['heartbeat_at'] = null;
            }
            if (!empty($columns['cancel_requested'])) {
                $updateData['cancel_requested'] = 0;
            }
            if (!empty($columns['cancel_requested_at'])) {
                $updateData['cancel_requested_at'] = null;
            }

            if ($metricsSupported) {
                if ($nextStatus === 'pending') {
                    $updateData['started_at'] = null;
                    $updateData['finished_at'] = null;
                    $updateData['duration_seconds'] = null;
                    $updateData['stats_json'] = null;
                } else {
                    $updateData['finished_at'] = $now;
                    if (!empty($job->started_at)) {
                        $duration = time() - strtotime((string) $job->started_at);
                        $updateData['duration_seconds'] = max(0, intval($duration));
                    }
                }
            }

            $updated = Capsule::table('mod_cloudflare_jobs')
                ->where('id', $job->id)
                ->where('status', 'running')
                ->update($updateData);
            if ($updated > 0) {
                $recovered++;
            }
        } catch (\Throwable $e) {
            cfmod_report_exception('recover_stalled_job', $e);
        }
    }

    if ($recovered > 0 && function_exists('cloudflare_subdomain_log')) {
        cloudflare_subdomain_log('queue_recover_stalled_jobs', [
            'recovered' => $recovered,
            'timeout_minutes' => $timeoutMinutes,
        ]);
    }

    return $recovered;
}

function cfmod_job_type_concurrency_quota(string $type, array $settings): int
{
    if ($type === 'cleanup_expired_subdomains') {
        return max(1, min(10, intval($settings['cleanup_expired_main_concurrency'] ?? 1)));
    }
    if ($type === 'cleanup_expired_subdomain_remote') {
        return max(1, min(20, intval($settings['cleanup_expired_remote_concurrency'] ?? 2)));
    }
    $defaultQuota = max(1, intval($settings['queue_default_type_concurrency'] ?? 1));
    $mapRaw = trim((string) ($settings['queue_job_type_concurrency'] ?? ''));
    if ($mapRaw === '' || $type === '') {
        return $defaultQuota;
    }
    $pairs = preg_split('/[\r\n,;]+/', $mapRaw) ?: [];
    foreach ($pairs as $pair) {
        $item = trim((string) $pair);
        if ($item === '' || strpos($item, ':') === false) {
            continue;
        }
        [$k, $v] = array_map('trim', explode(':', $item, 2));
        if ($k === $type) {
            return max(1, min(20, intval($v)));
        }
    }
    return $defaultQuota;
}

function cfmod_cleanup_circuit_is_open(string $providerKey, array $settings = []): bool
{
    if ($providerKey === '') {
        return false;
    }
    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_job_locks')) {
            return false;
        }
        $row = Capsule::table('mod_cloudflare_job_locks')
            ->where('lock_key', 'cleanup_circuit:' . $providerKey)
            ->first();
        if (!$row) {
            return false;
        }
        $scopeRaw = (string) ($row->scope_key ?? '');
        $openUntilTs = 0;
        if ($scopeRaw !== '') {
            $decoded = json_decode($scopeRaw, true);
            if (is_array($decoded)) {
                $openUntilTs = intval($decoded['open_until_ts'] ?? 0);
            } else {
                $legacyParts = explode('|', $scopeRaw);
                $legacyOpen = intval($legacyParts[2] ?? 0);
                if ($legacyOpen > 0) {
                    $openUntilTs = $legacyOpen;
                }
            }
        }
        return $openUntilTs > time();
    } catch (\Throwable $e) {
        return false;
    }
}

function cfmod_cleanup_circuit_mark_failure(string $providerKey, string $errorClass, array $settings = []): void
{
    if ($providerKey === '') {
        return;
    }
    $windowSec = max(10, min(600, intval($settings['cleanup_remote_circuit_window_seconds'] ?? 60)));
    $threshold = max(2, min(20, intval($settings['cleanup_remote_circuit_failure_threshold'] ?? 3)));
    $cooldownSec = max(10, min(3600, intval($settings['cleanup_remote_circuit_cooldown_seconds'] ?? 120)));
    $retryableClasses = ['network_timeout', 'network', 'provider_unavailable', 'rate_limit'];
    if (!in_array(strtolower($errorClass), $retryableClasses, true)) {
        return;
    }
    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_job_locks')) {
            return;
        }
        $lockKey = 'cleanup_circuit:' . $providerKey;
        $now = time();
        $cooldownUntilTs = $now + $cooldownSec;
        $meta = Capsule::table('mod_cloudflare_job_locks')->where('lock_key', $lockKey)->first();
        $count = 1;
        $windowTs = $now;
        $openUntilTs = 0;
        if ($meta && !empty($meta->scope_key)) {
            $scopeRaw = (string) $meta->scope_key;
            $decoded = json_decode($scopeRaw, true);
            if (is_array($decoded)) {
                $windowTs = intval($decoded['window_ts'] ?? $now);
                $count = intval($decoded['fail_count'] ?? 0);
                $openUntilTs = intval($decoded['open_until_ts'] ?? 0);
            } else {
                $legacyParts = explode('|', $scopeRaw);
                $windowTs = intval($legacyParts[0] ?? $now);
                $count = intval($legacyParts[1] ?? 0);
                $openUntilTs = intval($legacyParts[2] ?? 0);
            }
            if ($windowTs < ($now - $windowSec)) {
                $windowTs = $now;
                $count = 0;
            }
            if ($openUntilTs > $now) {
                $count = max($count, $threshold);
            }
        }
        $count++;
        if ($count >= $threshold) {
            $openUntilTs = max($openUntilTs, $cooldownUntilTs);
            $windowTs = $now;
            $count = 0;
        }
        $scopeValue = json_encode([
            'window_ts' => $windowTs,
            'fail_count' => $count,
            'open_until_ts' => $openUntilTs,
        ], JSON_UNESCAPED_UNICODE);
        Capsule::table('mod_cloudflare_job_locks')->updateOrInsert(
            ['lock_key' => $lockKey],
            [
                'job_type' => 'cleanup_circuit',
                'scope_key' => $scopeValue,
                'created_at' => $meta->created_at ?? date('Y-m-d H:i:s', $now),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]
        );
    } catch (\Throwable $e) {
        return;
    }
}

function cfmod_cleanup_apply_client_timeout($client, array $settings = []): void
{
    try {
        $timeoutSeconds = intval($settings['cleanup_remote_hard_timeout_seconds'] ?? 20);
        $timeoutSeconds = max(3, min(120, $timeoutSeconds));
        if (is_object($client) && method_exists($client, 'setRequestTimeout')) {
            $client->setRequestTimeout($timeoutSeconds);
            return;
        }
        if (is_object($client) && property_exists($client, 'timeout')) {
            $ref = new \ReflectionObject($client);
            if ($ref->hasProperty('timeout')) {
                $prop = $ref->getProperty('timeout');
                $prop->setAccessible(true);
                $prop->setValue($client, $timeoutSeconds);
            }
        }
    } catch (\Throwable $e) {
        // best effort
    }
}

function cfmod_apply_priority_aging(array $settings): void
{
    $enabled = in_array(strtolower((string) ($settings['queue_priority_aging_enabled'] ?? '0')), ['1', 'on', 'yes', 'true'], true);
    if (!$enabled) {
        return;
    }
    $minutes = max(5, min(1440, intval($settings['queue_priority_aging_minutes'] ?? 30)));
    $step = max(1, min(10, intval($settings['queue_priority_aging_step'] ?? 1)));
    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', time() - $minutes * 60);
    try {
        $jobs = Capsule::table('mod_cloudflare_jobs')
            ->where('status', 'pending')
            ->where('priority', '>', 1)
            ->where('updated_at', '<=', $cutoff)
            ->limit(200)
            ->get();
        foreach ($jobs as $job) {
            $newPriority = max(1, intval($job->priority ?? 10) - $step);
            Capsule::table('mod_cloudflare_jobs')
                ->where('id', intval($job->id))
                ->where('status', 'pending')
                ->update([
                    'priority' => $newPriority,
                    'updated_at' => $now,
                    'last_error' => substr(trim((string) ($job->last_error ?? '')) . ' | priority_aged', 0, 1000),
                ]);
        }
    } catch (\Throwable $e) {
    }
}

function run_cf_queue_once(int $maxJobs = 3): void {
    cfmod_worker_register_shutdown_guard();
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    try {
        $settings = cfmod_get_settings();
        $maxWorkers = intval($settings['queue_max_workers'] ?? 1);
        $maxWorkers = max(1, min(99, $maxWorkers));
        if (!cfmod_worker_acquire_process_slot_lock($maxWorkers, 0)) {
            return;
        }
        cfmod_recover_stalled_running_jobs($settings);
        cfmod_apply_priority_aging($settings);

        $now = date('Y-m-d H:i:s');
        $metricsSupported = cfmod_job_metrics_supported();
        $controlColumns = cfmod_job_control_columns();
        $claimLimit = max($maxJobs, min(500, $maxJobs * 20));
        $jobsQuery = Capsule::table('mod_cloudflare_jobs')
            ->where('status', 'pending')
            ->where(function($q) use ($now) { $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now); })
            ->orderBy('priority', 'asc')
            ->orderBy('attempts', 'asc')
            ->orderByRaw("CASE WHEN type = 'cleanup_expired_subdomain_remote' AND JSON_EXTRACT(payload_json, '$.compensation') = true THEN 0 ELSE 1 END ASC")
            ->orderByRaw("CASE WHEN type = 'cleanup_expired_subdomain_remote' AND JSON_EXTRACT(payload_json, '$.compensation') = true THEN id END DESC")
            ->orderBy('id', 'asc')
            ->limit($claimLimit);
        if (!empty($controlColumns['cancel_requested'])) {
            $jobsQuery->where(function($q) {
                $q->whereNull('cancel_requested')->orWhere('cancel_requested', 0);
            });
        }
        $jobs = $jobsQuery->get();

        $claimedJobs = 0;
        $claimedCompensationJobs = 0;
        $maxCompensationShare = max(1, (int) floor(max(1, $maxJobs) * 0.6));
        $maxJobsPerMinute = max(0, intval($settings['max_jobs_per_minute'] ?? 0));
        $jobsStartedLastMinute = 0;
        if ($maxJobsPerMinute > 0) {
            try {
                $since = date('Y-m-d H:i:s', time() - 60);
                $jobsStartedLastMinute = (int) Capsule::table('mod_cloudflare_jobs')
                    ->whereNotNull('started_at')
                    ->where('started_at', '>=', $since)
                    ->count();
            } catch (\Throwable $e) {
                $jobsStartedLastMinute = 0;
            }
        }
        foreach ($jobs as $job) {
            if ($claimedJobs >= $maxJobs) {
                break;
            }
            if ($maxJobsPerMinute > 0 && $jobsStartedLastMinute >= $maxJobsPerMinute) {
                break;
            }
            $isCompensationRemote = (($job->type ?? '') === 'cleanup_expired_subdomain_remote')
                && (strpos((string) ($job->payload_json ?? ''), '"compensation":true') !== false
                    || strpos((string) ($job->payload_json ?? ''), '"compensation":1') !== false);
            if ($isCompensationRemote && $claimedCompensationJobs >= $maxCompensationShare) {
                continue;
            }
            if (cfmod_is_job_type_disabled((string) ($job->type ?? ''), $settings)) {
                Capsule::table('mod_cloudflare_jobs')
                    ->where('id', $job->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'last_error' => 'job type disabled by settings',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                continue;
            }
            $jobStartMicro = microtime(true);
            $jobStartAt = date('Y-m-d H:i:s');
            $stats = [];
            $leaseToken = !empty($controlColumns['lease_token']) ? cfmod_job_new_lease_token() : null;
            try {
            $sameTypeRunning = Capsule::table('mod_cloudflare_jobs')
                ->where('type', $job->type)
                ->where('status', 'running')
                ->where('id', '<>', $job->id)
                ->count();
            $quota = cfmod_job_type_concurrency_quota((string) ($job->type ?? ''), $settings);
            if ($sameTypeRunning >= $quota) {
                continue;
            }
            $claimData = [
                'status' => 'running',
                'attempts' => intval($job->attempts ?? 0) + 1,
                'updated_at' => $jobStartAt,
            ];
            if ($metricsSupported) {
                $claimData['started_at'] = $jobStartAt;
                $claimData['finished_at'] = null;
                $claimData['duration_seconds'] = null;
                $claimData['stats_json'] = null;
            }
            if (!empty($controlColumns['lease_token'])) {
                $claimData['lease_token'] = $leaseToken;
            }
            if (!empty($controlColumns['worker_id'])) {
                $claimData['worker_id'] = cfmod_job_worker_id();
            }
            if (!empty($controlColumns['heartbeat_at'])) {
                $claimData['heartbeat_at'] = $jobStartAt;
            }
            if (!empty($controlColumns['cancel_requested'])) {
                $claimData['cancel_requested'] = 0;
            }
            if (!empty($controlColumns['cancel_requested_at'])) {
                $claimData['cancel_requested_at'] = null;
            }

            $claimQuery = Capsule::table('mod_cloudflare_jobs')
                ->where('id', $job->id)
                ->where('status', 'pending');
            if (!empty($controlColumns['cancel_requested'])) {
                $claimQuery->where(function($q) {
                    $q->whereNull('cancel_requested')->orWhere('cancel_requested', 0);
                });
            }
            $claimed = $claimQuery->update($claimData);
            if ($claimed === 0) {
                continue;
            }
            $claimedJobs++;
            if ($maxJobsPerMinute > 0) {
                $jobsStartedLastMinute++;
            }
            if ($isCompensationRemote) {
                $claimedCompensationJobs++;
            }

            $heartbeatInterval = intval($settings['queue_heartbeat_interval_seconds'] ?? 20);
            $heartbeatInterval = max(5, min(120, $heartbeatInterval));
            cfmod_worker_set_active_job_context([
                'job_id' => intval($job->id),
                'lease_token' => $leaseToken,
                'heartbeat_interval' => $heartbeatInterval,
                'next_touch_at' => 0,
            ]);
            cfmod_worker_touch_progress(true);

            $payload = json_decode($job->payload_json ?? '{}', true) ?: [];
            $normalizedPayload = cfmod_normalize_job_payload($job->type ?? '', $payload, $settings);
            if ($normalizedPayload !== $payload) {
                $payload = $normalizedPayload;
                try {
                    Capsule::table('mod_cloudflare_jobs')
                        ->where('id', $job->id)
                        ->where('status', 'running')
                        ->update([
                            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                } catch (\Throwable $e) {
                    cfmod_report_exception('normalize_job_payload_' . ($job->type ?? 'unknown'), $e);
                }
            }
            $type = $job->type;

            switch ($type) {
                case 'calibrate_all':
                    $stats = cfmod_job_calibrate_all($job, $payload) ?: [];
                    break;
                case 'auto_unban_due':
                    $stats = cfmod_job_auto_unban_due($job, $payload) ?: [];
                    break;
                case 'risk_scan_all':
                    $stats = cfmod_job_risk_scan_all($job, $payload) ?: [];
                    break;
                case 'safe_browsing_scan_all':
                    $stats = cfmod_job_safe_browsing_scan_all($job, $payload) ?: [];
                    break;
                case 'virustotal_scan_all':
                    $stats = cfmod_job_virustotal_scan_all($job, $payload) ?: [];
                    break;
                case 'cleanup_risk_events':
                    $stats = cfmod_job_cleanup_risk_events($job, $payload) ?: [];
                    break;
                case 'cleanup_expired_subdomains':
                    $stats = cfmod_job_cleanup_expired_subdomains($job, $payload) ?: [];
                    break;
                case 'cleanup_expired_subdomain_remote':
                    $stats = cfmod_job_cleanup_expired_subdomain_remote($job, $payload) ?: [];
                    break;
                case 'process_quota_refunds':
                    $stats = cfmod_job_process_quota_refunds($job, $payload) ?: [];
                    break;
                case 'cleanup_api_logs':
                    $stats = cfmod_job_cleanup_api_logs($job, $payload) ?: [];
                    break;
                case 'cleanup_general_logs':
                    $stats = cfmod_job_cleanup_general_logs($job, $payload) ?: [];
                    break;
                case 'cleanup_dig_logs':
                    $stats = cfmod_job_cleanup_dig_logs($job, $payload) ?: [];
                    break;
                case 'cleanup_sync_logs':
                    $stats = cfmod_job_cleanup_sync_logs($job, $payload) ?: [];
                    break;
                case 'cleanup_user_subdomains':
                    $stats = cfmod_job_cleanup_user_subdomains($job, $payload) ?: [];
                    break;
                case 'ban_user_delete_dns_like_client':
                    $stats = cfmod_job_ban_user_delete_dns_like_client($job, $payload) ?: [];
                    break;
                case 'cleanup_domain_gifts':
                    $stats = cfmod_job_cleanup_domain_gifts($job, $payload) ?: [];
                    break;
                case 'cleanup_root_verify_tasks':
                    $stats = cfmod_job_cleanup_root_verify_tasks($job, $payload) ?: [];
                    break;
                case 'cleanup_orphan_dns':
                    $stats = cfmod_job_cleanup_orphan_dns($job, $payload) ?: [];
                    break;
                case 'cleanup_local_only_compensation':
                    $stats = cfmod_job_cleanup_local_only_compensation($job, $payload) ?: [];
                    break;
                case 'send_expiry_notices':
                    $stats = cfmod_job_send_expiry_notices($job, $payload) ?: [];
                    break;
                case 'send_expiry_telegram_notices':
                    $stats = cfmod_job_send_expiry_telegram_notices($job, $payload) ?: [];
                    break;
                case 'replace_root_domain':
                    $stats = cfmod_job_replace_root($job, $payload) ?: [];
                    break;
                case 'transfer_root_provider':
                    $stats = cfmod_job_transfer_root_provider($job, $payload) ?: [];
                    break;
                case 'purge_root_local':
                    $stats = cfmod_job_purge_root_local($job, $payload) ?: [];
                    break;
                case 'reconcile_all':
                    $stats = cfmod_job_reconcile_all($job, $payload) ?: [];
                    break;
                case 'enforce_ban_dns':
                    $stats = cfmod_job_enforce_ban_dns($job, $payload) ?: [];
                    break;
                case 'client_dns_operation':
                    $stats = cfmod_job_client_dns_operation($job, $payload) ?: [];
                    break;
                case 'client_cleanup_orphan_dns_remote':
                    $stats = cfmod_job_client_cleanup_orphan_dns_remote($job, $payload) ?: [];
                    break;
                case 'ban_user_frontend_like_dns_cleanup_remote':
                    $stats = cfmod_job_ban_user_frontend_like_dns_cleanup_remote($job, $payload) ?: [];
                    break;
                case 'precompute_admin_stats':
                    $stats = cfmod_job_precompute_admin_stats($job, $payload) ?: [];
                    break;
                case 'unlock_invite_registration_all':
                    $stats = cfmod_job_unlock_invite_registration_all($job, $payload) ?: [];
                    break;
                default:
                    throw new \RuntimeException('Unknown job type: ' . $type);
            }

            cfmod_worker_touch_progress(true);
            if (is_array($stats)) {
                $jobErrorClass = trim((string) ($stats['job_error_class'] ?? ''));
                if ($jobErrorClass !== '') {
                    $jobErrorMessage = trim((string) ($stats['job_error_message'] ?? ($stats['message'] ?? 'job_failed')));
                    $jobRetryable = array_key_exists('job_retryable', $stats)
                        ? (bool) $stats['job_retryable']
                        : !in_array($jobErrorClass, ['not_found', 'already_deleted', 'invalid_request', 'auth'], true);
                    throw new \RuntimeException('__job_classified_error__|' . $jobErrorClass . '|' . ($jobRetryable ? '1' : '0') . '|' . $jobErrorMessage);
                }
            }
            $summary = cfmod_build_stats_summary($stats);
            $finishedAt = date('Y-m-d H:i:s');
            $durationSeconds = (int) max(0, round(microtime(true) - $jobStartMicro));
            $jobWarnSeconds = max(5, min(3600, intval($settings['job_warn_seconds'] ?? 45)));
            if ($durationSeconds >= $jobWarnSeconds) {
                if (!is_array($stats)) {
                    $stats = [];
                }
                $warnings = isset($stats['warnings']) && is_array($stats['warnings']) ? $stats['warnings'] : [];
                $warnings[] = 'slow_job:' . $durationSeconds . 's';
                $stats['warnings'] = array_values(array_unique($warnings));
                $stats['slow_job'] = 1;
                $stats['job_warn_seconds'] = $jobWarnSeconds;
                $summary = cfmod_build_stats_summary($stats);
            }

            $updateData = [
                'status' => 'done',
                'next_run_at' => null,
                'updated_at' => $finishedAt,
                'last_error' => substr($summary, 0, 1000),
            ];
            if ($metricsSupported) {
                $updateData['finished_at'] = $finishedAt;
                $updateData['duration_seconds'] = $durationSeconds;
                $updateData['stats_json'] = !empty($stats) ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null;
            }
            if (!empty($controlColumns['lease_token'])) {
                $updateData['lease_token'] = null;
            }
            if (!empty($controlColumns['worker_id'])) {
                $updateData['worker_id'] = null;
            }
            if (!empty($controlColumns['heartbeat_at'])) {
                $updateData['heartbeat_at'] = null;
            }
            if (!empty($controlColumns['cancel_requested'])) {
                $updateData['cancel_requested'] = 0;
            }
            if (!empty($controlColumns['cancel_requested_at'])) {
                $updateData['cancel_requested_at'] = null;
            }

            $doneQuery = Capsule::table('mod_cloudflare_jobs')
                ->where('id', $job->id)
                ->where('status', 'running');
            if (!empty($controlColumns['lease_token']) && !empty($leaseToken)) {
                $doneQuery->where('lease_token', $leaseToken);
            }
            $doneQuery->update($updateData);
        } catch (\Throwable $e) {
            $durationSeconds = (int) max(0, round(microtime(true) - $jobStartMicro));
            $message = trim((string) $e->getMessage());
            $finishedAt = date('Y-m-d H:i:s');

            if ($message === '__job_lease_lost__') {
                // another worker recovered/reclaimed the job, do not override state
            } else {
                $failQuery = Capsule::table('mod_cloudflare_jobs')
                    ->where('id', $job->id)
                    ->where('status', 'running');
                if (!empty($controlColumns['lease_token']) && !empty($leaseToken)) {
                    $failQuery->where('lease_token', $leaseToken);
                }

                if ($message === '__job_cancelled__') {
                    $updateData = [
                        'status' => 'cancelled',
                        'next_run_at' => null,
                        'updated_at' => $finishedAt,
                        'last_error' => 'cancel_requested',
                    ];
                    if ($metricsSupported) {
                        $updateData['finished_at'] = $finishedAt;
                        $updateData['duration_seconds'] = $durationSeconds;
                        $updateData['stats_json'] = !empty($stats) ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null;
                    }
                    if (!empty($controlColumns['lease_token'])) {
                        $updateData['lease_token'] = null;
                    }
                    if (!empty($controlColumns['worker_id'])) {
                        $updateData['worker_id'] = null;
                    }
                    if (!empty($controlColumns['heartbeat_at'])) {
                        $updateData['heartbeat_at'] = null;
                    }
                    if (!empty($controlColumns['cancel_requested'])) {
                        $updateData['cancel_requested'] = 0;
                    }
                    if (!empty($controlColumns['cancel_requested_at'])) {
                        $updateData['cancel_requested_at'] = null;
                    }
                    $failQuery->update($updateData);
                } else {
                    $attempts = intval($job->attempts ?? 0) + 1;
                    $parsedClassified = cfmod_parse_classified_job_error($message);
                    $errorClass = $parsedClassified['class'];
                    $retryable = $parsedClassified['retryable'];
                    $errorMessage = $parsedClassified['message'];
                    $maxAttempts = cfmod_job_max_attempts_by_error_class($errorClass, $retryable, $settings);
                    $backoffMinutes = cfmod_job_backoff_minutes_by_error_class($attempts, $errorClass, $retryable, $settings);
                    $nextRunAt = ($attempts >= $maxAttempts || !$retryable) ? null : date('Y-m-d H:i:s', time() + $backoffMinutes * 60);
                    $updateData = [
                        'status' => ($attempts >= $maxAttempts || !$retryable ? 'failed' : 'pending'),
                        'next_run_at' => $nextRunAt,
                        'last_error' => substr($errorMessage !== '' ? $errorMessage : ($message !== '' ? $message : 'unknown job error'), 0, 1000),
                        'updated_at' => $finishedAt,
                        'attempts' => $attempts,
                    ];
                    if ($metricsSupported) {
                        $updateData['finished_at'] = $finishedAt;
                        $updateData['duration_seconds'] = $durationSeconds;
                        $updateData['stats_json'] = null;
                    }
                    if (!empty($controlColumns['lease_token'])) {
                        $updateData['lease_token'] = null;
                    }
                    if (!empty($controlColumns['worker_id'])) {
                        $updateData['worker_id'] = null;
                    }
                    if (!empty($controlColumns['heartbeat_at'])) {
                        $updateData['heartbeat_at'] = null;
                    }
                    if (!empty($controlColumns['cancel_requested'])) {
                        $updateData['cancel_requested'] = 0;
                    }
                    if (!empty($controlColumns['cancel_requested_at'])) {
                        $updateData['cancel_requested_at'] = null;
                    }
                    $failQuery->update($updateData);
                    cfmod_report_exception('job_' . ($job->type ?? 'unknown'), $e);
                }
            }
            } finally {
                cfmod_worker_set_active_job_context(null);
            }
        }
    } finally {
        cfmod_worker_release_process_lock();
    }
}

function cfmod_is_job_type_disabled(string $type, array $settings = []): bool
{
    $type = strtolower(trim($type));
    if ($type === '') {
        return false;
    }
    if (empty($settings)) {
        $settings = cfmod_get_settings();
    }
    $raw = (string) ($settings['queue_disabled_job_types'] ?? '');
    if ($raw === '') {
        return false;
    }
    $tokens = preg_split('/[\s,;|]+/', strtolower($raw)) ?: [];
    $tokens = array_values(array_filter(array_map('trim', $tokens), function ($v) {
        return $v !== '';
    }));
    return in_array($type, $tokens, true);
}

function cfmod_parse_classified_job_error(string $message): array
{
    $parsed = [
        'class' => '',
        'retryable' => true,
        'message' => trim($message),
    ];
    if (strpos($message, '__job_classified_error__|') !== 0) {
        return $parsed;
    }
    $parts = explode('|', $message, 4);
    $parsed['class'] = trim((string) ($parts[1] ?? ''));
    $parsed['retryable'] = (($parts[2] ?? '1') === '1');
    $parsed['message'] = trim((string) ($parts[3] ?? 'job_failed'));
    return $parsed;
}

function cfmod_job_backoff_minutes_by_error_class(int $attempts, string $errorClass, bool $retryable, array $settings = []): int
{
    if (!$retryable) {
        return 0;
    }
    $attempts = max(1, $attempts);
    $customBackoff = trim((string) ($settings['job_fail_retry_backoff'] ?? ''));
    if ($customBackoff !== '') {
        $parts = preg_split('/[\s,;]+/', $customBackoff) ?: [];
        $minutes = [];
        foreach ($parts as $part) {
            $value = intval($part);
            if ($value > 0) {
                $minutes[] = min(720, $value);
            }
        }
        if (!empty($minutes)) {
            $index = min(count($minutes) - 1, $attempts - 1);
            return max(1, (int) $minutes[$index]);
        }
    }
    $errorClass = strtolower(trim($errorClass));
    if (in_array($errorClass, ['rate_limit', 'rate_limited', 'throttle'], true)) {
        return min(60, 2 * (int) pow(2, min(5, $attempts - 1)));
    }
    if (in_array($errorClass, ['network_timeout', 'network', 'provider_unavailable'], true)) {
        return min(45, max(1, (int) pow(2, min(5, $attempts - 1))));
    }
    return min(30, max(1, (int) pow(2, min(5, $attempts - 1))));
}

function cfmod_job_max_attempts_by_error_class(string $errorClass, bool $retryable, array $settings = []): int
{
    if (!$retryable) {
        return 1;
    }
    $defaultRetry = max(1, min(10, intval($settings['queue_retry_default_max_attempts'] ?? 5)));
    $errorClass = strtolower(trim($errorClass));
    if (in_array($errorClass, ['auth', 'invalid_request'], true)) {
        return 1;
    }
    if (in_array($errorClass, ['rate_limit', 'rate_limited', 'throttle'], true)) {
        return max(2, min(12, intval($settings['queue_retry_rate_limit_max_attempts'] ?? 8)));
    }
    if (in_array($errorClass, ['network_timeout', 'network', 'provider_unavailable'], true)) {
        return max(2, min(10, intval($settings['queue_retry_network_max_attempts'] ?? 6)));
    }
    return $defaultRetry;
}

function cfmod_normalize_job_payload(string $type, array $payload, array $settings): array
{
    if ($type === 'cleanup_general_logs') {
        $settingLimit = intval($settings['general_logs_cleanup_batch_limit'] ?? 2000);
        $settingLimit = max(1, min(9999999, $settingLimit > 0 ? $settingLimit : 2000));
        if (!empty($payload['auto']) || empty($payload['batch_limit']) || intval($payload['batch_limit']) <= 0) {
            $payload['batch_limit'] = $settingLimit;
        }
    } elseif ($type === 'cleanup_expired_subdomains') {
        $settingRuntime = intval($settings['domain_cleanup_max_runtime_seconds'] ?? 240);
        $settingRuntime = max(30, min(3600, $settingRuntime > 0 ? $settingRuntime : 240));
        $settingBatch = intval($settings['domain_cleanup_batch_size'] ?? 50);
        $settingBatch = max(1, min(5000, $settingBatch > 0 ? $settingBatch : 50));
        if (!empty($payload['auto']) || empty($payload['max_runtime_seconds']) || intval($payload['max_runtime_seconds']) <= 0) {
            $payload['max_runtime_seconds'] = $settingRuntime;
        }
        if (empty($payload['batch_size']) || intval($payload['batch_size']) <= 0) {
            $payload['batch_size'] = $settingBatch;
        }
    }
    return $payload;
}

function cfmod_job_calibrate_all($job, array $payload): array {
    $jobId = intval($job->id);
    $settings = cfmod_get_settings();
    $rawMode = strtolower(trim((string) ($payload['mode'] ?? 'dry')));
    $mode = in_array($rawMode, ['fix', 'check_and_fix'], true) ? 'fix' : 'dry';
    $payloadBatch = intval($payload['batch_size'] ?? 0);
    if ($payloadBatch > 0) {
        $batchSize = $payloadBatch;
    } else {
        $configBatch = intval($settings['calibration_batch_size'] ?? 150);
        if ($configBatch <= 0) { $configBatch = 150; }
        $batchSize = $configBatch;
    }
    $batchSize = max(50, min(5000, $batchSize));
    $cursor = intval($payload['cursor_id'] ?? 0);
    $targetRoot = strtolower(trim((string) ($payload['rootdomain'] ?? '')));
    $targetUserId = intval($payload['userid'] ?? 0);
    $fixScope = cfmod_worker_resolve_fix_scope($payload);
    $allowSensitiveDelete = cfmod_worker_allow_sensitive_delete($payload, $settings);

    $subsQuery = Capsule::table('mod_cloudflare_subdomain')
        ->orderBy('id', 'asc');
    if ($cursor > 0) {
        $subsQuery->where('id', '>', $cursor);
    }
    if ($targetRoot !== '') {
        $subsQuery->whereRaw('LOWER(rootdomain) = ?', [$targetRoot]);
    }
    if ($targetUserId > 0) {
        $subsQuery->where('userid', $targetUserId);
    }
    [$subsQuery, $shardTotal, $shardIndex] = cfmod_worker_apply_shard_filter($subsQuery, $payload);
    $subsCollection = $subsQuery
        ->limit($batchSize + 1)
        ->get();

    if (!($subsCollection instanceof \Illuminate\Support\Collection)) {
        $subsCollection = new \Illuminate\Support\Collection(is_array($subsCollection) ? $subsCollection : (array) $subsCollection);
    }

    if ($subsCollection->count() === 0) {
        $emptyStats = [
            'mode' => $mode,
            'batch_size' => $batchSize,
            'cursor_start' => $cursor,
            'processed_subdomains' => 0,
            'differences_total' => 0,
            'warnings' => ['no_subdomains'],
            'fix_scope' => $fixScope,
            'allow_sensitive_delete' => $allowSensitiveDelete ? 1 : 0,
        ];
        if ($targetRoot !== '') {
            $emptyStats['rootdomain'] = $targetRoot;
        }
        if ($targetUserId > 0) {
            $emptyStats['userid'] = $targetUserId;
        }
        return $emptyStats;
    }

    $hasMore = $subsCollection->count() > $batchSize;
    $subs = $hasMore ? $subsCollection->slice(0, $batchSize)->values() : $subsCollection->values();

    $priority = strtolower($settings['sync_authoritative_source'] ?? 'local');
    if (!in_array($priority, ['local', 'aliyun'], true)) { $priority = 'local'; }
    $reconcileMode = cfmod_worker_reconcile_mode($settings);
    $replaceTypes = cfmod_worker_get_replace_mode_types($settings);

    $stats = [
        'mode' => $mode,
        'batch_size' => $batchSize,
        'cursor_start' => $cursor,
        'processed_subdomains' => 0,
        'processed_records' => 0,
        'differences_total' => 0,
        'difference_breakdown' => [],
        'action_breakdown' => [],
        'warnings' => [],
        'priority' => $priority,
        'fix_scope' => $fixScope,
        'allow_sensitive_delete' => $allowSensitiveDelete ? 1 : 0,
    ];
    if ($targetRoot !== '') {
        $stats['rootdomain'] = $targetRoot;
    }
    if ($targetUserId > 0) {
        $stats['userid'] = $targetUserId;
    }
    if ($shardTotal > 1) {
        $stats['shard_total'] = $shardTotal;
        $stats['shard_index'] = $shardIndex;
    }

    $providerClients = [];
    $groupedSubs = [];
    $recordsBySubdomain = [];
    $subdomainIds = [];
    foreach ($subs as $s) {
        $sid = intval($s->id ?? 0);
        if ($sid > 0) {
            $subdomainIds[] = $sid;
        }
    }
    if (!empty($subdomainIds)) {
        try {
            $allLocalRecords = Capsule::table('mod_cloudflare_dns_records')
                ->whereIn('subdomain_id', $subdomainIds)
                ->orderBy('subdomain_id', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            foreach ($allLocalRecords as $record) {
                $sid = intval($record->subdomain_id ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                if (!isset($recordsBySubdomain[$sid])) {
                    $recordsBySubdomain[$sid] = [];
                }
                $recordsBySubdomain[$sid][] = $record;
            }
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'local_records_prefetch_failed';
            cfmod_report_exception('calibrate_prefetch_local_records', $e);
        }
    }

    foreach ($subs as $s) {
        cfmod_worker_touch_progress();
        $stats['processed_subdomains']++;
        $providerId = cfmod_worker_resolve_provider_account_id_for_subdomain($s, $settings);
        $groupKey = $providerId ?: 0;
        if (!isset($groupedSubs[$groupKey])) {
            $groupedSubs[$groupKey] = [];
        }
        $groupedSubs[$groupKey][] = $s;
    }

    foreach ($groupedSubs as $providerKey => $groupSubs) {
        $providerAccountId = $providerKey ?: null;
        $providerContext = cfmod_worker_acquire_provider_client_cached($providerAccountId, $settings, $providerClients, $stats, 'calibrate');
        if (!$providerContext) {
            foreach ($groupSubs as $failedSub) {
                $stats['warnings'][] = 'calibrate_provider_missing_sub:' . $failedSub->id;
            }
            continue;
        }
        $cf = $providerContext['client'];
        $zoneCache = [];

        foreach ($groupSubs as $s) {
            cfmod_worker_touch_progress();
            $zoneId = $s->cloudflare_zone_id ?: ($s->rootdomain ?? null);
            if (!$zoneId) {
                $stats['warnings'][] = 'missing_zone:' . $s->id;
                continue;
            }

            try {
                $localRecords = $recordsBySubdomain[intval($s->id ?? 0)] ?? [];
                cfmod_calibrate_subdomain($jobId, $mode, $cf, $s, $localRecords, $zoneCache, $zoneId, $stats, $priority, $fixScope, $allowSensitiveDelete);
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'calibrate_error:' . $s->id;
                cfmod_report_exception('calibrate_subdomain', $e);
            }
        }
    }

    $lastProcessedId = $subs->last()->id ?? $cursor;

    if ($hasMore && $lastProcessedId) {
        $newPayload = $payload;
        $newPayload['cursor_id'] = $lastProcessedId;
        $newPayload['batch_size'] = $batchSize;
        $newPayload['mode'] = $mode;
        $newPayload['origin_job_id'] = $payload['origin_job_id'] ?? $jobId;
        if ($targetRoot !== '') {
            $newPayload['rootdomain'] = $targetRoot;
        }
        if ($targetUserId > 0) {
            $newPayload['userid'] = $targetUserId;
        }
        if ($shardTotal > 1) {
            $newPayload['shard_total'] = $shardTotal;
            $newPayload['shard_index'] = $shardIndex;
        }

        try {
            $continuationId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                'type' => 'calibrate_all',
                'payload_json' => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
                'priority' => intval($job->priority ?? 10),
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            $stats['has_more'] = true;
            $stats['next_cursor'] = $lastProcessedId;
            $stats['continuation_job_id'] = $continuationId;
        } catch (\Throwable $e) {
            $stats['has_more'] = true;
            $stats['warnings'][] = 'enqueue_failed:' . $lastProcessedId;
            cfmod_report_exception('calibrate_enqueue', $e);
        }
    } else {
        $stats['has_more'] = false;
    }

    return $stats;
}


/**
 * @param CloudflareAPI|DNSPodLegacyAPI|DNSPodIntlAPI|mixed $cf
 */
function cfmod_calibrate_subdomain(int $jobId, string $mode, $cf, $sub, array $locals, array &$zoneCache, string $zoneId, array &$stats, string $priority, array $fixScope = [], bool $allowSensitiveDelete = false): void {
    if (!is_object($cf) || !method_exists($cf, 'getDnsRecords')) {
        throw new \InvalidArgumentException('calibrate_subdomain requires a provider client supporting getDnsRecords');
    }
    $allowFixTtl = $fixScope['ttl'] ?? true;
    $allowFixMissing = $fixScope['missing'] ?? true;
    $allowFixExtra = $fixScope['extra'] ?? true;

    $nameSub = strtolower($sub->subdomain);
    $stats['processed_records'] = ($stats['processed_records'] ?? 0) + count($locals);

    $remoteIndex = cfmod_worker_build_remote_index_for_subdomain($cf, $zoneId, $sub, $locals, $zoneCache, $stats);

    foreach ($locals as $lr) {
        cfmod_worker_touch_progress();
        $normalizedTtl = cfmod_normalize_ttl($lr->ttl ?? 600);
        if (!isset($lr->ttl) || intval($lr->ttl) !== $normalizedTtl) {
            Capsule::table('mod_cloudflare_dns_records')
                ->where('id', $lr->id)
                ->update([
                    'ttl' => $normalizedTtl,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
        }
        $lr->ttl = $normalizedTtl;

        $n = strtolower($lr->name);
        $t = strtoupper($lr->type);
        $matched = cfmod_worker_consume_remote_match($remoteIndex, $n, $t, (string) $lr->content);
        if (!$matched) {
            $action = 'noop';
            if ($mode === 'fix') {
                if ($allowFixMissing) {
                    $res = $cf->createDnsRecord($zoneId, $lr->name, $t, $lr->content, $lr->ttl, boolval($lr->proxied));
                    if ($res['success'] ?? false) {
                        $action = 'created_on_cf';
                        $newId = $res['result']['id'] ?? null;
                        Capsule::table('mod_cloudflare_dns_records')->where('id', $lr->id)->update([
                            'record_id' => $newId,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    } else {
                        $action = 'create_failed';
                        $stats['warnings'][] = 'create_failed:' . $sub->id . ':' . $n . ':' . $t;
                    }
                } else {
                    $action = 'scope_skip_missing';
                }
            }
            cfmod_sync_result($jobId, $sub->id, 'missing_on_cf', $action, [
                'name' => $lr->name,
                'type' => $t,
                'content' => $lr->content
            ]);
            cfmod_track_sync_stat($stats, 'missing_on_cf', $action);
            continue;
        }

        $needUpdate = false;
        $update = [];
        if (intval($matched['ttl'] ?? 0) !== $lr->ttl) {
            $needUpdate = true;
            $update['ttl'] = $lr->ttl;
        }
        if ($needUpdate) {
            $action = 'noop';
            if ($mode === 'fix') {
                if ($allowFixTtl) {
                    $res = $cf->updateDnsRecord($zoneId, $matched['id'], array_merge([
                        'type' => $t,
                        'content' => $lr->content,
                        'name' => $lr->name
                    ], $update));
                    if ($res['success'] ?? false) {
                        $action = 'updated_on_cf';
                    } else {
                        $action = 'update_failed';
                        $stats['warnings'][] = 'update_failed:' . ($matched['id'] ?? $sub->id);
                    }
                } else {
                    $action = 'scope_skip_ttl';
                }
            }
            cfmod_sync_result($jobId, $sub->id, 'mismatch', $action, [
                'name' => $lr->name,
                'type' => $t,
                'from' => ['ttl' => ($matched['ttl'] ?? null)],
                'to' => $update
            ]);
            cfmod_track_sync_stat($stats, 'mismatch', $action);
        }
    }

    foreach ($remoteIndex as $n => $typeToList) {
        cfmod_worker_touch_progress();
        if (!($n === $nameSub || cf_str_ends_with($n, '.' . $nameSub))) {
            continue;
        }
        foreach ($typeToList as $t => $list) {
            foreach ($list as $idx => $cr) {
                $action = 'noop';
                if ($priority === 'local') {
                    if ($mode === 'fix') {
                        if (!$allowFixExtra) {
                            $action = 'scope_skip_extra';
                        } elseif (cfmod_worker_is_sensitive_dns_type((string) $t) && !$allowSensitiveDelete) {
                            if ($allowFixMissing) {
                                $imported = cfmod_worker_import_remote_record_to_local(
                                    intval($sub->id),
                                    $zoneId,
                                    $n,
                                    $t,
                                    $cr
                                );
                                if ($imported) {
                                    $action = 'imported_local_sensitive';
                                    $stats['records_imported_local'] = ($stats['records_imported_local'] ?? 0) + 1;
                                } else {
                                    $action = 'import_failed_sensitive';
                                    $stats['warnings'][] = 'import_failed_sensitive:' . $sub->id . ':' . $n . ':' . $t;
                                }
                            } else {
                                $action = 'scope_skip_sensitive';
                            }
                        } elseif (!empty($cr['id'])) {
                            $res = $cf->deleteSubdomain($zoneId, $cr['id'], [
                                'name' => $n,
                                'type' => $t,
                                'content' => $cr['content'] ?? null,
                            ]);
                            if (($res['success'] ?? false) || cfmod_worker_provider_not_found($res)) {
                                $action = 'deleted_on_cf';
                                unset($remoteIndex[$n][$t][$idx]);
                                $remoteIndex[$n][$t] = array_values($remoteIndex[$n][$t]);
                            } else {
                                $action = 'delete_failed';
                                $stats['warnings'][] = 'delete_failed:' . ($cr['id'] ?? '');
                            }
                        } else {
                            $action = 'delete_skipped_no_id';
                            $stats['warnings'][] = 'delete_skipped_no_id:' . $sub->id . ':' . $n . ':' . $t;
                        }
                    }
                    cfmod_sync_result($jobId, $sub->id, 'extra_on_cf', $action, [
                        'name' => $n,
                        'type' => $t,
                        'content' => ($cr['content'] ?? ''),
                        'record_id' => ($cr['id'] ?? null)
                    ]);
                    cfmod_track_sync_stat($stats, 'extra_on_cf', $action);
                    continue;
                }

                if ($mode === 'fix') {
                    if (!$allowFixMissing) {
                        $action = 'scope_skip_missing';
                    } else {
                        try {
                            Capsule::table('mod_cloudflare_dns_records')->insert([
                                'subdomain_id' => $sub->id,
                                'zone_id' => $zoneId,
                                'record_id' => ($cr['id'] ?? null),
                                'name' => $n,
                                'type' => $t,
                                'content' => ($cr['content'] ?? ''),
                                'ttl' => intval($cr['ttl'] ?? 600),
                                'proxied' => 0,
                                'status' => 'active',
                                'priority' => null,
                                'line' => null,
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                            CfSubdomainService::markHasDnsHistory($sub->id);
                            $action = 'imported_local';
                        } catch (\Throwable $e) {
                            $action = 'import_failed';
                            $stats['warnings'][] = 'import_failed:' . $sub->id . ':' . $n . ':' . $t;
                            cfmod_report_exception('calibrate_import', $e);
                        }
                    }
                }

                cfmod_sync_result($jobId, $sub->id, 'extra_on_cf', $action, [
                    'name' => $n,
                    'type' => $t,
                    'content' => ($cr['content'] ?? '')
                ]);
                cfmod_track_sync_stat($stats, 'extra_on_cf', $action);
            }
        }
    }
}


/**
 * @param CloudflareAPI|DNSPodLegacyAPI|DNSPodIntlAPI|mixed $cf
 */
function cfmod_worker_build_remote_index_for_subdomain($cf, string $zoneId, $sub, $locals, array &$zoneCache, array &$stats): array {
    $nameSub = strtolower($sub->subdomain ?? '');
    $names = [$nameSub];
    foreach ($locals as $lr) {
        $candidate = strtolower($lr->name ?? '');
        if ($candidate !== '' && !in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    }
    $index = [];
    foreach ($names as $name) {
        if ($name === '') {
            continue;
        }
        $records = cfmod_worker_fetch_remote_records_by_name($cf, $zoneId, $name, $zoneCache, $stats);
        foreach ($records as $rec) {
            $recordName = strtolower($rec['name'] ?? $name);
            $recordType = strtoupper($rec['type'] ?? '');
            if ($recordName === '' || $recordType === '') {
                continue;
            }
            $index[$recordName][$recordType][] = $rec;
        }
    }
    return $index;
}

/**
 * @param CloudflareAPI|DNSPodLegacyAPI|DNSPodIntlAPI|mixed $cf
 */
function cfmod_worker_fetch_remote_records_by_name($cf, string $zoneId, string $name, array &$zoneCache, array &$stats): array {
    $cacheKey = strtolower($zoneId);
    if (!isset($zoneCache[$cacheKey])) {
        $zoneCache[$cacheKey] = [];
    }
    $nameKey = strtolower($name);
    if (!array_key_exists($nameKey, $zoneCache[$cacheKey])) {
        $res = $cf->getDnsRecords($zoneId, $name, ['per_page' => 500]);
        if (!($res['success'] ?? false)) {
            $stats['warnings'][] = 'fetch_failed:' . $zoneId . ':' . $name;
            $zoneCache[$cacheKey][$nameKey] = [];
        } else {
            $zoneCache[$cacheKey][$nameKey] = $res['result'] ?? [];
        }
    }
    return $zoneCache[$cacheKey][$nameKey];
}

function cfmod_sync_result(int $jobId, ?int $subId, string $kind, string $action, array $detail): void {
    try {
        Capsule::table('mod_cloudflare_sync_results')->insert([
            'job_id' => $jobId,
            'subdomain_id' => $subId,
            'kind' => $kind,
            'action' => $action,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    } catch (\Exception $e) {}
}

function cfmod_job_auto_unban_due($job, array $payload = []): array {
    $now = date('Y-m-d H:i:s');
    $due = Capsule::table('mod_cloudflare_user_bans')
        ->where('status', 'banned')
        ->whereNotNull('ban_expires_at')
        ->where('ban_expires_at', '<=', $now)
        ->get();

    if ($due instanceof \Illuminate\Support\Collection) {
        $dueRows = $due->all();
    } elseif (is_array($due)) {
        $dueRows = $due;
    } elseif ($due instanceof \Traversable) {
        $dueRows = iterator_to_array($due);
    } elseif ($due === null) {
        $dueRows = [];
    } else {
        $dueRows = [$due];
    }

    $stats = [
        'unbanned' => 0,
        'warnings' => [],
        'processed_subdomains' => count($dueRows),
    ];

    if (empty($dueRows)) {
        $stats['message'] = 'no bans to lift';
        return $stats;
    }

    $banIds = [];
    $userIds = [];
    foreach ($dueRows as $banRow) {
        $banId = (int) ($banRow->id ?? 0);
        $userId = (int) ($banRow->userid ?? 0);
        if ($banId <= 0 || $userId <= 0) {
            continue;
        }
        $banIds[$banId] = $banId;
        $userIds[$userId] = $userId;
    }
    $banIds = array_values($banIds);
    $userIds = array_values($userIds);

    if (empty($banIds)) {
        $stats['message'] = 'no bans to lift';
        return $stats;
    }

    try {
        Capsule::transaction(function () use ($banIds, $userIds, $now) {
            Capsule::table('mod_cloudflare_user_bans')
                ->whereIn('id', $banIds)
                ->update([
                    'status' => 'unbanned',
                    'unbanned_at' => $now,
                    'updated_at' => $now,
                ]);

            if (!empty($userIds)) {
                Capsule::table('tblclients')
                    ->whereIn('id', $userIds)
                    ->update([
                        'status' => 'Active',
                    ]);
            }
        });

        $stats['unbanned'] = count($banIds);
        $stats['message'] = 'lifted ' . $stats['unbanned'] . ' bans';

        if (function_exists('cloudflare_subdomain_log')) {
            foreach ($dueRows as $banRow) {
                $banId = (int) ($banRow->id ?? 0);
                $userId = (int) ($banRow->userid ?? 0);
                if ($banId <= 0 || $userId <= 0) {
                    continue;
                }
                cloudflare_subdomain_log('auto_unban_user', [
                    'userid' => $userId,
                    'ban_id' => $banId,
                ]);
            }
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'bulk_unban_failed';
        $stats['message'] = 'auto unban failed';
        cfmod_report_exception('auto_unban_due', $e);
    }

    return $stats;
}

function cfmod_job_risk_scan_all($job, array $payload = []): array {
    $jobId = intval($job->id);
    $settings = cfmod_get_settings();
    $safeBrowsingOnly = !empty($payload['safe_browsing_only']);
    $virusTotalOnly = !empty($payload['virustotal_only']);
    $forceRefresh = !empty($payload['force_refresh']);
    $endpoint = trim($settings['risk_api_endpoint'] ?? '');
    $apiKey = trim($settings['risk_api_key'] ?? '');
    $client = null;
    if (!$safeBrowsingOnly) {
        if ($endpoint === '') {
            throw new \RuntimeException('risk_api_endpoint not configured');
        }
        $client = new ExternalRiskAPI($endpoint, $apiKey !== '' ? $apiKey : null);
    }

    $stats = [
        'scanned' => 0,
        'high_risk' => 0,
        'warnings' => [],
    ];

    $batchSize = intval($payload['batch_size'] ?? ($settings['risk_scan_batch_size'] ?? 50));
    if ($batchSize <= 0) {
        $batchSize = 50;
    }
    $batchSize = max(10, min(1000, $batchSize));
    $cursor = intval($payload['cursor_id'] ?? 0);

    $now = date('Y-m-d H:i:s');
    $auto = $settings['risk_auto_action'] ?? 'none';
    $threshold = max(0, min(100, intval($settings['risk_auto_threshold'] ?? 80)));

    $kwRaw = (string)($settings['risk_keywords'] ?? '');
    $keywords = [];
    if ($kwRaw !== '') {
        $parts = preg_split('/[，,]+/u', $kwRaw);
        $parts = array_map('trim', $parts ?: []);
        foreach ($parts as $p) {
            if ($p !== '') {
                $keywords[] = $p;
            }
        }
    }

    $includeRecords = (($settings['risk_include_records'] ?? 'off') === 'on' || ($settings['risk_include_records'] ?? '0') == '1');
    $recordTypesRaw = (string)($settings['risk_record_types'] ?? 'A,CNAME');
    $typeSet = [];
    foreach (array_map('trim', explode(',', $recordTypesRaw)) as $t) {
        if ($t !== '') {
            $typeSet[strtoupper($t)] = true;
        }
    }
    $recordLimit = max(0, intval($settings['risk_record_limit'] ?? 10));
    $parallel = intval($settings['risk_parallel_requests'] ?? 5);
    $parallel = max(1, min(10, $parallel));

    $subs = Capsule::table('mod_cloudflare_subdomain')
        ->where('id', '>', $cursor)
        ->orderBy('id', 'asc')
        ->limit($batchSize)
        ->get();
    $subsArray = cfmod_iterable_to_array($subs);
    if (empty($subsArray)) {
        $stats['processed_subdomains'] = 0;
        $stats['cursor_start'] = $cursor;
        $stats['message'] = $cursor > 0 ? 'scan_completed' : 'no_subdomains';
        return $stats;
    }

    $nextCursor = 0;
    $subdomainIds = [];
    foreach ($subsArray as $row) {
        $sid = is_object($row) ? (int) ($row->id ?? 0) : (int) ($row['id'] ?? 0);
        if ($sid > 0) {
            $subdomainIds[] = $sid;
            $nextCursor = $sid;
        }
    }

    $allRecords = [];
    if (!empty($subdomainIds) && $includeRecords) {
        $recordsQuery = Capsule::table('mod_cloudflare_dns_records')
            ->whereIn('subdomain_id', $subdomainIds)
            ->orderBy('subdomain_id', 'asc')
            ->orderBy('id', 'asc')
            ->get();
        foreach ($recordsQuery as $r) {
            if (!isset($allRecords[$r->subdomain_id])) {
                $allRecords[$r->subdomain_id] = [];
            }
            $allRecords[$r->subdomain_id][] = $r;
        }
    }

    $requests = [];
    $metas = [];
    foreach ($subsArray as $s) {
        $rowObj = is_object($s) ? $s : (object) $s;
        $name = strtolower($rowObj->subdomain ?? '');
        if ($name === '') {
            $stats['warnings'][] = 'sub_missing_name:' . ($rowObj->id ?? '');
            continue;
        }
        $extras = [];
        if (!empty($keywords)) {
            $extras['keywords'] = $keywords;
        }
        if ($includeRecords) {
            $targets = [];
            $records = $allRecords[$rowObj->id] ?? [];
            foreach ($records as $r) {
                $rt = strtoupper($r->type ?? '');
                if (!isset($typeSet[$rt])) {
                    continue;
                }
                $host = strtolower($r->name ?? '');
                if ($host !== '' && $host !== $name) {
                    $targets[] = $host;
                }
                if ($recordLimit > 0 && count($targets) >= $recordLimit) {
                    break;
                }
            }
            if (!empty($targets)) {
                $extras['targets'] = array_values(array_unique($targets));
            }
        }
        $requests[] = ['subdomain' => $name, 'extras' => $extras];
        $metas[] = ['sub' => $rowObj, 'name' => $name];
    }

    if (!empty($requests)) {
        if ($safeBrowsingOnly) {
            $responses = array_fill(0, count($requests), ['success' => true, 'result' => []]);
        } elseif ($parallel === 1 || count($requests) === 1) {
            $responses = [];
            foreach ($requests as $req) {
                $responses[] = $client->scanSubdomain($req['subdomain'], $req['extras']);
            }
        } else {
            $responses = $client->scanBatch($requests, $parallel);
        }

        foreach ($metas as $idx => $meta) {
            $s = $meta['sub'];
            $response = $responses[$idx] ?? ['success' => false, 'errors' => ['missing_response' => true]];
            try {
                if (!is_array($response)) {
                    throw new \RuntimeException('invalid response');
                }
                if ($safeBrowsingOnly) {
                    $data = ['risk_score' => 0, 'risk_level' => 'low', 'reasons' => [], 'events' => []];
                } else {
                    $ok = (bool)($response['success'] ?? false);
                    $data = $response['result'] ?? $response['data'] ?? [];
                    if (!$ok || !is_array($data)) {
                        $detail = cfmod_risk_scan_response_error($response);
                        throw new \RuntimeException('scan failed: ' . $detail);
                    }
                }

                $riskScore = max(0, min(100, intval($data['risk_score'] ?? 0)));
                $riskLevel = (string)($data['risk_level'] ?? ($riskScore >= $threshold ? 'high' : 'low'));
                $reasons = is_array($data['reasons'] ?? null) ? ($data['reasons'] ?? []) : [];
                $events = is_array($data['events'] ?? null) ? ($data['events'] ?? []) : [];
                if (!$virusTotalOnly && class_exists('CfSafeBrowsingService') && CfSafeBrowsingService::isEnabled($settings)) {
                    $checkUrl = 'https://' . $meta['name'];
                    $safeResult = CfSafeBrowsingService::checkUrl($checkUrl, $settings);
                    if (!empty($safeResult['success']) && !empty($safeResult['matched'])) {
                        $riskScore = max($riskScore, 95);
                        $riskLevel = 'high';
                        $reasons[] = 'google_safe_browsing_matched';
                        $events[] = [
                            'level' => 'high',
                            'score' => 95,
                            'source' => 'google_safe_browsing',
                            'reason' => 'threat match',
                            'details' => [
                                'url' => $checkUrl,
                                'matches' => $safeResult['matches'] ?? [],
                            ],
                        ];
                    } elseif (empty($safeResult['success'])) {
                        $stats['warnings'][] = 'safe_browsing_check_failed:' . ($safeResult['error'] ?? 'unknown');
                    }
                }

                if (($virusTotalOnly || !$safeBrowsingOnly) && class_exists('CfVirusTotalService') && CfVirusTotalService::isEnabled($settings)) {
                    $vtResult = CfVirusTotalService::checkDomain((string) ($meta['name'] ?? ''), $settings, ['force_refresh' => $forceRefresh]);
                    if (!empty($vtResult['success'])) {
                        $vtLevel = (string) ($vtResult['risk_level'] ?? 'low');
                        $vtScore = max(0, min(100, intval($vtResult['risk_score'] ?? 0)));
                        $vtStats = is_array($vtResult['stats'] ?? null) ? $vtResult['stats'] : [];
                        if ($vtLevel === 'high' || $vtLevel === 'medium' || !empty($vtResult['matched'])) {
                            $riskScore = max($riskScore, $vtScore);
                            if ($vtLevel === 'high') {
                                $riskLevel = 'high';
                            } elseif ($vtLevel === 'medium' && $riskLevel !== 'high') {
                                $riskLevel = 'medium';
                            }
                            $reasons[] = 'virustotal_domain_flagged';
                            $events[] = [
                                'level' => $vtLevel,
                                'score' => $vtScore,
                                'source' => 'virustotal',
                                'reason' => 'domain reputation match',
                                'details' => [
                                    'domain' => (string) ($meta['name'] ?? ''),
                                    'stats' => $vtStats,
                                    'from_cache' => !empty($vtResult['from_cache']) ? 1 : 0,
                                ],
                            ];
                        }
                    } else {
                        $stats['warnings'][] = 'virustotal_check_failed:' . ($vtResult['error'] ?? 'unknown');
                    }
                }

                $stats['scanned']++;
                if ($riskLevel === 'high' || $riskScore >= $threshold) {
                    $stats['high_risk']++;
                }

                $reasonsJson = json_encode($reasons, JSON_UNESCAPED_UNICODE);
                $exists = Capsule::table('mod_cloudflare_domain_risk')->where('subdomain_id', $s->id)->first();
                if ($exists) {
                    Capsule::table('mod_cloudflare_domain_risk')->where('subdomain_id', $s->id)->update([
                        'risk_score' => $riskScore,
                        'risk_level' => $riskLevel,
                        'reasons_json' => $reasonsJson,
                        'last_checked_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    Capsule::table('mod_cloudflare_domain_risk')->insert([
                        'subdomain_id' => $s->id,
                        'risk_score' => $riskScore,
                        'risk_level' => $riskLevel,
                        'reasons_json' => $reasonsJson,
                        'last_checked_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $highRiskEvents = [];
                foreach ($events as $ev) {
                    $lvl = strtolower(trim((string)($ev['level'] ?? 'low')));
                    $score = intval($ev['score'] ?? 0);
                    if ($lvl === 'high' || $score >= $threshold) {
                        $src = substr(trim((string)($ev['source'] ?? 'external')), 0, 32);
                        $reason = substr(trim((string)($ev['reason'] ?? '')), 0, 255);
                        $detailsJson = json_encode($ev['details'] ?? [], JSON_UNESCAPED_UNICODE);
                        $highRiskEvents[] = [
                            'subdomain_id' => $s->id,
                            'source' => $src,
                            'score' => $score,
                            'level' => 'high',
                            'reason' => $reason,
                            'details_json' => $detailsJson,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if (!empty($highRiskEvents)) {
                    Capsule::table('mod_cloudflare_risk_events')->insert($highRiskEvents);
                }

                if ($riskLevel === 'high' || $riskScore >= $threshold) {
                    $todaySummary = Capsule::table('mod_cloudflare_risk_events')
                        ->where('subdomain_id', $s->id)
                        ->where('source', 'summary')
                        ->whereRaw('DATE(created_at) = CURDATE()')
                        ->first();
                    if (!$todaySummary) {
                        try {
                            Capsule::table('mod_cloudflare_risk_events')->insert([
                                'subdomain_id' => $s->id,
                                'source' => 'summary',
                                'score' => $riskScore,
                                'level' => 'high',
                                'reason' => 'scan summary',
                                'details_json' => json_encode([
                                    'events_count' => count($highRiskEvents),
                                    'total_events' => count($events),
                                    'reasons_count' => count($reasons),
                                    'last_checked_at' => $now,
                                ], JSON_UNESCAPED_UNICODE),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        } catch (\Throwable $e) {}
                    }
                }

                $acted = false;
                if ($auto === 'suspend' && $riskScore >= $threshold) {
                    Capsule::table('mod_cloudflare_subdomain')->where('id', $s->id)->update([
                        'status' => 'suspended',
                        'updated_at' => $now,
                    ]);
                    $acted = true;
                }

                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('risk_scan', [
                        'subdomain' => $meta['name'],
                        'score' => $riskScore,
                        'level' => $riskLevel,
                        'auto_action' => ($acted ? 'suspend' : 'none'),
                    ], intval($s->userid ?? 0), $s->id);
                }
            } catch (\Throwable $e) {
                error_log("Risk scan error for subdomain {$s->subdomain}: " . $e->getMessage());
                $stats['warnings'][] = 'sub:' . ($s->id ?? '');
                cfmod_report_exception('risk_scan', $e);
            }
        }
    }

    $processedCount = count($subsArray);
    $stats['processed_subdomains'] = $processedCount;
    $stats['cursor_start'] = $cursor;
    $stats['cursor_end'] = $nextCursor;
    $stats['message'] = 'scanned ' . $processedCount . ' domains';

    if ($processedCount === $batchSize && $nextCursor > 0) {
        $stats['has_more'] = true;
        if ($virusTotalOnly) {
            $nextType = 'virustotal_scan_all';
        } elseif ($safeBrowsingOnly) {
            $nextType = 'safe_browsing_scan_all';
        } else {
            $nextType = 'risk_scan_all';
        }
        try {
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => $nextType,
                'payload_json' => json_encode([
                    'cursor_id' => $nextCursor,
                    'batch_size' => $batchSize,
                    'auto' => !empty($payload['auto']),
                    'safe_browsing_only' => $safeBrowsingOnly ? 1 : 0,
                    'virustotal_only' => $virusTotalOnly ? 1 : 0,
                    'force_refresh' => $forceRefresh ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
                'priority' => intval($job->priority ?? 20),
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'requeue_failed';
            cfmod_report_exception('risk_scan_requeue', $e);
        }
        $stats['message'] .= ' (continuation queued)';
    }

    return $stats;
}


function cfmod_job_virustotal_scan_all($job, array $payload = []): array {
    $settings = cfmod_get_settings();
    $batchSize = intval($payload['batch_size'] ?? ($settings['virustotal_scan_batch_size'] ?? ($settings['risk_scan_batch_size'] ?? 50)));
    $batchSize = max(10, min(1000, $batchSize > 0 ? $batchSize : 50));
    $payload['batch_size'] = $batchSize;
    $payload['safe_browsing_only'] = 0;
    $payload['virustotal_only'] = 1;

    $stats = cfmod_job_risk_scan_all($job, $payload);

    $vtEnabled = class_exists('CfVirusTotalService') && CfVirusTotalService::isEnabled($settings);
    $intervalMinutes = max(0, intval($settings['virustotal_scan_interval'] ?? 120));
    $hasContinuation = is_array($stats) && strpos((string)($stats['message'] ?? ''), 'continuation queued') !== false;

    if ($vtEnabled && $intervalMinutes > 0 && !$hasContinuation) {
        try {
            $now = date('Y-m-d H:i:s');
            $exists = Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'virustotal_scan_all')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            if (!$exists) {
                $nextRunAt = date('Y-m-d H:i:s', time() + $intervalMinutes * 60);
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'virustotal_scan_all',
                    'payload_json' => json_encode(['batch_size' => $batchSize], JSON_UNESCAPED_UNICODE),
                    'priority' => 5,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => $nextRunAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if (is_array($stats)) {
                    $stats['message'] = trim((string)($stats['message'] ?? '')) . ' (next VT scan scheduled at ' . $nextRunAt . ')';
                }
            }
        } catch (\Throwable $e) {
            if (is_array($stats)) {
                $stats['warnings'] = is_array($stats['warnings'] ?? null) ? $stats['warnings'] : [];
                $stats['warnings'][] = 'virustotal_schedule_failed';
            }
            cfmod_report_exception('virustotal_schedule', $e);
        }
    }

    return is_array($stats) ? $stats : [];
}

function cfmod_job_safe_browsing_scan_all($job, array $payload = []): array {
    $settings = cfmod_get_settings();
    $batchSize = intval($payload['batch_size'] ?? ($settings['safe_browsing_scan_batch_size'] ?? 50));
    $batchSize = max(10, min(1000, $batchSize > 0 ? $batchSize : 50));
    $payload['batch_size'] = $batchSize;
    $payload['safe_browsing_only'] = 1;
    return cfmod_job_risk_scan_all($job, $payload);
}

function cfmod_job_replace_root($job, array $payload = []): array {
    $jobId = intval($job->id ?? 0);
    $fromRoot = trim((string)($payload['from_root'] ?? ''));
    $toRoot = trim((string)($payload['to_root'] ?? ''));
    $deleteOld = !!($payload['delete_old'] ?? false);
    if ($fromRoot === '' || $toRoot === '' || $fromRoot === $toRoot) { throw new \InvalidArgumentException('invalid root domains'); }

    $batchSize = intval($payload['batch_size'] ?? 200);
    if ($batchSize <= 0) { $batchSize = 200; }
    $batchSize = max(25, min(5000, $batchSize));
    $cursor = intval($payload['cursor_id'] ?? 0);

    $settings = cfmod_get_settings();
    $targetProviderContext = cfmod_acquire_provider_client_for_rootdomain($toRoot, $settings);
    if (!$targetProviderContext || empty($targetProviderContext['client'])) {
        throw new \RuntimeException('No active provider account available for target root');
    }
    $targetCf = $targetProviderContext['client'];

    $toZone = $targetCf->getZoneId($toRoot);
    if (!$toZone) { throw new \RuntimeException('new root zone not found: '.$toRoot); }

    $stats = [
        'processed_subdomains' => 0,
        'records_updated_on_cf' => 0,
        'records_updated_local' => 0,
        'records_imported_local' => 0,
        'warnings' => [],
        'batch_size' => $batchSize,
        'cursor_start' => $cursor,
    ];

    $fromLo = strtolower($fromRoot);
    $subsQuery = Capsule::table('mod_cloudflare_subdomain')
        ->where(function($outer) use ($fromLo) {
            $outer->whereRaw('LOWER(rootdomain) = ?', [$fromLo])
                  ->orWhere(function($inner) use ($fromLo) {
                      $inner->where('subdomain', $fromLo)
                            ->orWhere('subdomain', 'like', '%.' . $fromLo);
                  });
        })
        ->orderBy('id','asc');
    if ($cursor > 0) {
        $subsQuery->where('id', '>', $cursor);
    }
    $subsRaw = $subsQuery->limit($batchSize + 1)->get();
    if (!($subsRaw instanceof \Illuminate\Support\Collection)) {
        $subsRaw = new \Illuminate\Support\Collection(is_array($subsRaw) ? $subsRaw : (array) $subsRaw);
    }
    if ($subsRaw->count() === 0) {
        $stats['cursor_end'] = $cursor;
        $stats['message'] = 'no subdomains matched ' . $fromRoot . ($cursor > 0 ? ' after cursor ' . $cursor : '');
        return $stats;
    }

    $hasMore = $subsRaw->count() > $batchSize;
    $batch = $hasMore ? $subsRaw->slice(0, $batchSize)->values() : $subsRaw->values();

    $subdomainIds = [];
    $lastId = $cursor;
    foreach ($batch as $row) {
        $sid = intval($row->id ?? 0);
        if ($sid > 0) {
            $subdomainIds[] = $sid;
            $lastId = $sid;
        }
    }
    if (empty($subdomainIds)) {
        $stats['cursor_end'] = $cursor;
        $stats['message'] = 'no subdomain IDs resolved for ' . $fromRoot;
        return $stats;
    }

    $allLocalRecords = [];
    try {
        $localRecords = Capsule::table('mod_cloudflare_dns_records')
            ->whereIn('subdomain_id', $subdomainIds)
            ->orderBy('subdomain_id', 'asc')
            ->orderBy('id', 'asc')
            ->get();
        foreach ($localRecords as $r) {
            if (!isset($allLocalRecords[$r->subdomain_id])) {
                $allLocalRecords[$r->subdomain_id] = [];
            }
            $allLocalRecords[$r->subdomain_id][] = $r;
        }
    } catch (\Throwable $e) {
        cfmod_report_exception('replace_root_local_records', $e);
    }

    $providerClients = [];
    $now = date('Y-m-d H:i:s');
    foreach ($batch as $s) {
        cfmod_worker_touch_progress();
        try {
            $stats['processed_subdomains']++;
            $oldFull = strtolower($s->subdomain);
            if (cf_str_ends_with($oldFull, '.' . strtolower($fromRoot))) {
                $prefix = substr($oldFull, 0, - (strlen($fromRoot) + 1));
                $newFull = ($prefix !== '' ? ($prefix . '.') : '') . $toRoot;
            } elseif ($oldFull === strtolower($fromRoot)) {
                $newFull = $toRoot;
            } else {
                $newFull = str_ireplace($fromRoot, $toRoot, $oldFull);
            }

            $sourceProviderId = cfmod_worker_resolve_provider_account_id_for_subdomain($s, $settings);
            $sourceContext = cfmod_worker_acquire_provider_client_cached($sourceProviderId, $settings, $providerClients, $stats, 'replace_root_source');
            if (!$sourceContext) {
                $stats['warnings'][] = 'source_provider_missing:' . $s->id;
                continue;
            }
            $sourceCf = $sourceContext['client'];

            $local = $allLocalRecords[$s->id] ?? [];
            $records = [];
            if (count($local) > 0) {
                foreach ($local as $r) {
                    $records[] = [
                        'id' => $r->id,
                        'name' => strtolower($r->name ?? ''),
                        'record_id' => $r->record_id,
                        'type' => strtoupper($r->type ?? ''),
                        'content' => $r->content ?? '',
                        'ttl' => intval($r->ttl ?? 600),
                        'priority' => isset($r->priority) ? intval($r->priority) : null,
                    ];
                }
            } else {
                $remote = $sourceCf->getDnsRecords($s->cloudflare_zone_id ?: $fromRoot, $oldFull, ['per_page' => 500]);
                if (($remote['success'] ?? false)) {
                    foreach (($remote['result'] ?? []) as $rr) {
                        $records[] = [
                            'id' => null,
                            'name' => strtolower($rr['name'] ?? ''),
                            'record_id' => $rr['id'] ?? null,
                            'type' => strtoupper($rr['type'] ?? ''),
                            'content' => $rr['content'] ?? '',
                            'ttl' => intval($rr['ttl'] ?? 600),
                            'priority' => null,
                        ];
                    }
                }
            }

            $dnsRowsToUpdate = [];
            $primaryRecordId = null;
            foreach ($records as $rec) {
                $oldName = $rec['name'];
                if (cf_str_ends_with($oldName, '.' . strtolower($fromRoot))) {
                    $prefix = substr($oldName, 0, - (strlen($fromRoot) + 1));
                    $newName = ($prefix !== '' ? ($prefix . '.') : '') . $toRoot;
                } elseif ($oldName === strtolower($fromRoot)) {
                    $newName = $toRoot;
                } else {
                    $newName = str_ireplace($fromRoot, $toRoot, $oldName);
                }

                $createdId = null;
                $res = $targetCf->createDnsRecord($toZone, $newName, $rec['type'], $rec['content'], $rec['ttl'] ?: 600, false);
                if (!($res['success'] ?? false)) {
                    $existing = $targetCf->getDnsRecords($toZone, $newName, ['type' => $rec['type']]);
                    if (($existing['success'] ?? false) && !empty($existing['result'])) {
                        $existOne = $existing['result'][0];
                        $eid = $existOne['id'] ?? null;
                        if ($eid) {
                            $upd = $targetCf->updateDnsRecord($toZone, $eid, [
                                'type' => $rec['type'],
                                'name' => $newName,
                                'content' => $rec['content'],
                                'ttl' => $rec['ttl'] ?: 600,
                                'priority' => $rec['priority']
                            ]);
                            if (($upd['success'] ?? false)) { $createdId = $eid; }
                        }
                    }
                } else {
                    $createdId = $res['result']['RecordId'] ?? ($res['result']['id'] ?? null);
                    if ($rec['type'] === 'MX' && $rec['priority'] !== null && $createdId) {
                        $targetCf->updateDnsRecord($toZone, $createdId, [
                            'type' => 'MX',
                            'name' => $newName,
                            'content' => $rec['content'],
                            'ttl' => $rec['ttl'] ?: 600,
                            'priority' => $rec['priority']
                        ]);
                    }
                }

                if ($createdId) {
                    $dnsRowsToUpdate[] = [ 'local_id' => $rec['id'], 'new_name' => $newName, 'new_record_id' => $createdId ];
                    if ($newName === $newFull) { $primaryRecordId = $createdId; }
                }
            }

            foreach ($dnsRowsToUpdate as $u) {
                if ($u['local_id']) {
                    Capsule::table('mod_cloudflare_dns_records')->where('id', $u['local_id'])->update([
                        'name' => strtolower($u['new_name']),
                        'zone_id' => $toZone,
                        'record_id' => $u['new_record_id'],
                        'updated_at' => $now,
                    ]);
                }
            }

            if ($deleteOld) {
                try { $sourceCf->deleteDomainRecordsDeep($s->cloudflare_zone_id ?: $fromRoot, $oldFull); } catch (\Throwable $e) {}
            }

            $upd = [ 'subdomain' => $newFull, 'rootdomain' => $toRoot, 'cloudflare_zone_id' => $toZone, 'updated_at' => $now ];
            if ($primaryRecordId) { $upd['dns_record_id'] = $primaryRecordId; }
            Capsule::table('mod_cloudflare_subdomain')->where('id', $s->id)->update($upd);

            try { Capsule::table('mod_cloudflare_forbidden_domains')->where('rootdomain', $fromRoot)->update(['rootdomain' => $toRoot]); } catch (\Throwable $e) {}

            try {
                $fresh = $targetCf->getDnsRecords($toZone, $newFull, ['per_page' => 1000]);
                if (($fresh['success'] ?? false)) {
                    foreach (($fresh['result'] ?? []) as $fr) {
                        cfmod_worker_touch_progress();
                        $name = strtolower($fr['name'] ?? '');
                        $type = strtoupper($fr['type'] ?? '');
                        $content = (string)($fr['content'] ?? '');
                        $ttl = intval($fr['ttl'] ?? 600);
                        $rid = $fr['id'] ?? null;
                        $exists = Capsule::table('mod_cloudflare_dns_records')
                            ->where('subdomain_id', $s->id)
                            ->where('name', $name)
                            ->where('type', $type)
                            ->first();
                        if ($exists) {
                            Capsule::table('mod_cloudflare_dns_records')->where('id', $exists->id)->update([
                                'zone_id' => $toZone,
                                'record_id' => $rid,
                                'content' => $content,
                                'ttl' => $ttl,
                                'updated_at' => $now
                            ]);
                            $stats['records_updated_local']++;
                        } else {
                            Capsule::table('mod_cloudflare_dns_records')->insert([
                                'subdomain_id' => $s->id,
                                'zone_id' => $toZone,
                                'record_id' => $rid,
                                'name' => $name,
                                'type' => $type,
                                'content' => $content,
                                'ttl' => $ttl,
                                'proxied' => 0,
                                'priority' => null,
                                'line' => null,
                                'created_at' => $now,
                                'updated_at' => $now
                            ]);
                            CfSubdomainService::markHasDnsHistory($s->id);
                            $stats['records_imported_local']++;
                        }
                    }
                }
            } catch (\Throwable $e) {}

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('job_replace_root_progress', [ 'from' => $fromRoot, 'to' => $toRoot, 'subdomain' => $oldFull, 'new' => $newFull ]);
            }
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'sub:' . $s->id;
            cfmod_report_exception('replace_root', $e);
        }
    }

    $stats['cursor_end'] = $lastId;
    $stats['message'] = 'replaced ' . $fromRoot . ' -> ' . $toRoot . ' (batch ' . $stats['processed_subdomains'] . ')';
    if ($hasMore && $lastId > 0) {
        $stats['has_more'] = true;
        try {
            $nextPayload = [
                'from_root' => $fromRoot,
                'to_root' => $toRoot,
                'delete_old' => $deleteOld,
                'batch_size' => $batchSize,
                'cursor_id' => $lastId,
            ];
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'replace_root_domain',
                'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                'priority' => intval($job->priority ?? 5),
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'enqueue_failed';
            cfmod_report_exception('replace_root_enqueue', $e);
        }
    } else {
        $stats['has_more'] = false;
    }

    if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
        cfmod_clear_rootdomain_limits_cache();
    }
    return $stats;
}

function cfmod_job_transfer_root_provider($job, array $payload = []): array {
    $rootdomain = strtolower(trim((string)($payload['rootdomain'] ?? '')));
    if ($rootdomain === '') {
        throw new \InvalidArgumentException('rootdomain required');
    }

    $targetProviderId = intval($payload['target_provider_id'] ?? 0);
    if ($targetProviderId <= 0) {
        throw new \InvalidArgumentException('target provider required');
    }

    $settings = cfmod_get_settings();
    $batchSize = intval($payload['batch_size'] ?? 200);
    if ($batchSize <= 0) { $batchSize = 200; }
    $batchSize = max(25, min(5000, $batchSize));
    $cursor = intval($payload['cursor_id'] ?? 0);
    $deleteOld = !empty($payload['delete_old_records']);
    $disableContinuationEnqueue = !empty($payload['disable_continuation_enqueue']);
    $autoResume = !empty($payload['auto_resume']);
    $resumeStatus = isset($payload['resume_status']) ? (string)$payload['resume_status'] : null;
    $targetZone = trim((string)($payload['target_zone_identifier'] ?? ''));
    $cloneSettingRaw = strtolower(trim((string) ($settings['transfer_clone_full_zone'] ?? '1')));
    $cloneFullZone = array_key_exists('clone_full_zone', $payload)
        ? !empty($payload['clone_full_zone'])
        : in_array($cloneSettingRaw, ['1', 'yes', 'true', 'on'], true);
    $transferMode = cfmod_worker_normalize_transfer_mode($payload['transfer_mode'] ?? 'mixed');
    $allowLocalSource = $transferMode !== 'cloud_only';
    $allowRemoteSource = $transferMode !== 'local_only';
    $remoteSourceEnabled = $allowRemoteSource;
    $localMissingThreshold = floatval($payload['local_missing_threshold'] ?? 30);
    if (!is_finite($localMissingThreshold)) {
        $localMissingThreshold = 30;
    }
    $localMissingThreshold = max(0.0, min(100.0, $localMissingThreshold));
    if ($transferMode === 'local_only') {
        $cloneFullZone = false;
    }

    $sourceProviderId = intval($payload['source_provider_id'] ?? 0);
    if ($sourceProviderId <= 0) {
        try {
            $rootRow = Capsule::table('mod_cloudflare_rootdomains')
                ->whereRaw('LOWER(domain) = ?', [$rootdomain])
                ->first();
            if ($rootRow && !empty($rootRow->provider_account_id)) {
                $sourceProviderId = intval($rootRow->provider_account_id);
            }
        } catch (\Throwable $ignored) {
        }
    }
    $now = date('Y-m-d H:i:s');

    $targetContext = cfmod_make_provider_client($targetProviderId, $rootdomain, null, $settings, true);
    if (!$targetContext || empty($targetContext['client'])) {
        throw new \RuntimeException('target provider unavailable');
    }
    $targetCf = $targetContext['client'];
    if ($targetZone === '') {
        $targetZone = $targetCf->getZoneId($rootdomain);
        if (!$targetZone) {
            throw new \RuntimeException('target zone not found');
        }
    }

    $stats = [
        'rootdomain' => $rootdomain,
        'target_provider_id' => $targetProviderId,
        'source_provider_id' => $sourceProviderId,
        'batch_size' => $batchSize,
        'cursor_start' => $cursor,
        'transfer_mode' => $transferMode,
        'local_missing_threshold' => $localMissingThreshold,
        'processed_subdomains' => 0,
        'records_created_on_cf' => 0,
        'records_updated_on_cf' => 0,
        'records_exists_on_cf' => 0,
        'records_deleted_on_cf' => 0,
        'records_failed_on_cf' => 0,
        'records_updated_local' => 0,
        'records_imported_local' => 0,
        'remote_rate_limit_hits' => 0,
        'rate_limit_backoffs' => 0,
        'degraded_to_local_only' => 0,
        'clone_full_zone' => $cloneFullZone ? 1 : 0,
        'full_zone_created' => 0,
        'full_zone_exists' => 0,
        'full_zone_failed' => 0,
        'warnings' => [],
    ];

    $subsQuery = Capsule::table('mod_cloudflare_subdomain')
        ->whereRaw('LOWER(rootdomain) = ?', [$rootdomain])
        ->orderBy('id', 'asc');
    if ($cursor > 0) {
        $subsQuery->where('id', '>', $cursor);
    }
    $subsRaw = $subsQuery->limit($batchSize + 1)->get();
    if (!($subsRaw instanceof \Illuminate\Support\Collection)) {
        $subsRaw = new \Illuminate\Support\Collection(is_array($subsRaw) ? $subsRaw : (array) $subsRaw);
    }

    $hasMore = $subsRaw->count() > $batchSize;
    $batch = $hasMore ? $subsRaw->slice(0, $batchSize)->values() : $subsRaw->values();
    $subdomainIds = [];
    $lastId = $cursor;
    foreach ($batch as $row) {
        $sid = intval($row->id ?? 0);
        if ($sid > 0) {
            $subdomainIds[] = $sid;
            $lastId = $sid;
        }
    }

    $allLocalRecords = [];
    if ($allowLocalSource && !empty($subdomainIds)) {
        try {
            $localRecords = Capsule::table('mod_cloudflare_dns_records')
                ->whereIn('subdomain_id', $subdomainIds)
                ->orderBy('subdomain_id', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            foreach ($localRecords as $record) {
                $sid = intval($record->subdomain_id ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                if (!isset($allLocalRecords[$sid])) {
                    $allLocalRecords[$sid] = [];
                }
                $allLocalRecords[$sid][] = $record;
            }
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'load_local_records_failed';
            cfmod_report_exception('transfer_root_provider_local_records', $e);
        }
    }

    $providerClients = [];

    foreach ($batch as $s) {
        cfmod_worker_touch_progress();
        try {
            $sid = intval($s->id ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $stats['processed_subdomains']++;
            $subdomainName = strtolower($s->subdomain ?? '');
            if ($subdomainName === '') {
                $stats['warnings'][] = 'missing_subdomain:' . $sid;
                continue;
            }

            $subSourceProviderId = cfmod_worker_resolve_provider_account_id_for_subdomain($s, $settings);
            $sourceContext = null;
            $sourceCf = null;
            if ($subSourceProviderId !== null) {
                $sourceContext = cfmod_worker_acquire_provider_client_cached($subSourceProviderId, $settings, $providerClients, $stats, 'transfer_root_source');
                if ($sourceContext) {
                    $sourceCf = $sourceContext['client'] ?? null;
                }
            }

            $recordPool = [];
            $appendRecord = static function (array $candidate) use (&$recordPool, $subdomainName): void {
                $normalized = cfmod_worker_transfer_normalize_record($candidate, $subdomainName);
                if ($normalized === null) {
                    return;
                }
                $recordKey = strtolower($normalized['name']) . '|' . strtoupper($normalized['type']) . '|' . $normalized['content'] . '|' . (string) ($normalized['priority'] ?? '');
                if (!isset($recordPool[$recordKey])) {
                    $recordPool[$recordKey] = $normalized;
                    return;
                }
                $existing = $recordPool[$recordKey];
                if ($existing['priority'] === null && $normalized['priority'] !== null) {
                    $recordPool[$recordKey]['priority'] = $normalized['priority'];
                }
                if (intval($existing['ttl'] ?? 0) <= 0 && intval($normalized['ttl'] ?? 0) > 0) {
                    $recordPool[$recordKey]['ttl'] = intval($normalized['ttl']);
                }
            };

            if (!empty($allLocalRecords[$sid])) {
                foreach ($allLocalRecords[$sid] as $recordRow) {
                    $appendRecord([
                        'name' => strtolower($recordRow->name ?? $subdomainName),
                        'type' => strtoupper($recordRow->type ?? ''),
                        'content' => (string) ($recordRow->content ?? ''),
                        'ttl' => intval($recordRow->ttl ?? 600),
                        'priority' => isset($recordRow->priority) ? intval($recordRow->priority) : null,
                    ]);
                }
            }

            if ($sourceCf && $remoteSourceEnabled) {
                $remoteAttempts = 0;
                $maxRemoteAttempts = $allowLocalSource ? 1 : 4;
                while ($remoteAttempts < $maxRemoteAttempts) {
                    $remoteAttempts++;
                    try {
                        $remote = $sourceCf->getDnsRecords($s->cloudflare_zone_id ?: $rootdomain, $subdomainName, ['per_page' => 1000]);
                        if (($remote['success'] ?? false)) {
                            foreach (($remote['result'] ?? []) as $rr) {
                                $appendRecord([
                                    'name' => strtolower($rr['name'] ?? $subdomainName),
                                    'type' => strtoupper($rr['type'] ?? ''),
                                    'content' => (string) ($rr['content'] ?? ''),
                                    'ttl' => intval($rr['ttl'] ?? 600),
                                    'priority' => isset($rr['priority']) ? intval($rr['priority']) : null,
                                ]);
                            }
                            break;
                        }

                        if (cfmod_worker_is_rate_limited_response($remote)) {
                            $stats['remote_rate_limit_hits']++;
                            if ($allowLocalSource) {
                                $remoteSourceEnabled = false;
                                $stats['degraded_to_local_only'] = 1;
                                $stats['warnings'][] = 'remote_rate_limited_fallback_local_only';
                                break;
                            }
                            if ($remoteAttempts < $maxRemoteAttempts) {
                                $sleepSeconds = cfmod_worker_rate_limit_backoff_seconds($remoteAttempts);
                                $stats['rate_limit_backoffs']++;
                                $stats['warnings'][] = 'remote_rate_limited_backoff:' . $sleepSeconds . 's';
                                cfmod_worker_touch_progress();
                                sleep($sleepSeconds);
                                continue;
                            }
                        }

                        $stats['warnings'][] = 'remote_records_unavailable:' . $sid;
                        break;
                    } catch (\Throwable $e) {
                        if (cfmod_worker_is_rate_limited_exception($e)) {
                            $stats['remote_rate_limit_hits']++;
                            if ($allowLocalSource) {
                                $remoteSourceEnabled = false;
                                $stats['degraded_to_local_only'] = 1;
                                $stats['warnings'][] = 'remote_rate_limited_fallback_local_only';
                                break;
                            }
                            if ($remoteAttempts < $maxRemoteAttempts) {
                                $sleepSeconds = cfmod_worker_rate_limit_backoff_seconds($remoteAttempts);
                                $stats['rate_limit_backoffs']++;
                                $stats['warnings'][] = 'remote_rate_limited_backoff:' . $sleepSeconds . 's';
                                cfmod_worker_touch_progress();
                                sleep($sleepSeconds);
                                continue;
                            }
                        }
                        $stats['warnings'][] = 'remote_records_error:' . $sid;
                        cfmod_report_exception('transfer_root_provider_remote_fetch', $e);
                        break;
                    }
                }
            }

            $records = array_values($recordPool);

            if (empty($records)) {
                $stats['warnings'][] = 'no_records:' . $sid;
                if ($transferMode === 'cloud_only') {
                    $stats['records_failed_on_cf']++;
                    continue;
                }
            }

            foreach ($records as $rec) {
                cfmod_worker_touch_progress();
                $name = $rec['name'] ?: $subdomainName;
                $type = $rec['type'] ?: 'A';
                $ttl = intval($rec['ttl'] ?? 600);
                if ($ttl <= 0) {
                    $ttl = 600;
                }
                try {
                    $createAttempts = 0;
                    $maxCreateAttempts = 4;
                    $res = ['success' => false, 'errors' => ['create not started']];
                    while ($createAttempts < $maxCreateAttempts) {
                        $createAttempts++;
                        try {
                            $res = cfmod_worker_transfer_create_on_target($targetCf, $targetZone, $name, $type, $rec, $ttl);
                        } catch (\Throwable $e) {
                            if (cfmod_worker_is_rate_limited_exception($e) && $createAttempts < $maxCreateAttempts) {
                                $sleepSeconds = cfmod_worker_rate_limit_backoff_seconds($createAttempts);
                                $stats['remote_rate_limit_hits']++;
                                $stats['rate_limit_backoffs']++;
                                $stats['warnings'][] = 'target_rate_limited_backoff:' . $sleepSeconds . 's';
                                cfmod_worker_touch_progress();
                                sleep($sleepSeconds);
                                continue;
                            }
                            throw $e;
                        }

                        if ($res['success'] ?? false) {
                            break;
                        }
                        if (!cfmod_worker_is_rate_limited_response($res) || $createAttempts >= $maxCreateAttempts) {
                            break;
                        }
                        $sleepSeconds = cfmod_worker_rate_limit_backoff_seconds($createAttempts);
                        $stats['remote_rate_limit_hits']++;
                        $stats['rate_limit_backoffs']++;
                        $stats['warnings'][] = 'target_rate_limited_backoff:' . $sleepSeconds . 's';
                        cfmod_worker_touch_progress();
                        sleep($sleepSeconds);
                    }

                    if ($res['success'] ?? false) {
                        $stats['records_created_on_cf']++;
                        continue;
                    }

                    $existing = $targetCf->getDnsRecords($targetZone, $name, ['type' => $type, 'per_page' => 1000]);
                    if (($existing['success'] ?? false) && cfmod_worker_transfer_remote_record_exists($existing['result'] ?? [], $rec)) {
                        $stats['records_exists_on_cf']++;
                        continue;
                    }
                    $stats['warnings'][] = 'create_failed:' . $sid;
                    $stats['records_failed_on_cf']++;
                } catch (\Throwable $e) {
                    $stats['warnings'][] = 'write_failed:' . $sid;
                    $stats['records_failed_on_cf']++;
                    cfmod_report_exception('transfer_root_provider_write', $e);
                }
            }

            $primaryRecordId = null;
            try {
                $fresh = $targetCf->getDnsRecords($targetZone, $subdomainName, ['per_page' => 1000]);
                if (($fresh['success'] ?? false)) {
                    foreach (($fresh['result'] ?? []) as $fr) {
                        cfmod_worker_touch_progress();
                        $name = strtolower($fr['name'] ?? '');
                        $type = strtoupper($fr['type'] ?? '');
                        $content = (string) ($fr['content'] ?? '');
                        $ttl = intval($fr['ttl'] ?? 600);
                        $rid = $fr['id'] ?? null;
                        if ($name === $subdomainName && $rid && $primaryRecordId === null) {
                            $primaryRecordId = $rid;
                        }
                        $localSyncResult = cfmod_worker_transfer_upsert_local_record($sid, $targetZone, [
                            'id' => $rid,
                            'name' => $name,
                            'type' => $type,
                            'content' => $content,
                            'ttl' => $ttl,
                            'priority' => $fr['priority'] ?? null,
                            'proxied' => !empty($fr['proxied']) ? 1 : 0,
                        ], $now);
                        if ($localSyncResult === 'updated') {
                            $stats['records_updated_local']++;
                        } elseif ($localSyncResult === 'inserted') {
                            $stats['records_imported_local']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'refresh_local_failed:' . $sid;
                $stats['records_failed_on_cf']++;
                cfmod_report_exception('transfer_root_provider_refresh_local', $e);
            }

            if ($deleteOld) {
                if ($subSourceProviderId && $subSourceProviderId !== $targetProviderId && $sourceCf) {
                    try {
                        $sourceZone = $s->cloudflare_zone_id ?: $rootdomain;
                        $deleted = $sourceCf->deleteDomainRecordsDeep($sourceZone, $subdomainName);
                        if (($deleted['success'] ?? false)) {
                            $stats['records_deleted_on_cf'] += intval($deleted['deleted_count'] ?? 0);
                        }
                    } catch (\Throwable $e) {
                        $stats['warnings'][] = 'delete_old_failed:' . $sid;
                        cfmod_report_exception('transfer_root_provider_delete_old', $e);
                    }
                } elseif ($subSourceProviderId === $targetProviderId) {
                    $stats['warnings'][] = 'skip_delete_same_provider:' . $sid;
                } else {
                    $stats['warnings'][] = 'delete_source_missing:' . $sid;
                }
            }

            $updatePayload = [
                'provider_account_id' => $targetProviderId,
                'cloudflare_zone_id' => $targetZone,
                'updated_at' => $now,
            ];
            if ($primaryRecordId) {
                $updatePayload['dns_record_id'] = $primaryRecordId;
            }
            Capsule::table('mod_cloudflare_subdomain')->where('id', $sid)->update($updatePayload);

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('job_transfer_root_provider_progress', [
                    'rootdomain' => $rootdomain,
                    'subdomain' => $subdomainName,
                    'source_provider_id' => $subSourceProviderId,
                    'target_provider_id' => $targetProviderId,
                ], intval($s->userid ?? 0), $sid);
            }
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'sub:' . ($s->id ?? 'unknown');
            cfmod_report_exception('transfer_root_provider_subdomain', $e);
        }
    }

    if (!empty($stats['degraded_to_local_only'])) {
        $cloneFullZone = false;
        $stats['clone_full_zone'] = 0;
    }

    $stats['cursor_end'] = $lastId;
    $stats['message'] = $stats['processed_subdomains'] > 0
        ? ('migrated ' . $stats['processed_subdomains'] . ' subdomains to provider #' . $targetProviderId)
        : ('no subdomains matched ' . $rootdomain);

    if ($hasMore && $lastId > 0) {
        $stats['has_more'] = true;
        if (!$disableContinuationEnqueue) {
            try {
                $nextPayload = $payload;
                $nextPayload['cursor_id'] = $lastId;
                $nextPayload['target_zone_identifier'] = $targetZone;
                $nextPayload['source_provider_id'] = $sourceProviderId;
                $nextPayload['clone_full_zone'] = $cloneFullZone ? 1 : 0;
                if (!empty($stats['degraded_to_local_only']) && $transferMode === 'mixed') {
                    $nextPayload['transfer_mode'] = 'local_only';
                    $nextPayload['clone_full_zone'] = 0;
                }
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'transfer_root_provider',
                    'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 5),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'enqueue_failed';
                cfmod_report_exception('transfer_root_provider_enqueue', $e);
            }
        }
    } else {
        $stats['has_more'] = false;
        $canFinalizeSwitch = true;

        if ($cloneFullZone) {
            if ($sourceProviderId > 0 && $sourceProviderId !== $targetProviderId) {
                $sourceContext = cfmod_worker_acquire_provider_client_cached($sourceProviderId, $settings, $providerClients, $stats, 'transfer_full_zone_source');
                if ($sourceContext && !empty($sourceContext['client'])) {
                    $sourceCfForZone = $sourceContext['client'];
                    $cloneOk = cfmod_worker_transfer_clone_full_zone_records($sourceCfForZone, $rootdomain, $targetCf, $targetZone, $stats);
                    if (!$cloneOk) {
                        $canFinalizeSwitch = false;
                    }
                } else {
                    $stats['warnings'][] = 'full_zone_source_unavailable';
                    $canFinalizeSwitch = false;
                }
            } elseif ($sourceProviderId === $targetProviderId && $sourceProviderId > 0) {
                $stats['warnings'][] = 'full_zone_skip_same_provider';
            } else {
                $stats['warnings'][] = 'full_zone_source_unknown';
                $canFinalizeSwitch = false;
            }
        }

        if (intval($stats['records_failed_on_cf'] ?? 0) > 0) {
            $canFinalizeSwitch = false;
        }
        if (intval($stats['full_zone_failed'] ?? 0) > 0) {
            $canFinalizeSwitch = false;
        }

        if ($canFinalizeSwitch) {
            try {
                $update = [
                    'provider_account_id' => $targetProviderId,
                    'cloudflare_zone_id' => $targetZone,
                    'updated_at' => $now,
                ];
                if ($autoResume && $resumeStatus !== null && $resumeStatus !== '') {
                    $update['status'] = $resumeStatus;
                }
                Capsule::table('mod_cloudflare_rootdomains')
                    ->whereRaw('LOWER(domain) = ?', [$rootdomain])
                    ->update($update);
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'root_update_failed';
                cfmod_report_exception('transfer_root_provider_finalize', $e);
            }
        } else {
            $stats['warnings'][] = 'switch_skipped_due_to_errors';
        }

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('job_transfer_root_provider_done', [
                'rootdomain' => $rootdomain,
                'source_provider_id' => $sourceProviderId,
                'target_provider_id' => $targetProviderId,
                'transfer_mode' => $transferMode,
                'degraded_to_local_only' => intval($stats['degraded_to_local_only'] ?? 0),
                'remote_rate_limit_hits' => intval($stats['remote_rate_limit_hits'] ?? 0),
                'rate_limit_backoffs' => intval($stats['rate_limit_backoffs'] ?? 0),
                'processed_subdomains' => $stats['processed_subdomains'],
                'records_failed_on_cf' => $stats['records_failed_on_cf'] ?? 0,
                'full_zone_failed' => $stats['full_zone_failed'] ?? 0,
                'switched' => $canFinalizeSwitch ? 1 : 0,
            ]);
        }
    }

    if (function_exists('cfmod_clear_rootdomain_limits_cache')) {
        cfmod_clear_rootdomain_limits_cache();
    }

    return $stats;
}

function cfmod_worker_dns_record_identity_key(array $record): ?string {
    $normalized = cfmod_worker_transfer_normalize_record($record, (string) ($record['name'] ?? ''));
    if ($normalized === null) {
        return null;
    }

    $type = strtoupper((string) ($normalized['type'] ?? ''));
    if ($type === 'SOA') {
        return null;
    }

    $name = strtolower((string) ($normalized['name'] ?? ''));
    $content = strtolower(trim((string) ($normalized['content'] ?? '')));
    $priority = $normalized['priority'] ?? null;

    if ($type === 'MX') {
        $mx = cfmod_worker_transfer_mx_parts($normalized);
        $content = strtolower(trim((string) ($mx['content'] ?? '')));
        $priority = $mx['priority'] ?? $priority;
    }

    return $name . '|' . $type . '|' . $content . '|' . ($priority === null ? '' : (string) intval($priority));
}

function cfmod_worker_transfer_fetch_zone_records($providerClient, string $zoneId, array &$stats, string $context): array {
    if (!is_object($providerClient) || !method_exists($providerClient, 'getDnsRecords')) {
        $stats['warnings'][] = $context . '_provider_invalid';
        return [];
    }

    try {
        $res = $providerClient->getDnsRecords($zoneId, null, ['per_page' => 1000]);
    } catch (\Throwable $e) {
        $stats['warnings'][] = $context . '_fetch_exception';
        cfmod_report_exception('transfer_full_zone_' . $context, $e);
        return [];
    }

    if (!($res['success'] ?? false)) {
        $stats['warnings'][] = $context . '_fetch_failed';
        return [];
    }

    return is_array($res['result'] ?? null) ? $res['result'] : [];
}

function cfmod_worker_transfer_clone_full_zone_records($sourceCf, string $sourceZone, $targetCf, string $targetZone, array &$stats): bool {
    $sourceRecords = cfmod_worker_transfer_fetch_zone_records($sourceCf, $sourceZone, $stats, 'source_full_zone');
    if (empty($sourceRecords)) {
        return true;
    }

    $targetRecords = cfmod_worker_transfer_fetch_zone_records($targetCf, $targetZone, $stats, 'target_full_zone');
    $targetIdentity = [];
    foreach ($targetRecords as $existingRecord) {
        if (!is_array($existingRecord)) {
            continue;
        }
        $idKey = cfmod_worker_dns_record_identity_key($existingRecord);
        if ($idKey !== null && $idKey !== '') {
            $targetIdentity[$idKey] = true;
        }
    }

    foreach ($sourceRecords as $sourceRecord) {
        cfmod_worker_touch_progress();
        if (!is_array($sourceRecord)) {
            continue;
        }
        $identityKey = cfmod_worker_dns_record_identity_key($sourceRecord);
        if ($identityKey === null || $identityKey === '') {
            continue;
        }
        if (isset($targetIdentity[$identityKey])) {
            $stats['full_zone_exists'] = ($stats['full_zone_exists'] ?? 0) + 1;
            continue;
        }

        $normalized = cfmod_worker_transfer_normalize_record($sourceRecord, (string) ($sourceRecord['name'] ?? ''));
        if ($normalized === null) {
            continue;
        }
        $ttl = max(60, intval($normalized['ttl'] ?? 600));

        try {
            $created = cfmod_worker_transfer_create_on_target($targetCf, $targetZone, $normalized['name'], $normalized['type'], $normalized, $ttl);
            if ($created['success'] ?? false) {
                $stats['full_zone_created'] = ($stats['full_zone_created'] ?? 0) + 1;
                $targetIdentity[$identityKey] = true;
                continue;
            }

            $existsRes = $targetCf->getDnsRecords($targetZone, $normalized['name'], ['type' => $normalized['type'], 'per_page' => 1000]);
            if (($existsRes['success'] ?? false) && cfmod_worker_transfer_remote_record_exists($existsRes['result'] ?? [], $normalized)) {
                $stats['full_zone_exists'] = ($stats['full_zone_exists'] ?? 0) + 1;
                $targetIdentity[$identityKey] = true;
                continue;
            }
            $stats['full_zone_failed'] = ($stats['full_zone_failed'] ?? 0) + 1;
            $stats['warnings'][] = 'full_zone_create_failed:' . $normalized['name'] . ':' . $normalized['type'];
        } catch (\Throwable $e) {
            $stats['full_zone_failed'] = ($stats['full_zone_failed'] ?? 0) + 1;
            $stats['warnings'][] = 'full_zone_write_exception';
            cfmod_report_exception('transfer_full_zone_write', $e);
        }
    }

    return intval($stats['full_zone_failed'] ?? 0) === 0;
}

function cfmod_worker_transfer_normalize_record(array $record, string $defaultName): ?array {
    $name = strtolower(trim((string) ($record['name'] ?? $defaultName)));
    if ($name === '') {
        $name = strtolower(trim($defaultName));
    }
    $type = strtoupper(trim((string) ($record['type'] ?? '')));
    $content = trim((string) ($record['content'] ?? ''));
    if ($name === '' || $type === '' || $content === '') {
        return null;
    }

    $ttl = intval($record['ttl'] ?? 600);
    if ($ttl <= 0) {
        $ttl = 600;
    }

    $priority = null;
    if (array_key_exists('priority', $record) && $record['priority'] !== null && $record['priority'] !== '') {
        $priority = intval($record['priority']);
    }

    if ($type === 'MX') {
        if ($priority === null && preg_match('/^\s*(\d+)\s+(.+?)\s*$/', $content, $mxMatch)) {
            $priority = intval($mxMatch[1]);
            $content = trim($mxMatch[2]);
        }
        $content = rtrim($content, '.');
    } elseif (in_array($type, ['CNAME', 'NS', 'PTR'], true)) {
        $content = rtrim($content, '.');
    }

    return [
        'name' => $name,
        'type' => $type,
        'content' => $content,
        'ttl' => $ttl,
        'priority' => $priority,
    ];
}

function cfmod_worker_transfer_create_on_target($providerClient, string $zoneId, string $name, string $type, array $record, int $ttl): array {
    $type = strtoupper(trim($type));
    $content = (string) ($record['content'] ?? '');

    if ($type === 'MX' && method_exists($providerClient, 'createMXRecord')) {
        $priority = isset($record['priority']) && $record['priority'] !== null ? intval($record['priority']) : 10;
        return $providerClient->createMXRecord($zoneId, $name, $content, $priority, $ttl);
    }

    return $providerClient->createDnsRecord($zoneId, $name, $type, $content, $ttl, false);
}

function cfmod_worker_transfer_mx_parts(array $record): array {
    $priority = null;
    if (array_key_exists('priority', $record) && $record['priority'] !== null && $record['priority'] !== '') {
        $priority = intval($record['priority']);
    }
    $content = trim((string) ($record['content'] ?? ''));
    if (preg_match('/^\s*(\d+)\s+(.+?)\s*$/', $content, $mxMatch)) {
        if ($priority === null) {
            $priority = intval($mxMatch[1]);
        }
        $content = trim($mxMatch[2]);
    }
    $content = strtolower(rtrim($content, '.'));

    return [
        'priority' => $priority,
        'content' => $content,
    ];
}

function cfmod_worker_transfer_remote_record_exists(array $existingRecords, array $candidate): bool {
    $candidateNormalized = cfmod_worker_transfer_normalize_record($candidate, (string) ($candidate['name'] ?? ''));
    if ($candidateNormalized === null) {
        return false;
    }

    foreach ($existingRecords as $existing) {
        if (!is_array($existing)) {
            continue;
        }
        $existingNormalized = cfmod_worker_transfer_normalize_record($existing, $candidateNormalized['name']);
        if ($existingNormalized === null) {
            continue;
        }
        if ($existingNormalized['name'] !== $candidateNormalized['name']) {
            continue;
        }
        if ($existingNormalized['type'] !== $candidateNormalized['type']) {
            continue;
        }

        if ($candidateNormalized['type'] === 'MX') {
            $candidateMx = cfmod_worker_transfer_mx_parts($candidateNormalized);
            $existingMx = cfmod_worker_transfer_mx_parts($existingNormalized);
            if ($candidateMx['content'] !== $existingMx['content']) {
                continue;
            }
            if ($candidateMx['priority'] !== null && $existingMx['priority'] !== null && intval($candidateMx['priority']) !== intval($existingMx['priority'])) {
                continue;
            }
            return true;
        }

        if (strtolower(trim((string) $existingNormalized['content'])) === strtolower(trim((string) $candidateNormalized['content']))) {
            return true;
        }
    }

    return false;
}

function cfmod_worker_transfer_upsert_local_record(int $subdomainId, string $targetZone, array $remoteRecord, string $now): ?string {
    if ($subdomainId <= 0) {
        return null;
    }

    $normalized = cfmod_worker_transfer_normalize_record($remoteRecord, (string) ($remoteRecord['name'] ?? ''));
    if ($normalized === null) {
        return null;
    }

    $recordId = isset($remoteRecord['id']) && $remoteRecord['id'] !== null ? (string) $remoteRecord['id'] : null;
    $recordName = $normalized['name'];
    $recordType = $normalized['type'];
    $recordContent = (string) $normalized['content'];
    $recordTtl = intval($normalized['ttl'] ?? 600);
    $recordPriority = $normalized['priority'];

    $existing = null;
    if ($recordId !== null && $recordId !== '') {
        $existing = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomainId)
            ->where('record_id', $recordId)
            ->first();
    }
    if (!$existing) {
        $existing = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $subdomainId)
            ->where('name', $recordName)
            ->where('type', $recordType)
            ->where('content', $recordContent)
            ->first();
    }

    $updatePayload = [
        'zone_id' => $targetZone,
        'name' => $recordName,
        'type' => $recordType,
        'content' => $recordContent,
        'ttl' => $recordTtl,
        'priority' => $recordPriority,
        'proxied' => !empty($remoteRecord['proxied']) ? 1 : 0,
        'updated_at' => $now,
    ];
    if ($recordId !== null && $recordId !== '') {
        $updatePayload['record_id'] = $recordId;
    }

    if ($existing) {
        Capsule::table('mod_cloudflare_dns_records')->where('id', $existing->id)->update($updatePayload);
        return 'updated';
    }

    Capsule::table('mod_cloudflare_dns_records')->insert([
        'subdomain_id' => $subdomainId,
        'zone_id' => $targetZone,
        'record_id' => $recordId,
        'name' => $recordName,
        'type' => $recordType,
        'content' => $recordContent,
        'ttl' => $recordTtl,
        'proxied' => !empty($remoteRecord['proxied']) ? 1 : 0,
        'priority' => $recordPriority,
        'line' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    CfSubdomainService::markHasDnsHistory($subdomainId);

    return 'inserted';
}

function cfmod_job_purge_root_local($job, array $payload = []): array {
    $jobId = intval($job->id);
    $rootdomain = strtolower(trim((string)($payload['rootdomain'] ?? '')));
    if ($rootdomain === '') {
        throw new \InvalidArgumentException('rootdomain required');
    }

    $batchSize = intval($payload['batch_size'] ?? 200);
    if ($batchSize <= 0) {
        $batchSize = 200;
    }
    $batchSize = max(20, min(5000, $batchSize));

    $cursor = intval($payload['cursor_id'] ?? 0);

    $query = Capsule::table('mod_cloudflare_subdomain')
        ->whereRaw('LOWER(rootdomain) = ?', [$rootdomain])
        ->orderBy('id', 'asc');
    if ($cursor > 0) {
        $query->where('id', '>', $cursor);
    }

    $rowsRaw = $query->limit($batchSize + 1)->get();
    if (!($rowsRaw instanceof \Illuminate\Support\Collection)) {
        $rowsRaw = new \Illuminate\Support\Collection(is_array($rowsRaw) ? $rowsRaw : (array) $rowsRaw);
    }

    if ($rowsRaw->count() === 0) {
        return [
            'rootdomain' => $rootdomain,
            'processed_subdomains' => 0,
            'deleted' => 0,
            'deleted_total' => intval($payload['deleted_total'] ?? 0),
            'message' => 'no local subdomains matched ' . $rootdomain,
        ];
    }

    $hasMore = $rowsRaw->count() > $batchSize;
    $batch = $hasMore ? $rowsRaw->slice(0, $batchSize)->values() : $rowsRaw->values();

    $subdomainIds = [];
    $userCounts = [];
    $lastId = 0;
    $now = date('Y-m-d H:i:s');

    foreach ($batch as $row) {
        $sid = intval($row->id);
        $subdomainIds[] = $sid;
        $lastId = $sid;
        $uid = intval($row->userid ?? 0);
        if ($uid > 0) {
            if (!isset($userCounts[$uid])) {
                $userCounts[$uid] = 0;
            }
            $userCounts[$uid]++;
        }
    }

    $warnings = [];

    if (!empty($subdomainIds)) {
        try {
            Capsule::table('mod_cloudflare_dns_records')->whereIn('subdomain_id', $subdomainIds)->delete();
        } catch (\Throwable $e) {
            $warnings[] = 'dns_records_delete_failed';
            cfmod_report_exception('purge_root_local_dns_records', $e);
        }
        try {
            Capsule::table('mod_cloudflare_domain_risk')->whereIn('subdomain_id', $subdomainIds)->delete();
        } catch (\Throwable $e) {
            $warnings[] = 'domain_risk_delete_failed';
            cfmod_report_exception('purge_root_local_domain_risk', $e);
        }
        try {
            Capsule::table('mod_cloudflare_risk_events')->whereIn('subdomain_id', $subdomainIds)->delete();
        } catch (\Throwable $e) {
            $warnings[] = 'risk_events_delete_failed';
            cfmod_report_exception('purge_root_local_risk_events', $e);
        }
        try {
            Capsule::table('mod_cloudflare_sync_results')->whereIn('subdomain_id', $subdomainIds)->delete();
        } catch (\Throwable $e) {
            $warnings[] = 'sync_results_delete_failed';
            cfmod_report_exception('purge_root_local_sync_results', $e);
        }
        try {
            Capsule::table('mod_cloudflare_subdomain')->whereIn('id', $subdomainIds)->delete();
        } catch (\Throwable $e) {
            $warnings[] = 'subdomain_delete_failed';
            cfmod_report_exception('purge_root_local_subdomains', $e);
        }
    }

    $affectedUsers = 0;
    foreach ($userCounts as $uid => $count) {
        if ($uid <= 0) {
            continue;
        }
        try {
            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $uid)->first();
            if ($quota) {
                $used = max(0, intval($quota->used_count ?? 0) - $count);
                Capsule::table('mod_cloudflare_subdomain_quotas')
                    ->where('userid', $uid)
                    ->update([
                        'used_count' => $used,
                        'updated_at' => $now,
                    ]);
                $affectedUsers++;
            }
        } catch (\Throwable $e) {
            $warnings[] = 'quota_update_failed:' . $uid;
            cfmod_report_exception('purge_root_local_quota', $e);
        }
    }

    if (function_exists('cloudflare_subdomain_log')) {
        try {
            $logPayload = [
                'rootdomain' => $rootdomain,
                'deleted_count' => count($subdomainIds),
                'subdomain_ids' => array_slice(array_map('intval', $subdomainIds), 0, 20),
                'total_deleted_so_far' => intval($payload['deleted_total'] ?? 0) + count($subdomainIds),
            ];
            if (isset($payload['initiator'])) {
                $logPayload['initiator'] = $payload['initiator'];
            }
            if (!empty($payload['admin_id'])) {
                $logPayload['admin_id'] = intval($payload['admin_id']);
            }
            cloudflare_subdomain_log('admin_purge_rootdomain_local_batch', $logPayload);
        } catch (\Throwable $e) {}
    }

    $deletedCount = count($subdomainIds);
    $totalDeleted = intval($payload['deleted_total'] ?? 0) + $deletedCount;

    $stats = [
        'rootdomain' => $rootdomain,
        'processed_subdomains' => $deletedCount,
        'deleted' => $deletedCount,
        'deleted_total' => $totalDeleted,
        'affected_users' => $affectedUsers,
    ];
    if (!empty($warnings)) {
        $stats['warnings'] = array_values(array_unique($warnings));
    }
    $stats['message'] = 'purged ' . $deletedCount . ' local subdomains for ' . $rootdomain;

    if ($hasMore && $lastId) {
        $stats['has_more'] = true;
        $stats['next_cursor'] = $lastId;
        try {
            $nextPayload = [
                'rootdomain' => $rootdomain,
                'batch_size' => $batchSize,
                'cursor_id' => $lastId,
                'deleted_total' => $totalDeleted,
            ];
            if (isset($payload['initiator'])) {
                $nextPayload['initiator'] = $payload['initiator'];
            }
            if (isset($payload['admin_id'])) {
                $nextPayload['admin_id'] = $payload['admin_id'];
            }
            Capsule::table('mod_cloudflare_jobs')->insert([
                'type' => 'purge_root_local',
                'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                'priority' => intval($job->priority ?? 8),
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'enqueue_failed';
            cfmod_report_exception('purge_root_local_enqueue', $e);
        }
    } else {
        $stats['has_more'] = false;
    }

    return $stats;
}

function cfmod_job_reconcile_all($job, array $payload = []): array {
    $jobId = intval($job->id);
    $settings = cfmod_get_settings();
    $mode = (($payload['mode'] ?? 'dry') === 'fix') ? 'fix' : 'dry';
    $batchSize = intval($payload['batch_size'] ?? 150);
    if ($batchSize <= 0) { $batchSize = 150; }
    $batchSize = max(50, min(5000, $batchSize));
    $cursor = intval($payload['cursor_id'] ?? 0);
    $targetRoot = strtolower(trim((string) ($payload['rootdomain'] ?? '')));
    $targetUserId = intval($payload['userid'] ?? 0);
    $fixScope = cfmod_worker_resolve_fix_scope($payload);
    $allowSensitiveDelete = cfmod_worker_allow_sensitive_delete($payload, $settings);
    $allowFixTtl = $fixScope['ttl'] ?? true;
    $allowFixMissing = $fixScope['missing'] ?? true;
    $allowFixExtra = $fixScope['extra'] ?? true;
    $now = date('Y-m-d H:i:s');

    $priority = strtolower($settings['sync_authoritative_source'] ?? 'local');
    if (!in_array($priority, ['local', 'aliyun'], true)) { $priority = 'local'; }
    $reconcileMode = cfmod_worker_reconcile_mode($settings);
    $replaceTypes = cfmod_worker_get_replace_mode_types($settings);

    $stats = [
        'mode' => $mode,
        'batch_size' => $batchSize,
        'cursor_start' => $cursor,
        'processed_subdomains' => 0,
        'records_updated_local' => 0,
        'records_imported_local' => 0,
        'differences_total' => 0,
        'difference_breakdown' => [],
        'action_breakdown' => [],
        'warnings' => [],
        'priority' => $priority,
        'fix_scope' => $fixScope,
        'allow_sensitive_delete' => $allowSensitiveDelete ? 1 : 0,
    ];
    if ($targetRoot !== '') {
        $stats['rootdomain'] = $targetRoot;
    }
    if ($targetUserId > 0) {
        $stats['userid'] = $targetUserId;
    }

    $subsQuery = Capsule::table('mod_cloudflare_subdomain')
        ->where('id', '>', $cursor)
        ->orderBy('id', 'asc');
    if ($targetRoot !== '') {
        $subsQuery->whereRaw('LOWER(rootdomain) = ?', [$targetRoot]);
    }
    if ($targetUserId > 0) {
        $subsQuery->where('userid', $targetUserId);
    }
    [$subsQuery, $shardTotal, $shardIndex] = cfmod_worker_apply_shard_filter($subsQuery, $payload);
    $subsCollection = $subsQuery
        ->limit($batchSize + 1)
        ->get();
    if ($shardTotal > 1) {
        $stats['shard_total'] = $shardTotal;
        $stats['shard_index'] = $shardIndex;
    }

    if (!($subsCollection instanceof \Illuminate\Support\Collection)) {
        $subsCollection = new \Illuminate\Support\Collection(is_array($subsCollection) ? $subsCollection : (array) $subsCollection);
    }

    if ($subsCollection->count() === 0) {
        $stats['message'] = 'no subdomains to reconcile';
        return $stats;
    }

    $hasMore = $subsCollection->count() > $batchSize;
    $subs = $hasMore ? $subsCollection->slice(0, $batchSize)->values() : $subsCollection->values();

    $providerClients = [];
    $groupedSubs = [];

    foreach ($subs as $s) {
        cfmod_worker_touch_progress();
        $stats['processed_subdomains']++;
        $providerId = cfmod_worker_resolve_provider_account_id_for_subdomain($s, $settings);
        $groupKey = $providerId ?: 0;
        if (!isset($groupedSubs[$groupKey])) {
            $groupedSubs[$groupKey] = [];
        }
        $groupedSubs[$groupKey][] = $s;
    }

    foreach ($groupedSubs as $providerKey => $groupSubs) {
        $providerAccountId = $providerKey ?: null;
        $providerContext = cfmod_worker_acquire_provider_client_cached($providerAccountId, $settings, $providerClients, $stats, 'reconcile');
        if (!$providerContext) {
            foreach ($groupSubs as $failedSub) {
                $stats['warnings'][] = 'reconcile_provider_missing_sub:' . $failedSub->id;
            }
            continue;
        }
        $cf = $providerContext['client'];
        $zoneCache = [];

        foreach ($groupSubs as $s) {
            cfmod_worker_touch_progress();
            try {
                $zone = $s->cloudflare_zone_id ?: $s->rootdomain;
                $name = strtolower($s->subdomain);
                $localRows = Capsule::table('mod_cloudflare_dns_records')->where('subdomain_id', $s->id)->orderBy('id', 'asc')->get();
                $remoteIndex = cfmod_worker_build_remote_index_for_subdomain($cf, $zone, $s, $localRows, $zoneCache, $stats);

                $localBuckets = [];
                foreach ($localRows as $lr) {
                    $recordName = strtolower($lr->name ?? '');
                    $recordType = strtoupper($lr->type ?? '');
                    if ($recordName === '' || $recordType === '') {
                        continue;
                    }
                    $localBuckets[$recordName][$recordType][] = $lr;
                }

                $allNames = array_values(array_unique(array_merge(array_keys($localBuckets), array_keys($remoteIndex))));
                foreach ($allNames as $recordName) {
                    if (!($recordName === $name || cf_str_ends_with($recordName, '.' . $name))) {
                        continue;
                    }
                    $allTypes = array_values(array_unique(array_merge(
                        array_keys($localBuckets[$recordName] ?? []),
                        array_keys($remoteIndex[$recordName] ?? [])
                    )));

                    foreach ($allTypes as $recordType) {
                        $localList = $localBuckets[$recordName][$recordType] ?? [];
                        $remoteList = $remoteIndex[$recordName][$recordType] ?? [];

                        $localByContent = cfmod_worker_group_records_by_content($localList);
                        $remoteByContent = cfmod_worker_group_records_by_content($remoteList);
                        $contentKeys = array_values(array_unique(array_merge(array_keys($localByContent), array_keys($remoteByContent))));

                        foreach ($contentKeys as $contentKey) {
                            $localsForContent = $localByContent[$contentKey] ?? [];
                            $remoteForContent = $remoteByContent[$contentKey] ?? [];
                            $matchedCount = min(count($localsForContent), count($remoteForContent));

                            for ($i = 0; $i < $matchedCount; $i++) {
                                $lr = $localsForContent[$i];
                                $cr = $remoteForContent[$i];
                                $updateData = [];
                                $remoteContent = (string) ($cr['content'] ?? '');
                                if ((string) ($lr->content ?? '') !== $remoteContent) {
                                    $updateData['content'] = $remoteContent;
                                }
                                $remoteTtl = cfmod_normalize_ttl($cr['ttl'] ?? 600);
                                if (intval($lr->ttl ?? 0) !== $remoteTtl) {
                                    $updateData['ttl'] = $remoteTtl;
                                }
                                $remoteRecordId = (string) ($cr['id'] ?? '');
                                if ($remoteRecordId !== '' && (string) ($lr->record_id ?? '') !== $remoteRecordId) {
                                    $updateData['record_id'] = $remoteRecordId;
                                }

                                if (!empty($updateData)) {
                                    $action = ($mode === 'fix') ? 'update_local' : 'diff_update_local';
                                    if ($mode === 'fix') {
                                        if ($allowFixTtl) {
                                            $updateData['updated_at'] = $now;
                                            Capsule::table('mod_cloudflare_dns_records')->where('id', $lr->id)->update($updateData);
                                            $stats['records_updated_local']++;
                                        } else {
                                            $action = 'scope_skip_ttl';
                                        }
                                    }
                                    cfmod_sync_result($jobId, $s->id, 'reconcile', $action, [
                                        'name' => $recordName,
                                        'type' => $recordType,
                                        'record_id' => $remoteRecordId !== '' ? $remoteRecordId : null,
                                    ]);
                                    cfmod_track_sync_stat($stats, 'reconcile', $action);
                                }
                            }

                            for ($i = $matchedCount; $i < count($remoteForContent); $i++) {
                                $cr = $remoteForContent[$i];
                                if ($priority === 'local') {
                                    $action = ($mode === 'fix') ? 'deleted_on_cf' : 'diff_cloud_extra';
                                    if ($mode === 'fix') {
                                        if (!$allowFixExtra) {
                                            $action = 'scope_skip_extra';
                                        } elseif (cfmod_worker_is_sensitive_dns_type((string) $recordType) && !$allowSensitiveDelete) {
                                            if ($allowFixMissing) {
                                                $imported = cfmod_worker_import_remote_record_to_local(
                                                    intval($s->id),
                                                    (string) $zone,
                                                    $recordName,
                                                    $recordType,
                                                    $cr
                                                );
                                                if ($imported) {
                                                    $action = 'imported_local_sensitive';
                                                    $stats['records_imported_local']++;
                                                } else {
                                                    $action = 'import_failed_sensitive';
                                                    $stats['warnings'][] = 'import_failed_sensitive:' . $s->id . ':' . $recordName . ':' . $recordType;
                                                }
                                            } else {
                                                $action = 'scope_skip_sensitive';
                                            }
                                        } elseif (!empty($cr['id'])) {
                                            $res = $cf->deleteSubdomain($zone, $cr['id'], [
                                                'name' => $recordName,
                                                'type' => $recordType,
                                                'content' => $cr['content'] ?? null,
                                            ]);
                                            if (!(($res['success'] ?? false) || cfmod_worker_provider_not_found($res))) {
                                                $action = 'delete_failed';
                                                $stats['warnings'][] = 'delete_failed:' . ($cr['id'] ?? '');
                                            }
                                        } else {
                                            $action = 'delete_skipped_no_id';
                                            $stats['warnings'][] = 'delete_skipped_no_id:' . $s->id . ':' . $recordName . ':' . $recordType;
                                        }
                                    }
                                    cfmod_sync_result($jobId, $s->id, 'reconcile', $action, [
                                        'name' => $recordName,
                                        'type' => $recordType,
                                        'record_id' => $cr['id'] ?? null,
                                    ]);
                                    cfmod_track_sync_stat($stats, 'reconcile', $action);
                                    continue;
                                }

                                $lineKey = 'default';
                                if ($reconcileMode === 'local_empty_add_as_replace'
                                    && in_array(strtoupper((string) $recordType), $replaceTypes, true)) {
                                    $managedRows = Capsule::table('mod_cloudflare_dns_records')
                                        ->where('subdomain_id', $s->id)
                                        ->where('name', strtolower((string) $recordName))
                                        ->where('type', strtoupper((string) $recordType))
                                        ->whereRaw('COALESCE(`line`, "") = ?', [''])
                                        ->get();
                                    $managedRowsArr = ($managedRows instanceof \Illuminate\Support\Collection) ? $managedRows->all() : (array) $managedRows;
                                    if (count($managedRowsArr) > 0) {
                                        $remoteContent = strtolower(trim((string) ($cr['content'] ?? '')));
                                        $allowImport = false;
                                        foreach ($managedRowsArr as $mr) {
                                            $localContent = strtolower(trim((string) ($mr->content ?? '')));
                                            if ($localContent !== '' && $localContent === $remoteContent) {
                                                $allowImport = true;
                                                break;
                                            }
                                        }
                                        if (!$allowImport) {
                                            $action = ($mode === 'fix') ? 'managed_key_skip_remote_import' : 'diff_managed_key_skip_remote_import';
                                            cfmod_sync_result($jobId, $s->id, 'reconcile', $action, [
                                                'name' => $recordName,
                                                'type' => $recordType,
                                                'record_id' => $cr['id'] ?? null,
                                                'managed_record_key' => strtolower($recordName . '|' . strtoupper($recordType) . '|' . $lineKey),
                                                'line_normalized' => '',
                                            ]);
                                            cfmod_track_sync_stat($stats, 'reconcile', $action);
                                            continue;
                                        }
                                    }
                                }

                                $action = ($mode === 'fix') ? 'insert_local' : 'diff_insert_local';
                                if ($mode === 'fix') {
                                    if (!$allowFixMissing) {
                                        $action = 'scope_skip_missing';
                                    } else {
                                        Capsule::table('mod_cloudflare_dns_records')->insert([
                                            'subdomain_id' => $s->id,
                                            'zone_id' => $zone,
                                            'record_id' => $cr['id'] ?? null,
                                            'name' => strtolower($recordName),
                                            'type' => strtoupper($recordType),
                                            'content' => (string) ($cr['content'] ?? ''),
                                            'ttl' => intval($cr['ttl'] ?? 600),
                                            'proxied' => 0,
                                            'priority' => null,
                                            'line' => null,
                                            'created_at' => $now,
                                            'updated_at' => $now
                                        ]);
                                        CfSubdomainService::markHasDnsHistory($s->id);
                                        $stats['records_imported_local']++;
                                    }
                                }
                                cfmod_sync_result($jobId, $s->id, 'reconcile', $action, [
                                    'name' => $recordName,
                                    'type' => $recordType,
                                    'record_id' => $cr['id'] ?? null,
                                ]);
                                cfmod_track_sync_stat($stats, 'reconcile', $action);
                            }

                            for ($i = $matchedCount; $i < count($localsForContent); $i++) {
                                $lr = $localsForContent[$i];
                                if ($priority === 'local') {
                                    $action = ($mode === 'fix') ? 'created_on_cf' : 'diff_cloud_missing';
                                    if ($mode === 'fix') {
                                        if (!$allowFixMissing) {
                                            $action = 'scope_skip_missing';
                                        } else {
                                            $res = $cf->createDnsRecord(
                                                $zone,
                                                $recordName,
                                                $recordType,
                                                (string) ($lr->content ?? ''),
                                                intval($lr->ttl ?? 600),
                                                boolval($lr->proxied ?? false)
                                            );
                                            if ($res['success'] ?? false) {
                                                $newId = $res['result']['id'] ?? null;
                                                Capsule::table('mod_cloudflare_dns_records')->where('id', $lr->id)->update([
                                                    'record_id' => $newId,
                                                    'updated_at' => $now,
                                                ]);
                                            } else {
                                                $action = 'create_failed';
                                                $stats['warnings'][] = 'create_failed:' . $s->id . ':' . $recordName . ':' . $recordType;
                                            }
                                        }
                                    }
                                    cfmod_sync_result($jobId, $s->id, 'reconcile', $action, [
                                        'name' => $recordName,
                                        'type' => $recordType,
                                        'content' => (string) ($lr->content ?? ''),
                                    ]);
                                    cfmod_track_sync_stat($stats, 'reconcile', $action);
                                    continue;
                                }

                                $action = 'diff_cloud_missing';
                                cfmod_sync_result($jobId, $s->id, 'reconcile', $action, [
                                    'name' => $recordName,
                                    'type' => $recordType,
                                    'content' => (string) ($lr->content ?? ''),
                                ]);
                                cfmod_track_sync_stat($stats, 'reconcile', $action);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'sub:' . $s->id;
                cfmod_sync_result($jobId, $s->id, 'reconcile', 'error', ['message' => substr($e->getMessage(), 0, 200)]);
                cfmod_report_exception('reconcile_all', $e);
            }
        }
    }

    $lastProcessedId = $subs->last()->id ?? $cursor;
    if ($hasMore && $lastProcessedId) {
        $newPayload = $payload;
        $newPayload['cursor_id'] = $lastProcessedId;
        $newPayload['batch_size'] = $batchSize;
        $newPayload['mode'] = $mode;
        if ($targetRoot !== '') {
            $newPayload['rootdomain'] = $targetRoot;
        }
        if ($targetUserId > 0) {
            $newPayload['userid'] = $targetUserId;
        }
        if ($shardTotal > 1) {
            $newPayload['shard_total'] = $shardTotal;
            $newPayload['shard_index'] = $shardIndex;
        }
        try {
            $continuationId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                'type' => 'reconcile_all',
                'payload_json' => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
                'priority' => intval($job->priority ?? 10),
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            $stats['has_more'] = true;
            $stats['next_cursor'] = $lastProcessedId;
            $stats['continuation_job_id'] = $continuationId;
        } catch (\Throwable $e) {
            $stats['has_more'] = true;
            $stats['warnings'][] = 'enqueue_failed:' . $lastProcessedId;
            cfmod_report_exception('reconcile_enqueue', $e);
        }
    } else {
        $stats['has_more'] = false;
    }

    $stats['message'] = 'reconcile ' . ($mode === 'fix' ? 'fix' : 'dry');
    return $stats;
}



/**
 * Enforce DNS on all free subdomains of a banned user:
 * - Keep only A records and set to specified IPv4
 * - Delete all other record types (AAAA, CNAME, TXT, MX, NS, etc.)
 * payload: { userid: number, ipv4: string }
 */
function cfmod_job_enforce_ban_dns($job, array $payload = []): array {
    $jobId = intval($job->id);
    $userid = intval($payload['userid'] ?? 0);
    $ip4 = trim((string)($payload['ipv4'] ?? ''));
    if ($userid <= 0 || $ip4 === '') {
        return [
            'warnings' => ['invalid_payload'],
            'message' => 'invalid payload',
            'processed_subdomains' => 0,
        ];
    }
    $settings = cfmod_get_settings();
    $now = date('Y-m-d H:i:s');
    $subs = Capsule::table('mod_cloudflare_subdomain')->where('userid', $userid)->orderBy('id','asc')->get();

    $stats = [
        'processed_subdomains' => 0,
        'success_subdomains' => 0,
        'failed_subdomains' => 0,
        'records_updated_on_cf' => 0,
        'records_deleted_on_cf' => 0,
        'warnings' => [],
    ];

    $providerClients = [];
    foreach ($subs as $s) {
        try {
            $stats['processed_subdomains']++;
            $providerId = cfmod_worker_resolve_provider_account_id_for_subdomain($s, $settings);
            $providerContext = cfmod_worker_acquire_provider_client_cached($providerId, $settings, $providerClients, $stats, 'enforce_ban_dns');
            if (!$providerContext) {
                $stats['warnings'][] = 'provider_unavailable:' . $s->id;
                continue;
            }
            $cf = $providerContext['client'];
            $providerType = strtolower((string) (($providerContext['provider_type'] ?? $providerContext['provider']['provider_type'] ?? '') ?: ''));

            $zone = $s->cloudflare_zone_id ?: $s->rootdomain;
            $name = strtolower($s->subdomain);
            if ($zone === '' || $name === '') {
                $stats['failed_subdomains']++;
                $stats['warnings'][] = 'invalid_zone_or_name:' . $s->id;
                continue;
            }
            $remote = $cf->getDnsRecords($zone, $name, ['per_page' => 1000]);
            if (!($remote['success'] ?? false)) { throw new \RuntimeException('list failed'); }
            $records = $remote['result'] ?? [];
            $subdomainFailed = false;

            if ($providerType === 'powerdns' && method_exists($cf, 'applyRrsetChangesBatch')) {
                $targetTtl = 600;
                foreach ($records as $r) {
                    if (strtoupper((string) ($r['type'] ?? '')) === 'A') {
                        $targetTtl = max(60, intval($r['ttl'] ?? 600));
                        break;
                    }
                }
                $batchRes = $cf->applyRrsetChangesBatch($zone, [[
                    'name' => $name,
                    'type' => 'A',
                    'changetype' => 'REPLACE',
                    'ttl' => $targetTtl,
                    'records' => [[
                        'content' => $ip4,
                        'disabled' => false,
                    ]],
                ]]);
                if (!($batchRes['success'] ?? false)) {
                    $subdomainFailed = true;
                    $stats['warnings'][] = 'pdns_batch_replace_failed:' . $s->id;
                } else {
                    $stats['records_updated_on_cf']++;
                }
            } else {
                $hasARecord = false;
                foreach ($records as $r) {
                    $rid = $r['id'] ?? null;
                    $type = strtoupper($r['type'] ?? '');
                    $rname = $r['name'] ?? $name;
                    if (!$rid || $rname === '') { continue; }
                    if ($type === 'A') {
                        $hasARecord = true;
                        $updateRes = $cf->updateDnsRecord($zone, $rid, [ 'type' => 'A', 'name' => $rname, 'content' => $ip4, 'ttl' => intval($r['ttl'] ?? 600) ]);
                        if (!($updateRes['success'] ?? false)) {
                            $subdomainFailed = true;
                            $stats['warnings'][] = 'update_failed:' . $rid;
                        } else {
                            $stats['records_updated_on_cf']++;
                        }
                    } else {
                        try {
                            $deleteRes = $cf->deleteSubdomain($zone, $rid, [
                                'name' => $rname,
                                'type' => $type,
                                'content' => $r['content'] ?? null,
                            ]);
                            if (!($deleteRes['success'] ?? false) && method_exists($cf, 'deleteRecordByContent')) {
                                $deleteRes = $cf->deleteRecordByContent($zone, $rname, $type, (string) ($r['content'] ?? ''));
                            }
                            if (!($deleteRes['success'] ?? false)
                                && in_array($providerType, ['powerdns', 'aliyun', 'ali', 'alidns'], true)
                                && method_exists($cf, 'deleteDomainRecordsDeep')
                            ) {
                                $deleteRes = $cf->deleteDomainRecordsDeep($zone, $name);
                            }
                            if (!($deleteRes['success'] ?? false)) {
                                $subdomainFailed = true;
                                $stats['warnings'][] = 'delete_failed:' . $rid;
                            } else {
                                $stats['records_deleted_on_cf']++;
                            }
                        } catch (\Throwable $inner) {
                            $subdomainFailed = true;
                            $stats['warnings'][] = 'delete_failed:' . $rid;
                            cfmod_report_exception('enforce_ban_dns_delete', $inner);
                        }
                    }
                }
                if (!$hasARecord) {
                    try {
                        $createRes = $cf->createDnsRecord($zone, $name, 'A', $ip4, 600, false);
                        if (!($createRes['success'] ?? false)) {
                            $subdomainFailed = true;
                            $stats['warnings'][] = 'create_a_failed:' . $s->id;
                        } else {
                            $stats['records_updated_on_cf']++;
                        }
                    } catch (\Throwable $createError) {
                        $subdomainFailed = true;
                        $stats['warnings'][] = 'create_a_failed:' . $s->id;
                        cfmod_report_exception('enforce_ban_dns_create_a', $createError);
                    }
                }
            }
            // Provider-specific verification: ensure only A records remain and all A values are forced to target IPv4
            try {
                $verifyRes = $cf->getDnsRecords($zone, $name, ['per_page' => 1000]);
                if ($verifyRes['success'] ?? false) {
                    $verifyRows = $verifyRes['result'] ?? [];
                    $hasAnyA = false;
                    foreach ($verifyRows as $vr) {
                        $vType = strtoupper((string) ($vr['type'] ?? ''));
                        $vContent = trim((string) ($vr['content'] ?? ''));
                        if ($vType === 'A') {
                            $hasAnyA = true;
                            if ($vContent !== $ip4) {
                                $subdomainFailed = true;
                                $stats['warnings'][] = 'verify_a_mismatch:' . $s->id;
                            }
                        } else {
                            $subdomainFailed = true;
                            $stats['warnings'][] = 'verify_non_a_remaining:' . $s->id;
                        }
                    }
                    if (!$hasAnyA) {
                        $subdomainFailed = true;
                        $stats['warnings'][] = 'verify_missing_a:' . $s->id;
                    }
                } else {
                    $subdomainFailed = true;
                    $stats['warnings'][] = 'verify_list_failed:' . $s->id;
                }
            } catch (\Throwable $verifyError) {
                $subdomainFailed = true;
                $stats['warnings'][] = 'verify_exception:' . $s->id;
                cfmod_report_exception('enforce_ban_dns_verify', $verifyError);
            }
            if ($subdomainFailed) {
                $stats['failed_subdomains']++;
            } else {
                $stats['success_subdomains']++;
            }
        } catch (\Throwable $e) {
            $stats['failed_subdomains']++;
            $stats['warnings'][] = 'sub:' . $s->id;
            cfmod_report_exception('enforce_ban_dns', $e);
        }
    }

    if ($stats['failed_subdomains'] > 0 && $stats['success_subdomains'] === 0) {
        throw new \RuntimeException('enforce_ban_dns failed for all targeted subdomains');
    }
    $stats['message'] = 'enforced dns for user ' . $userid;
    return $stats;
}

function cfmod_job_client_dns_operation($job, array $payload = []): array
{
    $userId = intval($payload['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new \RuntimeException('Async DNS job missing user id (job #' . ($job->id ?? '?') . ')');
    }
    $postData = $payload['post'] ?? null;
    if (!is_array($postData) || empty($postData['action'])) {
        throw new \RuntimeException('Async DNS job payload is invalid (job #' . ($job->id ?? '?') . ')');
    }

    $originalPost = $_POST ?? [];
    $originalRequest = $_REQUEST ?? [];
    $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;

    try {
        $_POST = $postData;
        $_REQUEST = $_POST;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            $sessionId = 'asyncdns_' . ($job->id ?? 0) . '_' . bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            $sessionId = 'asyncdns_' . ($job->id ?? 0) . '_' . uniqid();
        }
        session_id($sessionId);
        session_start();
        $_SESSION['uid'] = $userId;

        $viewModel = CfClientViewModelBuilder::build($userId);
        $globals = $viewModel['globals'] ?? [];
        if (empty($globals)) {
            throw new \RuntimeException('Unable to build client context for async DNS job #' . ($job->id ?? '?'));
        }

        $result = CfClientActionService::process($globals);
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_POST = $originalPost;
        $_REQUEST = $originalRequest;
        if ($originalMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $originalMethod;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    $msg = trim(strip_tags($result['msg'] ?? ''));
    $msgType = strtolower((string)($result['msg_type'] ?? ''));
    if ($msgType === 'danger') {
        throw new \RuntimeException($msg !== '' ? $msg : 'DNS operation failed');
    }

    return [
        'message' => $msg !== '' ? $msg : 'queued',
        'status' => $msgType !== '' ? $msgType : 'success',
        'action' => $postData['action'],
    ];
}

function cfmod_job_precompute_admin_stats($job, array $payload = []): array
{
    if (!class_exists('CfAdminViewModelBuilder')) {
        throw new \RuntimeException('AdminViewModelBuilder is unavailable');
    }

    $stats = CfAdminViewModelBuilder::computeHeavyStatsSnapshot();
    if (!is_array($stats)) {
        throw new \RuntimeException('Heavy stats compute returned invalid payload');
    }

    $stored = false;
    if (class_exists('CfAdminStatsSnapshotService')) {
        $stored = CfAdminStatsSnapshotService::storeSnapshot($stats);
    }

    return [
        'message' => $stored ? 'admin heavy stats refreshed' : 'admin heavy stats computed (store failed)',
        'stored' => $stored ? 1 : 0,
        'totalSubdomains' => (int) ($stats['totalSubdomains'] ?? 0),
        'activeSubdomains' => (int) ($stats['activeSubdomains'] ?? 0),
        'registeredUsers' => (int) ($stats['registeredUsers'] ?? 0),
    ];
}

function cfmod_job_unlock_invite_registration_all($job, array $payload = []): array
{
    if (!class_exists('CfInviteRegistrationService')) {
        $servicePath = __DIR__ . '/lib/Services/InviteRegistrationService.php';
        if (file_exists($servicePath)) {
            require_once $servicePath;
        }
    }
    if (!class_exists('CfInviteRegistrationService')) {
        throw new \RuntimeException('InviteRegistrationService is unavailable');
    }

    $cursorId = max(0, intval($payload['cursor_id'] ?? 0));
    $batchSize = max(100, min(5000, intval($payload['batch_size'] ?? 500)));
    $adminId = max(0, intval($payload['admin_id'] ?? 0));
    $now = date('Y-m-d H:i:s');

    $stats = [
        'processed' => 0,
        'newly_unlocked' => 0,
        'already_unlocked' => 0,
        'failed' => 0,
        'next_cursor' => $cursorId,
        'has_more' => 0,
        'warnings' => [],
    ];

    try {
        $users = Capsule::table('tblclients')
            ->select('id')
            ->where('id', '>', $cursorId)
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();
    } catch (\Throwable $e) {
        throw new \RuntimeException('Failed to load WHMCS users: ' . $e->getMessage());
    }

    $rows = ($users instanceof \Illuminate\Support\Collection) ? $users->all() : (is_array($users) ? $users : []);
    foreach ($rows as $row) {
        $userId = intval(is_object($row) ? ($row->id ?? 0) : ($row['id'] ?? 0));
        if ($userId <= 0) {
            continue;
        }

        $stats['processed']++;
        $stats['next_cursor'] = $userId;

        try {
            $profile = CfInviteRegistrationService::ensureProfile($userId);
            $wasUnlocked = !empty($profile['unlocked_at']);
            CfInviteRegistrationService::adminUnlock($userId, $adminId);
            if ($wasUnlocked) {
                $stats['already_unlocked']++;
            } else {
                $stats['newly_unlocked']++;
            }
        } catch (\Throwable $e) {
            $stats['failed']++;
            if (count($stats['warnings']) < 20) {
                $stats['warnings'][] = 'uid:' . $userId . ':' . substr((string) $e->getMessage(), 0, 80);
            }
        }

        cfmod_worker_touch_progress();
    }

    $processedCount = count($rows);
    $hasMore = $processedCount >= $batchSize;
    $stats['has_more'] = $hasMore ? 1 : 0;

    if ($hasMore && $stats['next_cursor'] > 0) {
        $nextPayload = [
            'cursor_id' => intval($stats['next_cursor']),
            'batch_size' => $batchSize,
            'admin_id' => $adminId,
            'requested_at' => $payload['requested_at'] ?? date('c'),
            'continued_from_job' => intval($job->id ?? 0),
        ];
        try {
            $nextJobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                'type' => 'unlock_invite_registration_all',
                'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                'priority' => intval($job->priority ?? 6),
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $stats['next_job_id'] = intval($nextJobId);
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'enqueue_next_failed';
            cfmod_report_exception('unlock_invite_registration_all_enqueue', $e);
        }
    }

    $stats['message'] = 'processed ' . intval($stats['processed']) . ', new ' . intval($stats['newly_unlocked']) . ', skipped ' . intval($stats['already_unlocked']) . ', failed ' . intval($stats['failed']);
    return $stats;
}

/**
 * 清理风险事件数据任务
 */
function cfmod_job_cleanup_risk_events($job, array $payload = []): array {
    $now = date('Y-m-d H:i:s');
    $totalCleaned = 0;
    $highRiskCleaned = 0;
    $duplicateCleaned = 0;
    $stats = [
        'deleted' => 0,
        'warnings' => [],
        'processed_subdomains' => 0,
        'high_risk_deleted' => 0,
        'duplicate_deleted' => 0,
    ];

    try {
        $highRiskCleaned = Capsule::table('mod_cloudflare_risk_events')
            ->where('level', 'high')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-72 hours')))
            ->delete();
        $totalCleaned += $highRiskCleaned;

        $duplicatesRaw = Capsule::table('mod_cloudflare_risk_events')
            ->select('subdomain_id', Capsule::raw('DATE(created_at) as date'), Capsule::raw('COUNT(*) as count'))
            ->where('source', 'summary')
            ->groupBy('subdomain_id', Capsule::raw('DATE(created_at)'))
            ->having('count', '>', 1)
            ->get();
        if ($duplicatesRaw instanceof \Illuminate\Support\Collection) {
            $duplicates = $duplicatesRaw->all();
            $duplicatesCount = $duplicatesRaw->count();
        } else {
            $duplicates = is_array($duplicatesRaw) ? $duplicatesRaw : [];
            $duplicatesCount = count($duplicates);
        }

        foreach ($duplicates as $dup) {
            $subdomainId = is_object($dup) ? ($dup->subdomain_id ?? null) : ($dup['subdomain_id'] ?? null);
            $dupDate = is_object($dup) ? ($dup->date ?? null) : ($dup['date'] ?? null);
            if ($subdomainId === null || $dupDate === null) {
                continue;
            }

            $toDeleteRaw = Capsule::table('mod_cloudflare_risk_events')
                ->where('subdomain_id', $subdomainId)
                ->where('source', 'summary')
                ->whereRaw('DATE(created_at) = ?', [$dupDate])
                ->orderBy('id', 'desc')
                ->skip(1)
                ->get();
            $toDelete = $toDeleteRaw instanceof \Illuminate\Support\Collection ? $toDeleteRaw->all() : (is_array($toDeleteRaw) ? $toDeleteRaw : []);

            foreach ($toDelete as $record) {
                $recordId = is_object($record) ? ($record->id ?? null) : ($record['id'] ?? null);
                if ($recordId === null) {
                    continue;
                }
                Capsule::table('mod_cloudflare_risk_events')->where('id', $recordId)->delete();
                $duplicateCleaned++;
            }
        }
        $totalCleaned += $duplicateCleaned;

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('risk_events_cleanup', [
                'cleaned_count' => $totalCleaned,
                'high_risk' => $highRiskCleaned,
                'duplicates' => $duplicateCleaned,
                'note' => 'Only high-risk events are stored and cleaned after 72 hours'
            ]);
        }

        try {
            Capsule::statement("OPTIMIZE TABLE `mod_cloudflare_risk_events`");
        } catch (\Throwable $optimizeException) {
            $warnMsg = trim((string) $optimizeException->getMessage());
            if ($warnMsg === '') {
                $stats['warnings'][] = 'optimize_failed';
            } else {
                $stats['warnings'][] = 'optimize_failed:' . substr($warnMsg, 0, 120);
            }
            cfmod_report_exception('cleanup_risk_events_optimize', $optimizeException);
        }

        $stats['deleted'] = $totalCleaned;
        $stats['processed_subdomains'] = $duplicatesCount;
        $stats['high_risk_deleted'] = $highRiskCleaned;
        $stats['duplicate_deleted'] = $duplicateCleaned;
        $message = 'cleaned ' . $totalCleaned . ' risk events';
        if (!empty($stats['warnings'])) {
            $message .= ' (with warnings)';
        }
        $stats['message'] = $message;
    } catch (\Throwable $e) {
        $errMsg = trim((string) $e->getMessage());
        if ($errMsg === '') {
            $errMsg = 'cleanup failed';
        }
        $stats['warnings'][] = 'cleanup_failed:' . substr($errMsg, 0, 120);
        $stats['message'] = 'cleanup encountered errors: ' . substr($errMsg, 0, 120);
        cfmod_report_exception('cleanup_risk_events', $e);
    }

    return $stats;
}

function cfmod_job_send_expiry_notices($job, array $payload = []): array {
    $stats = [
        'processed' => 0,
        'sent' => 0,
        'warnings' => [],
        'days' => [],
        'has_more' => false,
    ];

    try {
        $settings = cfmod_get_settings();
        if (!CfRenewalNoticeService::isEnabled($settings)) {
            $stats['message'] = 'renewal_notice_disabled';
            return $stats;
        }
        $template = trim((string)($settings['renewal_notice_template'] ?? ''));
        if ($template === '') {
            $stats['warnings'][] = 'template_missing';
            $stats['message'] = 'template_missing';
            return $stats;
        }
        $daysList = CfRenewalNoticeService::parseConfiguredDays($settings, $payload['days'] ?? []);
        if (empty($daysList)) {
            $stats['message'] = 'no_days_configured';
            return $stats;
        }
        CfRenewalNoticeService::ensureTable();

        $batchLimit = intval($payload['batch_size'] ?? 200);
        $batchLimit = max(10, min(1000, $batchLimit));

        $continuationRound = intval($payload['continuation_round'] ?? 0);
        if ($continuationRound < 0) {
            $continuationRound = 0;
        }
        $maxContinuationRounds = intval($payload['max_continuation_rounds'] ?? 50);
        $maxContinuationRounds = max(1, min(500, $maxContinuationRounds));

        $stats['days'] = $daysList;
        $stats['continuation_round'] = $continuationRound;
        $stats['max_continuation_rounds'] = $maxContinuationRounds;

        $hasMore = false;

        foreach ($daysList as $days) {
            $reminderKey = CfRenewalNoticeService::reminderKey($days);
            $targetTs = strtotime('+' . $days . ' days');
            $start = date('Y-m-d 00:00:00', $targetTs);
            $end = date('Y-m-d 23:59:59', $targetTs);
            $recordsRaw = Capsule::table('mod_cloudflare_subdomain as s')
                ->select('s.*')
                ->whereNotNull('s.expires_at')
                ->where('s.never_expires', 0)
                ->whereNotIn('s.status', ['deleted', 'Deleted'])
                ->whereBetween('s.expires_at', [$start, $end])
                ->whereNotExists(function($sub) use ($reminderKey) {
                    $sub->select(Capsule::raw('1'))
                        ->from(CfRenewalNoticeService::TABLE . ' as n')
                        ->whereColumn('n.subdomain_id', 's.id')
                        ->where('n.reminder_key', $reminderKey)
                        ->whereColumn('n.expires_at_snapshot', 's.expires_at');
                })
                ->orderBy('s.expires_at', 'asc')
                ->orderBy('s.id', 'asc')
                ->limit($batchLimit + 1)
                ->get();

            $records = $recordsRaw;
            if ($records instanceof Collection) {
                $records = $records->all();
            }
            if (!is_array($records)) {
                $records = [];
            }

            if (count($records) > $batchLimit) {
                $hasMore = true;
                $records = array_slice($records, 0, $batchLimit);
            }

            foreach ($records as $subdomainRow) {
                $subdomain = is_array($subdomainRow) ? (object) $subdomainRow : $subdomainRow;
                $stats['processed']++;
                $result = CfRenewalNoticeService::sendReminderEmail($subdomain, $template, $days);
                if ($result['success']) {
                    $stats['sent']++;
                    CfRenewalNoticeService::markReminderSent(
                        intval($subdomain->id ?? 0),
                        $reminderKey,
                        $subdomain->expires_at ?? null
                    );
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('auto_send_expiry_notice', [
                            'subdomain' => $subdomain->subdomain ?? '',
                            'reminder_key' => $reminderKey,
                            'days_left' => $days,
                        ], intval($subdomain->userid ?? 0), intval($subdomain->id ?? 0));
                    }
                } else {
                    $stats['warnings'][] = $result['message'] ?? 'send_failed';
                }
            }
        }

        $stats['has_more'] = $hasMore;
        $stats['message'] = 'sent ' . $stats['sent'] . ' notices';

        if ($hasMore) {
            if ($continuationRound >= $maxContinuationRounds) {
                $stats['warnings'][] = 'continuation_limit_reached';
                $stats['message'] .= ' (continuation limit reached)';
            } else {
                $nextPayload = [
                    'days' => $daysList,
                    'batch_size' => $batchLimit,
                    'auto' => !empty($payload['auto']),
                    'continuation_round' => $continuationRound + 1,
                    'max_continuation_rounds' => $maxContinuationRounds,
                ];
                $originJobId = intval($payload['origin_job_id'] ?? 0);
                if ($originJobId <= 0) {
                    $originJobId = intval($job->id ?? 0);
                }
                if ($originJobId > 0) {
                    $nextPayload['origin_job_id'] = $originJobId;
                }

                $now = date('Y-m-d H:i:s');
                $nextJobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                    'type' => 'send_expiry_notices',
                    'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 18),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $stats['continuation_job_id'] = $nextJobId;
                $stats['message'] .= ' (continuation queued)';
            }
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = $e->getMessage();
        $stats['message'] = 'failure:' . substr($e->getMessage(), 0, 60);
        cfmod_report_exception('send_expiry_notices', $e);
    }

    return $stats;
}

function cfmod_job_send_expiry_telegram_notices($job, array $payload = []): array {
    $stats = [
        'processed' => 0,
        'sent' => 0,
        'warnings' => [],
        'days' => [],
        'has_more' => false,
        'rate_limited' => 0,
    ];

    try {
        if (!class_exists('CfTelegramExpiryReminderService')) {
            $stats['message'] = 'telegram_notice_service_missing';
            return $stats;
        }

        $settings = cfmod_get_settings();
        if (!CfTelegramExpiryReminderService::isEnabled($settings)) {
            $stats['message'] = 'telegram_notice_disabled';
            return $stats;
        }
        if (!CfTelegramExpiryReminderService::isConfigured($settings)) {
            $stats['warnings'][] = 'telegram_notice_not_configured';
            $stats['message'] = 'telegram_notice_not_configured';
            return $stats;
        }

        $daysList = CfTelegramExpiryReminderService::parseConfiguredDays($settings, $payload['days'] ?? []);
        if (empty($daysList)) {
            $stats['message'] = 'no_days_configured';
            return $stats;
        }

        CfTelegramExpiryReminderService::ensureTables();

        $batchLimit = intval($payload['batch_size'] ?? 100);
        $batchLimit = max(10, min(1000, $batchLimit));

        $continuationRound = intval($payload['continuation_round'] ?? 0);
        if ($continuationRound < 0) {
            $continuationRound = 0;
        }
        $maxContinuationRounds = intval($payload['max_continuation_rounds'] ?? 80);
        $maxContinuationRounds = max(1, min(500, $maxContinuationRounds));

        $stats['days'] = $daysList;
        $stats['continuation_round'] = $continuationRound;
        $stats['max_continuation_rounds'] = $maxContinuationRounds;

        $hasMore = false;
        $stopCurrentRun = false;
        $retryAfter = 0;

        foreach ($daysList as $days) {
            $records = CfTelegramExpiryReminderService::fetchPendingRecords($days, $batchLimit);
            if (count($records) > $batchLimit) {
                $hasMore = true;
                $records = array_slice($records, 0, $batchLimit);
            }

            foreach ($records as $subdomainRow) {
                $subdomain = is_array($subdomainRow) ? (object) $subdomainRow : $subdomainRow;
                $stats['processed']++;

                try {
                    $result = CfTelegramExpiryReminderService::sendReminderMessage($subdomain, (int) $days, $settings);
                } catch (CfTelegramExpiryReminderException $sendException) {
                    if ($sendException->getReason() === 'rate_limited') {
                        $stopCurrentRun = true;
                        $hasMore = true;
                        $stats['rate_limited'] = 1;
                        $retryAfter = max($retryAfter, $sendException->getRetryAfter());
                        $stats['warnings'][] = 'rate_limited';
                        break;
                    }

                    $stats['warnings'][] = $sendException->getMessage();
                    continue;
                }

                if (!empty($result['success'])) {
                    $stats['sent']++;
                    CfTelegramExpiryReminderService::markReminderSent($subdomain, (int) $days);
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('auto_send_expiry_telegram_notice', [
                            'subdomain' => $subdomain->subdomain ?? '',
                            'rootdomain' => $subdomain->rootdomain ?? '',
                            'reminder_key' => CfTelegramExpiryReminderService::reminderKey((int) $days),
                            'days_left' => (int) $days,
                        ], intval($subdomain->userid ?? 0), intval($subdomain->id ?? 0));
                    }
                } else {
                    $stats['warnings'][] = $result['message'] ?? 'send_failed';
                }
            }

            if ($stopCurrentRun) {
                break;
            }
        }

        $stats['has_more'] = $hasMore;
        $stats['message'] = 'sent ' . $stats['sent'] . ' telegram notices';
        if (!empty($stats['rate_limited'])) {
            $stats['retry_after'] = max(3, $retryAfter > 0 ? $retryAfter : 5);
            $stats['message'] .= ' (rate limited)';
        }

        if ($hasMore) {
            if ($continuationRound >= $maxContinuationRounds) {
                $stats['warnings'][] = 'continuation_limit_reached';
                $stats['message'] .= ' (continuation limit reached)';
            } else {
                $nextPayload = [
                    'days' => $daysList,
                    'batch_size' => $batchLimit,
                    'auto' => !empty($payload['auto']),
                    'continuation_round' => $continuationRound + 1,
                    'max_continuation_rounds' => $maxContinuationRounds,
                ];
                $originJobId = intval($payload['origin_job_id'] ?? 0);
                if ($originJobId <= 0) {
                    $originJobId = intval($job->id ?? 0);
                }
                if ($originJobId > 0) {
                    $nextPayload['origin_job_id'] = $originJobId;
                }

                $delaySeconds = 0;
                if (!empty($stats['rate_limited'])) {
                    $delaySeconds = max(3, min(300, intval($stats['retry_after'] ?? 5)));
                }

                $nowTs = time();
                $now = date('Y-m-d H:i:s', $nowTs);
                $nextRunAt = $delaySeconds > 0 ? date('Y-m-d H:i:s', $nowTs + $delaySeconds) : null;

                $nextJobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                    'type' => 'send_expiry_telegram_notices',
                    'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 19),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => $nextRunAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $stats['continuation_job_id'] = $nextJobId;
                $stats['message'] .= ' (continuation queued)';
            }
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = $e->getMessage();
        $stats['message'] = 'failure:' . substr($e->getMessage(), 0, 60);
        cfmod_report_exception('send_expiry_telegram_notices', $e);
    }

    return $stats;
}

function cfmod_job_cleanup_expired_subdomains($job, array $payload = []): array {
    $stats = [
        'processed_subdomains' => 0,
        'deleted' => 0,
        'failed' => 0,
        'warnings' => [],
        'cursor_start' => 0,
        'cursor_end' => 0,
    ];

    try {
        $runStartedAt = microtime(true);
        $settings = cfmod_get_settings();
        $graceRaw = $settings['domain_grace_period_days'] ?? ($settings['domain_auto_delete_grace_days'] ?? 45);
        $graceDays = is_numeric($graceRaw) ? (int) $graceRaw : 45;
        if ($graceDays < 0) {
            $graceDays = 0;
        }

        $redemptionDays = intval($settings['domain_redemption_days'] ?? 0);
        if ($redemptionDays < 0) {
            $redemptionDays = 0;
        }
        $redemptionCleanupDays = intval($settings['domain_redemption_cleanup_days'] ?? 0);
        if ($redemptionCleanupDays < 0) {
            $redemptionCleanupDays = 0;
        }
        $totalRetentionDays = $graceDays + $redemptionDays + $redemptionCleanupDays;

        $batchSize = intval($payload['batch_size'] ?? ($settings['domain_cleanup_batch_size'] ?? 50));
        if ($batchSize <= 0) {
            $batchSize = 50;
        }
        $batchSize = max(1, min(5000, $batchSize));
        $maxRuntimeSeconds = intval($payload['max_runtime_seconds'] ?? ($settings['domain_cleanup_max_runtime_seconds'] ?? 240));
        $maxRuntimeSeconds = max(30, min(3600, $maxRuntimeSeconds));

        $deepDeletePayload = !empty($payload['deep_delete']);
        $deepDeleteSetting = in_array(($settings['domain_cleanup_deep_delete'] ?? 'yes'), ['1','on','yes','true'], true);
        $deepDelete = $deepDeletePayload || $deepDeleteSetting;
        $bulkDirectMode = !empty($payload['bulk_direct_mode'])
            || in_array(strtolower((string) ($settings['domain_cleanup_bulk_direct_mode'] ?? '0')), ['1', 'on', 'yes', 'true', 'enabled'], true);

        $cursor = intval($payload['cursor_id'] ?? 0);
        if ($cursor < 0) {
            $cursor = 0;
        }
        $stats['cursor_start'] = $cursor;
        $shardTotal = intval($payload['shard_total'] ?? ($settings['domain_cleanup_shard_total'] ?? 1));
        $shardTotal = max(1, min(32, $shardTotal));
        $shardIndex = intval($payload['shard_index'] ?? 0);
        if ($shardIndex < 0) { $shardIndex = 0; }
        if ($shardIndex >= $shardTotal) { $shardIndex = $shardIndex % $shardTotal; }
        $rootdomainFilter = trim((string) ($payload['rootdomain'] ?? ''));

        $thresholdTs = time() - ($totalRetentionDays * 86400);
        $threshold = date('Y-m-d H:i:s', $thresholdTs);

        $expiredQuery = Capsule::table('mod_cloudflare_subdomain')
            ->where('id', '>', $cursor)
            ->where('never_expires', 0)
            ->whereNotNull('expires_at')
            ->whereNull('auto_deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')
                  ->orWhereNotIn('status', ['deleted', 'Deleted']);
            })
            ->where('expires_at', '<', $threshold)
            ->when($shardTotal > 1, function ($q) use ($shardTotal, $shardIndex) {
                $q->whereRaw('MOD(id, ?) = ?', [$shardTotal, $shardIndex]);
            })
            ->when($rootdomainFilter !== '', function ($q) use ($rootdomainFilter) {
                $q->where('rootdomain', $rootdomainFilter);
            })
            ->orderBy('id', 'asc')
            ->limit($batchSize + 1);

        $rowsRaw = $expiredQuery->get();
        if ($rowsRaw instanceof \Illuminate\Support\Collection) {
            $rowsRaw = $rowsRaw->all();
        }
        if (!is_array($rowsRaw)) {
            $rowsRaw = [];
        }

        if (empty($rowsRaw)) {
            $stats['cursor_end'] = $cursor;
            $stats['has_more'] = false;
            $stats['message'] = $cursor > 0 ? 'cleanup_cursor_completed' : 'nothing_to_cleanup';
            return $stats;
        }

        $hasMore = count($rowsRaw) > $batchSize;
        $records = $hasMore ? array_slice($rowsRaw, 0, $batchSize) : $rowsRaw;

        $stats['processed_subdomains'] = count($records);
        if ($stats['processed_subdomains'] === 0) {
            $stats['cursor_end'] = $cursor;
            $stats['has_more'] = false;
            $stats['message'] = 'nothing_to_cleanup';
            return $stats;
        }

        $nowStr = date('Y-m-d H:i:s');
        $precheckEnabledGlobal = in_array(strtolower((string) ($settings['domain_cleanup_remote_precheck_enabled'] ?? '0')), ['1', 'on', 'yes', 'true', 'enabled'], true);
        $localDnsCountById = [];
        try {
            $recordIds = array_values(array_filter(array_map(function ($r) { return intval($r->id ?? 0); }, $records), function ($id) { return $id > 0; }));
            if (!empty($recordIds)) {
                $rows = Capsule::table('mod_cloudflare_dns_records')
                    ->select('subdomain_id', Capsule::raw('COUNT(*) as c'))
                    ->whereIn('subdomain_id', $recordIds)
                    ->groupBy('subdomain_id')
                    ->get();
                foreach ($rows as $rowCount) {
                    $localDnsCountById[intval($rowCount->subdomain_id ?? 0)] = intval($rowCount->c ?? 0);
                }
            }
        } catch (\Throwable $e) {
        }

        $failures = [];
        $failedItems = [];
        $queuedCount = 0;
        $directDeletedCount = 0;
        $remoteEnqueueLimitRaw = intval($settings['cleanup_remote_enqueue_limit_per_run'] ?? 20);
        $remoteEnqueueLimit = ($remoteEnqueueLimitRaw <= 0) ? 0 : max(1, min(9999, $remoteEnqueueLimitRaw));
        $remoteEnqueuedThisRun = 0;
        $lastScannedId = $cursor;
        $preHandledDirectIds = [];
        $providerClientsForStrategy = [];
        $probeRatioPercent = intval($settings['domain_cleanup_no_dns_probe_ratio_percent'] ?? 60);
        $probeRatioPercent = max(0, min(100, $probeRatioPercent));
        $probeCountPerBatch = intval($settings['domain_cleanup_no_dns_probe_count_per_batch'] ?? 1);
        $probeCountPerBatch = max(0, min(10, $probeCountPerBatch));
        $probeSeedBucket = date('YmdHi');
        $noDnsRecordIds = [];
        foreach ($records as $recordProbe) {
            $ridProbe = intval($recordProbe->id ?? 0);
            if ($ridProbe <= 0) {
                continue;
            }
            if (intval($localDnsCountById[$ridProbe] ?? 0) <= 0) {
                $noDnsRecordIds[] = $ridProbe;
            }
        }
        $totalBatchCount = count($records);
        $noDnsCount = count($noDnsRecordIds);
        $batchNoDnsRatio = $totalBatchCount > 0 ? round(($noDnsCount * 100) / $totalBatchCount, 2) : 0.0;
        $probeTriggered = $probeCountPerBatch > 0
            && $noDnsCount > 0
            && ($noDnsCount === $totalBatchCount || $batchNoDnsRatio >= $probeRatioPercent);
        $probeSelectedMap = [];
        if ($probeTriggered) {
            usort($noDnsRecordIds, static function ($a, $b) use ($probeSeedBucket) {
                $ha = sprintf('%u', crc32($probeSeedBucket . ':' . $a));
                $hb = sprintf('%u', crc32($probeSeedBucket . ':' . $b));
                if ($ha === $hb) {
                    return $a <=> $b;
                }
                return ($ha < $hb) ? -1 : 1;
            });
            $pick = min($probeCountPerBatch, $noDnsCount);
            for ($i = 0; $i < $pick; $i++) {
                $probeSelectedMap[intval($noDnsRecordIds[$i])] = true;
            }
        }

        if ($bulkDirectMode) {
            $providerClients = [];
            $pdnsGroups = [];
            foreach ($records as $record) {
                $rid = intval($record->id ?? 0);
                if ($rid <= 0) {
                    continue;
                }
                $providerId = cfmod_worker_resolve_provider_account_id_for_subdomain($record, $settings);
                $providerContext = cfmod_worker_acquire_provider_client_cached($providerId, $settings, $providerClients, $stats, 'cleanup_bulk_plan');
                if (!$providerContext || empty($providerContext['client'])) {
                    continue;
                }
                $providerType = strtolower((string) (($providerContext['provider_type'] ?? $providerContext['provider']['provider_type'] ?? '') ?: ''));
                if (!in_array($providerType, ['powerdns', 'pdns'], true) || !method_exists($providerContext['client'], 'deleteDomainRecordsDeepBatch')) {
                    continue;
                }
                $zone = (string) (($record->cloudflare_zone_id ?? '') ?: ($record->rootdomain ?? ''));
                $name = (string) ($record->subdomain ?? '');
                if ($zone === '' || $name === '') {
                    continue;
                }
                if (!isset($pdnsGroups[$zone])) {
                    $pdnsGroups[$zone] = ['client' => $providerContext['client'], 'records' => []];
                }
                $pdnsGroups[$zone]['records'][$rid] = ['id' => $rid, 'name' => $name, 'row' => $record];
            }
            foreach ($pdnsGroups as $zone => $group) {
                $targets = array_values(array_map(function ($item) { return $item['name']; }, $group['records']));
                $startTs = microtime(true);
                $batchRes = $group['client']->deleteDomainRecordsDeepBatch($zone, $targets);
                $durationMs = (int) round((microtime(true) - $startTs) * 1000);
                if (!($batchRes['success'] ?? false)) {
                    foreach ($group['records'] as $item) {
                        $failedItems[] = [
                            'id' => $item['id'],
                            'subdomain' => $item['name'],
                            'zone' => $zone,
                            'rrset_count' => 0,
                            'duration_ms' => $durationMs,
                            'http_code' => intval($batchRes['http_code'] ?? 0),
                            'error' => substr((is_array($batchRes['errors'] ?? null) ? json_encode($batchRes['errors'], JSON_UNESCAPED_UNICODE) : (string) ($batchRes['errors'] ?? 'batch_delete_failed')), 0, 200),
                            'error_type' => 'pdns_batch',
                        ];
                    }
                    continue;
                }

                $matchedTargets = array_map('strtolower', (array) ($batchRes['matched_targets'] ?? []));
                $matchedLookup = array_fill_keys($matchedTargets, true);

                foreach ($group['records'] as $item) {
                    $targetKey = strtolower((string) $item['name']);
                    if (!isset($matchedLookup[$targetKey])) {
                        $failedItems[] = [
                            'id' => $item['id'],
                            'subdomain' => $item['name'],
                            'zone' => $zone,
                            'rrset_count' => 0,
                            'duration_ms' => $durationMs,
                            'http_code' => intval($batchRes['http_code'] ?? 200),
                            'error' => 'pdns_batch_target_unmatched',
                            'error_type' => 'pdns_batch_partial',
                        ];
                        continue;
                    }
                    $cleanupWarnings = cfmod_delete_local_subdomain_artifacts([$item['id']]);
                    foreach ($cleanupWarnings as $warn) { $stats['warnings'][] = $warn; }
                    cfmod_quota_refund_create_pending((int) ($item['row']->userid ?? 0), $item['id'], 'expired_cleanup');
                    $directDeletedCount++;
                    $preHandledDirectIds[$item['id']] = true;
                }

                $stats['pdns_batch'] = $stats['pdns_batch'] ?? ['zones' => 0, 'matched_targets' => 0, 'unmatched_targets' => 0];
                $stats['pdns_batch']['zones']++;
                $stats['pdns_batch']['matched_targets'] += count($matchedTargets);
                $stats['pdns_batch']['unmatched_targets'] += max(0, count($group['records']) - count($matchedTargets));
            }
            if ($directDeletedCount > 0) {
                cfmod_enqueue_quota_refund_job();
            }
        }

        foreach ($records as $record) {
            if ((microtime(true) - $runStartedAt) >= $maxRuntimeSeconds) {
                $stats['warnings'][] = 'runtime_limit_reached';
                $hasMore = true;
                break;
            }
            cfmod_worker_touch_progress();
            $recordId = intval($record->id ?? 0);
            if ($recordId <= 0) {
                $stats['warnings'][] = 'invalid_record_id';
                continue;
            }
            if ($recordId > $lastScannedId) {
                $lastScannedId = $recordId;
            }
            if ($bulkDirectMode && isset($preHandledDirectIds[$recordId])) {
                continue;
            }

            $userid = intval($record->userid ?? 0);
            $subdomainName = (string) ($record->subdomain ?? '');
            try {
                $zoneIdRaw = (string) (($record->cloudflare_zone_id ?? '') ?: ($record->rootdomain ?? ''));
                $localDnsCount = intval($localDnsCountById[$recordId] ?? 0);
                $allowLocalDeleteWhenNoDns = in_array(strtolower((string) ($settings['domain_cleanup_local_delete_when_no_dns'] ?? '0')), ['1', 'on', 'yes', 'true', 'enabled'], true);
                $strongConsistencyMode = in_array(strtolower((string) ($settings['domain_cleanup_strong_consistency_mode'] ?? '0')), ['1', 'on', 'yes', 'true', 'enabled'], true);
                $providerId = cfmod_worker_resolve_provider_account_id_for_subdomain($record, $settings);
                $providerType = '';
                $providerContextForStrategy = cfmod_worker_acquire_provider_client_cached($providerId, $settings, $providerClientsForStrategy, $stats, 'cleanup_provider_strategy');
                if ($providerContextForStrategy) {
                    $providerType = strtolower((string) (($providerContextForStrategy['provider_type'] ?? $providerContextForStrategy['provider']['provider_type'] ?? '') ?: ''));
                }
                $providerStrategy = cfmod_cleanup_provider_strategy($providerType, $settings);
                $providerForcedLocalFirst = in_array($providerStrategy, ['local_first', 'remote_relaxed'], true) && !$strongConsistencyMode;
                $globalLocalFirst = in_array(strtolower((string) ($settings['domain_cleanup_local_first_mode'] ?? '1')), ['1', 'on', 'yes', 'true', 'enabled'], true);
                if (($globalLocalFirst && !$strongConsistencyMode) || $providerForcedLocalFirst || ($precheckEnabledGlobal && $localDnsCount <= 0 && $zoneIdRaw === '') || ($allowLocalDeleteWhenNoDns && !$strongConsistencyMode && $localDnsCount <= 0)) {
                    if ($remoteEnqueueLimit > 0 && $remoteEnqueuedThisRun >= $remoteEnqueueLimit) {
                        $stats['warnings'][] = 'remote_enqueue_limit_reached';
                        $hasMore = true;
                        break;
                    }
                    $skipImmediateRemoteEnqueueConfigured = in_array(strtolower((string) ($settings['domain_cleanup_no_dns_skip_remote_enqueue'] ?? '0')), ['1', 'on', 'yes', 'true', 'enabled'], true);
                    $isProbeSelected = isset($probeSelectedMap[$recordId]);
                    $skipImmediateRemoteEnqueue = $skipImmediateRemoteEnqueueConfigured && $localDnsCount <= 0 && !$isProbeSelected;
                    $compQueued = false;
                    if (!$skipImmediateRemoteEnqueue) {
                        if ($remoteEnqueueLimit > 0 && $remoteEnqueuedThisRun >= $remoteEnqueueLimit) {
                            $stats['warnings'][] = 'remote_enqueue_limit_reached';
                            $hasMore = true;
                            break;
                        }
                        $compQueued = cfmod_enqueue_remote_cleanup_compensation_job($record, $job, $deepDelete, $settings);
                        if ($compQueued) { $remoteEnqueuedThisRun++; }
                    }
                    $cleanupWarnings = cfmod_delete_local_subdomain_artifacts([$recordId]);
                    foreach ($cleanupWarnings as $warn) { $stats['warnings'][] = $warn; }
                    cfmod_quota_refund_create_pending((int) ($record->userid ?? 0), $recordId, 'expired_cleanup');
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('cleanup_expired_local_only_no_dns', [
                            'subdomain' => $subdomainName,
                            'userid' => $userid,
                            'zone_id_empty' => $zoneIdRaw === '',
                            'compensation_job_queued' => $compQueued ? 1 : 0,
                            'skip_immediate_remote_enqueue' => $skipImmediateRemoteEnqueue ? 1 : 0,
                            'skip_immediate_remote_enqueue_configured' => $skipImmediateRemoteEnqueueConfigured ? 1 : 0,
                            'probe_triggered' => $probeTriggered ? 1 : 0,
                            'probe_ratio_percent' => $probeRatioPercent,
                            'probe_selected' => $isProbeSelected ? 1 : 0,
                            'batch_no_dns_ratio' => $batchNoDnsRatio,
                            'provider_type' => $providerType,
                            'provider_strategy' => $providerStrategy,
                            'strategy_enabled' => $allowLocalDeleteWhenNoDns ? 1 : 0,
                            'strong_consistency_mode' => $strongConsistencyMode ? 1 : 0,
                        ], $userid, $recordId);
                    }
                    $directDeletedCount++;
                    continue;
                }
                if ($bulkDirectMode) {
                    $rowNow = Capsule::table('mod_cloudflare_subdomain')->where('id', $recordId)->first();
                    if (!$rowNow) {
                        continue;
                    }
                    $directPayload = [
                        'subdomain_id' => $recordId,
                        'deep_delete' => $deepDelete ? 1 : 0,
                        'auto' => !empty($payload['auto']),
                    ];
                    $directOk = false;
                    $directError = '';
                    $retryMax = intval($settings['pdns_cleanup_retry_max'] ?? 2);
                    if ($retryMax <= 0) { $retryMax = 1; }
                    $retryMax = max(1, min(5, $retryMax));
                    for ($retry = 0; $retry < $retryMax; $retry++) {
                        $directStats = cfmod_job_cleanup_expired_subdomain_remote($job, $directPayload);
                        if (intval($directStats['deleted'] ?? 0) > 0 || ($directStats['message'] ?? '') === 'already_deleted') {
                            $directOk = true;
                            break;
                        }
                        if (stripos((string) ($directStats['message'] ?? ''), 'not found') !== false) {
                            break;
                        }
                        $directError = (string) ($directStats['message'] ?? 'direct_cleanup_failed');
                        if ($retry + 1 < $retryMax) {
                            $isRateLimited = cfmod_worker_is_rate_limited_text($directError);
                            $baseDelayMs = $isRateLimited ? 500 : 150;
                            $sleepMs = min(4000, $baseDelayMs * (1 << $retry));
                            usleep($sleepMs * 1000);
                        }
                    }
                    if ($directOk) {
                        $directDeletedCount++;
                    } else {
                        $failedItems[] = [
                            'id' => $recordId,
                            'subdomain' => $subdomainName,
                            'error' => substr($directError, 0, 120),
                            'error_type' => 'direct',
                        ];
                    }
                    continue;
                }
                $remoteLockAcquired = cfmod_cleanup_remote_job_lock_acquire($recordId);
                if (!$remoteLockAcquired) {
                    $stats['warnings'][] = 'remote_job_lock_exists:' . $recordId;
                    continue;
                }
                $hasSubdomainColumn = cfmod_jobs_support_subdomain_id();
                $recordIdStr = (string) $recordId;
                $hasActiveRemoteJobQuery = Capsule::table('mod_cloudflare_jobs')
                    ->where('type', 'cleanup_expired_subdomain_remote')
                    ->whereIn('status', ['pending', 'running']);
                if ($hasSubdomainColumn) {
                    $hasActiveRemoteJobQuery->where('subdomain_id', $recordId);
                } else {
                    $hasActiveRemoteJobQuery->where(function ($q) use ($recordId, $recordIdStr) {
                        $q->whereRaw('JSON_EXTRACT(payload_json, "$.subdomain_id") = ?', [$recordId])
                          ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.subdomain_id")) = ?', [$recordIdStr]);
                    });
                }
                $hasActiveRemoteJob = $hasActiveRemoteJobQuery->exists();
                if ($hasActiveRemoteJob) {
                    cfmod_cleanup_remote_job_lock_release($recordId);
                    continue;
                }
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $recordId)
                    ->update([
                        'status' => 'expired_pending_remote_cleanup',
                        'updated_at' => $nowStr,
                    ]);
                $jobInsert = [
                    'type' => 'cleanup_expired_subdomain_remote',
                    'payload_json' => json_encode([
                        'subdomain_id' => $recordId,
                        'deep_delete' => $deepDelete ? 1 : 0,
                        'auto' => !empty($payload['auto']),
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 9),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
                if ($hasSubdomainColumn) {
                    $jobInsert['subdomain_id'] = $recordId;
                }
                Capsule::table('mod_cloudflare_jobs')->insert($jobInsert);
                $queuedCount++;
                $remoteEnqueuedThisRun++;
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('cleanup_expired_subdomain_queued_remote', [
                        'subdomain' => $subdomainName,
                        'userid' => $userid,
                    ], $userid, $recordId);
                }
            } catch (\Throwable $queueEx) {
                cfmod_cleanup_remote_job_lock_release($recordId);
                $failures[] = [
                    'id' => $recordId,
                    'subdomain' => $subdomainName,
                    'error' => substr($queueEx->getMessage(), 0, 120),
                    'error_type' => 'queue',
                ];
            }
        }

        $stats['deleted'] = $bulkDirectMode ? $directDeletedCount : $queuedCount;
        $stats['failed'] = count($failures);
        if (!empty($failures)) {
            $stats['failures'] = array_slice($failures, 0, 20);
            $stats['warnings'][] = count($failures) > 20 ? 'partial_failures_truncated' : 'partial_failures';
        }
        if (!empty($failedItems)) {
            $stats['failed'] += count($failedItems);
            $stats['failed_items'] = array_slice($failedItems, 0, 50);
            $stats['warnings'][] = count($failedItems) > 50 ? 'failed_items_truncated' : 'failed_items_present';
        }

        $stats['cursor_end'] = $lastScannedId;

        if ($hasMore && $lastScannedId > $cursor) {
            $stats['has_more'] = true;
            try {
                $hasActiveContinuation = Capsule::table('mod_cloudflare_jobs')
                    ->where('type', 'cleanup_expired_subdomains')
                    ->whereIn('status', ['pending', 'running'])
                    ->whereRaw('JSON_EXTRACT(payload_json, "$.cursor_id") = ?', [$lastScannedId])
                    ->whereRaw('COALESCE(JSON_EXTRACT(payload_json, "$.shard_total"), 1) = ?', [$shardTotal])
                    ->whereRaw('COALESCE(JSON_EXTRACT(payload_json, "$.shard_index"), 0) = ?', [$shardIndex])
                    ->exists();
                if (!$hasActiveContinuation) {
                    Capsule::table('mod_cloudflare_jobs')->insert([
                        'type' => 'cleanup_expired_subdomains',
                        'payload_json' => json_encode([
                            'cursor_id' => $lastScannedId,
                            'batch_size' => $batchSize,
                            'max_runtime_seconds' => $maxRuntimeSeconds,
                            'deep_delete' => $deepDelete ? 1 : 0,
                            'auto' => !empty($payload['auto']),
                            'shard_total' => $shardTotal,
                            'shard_index' => $shardIndex,
                            'rootdomain' => $rootdomainFilter,
                        ], JSON_UNESCAPED_UNICODE),
                        'priority' => intval($job->priority ?? 9),
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_run_at' => null,
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr
                    ]);
                }
            } catch (\Throwable $queueMore) {
                $stats['warnings'][] = 'requeue_failed';
                cfmod_report_exception('cleanup_expired_subdomains_requeue', $queueMore);
            }
        } elseif ($hasMore && $lastScannedId <= $cursor) {
            $stats['has_more'] = false;
            $stats['warnings'][] = 'cursor_not_advanced';
        } else {
            $stats['has_more'] = false;
        }

        $stats['message'] = $bulkDirectMode
            ? ('bulk direct cleanup deleted ' . $directDeletedCount . ' expired subdomains')
            : ('queued remote cleanup for ' . $queuedCount . ' expired subdomains');
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'cleanup_failed';
        $stats['message'] = 'cleanup failed: ' . substr($e->getMessage(), 0, 120);
        cfmod_report_exception('cleanup_expired_subdomains', $e);
    }

    return $stats;
}
function cfmod_delete_local_subdomain_artifacts(array $subdomainIds): array {
    $uniqueIds = array_values(array_filter(array_unique(array_map('intval', $subdomainIds)), function ($value) {
        return $value > 0;
    }));
    if (empty($uniqueIds)) {
        return [];
    }

    $warnings = [];
    $tasks = [
        ['table' => 'mod_cloudflare_dns_records', 'column' => 'subdomain_id', 'warning' => 'cleanup_dns_records_failed', 'context' => 'cleanup_expired_dns_records'],
        ['table' => 'mod_cloudflare_domain_risk', 'column' => 'subdomain_id', 'warning' => 'cleanup_domain_risk_failed', 'context' => 'cleanup_expired_domain_risk'],
        ['table' => 'mod_cloudflare_risk_events', 'column' => 'subdomain_id', 'warning' => 'cleanup_risk_events_failed', 'context' => 'cleanup_expired_risk_events'],
        ['table' => 'mod_cloudflare_sync_results', 'column' => 'subdomain_id', 'warning' => 'cleanup_sync_results_failed', 'context' => 'cleanup_expired_sync_results'],
        ['table' => 'mod_cloudflare_domain_gifts', 'column' => 'subdomain_id', 'warning' => 'cleanup_domain_gifts_failed', 'context' => 'cleanup_expired_domain_gifts'],
        ['table' => 'mod_cloudflare_subdomain', 'column' => 'id', 'warning' => 'cleanup_subdomains_failed', 'context' => 'cleanup_expired_subdomain_delete'],
    ];

    foreach ($tasks as $task) {
        try {
            Capsule::table($task['table'])
                ->whereIn($task['column'], $uniqueIds)
                ->delete();
        } catch (\Throwable $e) {
            $warnings[] = $task['warning'];
            cfmod_report_exception($task['context'], $e);
        }
    }

    return array_values(array_unique($warnings));
}

function cfmod_job_cleanup_expired_subdomain_remote($job, array $payload = []): array {
    $stats = ['processed_subdomains' => 0, 'deleted' => 0, 'failed' => 0, 'warnings' => []];
    $subdomainId = max(0, intval($payload['subdomain_id'] ?? 0));
    if ($subdomainId <= 0) {
        $stats['message'] = 'invalid_subdomain_id';
        return $stats;
    }
    try {
        $settings = cfmod_get_settings();
        $deepDeletePayload = !empty($payload['deep_delete']);
        $deepDeleteSetting = in_array(($settings['domain_cleanup_deep_delete'] ?? 'yes'), ['1','on','yes','true'], true);
        $deepDelete = $deepDeletePayload || $deepDeleteSetting;
        $row = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->first();
        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
        if (!$row && empty($snapshot)) {
            $stats['message'] = 'already_deleted';
            return $stats;
        }
        $cancelledDuplicates = Capsule::table('mod_cloudflare_jobs')
            ->where('type', 'cleanup_expired_subdomain_remote')
            ->where('id', '<>', intval($job->id ?? 0))
            ->where('status', 'pending')
            ->where(function ($q) use ($subdomainId) {
                $q->whereRaw('JSON_EXTRACT(payload_json, "$.subdomain_id") = ?', [$subdomainId])
                  ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.subdomain_id")) = ?', [(string) $subdomainId]);
            })
            ->update([
                'status' => 'cancelled',
                'last_error' => 'cancelled duplicate by running cleanup job',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($cancelledDuplicates > 0) {
            $stats['warnings'][] = 'cancelled_duplicate_jobs:' . intval($cancelledDuplicates);
        }
        $stats['processed_subdomains'] = 1;
        $zoneId = $row ? ($row->cloudflare_zone_id ?: ($row->rootdomain ?? '')) : (string) ($snapshot['zone_id'] ?? '');
        $subdomainName = $row ? (string) ($row->subdomain ?? '') : (string) ($snapshot['subdomain'] ?? '');
        $providerId = $row
            ? cfmod_worker_resolve_provider_account_id_for_subdomain($row, $settings)
            : intval($snapshot['provider_id'] ?? 0);
        $providerCache = [];
        $providerContext = cfmod_worker_acquire_provider_client_cached($providerId, $settings, $providerCache, $stats, 'cleanup_expired_remote');
        if (!$providerContext) {
            // Provider control-plane unavailable: if no local DNS artifacts remain, allow local cleanup to complete.
            $localDnsCount = 0;
            try {
                $localDnsCount = (int) Capsule::table('mod_cloudflare_dns_records')
                    ->where('subdomain_id', $subdomainId)
                    ->count();
            } catch (\Throwable $e) {
                $localDnsCount = 1;
            }
            if ($localDnsCount > 0) {
                throw new \RuntimeException('provider_unavailable');
            }
            $stats['warnings'][] = 'provider_unavailable_local_only_cleanup';
            $cleanupWarnings = cfmod_delete_local_subdomain_artifacts([$subdomainId]);
            foreach ($cleanupWarnings as $warn) {
                $stats['warnings'][] = $warn;
            }
            cfmod_quota_refund_create_pending((int) ($row->userid ?? ($snapshot['userid'] ?? 0)), $subdomainId, 'expired_cleanup');
            cfmod_enqueue_quota_refund_job();
            $stats['deleted'] = 1;
            $stats['message'] = 'local_cleanup_done_provider_unavailable';
            return $stats;
        }
        $cf = $providerContext['client'];
        cfmod_cleanup_apply_client_timeout($cf, $settings);
        $providerType = strtolower((string) (($providerContext['provider_type'] ?? $providerContext['provider']['provider_type'] ?? '') ?: ''));
        $providerCircuitKey = ($providerType !== '' ? $providerType : 'provider') . ':' . intval($providerId);
        if (cfmod_cleanup_circuit_is_open($providerCircuitKey, $settings)) {
            throw new \RuntimeException('provider_unavailable:circuit_open');
        }
        $hardTimeoutSeconds = intval($settings['cleanup_remote_hard_timeout_seconds'] ?? 20);
        $hardTimeoutSeconds = max(5, min(120, $hardTimeoutSeconds));
        $remoteHasRecords = true;
        $precheckEnabled = in_array(strtolower((string) ($settings['domain_cleanup_remote_precheck_enabled'] ?? '0')), ['1', 'on', 'yes', 'true', 'enabled'], true);
        if ($precheckEnabled && $zoneId && function_exists('cfmod_admin_verify_subdomain_remote_empty')) {
            try {
                $remoteHasRecords = !cfmod_admin_verify_subdomain_remote_empty($cf, (string) $zoneId, $subdomainName);
            } catch (\Throwable $e) {
                $remoteHasRecords = true;
            }
        }
        if ($zoneId && $remoteHasRecords) {
            $dnsSnapshot = is_array($snapshot['dns_records'] ?? null) ? $snapshot['dns_records'] : [];
            $result = ['success' => true];
            if (!empty($dnsSnapshot) && method_exists($cf, 'deleteSubdomain')) {
                foreach ($dnsSnapshot as $dnsRec) {
                    $recId = trim((string) ($dnsRec['record_id'] ?? ''));
                    if ($recId === '') {
                        continue;
                    }
                    $one = $cf->deleteSubdomain((string) $zoneId, $recId, [
                        'name' => $dnsRec['name'] ?? null,
                        'type' => $dnsRec['type'] ?? null,
                        'content' => $dnsRec['content'] ?? null,
                    ]);
                    if (!($one['success'] ?? false)) {
                        $result = $one;
                        break;
                    }
                }
            } else {
                $deleteStart = microtime(true);
                $result = $cf->deleteDomainRecords($zoneId, $subdomainName);
                $deleteDuration = microtime(true) - $deleteStart;
                if ($deleteDuration > $hardTimeoutSeconds) {
                    $result = ['success' => false, 'errors' => ['timeout' => 'deleteDomainRecords overtime:' . round($deleteDuration, 3) . 's']];
                }
            }
            $ok = (bool) ($result['success'] ?? false);
            $shouldDeepFallback = false;
            if (!in_array(strtolower((string) ($settings['cleanup_remote_disable_deep_delete'] ?? '1')), ['1', 'on', 'yes', 'true', 'enabled'], true)) {
                $shouldDeepFallback = $deepDelete || in_array($providerType, ['powerdns', 'pdns', 'aliyun', 'ali', 'alidns'], true);
            }
            if ($shouldDeepFallback && in_array($providerType, ['powerdns', 'pdns'], true)) {
                $zoneThreshold = intval($settings['domain_cleanup_pdns_deep_zone_threshold'] ?? 3000);
                $zoneThreshold = max(100, min(200000, $zoneThreshold));
                try {
                    if (method_exists($cf, 'getZoneDetails')) {
                        $zoneDetail = $cf->getZoneDetails((string) $zoneId);
                        if (($zoneDetail['success'] ?? false) && isset($zoneDetail['result']['rrsets']) && is_array($zoneDetail['result']['rrsets'])) {
                            $rrsetCount = count($zoneDetail['result']['rrsets']);
                            if ($rrsetCount > $zoneThreshold) {
                                $shouldDeepFallback = false;
                                $stats['warnings'][] = 'pdns_deep_skipped_large_zone:' . $rrsetCount;
                            }
                        }
                    }
                } catch (\Throwable $zoneEx) {
                    $stats['warnings'][] = 'pdns_zone_detail_probe_failed';
                }
            }
            if ($shouldDeepFallback && method_exists($cf, 'deleteDomainRecordsDeep')) {
                $deepStart = microtime(true);
                $deepResult = $cf->deleteDomainRecordsDeep($zoneId, $subdomainName);
                $deepDuration = microtime(true) - $deepStart;
                if ($deepDuration > $hardTimeoutSeconds) {
                    $deepResult = ['success' => false, 'errors' => ['timeout' => 'deleteDomainRecordsDeep overtime:' . round($deepDuration, 3) . 's']];
                }
                $deepOk = (bool) ($deepResult['success'] ?? false);
                if (!$ok) {
                    $result = $deepResult;
                    $ok = $deepOk;
                } elseif (!$deepOk) {
                    // 如果基础删除成功但深度删除失败，优先暴露深度失败，避免子级记录残留
                    $result = $deepResult;
                    $ok = false;
                } else {
                    // 两者都成功时，采用深度结果用于统计/日志
                    $result = $deepResult;
                    $ok = true;
                }
            }
            if (!$ok) {
                $classified = cfmod_classify_remote_cleanup_error($result, 'remote_delete_failed');
                if (!empty($classified['idempotent_success'])) {
                    $stats['warnings'][] = 'remote_not_found_treated_as_success';
                    $stats['warnings'][] = 'error_class:' . $classified['class'];
                } else {
                    cfmod_cleanup_circuit_mark_failure($providerCircuitKey, (string) ($classified['class'] ?? 'unknown'), $settings);
                    throw new \RuntimeException((string) ($classified['message'] ?? 'remote_delete_failed'));
                }
            }
        }
        $cleanupWarnings = cfmod_delete_local_subdomain_artifacts([$subdomainId]);
        foreach ($cleanupWarnings as $warn) {
            $stats['warnings'][] = $warn;
        }
        cfmod_quota_refund_create_pending((int) ($row->userid ?? ($snapshot['userid'] ?? 0)), $subdomainId, 'expired_cleanup');
        cfmod_enqueue_quota_refund_job();
        $stats['deleted'] = 1;
        $stats['message'] = $row ? 'remote_and_local_cleanup_done' : 'remote_cleanup_done_snapshot';
    } catch (\Throwable $e) {
        $stats['failed'] = 1;
        $stats['warnings'][] = 'cleanup_remote_failed';
        $classified = cfmod_classify_remote_cleanup_error([], $e->getMessage());
        $stats['warnings'][] = 'error_class:' . $classified['class'];
        $stats['job_error_class'] = $classified['class'];
        $stats['job_retryable'] = !empty($classified['retryable']);
        $stats['job_error_message'] = (string) ($classified['message'] ?? 'cleanup_remote_failed');
        $stats['message'] = 'cleanup_remote_failed:' . substr((string) ($classified['message'] ?? $e->getMessage()), 0, 120);
    } finally {
        if ($subdomainId > 0) {
            cfmod_cleanup_remote_job_lock_release($subdomainId);
        }
    }
    return $stats;
}

function cfmod_enqueue_remote_cleanup_compensation_job($record, $parentJob, bool $deepDelete, array $settings = []): bool
{
    $lockName = null;
    try {
        $recordId = intval($record->id ?? 0);
        if ($recordId <= 0) {
            return false;
        }
        $providerId = cfmod_worker_resolve_provider_account_id_for_subdomain($record, $settings);
        $snapshot = [
            'subdomain_id' => $recordId,
            'subdomain' => (string) ($record->subdomain ?? ''),
            'zone_id' => (string) (($record->cloudflare_zone_id ?? '') ?: ($record->rootdomain ?? '')),
            'provider_id' => $providerId,
            'userid' => intval($record->userid ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        try {
            $dnsRows = Capsule::table('mod_cloudflare_dns_records')
                ->select('record_id', 'name', 'type', 'content', 'zone_id')
                ->where('subdomain_id', $recordId)
                ->get();
            $dnsList = [];
            foreach ($dnsRows as $dnsRow) {
                $dnsList[] = [
                    'record_id' => (string) ($dnsRow->record_id ?? ''),
                    'name' => (string) ($dnsRow->name ?? ''),
                    'type' => (string) ($dnsRow->type ?? ''),
                    'content' => (string) ($dnsRow->content ?? ''),
                    'zone_id' => (string) ($dnsRow->zone_id ?? ''),
                ];
            }
            $snapshot['dns_records'] = $dnsList;
        } catch (\Throwable $dnsEx) {
            $snapshot['dns_records'] = [];
        }
        $payload = [
            'subdomain_id' => $recordId,
            'deep_delete' => $deepDelete ? 1 : 0,
            'auto' => true,
            'compensation' => true,
            'snapshot' => $snapshot,
        ];
        $lockName = 'cfmod_cleanup_enqueue_' . $recordId;
        $lockAcquired = false;
        try {
            $row = Capsule::select('SELECT GET_LOCK(?, 2) AS l', [$lockName]);
            $lockAcquired = isset($row[0]->l) && intval($row[0]->l) === 1;
        } catch (\Throwable $lockEx) {
            $lockAcquired = false;
        }
        if (!$lockAcquired) {
            return false;
        }
        $exists = Capsule::table('mod_cloudflare_jobs')
            ->where('type', 'cleanup_expired_subdomain_remote')
            ->whereIn('status', ['pending', 'running'])
            ->where(function ($q) use ($recordId) {
                $q->whereRaw('JSON_EXTRACT(payload_json, "$.subdomain_id") = ?', [$recordId])
                  ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.subdomain_id")) = ?', [(string) $recordId]);
            })
            ->exists();
        if ($exists) {
            try { Capsule::select('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable $unlockEx) {}
            return true;
        }
        $now = date('Y-m-d H:i:s');
        $compensationPriority = intval($settings['domain_cleanup_compensation_priority'] ?? 2);
        $compensationPriority = max(1, min(8, $compensationPriority));
        Capsule::table('mod_cloudflare_jobs')->insert([
            'type' => 'cleanup_expired_subdomain_remote',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'priority' => $compensationPriority,
            'status' => 'pending',
            'attempts' => 0,
            'next_run_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        try { Capsule::select('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable $unlockEx) {}
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('cleanup_expired_compensation_queued', [
                'subdomain' => $snapshot['subdomain'],
                'zone_id' => $snapshot['zone_id'],
                'provider_id' => $providerId,
            ], intval($snapshot['userid']), $recordId);
        }
        return true;
    } catch (\Throwable $e) {
        if ($lockName) {
            try { Capsule::select('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable $unlockEx) {}
        }
        cfmod_report_exception('enqueue_remote_cleanup_compensation', $e);
        return false;
    }
}

function cfmod_cleanup_provider_strategy(string $providerType, array $settings = []): string
{
    $providerType = strtolower(trim($providerType));
    if ($providerType === 'pdns') {
        $providerType = 'powerdns';
    }
    $defaultStrategy = in_array($providerType, ['powerdns'], true) ? 'local_first' : 'remote_strong';
    $raw = strtolower(trim((string) ($settings['domain_cleanup_provider_strategy'] ?? '')));
    if ($raw === '') {
        return $defaultStrategy;
    }
    $rules = array_filter(array_map('trim', explode(',', $raw)), static function ($v) { return $v !== ''; });
    foreach ($rules as $rule) {
        $parts = array_map('trim', explode(':', $rule, 2));
        if (count($parts) !== 2) {
            continue;
        }
        if (strtolower($parts[0]) !== $providerType) {
            continue;
        }
        $strategy = strtolower($parts[1]);
        if (in_array($strategy, ['local_first', 'remote_strong', 'remote_relaxed'], true)) {
            return $strategy;
        }
    }
    return $defaultStrategy;
}

function cfmod_classify_remote_cleanup_error(array $result = [], string $fallbackMessage = ''): array
{
    $raw = '';
    if (!empty($result)) {
        $errors = $result['errors'] ?? null;
        if (is_array($errors)) {
            $raw = json_encode($errors, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($errors)) {
            $raw = $errors;
        }
        if ($raw === '') {
            $raw = (string) ($result['message'] ?? '');
        }
        if ($raw === '') {
            $raw = (string) ($result['error'] ?? '');
        }
    }
    if ($raw === '') {
        $raw = $fallbackMessage;
    }
    $normalized = strtolower(trim((string) $raw));
    $classified = [
        'class' => 'unknown',
        'retryable' => true,
        'idempotent_success' => false,
        'message' => $raw !== '' ? $raw : 'remote_delete_failed',
    ];
    if ($normalized === '') {
        return $classified;
    }
    if (strpos($normalized, 'not found') !== false || strpos($normalized, 'notfound') !== false || strpos($normalized, 'no such') !== false || strpos($normalized, 'already deleted') !== false || strpos($normalized, 'record does not exist') !== false) {
        $classified['class'] = 'not_found';
        $classified['retryable'] = false;
        $classified['idempotent_success'] = true;
        return $classified;
    }
    if (strpos($normalized, 'rate limit') !== false || strpos($normalized, 'too many request') !== false || strpos($normalized, 'throttl') !== false || strpos($normalized, '429') !== false) {
        $classified['class'] = 'rate_limit';
        return $classified;
    }
    if (strpos($normalized, 'timeout') !== false || strpos($normalized, 'timed out') !== false || strpos($normalized, 'connection reset') !== false || strpos($normalized, 'network') !== false) {
        $classified['class'] = 'network_timeout';
        return $classified;
    }
    if (strpos($normalized, 'unauthorized') !== false || strpos($normalized, 'forbidden') !== false || strpos($normalized, 'invalid token') !== false || strpos($normalized, 'permission denied') !== false || strpos($normalized, 'auth') !== false) {
        $classified['class'] = 'auth';
        $classified['retryable'] = false;
        return $classified;
    }
    if (strpos($normalized, 'provider_unavailable') !== false || strpos($normalized, 'service unavailable') !== false || strpos($normalized, 'temporarily unavailable') !== false || strpos($normalized, 'unavailable') !== false) {
        $classified['class'] = 'provider_unavailable';
        return $classified;
    }
    if (strpos($normalized, 'invalid') !== false || strpos($normalized, 'bad request') !== false || strpos($normalized, 'malformed') !== false) {
        $classified['class'] = 'invalid_request';
        $classified['retryable'] = false;
        return $classified;
    }
    return $classified;
}

function cfmod_job_cleanup_local_only_compensation($job, array $payload = []): array
{
    $stats = ['processed_subdomains' => 0, 'deleted' => 0, 'failed' => 0, 'warnings' => []];
    try {
        $settings = cfmod_get_settings();
        $sampleRate = intval($payload['sample_rate'] ?? 10);
        $sampleRate = max(1, min(100, $sampleRate));
        $sampleLimit = intval($payload['sample_limit'] ?? 200);
        $sampleLimit = max(10, min(2000, $sampleLimit));
        $forceFull = !empty($payload['force_full']);
        $residualThresholdPercent = intval($payload['residual_threshold_percent'] ?? ($settings['domain_cleanup_compensation_residual_threshold_percent'] ?? 40));
        $residualThresholdPercent = max(1, min(100, $residualThresholdPercent));
        $scanCap = intval($payload['scan_cap'] ?? ($settings['domain_cleanup_compensation_force_full_scan_cap'] ?? 10000));
        $scanCap = max(100, min(500000, $scanCap));
        $since = date('Y-m-d H:i:s', time() - 24 * 3600);
        $rows = Capsule::table('mod_cloudflare_logs')
            ->select('subdomain_id')
            ->where('action', 'cleanup_expired_local_only_no_dns')
            ->whereNotNull('subdomain_id')
            ->where('created_at', '>=', $since)
            ->orderBy('id', 'desc')
            ->limit($forceFull ? $scanCap : ($sampleLimit * 3))
            ->get();
        $candidateIds = [];
        foreach ($rows as $row) {
            $sid = intval($row->subdomain_id ?? 0);
            if ($sid > 0 && !in_array($sid, $candidateIds, true)) {
                $candidateIds[] = $sid;
            }
        }
        if (empty($candidateIds)) {
            $stats['message'] = 'no_candidates';
            return $stats;
        }
        if ($forceFull) {
            $sampleIds = array_slice($candidateIds, 0, $scanCap);
        } else {
            shuffle($candidateIds);
            $sampleCount = max(1, min(count($candidateIds), intval(ceil(count($candidateIds) * $sampleRate / 100))));
            $sampleCount = min($sampleCount, $sampleLimit);
            $sampleIds = array_slice($candidateIds, 0, $sampleCount);
        }
        $stats['processed_subdomains'] = count($sampleIds);
        foreach ($sampleIds as $subId) {
            $res = cfmod_job_cleanup_expired_subdomain_remote($job, ['subdomain_id' => $subId, 'deep_delete' => 1, 'auto' => true, 'compensation' => true]);
            if (intval($res['deleted'] ?? 0) > 0 || ($res['message'] ?? '') === 'already_deleted') {
                $stats['deleted']++;
            } else {
                $stats['failed']++;
                $stats['warnings'][] = 'compensation_failed:' . $subId;
            }
        }
        if (!$forceFull && $stats['processed_subdomains'] > 0) {
            $failedPercent = (int) floor(($stats['failed'] * 100) / max(1, $stats['processed_subdomains']));
            $stats['failed_percent'] = $failedPercent;
            if ($failedPercent >= $residualThresholdPercent) {
                $hasPendingForceFull = Capsule::table('mod_cloudflare_jobs')
                    ->where('type', 'cleanup_local_only_compensation')
                    ->whereIn('status', ['pending', 'running'])
                    ->where(function ($q) {
                        $q->whereRaw('JSON_EXTRACT(payload_json, "$.force_full") = true')
                          ->orWhereRaw('JSON_EXTRACT(payload_json, "$.force_full") = 1')
                          ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.force_full")) = ?', ['1'])
                          ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.force_full")) = ?', ['true']);
                    })
                    ->exists();
                if ($hasPendingForceFull) {
                    $stats['warnings'][] = 'force_full_already_pending';
                } else {
                    $now = date('Y-m-d H:i:s');
                    Capsule::table('mod_cloudflare_jobs')->insert([
                        'type' => 'cleanup_local_only_compensation',
                        'payload_json' => json_encode([
                            'force_full' => 1,
                            'scan_cap' => $scanCap,
                            'residual_threshold_percent' => $residualThresholdPercent,
                            'auto' => true,
                        ], JSON_UNESCAPED_UNICODE),
                        'priority' => max(1, intval(($job->priority ?? 11) - 1)),
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_run_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $stats['warnings'][] = 'force_full_enqueued';
                }
            }
        }
        $stats['message'] = 'compensation_completed';
    } catch (\Throwable $e) {
        $stats['failed']++;
        $stats['warnings'][] = 'compensation_exception';
        $stats['message'] = 'compensation_failed:' . substr($e->getMessage(), 0, 120);
        cfmod_report_exception('cleanup_local_only_compensation', $e);
    }
    return $stats;
}

function cfmod_cleanup_remote_job_lock_acquire(int $subdomainId): bool {
    if ($subdomainId <= 0) {
        return false;
    }
    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_job_locks')) {
            return true;
        }
        $now = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - 6 * 3600);
        $lockKey = 'cleanup_expired_subdomain_remote:' . $subdomainId;
        Capsule::table('mod_cloudflare_job_locks')
            ->where('lock_key', $lockKey)
            ->where('updated_at', '<', $staleBefore)
            ->delete();
        Capsule::table('mod_cloudflare_job_locks')->insert([
            'lock_key' => $lockKey,
            'job_type' => 'cleanup_expired_subdomain_remote',
            'scope_key' => (string) $subdomainId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function cfmod_cleanup_remote_job_lock_release(int $subdomainId): void {
    if ($subdomainId <= 0) {
        return;
    }
    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_job_locks')) {
            return;
        }
        Capsule::table('mod_cloudflare_job_locks')
            ->where('lock_key', 'cleanup_expired_subdomain_remote:' . $subdomainId)
            ->delete();
    } catch (\Throwable $e) {
    }
}

function cfmod_quota_refund_create_pending(int $userId, int $subdomainId, string $reason): void {
    if ($userId <= 0 || $subdomainId <= 0) {
        return;
    }
    if (!cfmod_ensure_quota_refund_tables()) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $idempotencyKey = 'refund:subdomain:' . $subdomainId;
    Capsule::table('mod_cloudflare_quota_refunds')->updateOrInsert(
        ['idempotency_key' => $idempotencyKey],
        [
            'userid' => $userId,
            'subdomain_id' => $subdomainId,
            'reason' => substr($reason, 0, 64),
            'status' => 'quota_refund_pending',
            'last_error' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ]
    );
}

function cfmod_enqueue_quota_refund_job(): void {
    $exists = Capsule::table('mod_cloudflare_jobs')
        ->where('type', 'process_quota_refunds')
        ->whereIn('status', ['pending', 'running'])
        ->exists();
    if ($exists) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_cloudflare_jobs')->insert([
        'type' => 'process_quota_refunds',
        'payload_json' => json_encode(['auto' => true], JSON_UNESCAPED_UNICODE),
        'priority' => 7,
        'status' => 'pending',
        'attempts' => 0,
        'next_run_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cfmod_job_process_quota_refunds($job, array $payload = []): array {
    $stats = ['processed' => 0, 'done' => 0, 'failed' => 0, 'warnings' => []];
    $now = date('Y-m-d H:i:s');
    $limit = max(10, min(500, intval($payload['batch_size'] ?? 100)));
    try {
        if (!cfmod_ensure_quota_refund_tables()) {
            $stats['warnings'][] = 'subdomain_quota_table_missing';
            $stats['message'] = 'subdomain_quota_table_missing';
            return $stats;
        }
        $rows = Capsule::table('mod_cloudflare_quota_refunds')
            ->where('status', 'quota_refund_pending')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
        foreach ($rows as $row) {
            $stats['processed']++;
            $refundId = intval($row->id ?? 0);
            $userId = intval($row->userid ?? 0);
            if ($refundId <= 0 || $userId <= 0) {
                continue;
            }
            try {
                Capsule::transaction(function () use ($userId, $refundId, $now, &$stats) {
                    $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->lockForUpdate()->first();
                    if (!$quota) {
                        $insert = [
                            'userid' => $userId,
                            'used_count' => 0,
                            'max_count' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $schema = Capsule::schema();
                        if ($schema->hasColumn('mod_cloudflare_subdomain_quotas', 'invite_bonus_count')) {
                            $insert['invite_bonus_count'] = 0;
                        }
                        if ($schema->hasColumn('mod_cloudflare_subdomain_quotas', 'invite_bonus_limit')) {
                            $insert['invite_bonus_limit'] = 0;
                        }
                        Capsule::table('mod_cloudflare_subdomain_quotas')->insert($insert);
                        $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->lockForUpdate()->first();
                        if (!$quota) {
                            throw new \RuntimeException('quota_row_create_failed');
                        }
                    }
                    $used = max(0, intval($quota->used_count ?? 0));
                    $newUsed = $used > 0 ? ($used - 1) : 0;
                    Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->update([
                        'used_count' => $newUsed,
                        'updated_at' => $now,
                    ]);
                    Capsule::table('mod_cloudflare_quota_refunds')->where('id', $refundId)->update([
                        'status' => 'quota_refund_done',
                        'updated_at' => $now,
                        'last_error' => null,
                    ]);
                    $stats['done']++;
                });
            } catch (\Throwable $e) {
                $stats['failed']++;
                Capsule::table('mod_cloudflare_quota_refunds')->where('id', $refundId)->update([
                    'last_error' => substr($e->getMessage(), 0, 255),
                    'updated_at' => $now,
                ]);
            }
        }
        $stats['message'] = 'processed ' . $stats['processed'] . ', done ' . $stats['done'] . ', failed ' . $stats['failed'];
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'quota_refund_batch_failed';
        $stats['message'] = 'quota_refund_batch_failed: ' . substr($e->getMessage(), 0, 180);
    }
    return $stats;
}

function cfmod_ensure_quota_refund_tables(): bool {
    try {
        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_cloudflare_subdomain_quotas')) {
            return false;
        }
        if (!$schema->hasTable('mod_cloudflare_quota_refunds')) {
            $schema->create('mod_cloudflare_quota_refunds', function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned();
                $table->integer('subdomain_id')->unsigned();
                $table->string('reason', 64)->default('expired_cleanup');
                $table->string('idempotency_key', 128)->unique();
                $table->string('status', 32)->default('quota_refund_pending');
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
                $table->index('userid');
                $table->index('subdomain_id');
                $table->index('status');
            });
        }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function cfmod_job_cleanup_api_logs($job, array $payload = []): array {
    $stats = [
        'deleted' => 0,
        'warnings' => [],
    ];
    try {
        $settings = cfmod_get_settings();
        $days = intval($payload['retention_days'] ?? ($settings['api_logs_retention_days'] ?? 30));
        if ($days <= 0) {
            $stats['message'] = 'api log cleanup disabled';
            return $stats;
        }
        $days = max(1, min(365, $days));
        $threshold = date('Y-m-d H:i:s', time() - $days * 86400);
        $deleted = Capsule::table('mod_cloudflare_api_logs')->where('created_at','<',$threshold)->delete();
        try { Capsule::statement('OPTIMIZE TABLE `mod_cloudflare_api_logs`'); } catch (\Throwable $e) {
            $stats['warnings'][] = 'optimize_failed';
            cfmod_report_exception('cleanup_api_logs_optimize', $e);
        }

        $rateDeleted = 0;
        try {
            if (Capsule::schema()->hasTable('mod_cloudflare_api_rate_limit')) {
                $cutoffRate = date('Y-m-d H:i:s', time() - 2 * 86400);
                $rateDeleted = Capsule::table('mod_cloudflare_api_rate_limit')
                    ->where('window_end', '<', $cutoffRate)
                    ->delete();
                if ($rateDeleted > 0) {
                    try { Capsule::statement('OPTIMIZE TABLE `mod_cloudflare_api_rate_limit`'); } catch (\Throwable $e) {
                        $stats['warnings'][] = 'optimize_rate_limit_failed';
                        cfmod_report_exception('cleanup_api_rate_limit_optimize', $e);
                    }
                }
            }
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'rate_limit_cleanup_failed';
            cfmod_report_exception('cleanup_api_rate_limit', $e);
        }

        $stats['deleted'] = $deleted;
        if ($rateDeleted > 0) {
            $stats['deleted_rate_windows'] = $rateDeleted;
        }
        $message = 'cleaned '.$deleted.' api logs older than '.$days.' days';
        if ($rateDeleted > 0) {
            $message .= '; removed '.$rateDeleted.' rate windows';
        }
        $stats['message'] = $message;
        $stats['processed_subdomains'] = 0;
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'cleanup_failed';
        $stats['message'] = 'api log cleanup failed';
        cfmod_report_exception('cleanup_api_logs', $e);
    }
    return $stats;
}

function cfmod_job_cleanup_general_logs($job, array $payload = []): array {
    $stats = [
        'deleted' => 0,
        'warnings' => [],
    ];
    try {
        $settings = cfmod_get_settings();
        $retention = intval($payload['retention_days'] ?? ($settings['general_logs_retention_days'] ?? 0));
        if ($retention <= 0) {
            $stats['message'] = 'general log cleanup disabled';
            return $stats;
        }
        $batchLimit = intval($payload['batch_limit'] ?? ($settings['general_logs_cleanup_batch_limit'] ?? 2000));
        if ($batchLimit <= 0) { $batchLimit = 2000; }
        $batchLimit = max(1, min(9999999, $batchLimit));
        $cutoff = date('Y-m-d H:i:s', time() - $retention * 86400);

        $rowsRaw = Capsule::table('mod_cloudflare_logs')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id', 'asc')
            ->limit($batchLimit + 1)
            ->get();
        if ($rowsRaw instanceof \Illuminate\Support\Collection) {
            $rowsRaw = $rowsRaw->all();
        }
        $rowCount = is_array($rowsRaw) ? count($rowsRaw) : 0;
        if ($rowCount === 0) {
            $stats['message'] = 'no general logs to cleanup';
            return $stats;
        }
        $hasMore = $rowCount > $batchLimit;
        $batchRows = $hasMore ? array_slice($rowsRaw, 0, $batchLimit) : $rowsRaw;
        $ids = [];
        foreach ($batchRows as $row) {
            $id = is_object($row) ? ($row->id ?? null) : ($row['id'] ?? null);
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if (empty($ids)) {
            $stats['message'] = 'no general logs to cleanup';
            return $stats;
        }
        $deleted = Capsule::table('mod_cloudflare_logs')->whereIn('id', $ids)->delete();
        $stats['deleted'] = $deleted;
        $stats['message'] = 'deleted '.$deleted.' general logs older than '.$retention.' days';
        if ($hasMore) {
            $stats['has_more'] = true;
            try {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_general_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $retention,
                        'batch_limit' => $batchLimit,
                        'auto' => !empty($payload['auto'])
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 5),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'enqueue_failed';
                cfmod_report_exception('cleanup_general_logs_enqueue', $e);
            }
        }
        if ($deleted > 0 && function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('cleanup_general_logs', [
                'deleted' => $deleted,
                'cutoff' => $cutoff,
                'has_more' => $hasMore ? 1 : 0
            ]);
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'cleanup_failed';
        $stats['message'] = 'general log cleanup failed';
        cfmod_report_exception('cleanup_general_logs', $e);
    }
    return $stats;
}

function cfmod_job_cleanup_dig_logs($job, array $payload = []): array {
    $stats = [
        'deleted' => 0,
        'warnings' => [],
    ];
    try {
        $settings = cfmod_get_settings();
        $retention = intval($payload['retention_days'] ?? ($settings['dig_logs_retention_days'] ?? 30));
        if ($retention <= 0) {
            $stats['message'] = 'dig log cleanup disabled';
            return $stats;
        }
        $retention = max(1, min(365, $retention));

        $batchLimit = intval($payload['batch_limit'] ?? 2000);
        if ($batchLimit <= 0) {
            $batchLimit = 2000;
        }
        $batchLimit = max(100, min(5000, $batchLimit));

        $cutoff = date('Y-m-d H:i:s', time() - $retention * 86400);
        $rowsRaw = Capsule::table('mod_cloudflare_logs')
            ->where('action', 'client_dig_lookup')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id', 'asc')
            ->limit($batchLimit + 1)
            ->get();

        if ($rowsRaw instanceof \Illuminate\Support\Collection) {
            $rowsRaw = $rowsRaw->all();
        }
        $rowCount = is_array($rowsRaw) ? count($rowsRaw) : 0;
        if ($rowCount === 0) {
            $stats['message'] = 'no dig logs to cleanup';
            return $stats;
        }

        $hasMore = $rowCount > $batchLimit;
        $batchRows = $hasMore ? array_slice($rowsRaw, 0, $batchLimit) : $rowsRaw;
        $ids = [];
        foreach ($batchRows as $row) {
            $id = is_object($row) ? ($row->id ?? null) : ($row['id'] ?? null);
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if (empty($ids)) {
            $stats['message'] = 'no dig logs to cleanup';
            return $stats;
        }

        $deleted = Capsule::table('mod_cloudflare_logs')->whereIn('id', $ids)->delete();
        $stats['deleted'] = $deleted;
        $stats['message'] = 'deleted ' . $deleted . ' dig logs older than ' . $retention . ' days';

        if ($hasMore) {
            $stats['has_more'] = true;
            try {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_dig_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $retention,
                        'batch_limit' => $batchLimit,
                        'auto' => !empty($payload['auto']),
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 5),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'enqueue_failed';
                cfmod_report_exception('cleanup_dig_logs_enqueue', $e);
            }
        }

        if ($deleted > 0 && function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('cleanup_dig_logs', [
                'deleted' => $deleted,
                'cutoff' => $cutoff,
                'has_more' => $hasMore ? 1 : 0,
            ]);
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'cleanup_failed';
        $stats['message'] = 'dig log cleanup failed';
        cfmod_report_exception('cleanup_dig_logs', $e);
    }

    return $stats;
}

function cfmod_job_cleanup_sync_logs($job, array $payload = []): array {
    $stats = [
        'deleted' => 0,
        'warnings' => [],
    ];
    try {
        $settings = cfmod_get_settings();
        $retention = intval($payload['retention_days'] ?? ($settings['sync_logs_retention_days'] ?? 0));
        if ($retention <= 0) {
            $stats['message'] = 'sync log cleanup disabled';
            return $stats;
        }
        $batchLimit = intval($payload['batch_limit'] ?? 2000);
        if ($batchLimit <= 0) { $batchLimit = 2000; }
        $batchLimit = max(100, min(5000, $batchLimit));
        $cutoff = date('Y-m-d H:i:s', time() - $retention * 86400);

        $rowsRaw = Capsule::table('mod_cloudflare_sync_results')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id', 'asc')
            ->limit($batchLimit + 1)
            ->get();
        if ($rowsRaw instanceof \Illuminate\Support\Collection) {
            $rowsRaw = $rowsRaw->all();
        }
        $rowCount = is_array($rowsRaw) ? count($rowsRaw) : 0;
        if ($rowCount === 0) {
            $stats['message'] = 'no sync logs to cleanup';
            return $stats;
        }
        $hasMore = $rowCount > $batchLimit;
        $batchRows = $hasMore ? array_slice($rowsRaw, 0, $batchLimit) : $rowsRaw;
        $ids = [];
        foreach ($batchRows as $row) {
            $id = is_object($row) ? ($row->id ?? null) : ($row['id'] ?? null);
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if (empty($ids)) {
            $stats['message'] = 'no sync logs to cleanup';
            return $stats;
        }
        $deleted = Capsule::table('mod_cloudflare_sync_results')->whereIn('id', $ids)->delete();
        $stats['deleted'] = $deleted;
        $stats['message'] = 'deleted '.$deleted.' sync logs older than '.$retention.' days';
        if ($hasMore) {
            $stats['has_more'] = true;
            try {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_sync_logs',
                    'payload_json' => json_encode([
                        'retention_days' => $retention,
                        'batch_limit' => $batchLimit,
                        'auto' => !empty($payload['auto'])
                    ], JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 6),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'enqueue_failed';
                cfmod_report_exception('cleanup_sync_logs_enqueue', $e);
            }
        }
        if ($deleted > 0 && function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('cleanup_sync_logs', [
                'deleted' => $deleted,
                'cutoff' => $cutoff,
                'has_more' => $hasMore ? 1 : 0
            ]);
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'cleanup_failed';
        $stats['message'] = 'sync log cleanup failed';
        cfmod_report_exception('cleanup_sync_logs', $e);
    }
    return $stats;
}

function cfmod_job_cleanup_user_subdomains($job, array $payload = []): array {
    $userid = intval($payload['userid'] ?? 0);
    if ($userid <= 0) {
        throw new \RuntimeException('cleanup_user_subdomains requires userid');
    }
    $deleteRecords = !empty($payload['delete_records']);
    $deleteDomains = !empty($payload['delete_domains']);
    $forceLocalDnsCleanup = !empty($payload['force_local_dns_cleanup']);
    $stats = [
        'userid' => $userid,
        'processed_subdomains' => 0,
        'deleted_subdomains' => 0,
        'dns_records_deleted' => 0,
        'warnings' => [],
    ];
    if (!$deleteRecords && !$deleteDomains) {
        $stats['message'] = 'nothing to cleanup';
        return $stats;
    }

    $batchSize = intval($payload['batch_size'] ?? 50);
    if ($batchSize <= 0) {
        $batchSize = 50;
    }
    $batchSize = max(10, min(200, $batchSize));
    $cursor = intval($payload['cursor_id'] ?? 0);

    $subsCollection = Capsule::table('mod_cloudflare_subdomain')
        ->where('userid', $userid)
        ->where('id', '>', $cursor)
        ->orderBy('id', 'asc')
        ->limit($batchSize + 1)
        ->get();
    if (!($subsCollection instanceof \Illuminate\Support\Collection)) {
        $subsCollection = new \Illuminate\Support\Collection(is_array($subsCollection) ? $subsCollection : (array) $subsCollection);
    }
    if ($subsCollection->count() === 0) {
        $stats['message'] = 'no subdomains to cleanup';
        return $stats;
    }

    $hasMore = $subsCollection->count() > $batchSize;
    $subs = $hasMore ? $subsCollection->slice(0, $batchSize)->values() : $subsCollection->values();
    $subsArray = $subs->all();

    $recordsToDelete = [];
    $quotaDecrements = [];

    foreach ($subsArray as $sub) {
        $stats['processed_subdomains']++;
        $subId = intval($sub->id ?? 0);
        if ($subId <= 0) {
            $stats['warnings'][] = 'subdomain:invalid';
            continue;
        }

        try {
            if ($deleteRecords) {
                $deleted = cfmod_admin_deep_delete_subdomain(null, $sub);
                $stats['dns_records_deleted'] += max(0, intval($deleted));
            }

            if ($deleteDomains) {
                $recordsToDelete[$subId] = $sub;
                $quotaUserId = intval($sub->userid ?? 0);
                if ($quotaUserId > 0) {
                    $quotaDecrements[$quotaUserId] = intval($quotaDecrements[$quotaUserId] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            $errorText = strtolower(trim((string) $e->getMessage()));
            $isNotFound = ($errorText !== '')
                && (strpos($errorText, '404') !== false
                    || strpos($errorText, 'not found') !== false
                    || strpos($errorText, 'not_found') !== false
                    || strpos($errorText, '不存在') !== false
                    || strpos($errorText, 'record not found') !== false);
            if ($deleteRecords && $forceLocalDnsCleanup) {
                try {
                    $localDeleted = (int) Capsule::table('mod_cloudflare_dns_records')
                        ->where('subdomain_id', $subId)
                        ->delete();
                    $stats['dns_records_deleted'] += max(0, $localDeleted);
                    $stats['warnings'][] = 'remote_delete_failed_local_cleaned:' . $subId;
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('cleanup_user_subdomain_force_local_dns', [
                            'userid' => $userid,
                            'subdomain_id' => $subId,
                            'error' => (string) $e->getMessage(),
                            'local_deleted' => $localDeleted,
                        ], $userid, $subId);
                    }
                    try {
                        Capsule::table('mod_cloudflare_jobs')->insert([
                            'type' => 'ban_user_frontend_like_dns_cleanup_remote',
                            'payload_json' => json_encode([
                                'userid' => $userid,
                                'subdomain_id' => $subId,
                                'source' => 'admin_ban_force_local_cleanup_frontend_like',
                            ], JSON_UNESCAPED_UNICODE),
                            'priority' => 9,
                            'status' => 'pending',
                            'attempts' => 0,
                            'next_run_at' => date('Y-m-d H:i:s', time() + 300),
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    } catch (\Throwable $enqueueError) {
                        $stats['warnings'][] = 'enqueue_remote_orphan_cleanup_failed:' . $subId;
                        cfmod_report_exception('cleanup_user_subdomains_enqueue_remote_orphan_cleanup', $enqueueError);
                    }
                    continue;
                } catch (\Throwable $localDeleteError) {
                    $stats['warnings'][] = 'force_local_delete_failed:' . $subId;
                    cfmod_report_exception('cleanup_user_subdomains_force_local_delete', $localDeleteError);
                }
            }
            if ($isNotFound) {
                $stats['warnings'][] = 'not_found:' . $subId;
            } else {
                $stats['warnings'][] = 'subdomain:' . $subId;
            }
            cfmod_report_exception('cleanup_user_subdomains', $e);
        }
    }

    if ($deleteDomains && !empty($recordsToDelete)) {
        $deleteIds = array_values(array_map('intval', array_keys($recordsToDelete)));
        try {
            Capsule::transaction(function () use ($deleteIds, $deleteRecords, $quotaDecrements) {
                if (!$deleteRecords) {
                    Capsule::table('mod_cloudflare_dns_records')
                        ->whereIn('subdomain_id', $deleteIds)
                        ->delete();
                }

                Capsule::table('mod_cloudflare_subdomain')
                    ->whereIn('id', $deleteIds)
                    ->delete();

                foreach ($quotaDecrements as $quotaUserId => $decrementBy) {
                    $decrementBy = max(1, intval($decrementBy));
                    Capsule::table('mod_cloudflare_subdomain_quotas')
                        ->where('userid', intval($quotaUserId))
                        ->where('used_count', '>', 0)
                        ->update([
                            'used_count' => Capsule::raw('GREATEST(used_count - ' . $decrementBy . ', 0)'),
                        ]);
                }
            });

            $stats['deleted_subdomains'] = count($deleteIds);
            if (function_exists('cloudflare_subdomain_log')) {
                foreach ($recordsToDelete as $subId => $sub) {
                    cloudflare_subdomain_log('cleanup_user_subdomain', [
                        'subdomain' => $sub->subdomain,
                        'userid' => $userid,
                    ], $userid, intval($subId));
                }
            }
        } catch (\Throwable $e) {
            $stats['warnings'][] = 'bulk_delete_failed';
            cfmod_report_exception('cleanup_user_subdomains_bulk_delete', $e);
        }
    }

    if ($hasMore) {
        $last = end($subsArray);
        $nextCursor = $last ? ($last->id ?? null) : null;
        if ($nextCursor) {
            $newPayload = $payload;
            $newPayload['cursor_id'] = $nextCursor;
            try {
                Capsule::table('mod_cloudflare_jobs')->insert([
                    'type' => 'cleanup_user_subdomains',
                    'payload_json' => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 8),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $stats['has_more'] = true;
                $stats['next_cursor'] = $nextCursor;
            } catch (\Throwable $e) {
                $stats['warnings'][] = 'enqueue_failed';
                cfmod_report_exception('cleanup_user_subdomains_enqueue', $e);
            }
        }
    } else {
        $stats['message'] = 'cleanup complete';
    }

    return $stats;
}

function cfmod_job_ban_user_delete_dns_like_client($job, array $payload = []): array {
    $result = cfmod_job_cleanup_user_subdomains($job, $payload);
    $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
    $nonNotFoundWarnings = [];
    $notFoundWarnings = 0;
    foreach ($warnings as $warning) {
        $w = strtolower(trim((string) $warning));
        if ($w === '' || strpos($w, 'not_found') !== false || strpos($w, '404') !== false) {
            $notFoundWarnings++;
            continue;
        }
        $nonNotFoundWarnings[] = (string) $warning;
    }
    $result['warnings'] = $nonNotFoundWarnings;
    $result['not_found_ignored'] = $notFoundWarnings;
    $failed = count($nonNotFoundWarnings);
    $processed = max(0, intval($result['processed_subdomains'] ?? 0));
    $success = max(0, $processed - $failed);
    $result['success_subdomains'] = $success;
    $result['failed_subdomains'] = $failed;
    if ($processed === 0) {
        $result['result_level'] = 'noop';
    } elseif ($failed === 0) {
        $result['result_level'] = 'success';
    } elseif ($success > 0) {
        $result['result_level'] = 'partial_success';
    } else {
        $result['result_level'] = 'failed';
    }
    return $result;
}

function cfmod_job_cleanup_domain_gifts($job, array $payload = []): array {
    $stats = [
        'expired' => 0,
        'warnings' => [],
    ];
    $limit = intval($payload['batch_size'] ?? 200);
    $limit = max(20, min(500, $limit));
    try {
        $nowStr = date('Y-m-d H:i:s');
        $pending = Capsule::table('mod_cloudflare_domain_gifts')
            ->where('status', 'pending')
            ->where('expires_at', '<', $nowStr)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
        if (!($pending instanceof Collection)) {
            if (is_array($pending)) {
                $pending = new Collection($pending);
            } elseif ($pending instanceof \Traversable) {
                $pending = new Collection(iterator_to_array($pending));
            } elseif ($pending === null) {
                $pending = new Collection();
            } else {
                $pending = new Collection([$pending]);
            }
        }
        if ($pending->isEmpty()) {
            $stats['message'] = 'no expired gifts';
            return $stats;
        }
        foreach ($pending as $gift) {
            Capsule::transaction(function () use ($gift, $nowStr, &$stats) {
                $fresh = Capsule::table('mod_cloudflare_domain_gifts')
                    ->where('id', $gift->id)
                    ->lockForUpdate()
                    ->first();
                if (!$fresh || $fresh->status !== 'pending') {
                    return;
                }
                if (strtotime($fresh->expires_at ?? '') > time()) {
                    return;
                }
                Capsule::table('mod_cloudflare_domain_gifts')
                    ->where('id', $fresh->id)
                    ->update([
                        'status' => 'expired',
                        'cancelled_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ]);
                Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $fresh->subdomain_id)
                    ->where('gift_lock_id', $fresh->id)
                    ->update([
                        'gift_lock_id' => null,
                        'updated_at' => $nowStr,
                    ]);
                $stats['expired']++;
            });
        }
        $stats['message'] = 'expired ' . $stats['expired'] . ' gifts';
        if ($pending->count() === $limit) {
            $stats['has_more'] = true;
        }
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'gift_cleanup_failed';
        $stats['message'] = 'gift cleanup failed';
        cfmod_report_exception('cleanup_domain_gifts', $e);
    }
    return $stats;
}

function cfmod_job_cleanup_root_verify_tasks($job, array $payload = []): array
{
    $stats = [
        'processed' => 0,
        'expired' => 0,
        'cleanup_failed' => 0,
        'warnings' => [],
        'message' => 'noop',
    ];
    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_root_verify_tasks')) {
            $stats['message'] = 'table_missing';
            return $stats;
        }
        $batch = max(1, min(200, intval($payload['batch_size'] ?? 50)));
        $now = date('Y-m-d H:i:s');
        $rows = Capsule::table('mod_cloudflare_root_verify_tasks')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id', 'asc')
            ->limit($batch)
            ->get();
        if (!$rows || count($rows) === 0) {
            $stats['message'] = 'nothing_to_cleanup';
            return $stats;
        }
        $settings = function_exists('cfmod_get_module_settings') ? cfmod_get_module_settings() : [];
        foreach ($rows as $row) {
            $stats['processed']++;
            try {
                $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', intval($row->subdomain_id ?? 0))->first();
                $deleteOk = true;
                if ($sub) {
                    list($cf, $providerError,) = cfmod_client_acquire_provider_for_subdomain($sub, $settings);
                    if (!$cf) {
                        $deleteOk = false;
                        $stats['warnings'][] = 'provider_unavailable:' . intval($row->id);
                    } else {
                        $zoneId = (string) ($sub->cloudflare_zone_id ?? ($row->rootdomain ?? ''));
                        $providerClass = is_object($cf) ? get_class($cf) : '';
                        if (stripos($providerClass, 'PowerDNS') !== false) {
                            $zoneId = (string) ($row->rootdomain ?? '');
                        }
                        $host = trim((string) ($row->host ?? ''));
                        $rootdomain = trim((string) ($row->rootdomain ?? ''));
                        $txtValue = trim((string) ($row->txt_value ?? ''));
                        $nameCandidates = array_values(array_unique(array_filter([
                            $host,
                            $host !== '' && $rootdomain !== '' ? ($host . '.' . $rootdomain) : '',
                            $host !== '' && $rootdomain !== '' ? ($host . '.' . $rootdomain . '.') : '',
                        ])));
                        $txtCandidates = array_values(array_unique(array_filter([
                            $txtValue,
                            '"' . str_replace('"', '\\"', $txtValue) . '"',
                        ])));
                        $del = ['success' => false];
                        foreach ($nameCandidates as $nameTry) {
                            foreach ($txtCandidates as $txtTry) {
                                $del = cfmod_pdns_delete_record_on_provider($cf, $zoneId, [
                                    'record_id' => (string) ($row->record_id ?? ''),
                                    'name' => $nameTry,
                                    'type' => 'TXT',
                                    'content' => $txtTry,
                                ]);
                                if (!empty($del['success'])) {
                                    break 2;
                                }
                            }
                        }
                        if (!($del['success'] ?? false)) {
                            $deleteOk = false;
                        }
                    }
                }
                Capsule::table('mod_cloudflare_root_verify_tasks')
                    ->where('id', intval($row->id))
                    ->update([
                        'status' => $deleteOk ? 'expired' : 'cleanup_failed',
                        'locked_until' => $now,
                        'updated_at' => $now,
                    ]);
                if ($deleteOk) {
                    $stats['expired']++;
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('root_verify_expired_cleanup', ['task_id' => intval($row->id)], intval($row->client_id ?? 0), intval($row->subdomain_id ?? 0));
                    }
                } else {
                    $stats['cleanup_failed']++;
                    if (function_exists('cloudflare_subdomain_log')) {
                        cloudflare_subdomain_log('root_verify_cleanup_failed', ['task_id' => intval($row->id)], intval($row->client_id ?? 0), intval($row->subdomain_id ?? 0));
                    }
                }
            } catch (\Throwable $inner) {
                $stats['cleanup_failed']++;
                $stats['warnings'][] = 'row_failed:' . intval($row->id);
            }
        }
        $stats['message'] = 'processed ' . intval($stats['processed']);
    } catch (\Throwable $e) {
        $stats['warnings'][] = 'exception';
        $stats['message'] = 'cleanup exception';
        cfmod_report_exception('cleanup_root_verify_tasks', $e);
    }
    return $stats;
}

function cfmod_job_cleanup_orphan_dns($job, array $payload = []): array {
    $stats = CfAdminActionService::executeOrphanScan($payload);
    if (is_array($stats)) {
        $stats['job_id'] = $job->id ?? null;
        $hasMore = !empty($stats['has_more']);
        if ($hasMore) {
            try {
                $nextPayload = $payload;
                $nextPayload['cursor_mode'] = 'resume';
                $nextJobId = Capsule::table('mod_cloudflare_jobs')->insertGetId([
                    'type' => 'cleanup_orphan_dns',
                    'payload_json' => json_encode($nextPayload, JSON_UNESCAPED_UNICODE),
                    'priority' => intval($job->priority ?? 12),
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_run_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $stats['next_job_id'] = $nextJobId;
            } catch (\Throwable $e) {
                if (!isset($stats['warnings']) || !is_array($stats['warnings'])) {
                    $stats['warnings'] = [];
                }
                $stats['warnings'][] = 'enqueue_failed';
                cfmod_report_exception('cleanup_orphan_dns_enqueue', $e);
            }
        }
    }
    return $stats;
}

function cfmod_job_client_cleanup_orphan_dns_remote($job, array $payload = []): array {
    $stats = ['success' => false, 'deleted' => 0, 'local_deleted' => 0, 'warnings' => []];
    try {
        $subdomainId = intval($payload['subdomain_id'] ?? 0);
        $userid = intval($payload['userid'] ?? 0);
        if ($subdomainId <= 0 || $userid <= 0) {
            throw new \RuntimeException('invalid payload');
        }
        $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->where('userid', $userid)->first();
        if (!$sub) {
            throw new \RuntimeException('subdomain not found');
        }
        $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
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
        $remote = $client->getDnsRecords($zoneId, null, ['per_page' => 1000]);
        if (!($remote['success'] ?? false)) {
            throw new \RuntimeException('remote list failed');
        }
        foreach (($remote['result'] ?? []) as $record) {
            $recordName = strtolower(trim((string) ($record['name'] ?? '')));
            if (!cfmod_record_belongs_to_target_domain($recordName, $targetDomain)) {
                continue;
            }
            $recordId = trim((string) ($record['id'] ?? ''));
            if ($recordId === '') { continue; }
            $res = $client->deleteSubdomain($zoneId, $recordId, [
                'name' => $record['name'] ?? null,
                'type' => $record['type'] ?? null,
                'content' => $record['content'] ?? null,
            ]);
            if (!($res['success'] ?? false) && !api_provider_not_found($res)) {
                $stats['warnings'][] = 'delete_failed:' . $recordId;
                continue;
            }
            $stats['deleted']++;
        }
        $stats['local_deleted'] = (int) Capsule::table('mod_cloudflare_dns_records')->where('subdomain_id', $subdomainId)->delete();
        if (class_exists('CfSubdomainService')) {
            CfSubdomainService::syncDnsHistoryFlag($subdomainId);
        }
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('client_cleanup_orphan_dns_remote', [
                'domain' => $sub->subdomain ?? '',
                'remote_deleted' => $stats['deleted'],
                'local_deleted' => $stats['local_deleted'],
            ], $userid, $subdomainId);
        }
        $stats['success'] = true;
    } catch (\Throwable $e) {
        $stats['warnings'][] = $e->getMessage();
    }
    return $stats;
}

function cfmod_job_ban_user_frontend_like_dns_cleanup_remote($job, array $payload = []): array {
    $payload['source'] = $payload['source'] ?? 'admin_ban_frontend_like';
    $stats = cfmod_job_client_cleanup_orphan_dns_remote($job, $payload);
    if (!is_array($stats)) {
        $stats = ['success' => false, 'warnings' => ['invalid_stats']];
    }
    $stats['frontend_like_mode'] = 1;
    return $stats;
}

function cfmod_record_belongs_to_target_domain(string $recordName, string $targetDomain): bool {
    if ($recordName === '' || $targetDomain === '') {
        return false;
    }
    if ($recordName === $targetDomain) {
        return true;
    }
    return substr($recordName, -strlen('.' . $targetDomain)) === ('.' . $targetDomain);
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $max = isset($argv[1]) ? intval($argv[1]) : 3;
    run_cf_queue_once(max(1, $max));
}
