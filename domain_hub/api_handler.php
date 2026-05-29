<?php
if (!defined('WHMCS')) {
    // Try bootstrap WHMCS when called from clientarea index
    $cwd = getcwd();
    $dirs = [ $cwd, dirname($cwd), dirname(dirname($cwd)), dirname(dirname(dirname($cwd))) ];
    foreach ($dirs as $dir) {
        if (is_file($dir . '/init.php')) { require_once $dir . '/init.php'; break; }
    }
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/autoload.php';
CfModuleSettings::bootstrap();
require_once __DIR__ . '/lib/AtomicOperations.php';
require_once __DIR__ . '/lib/ErrorFormatter.php';
require_once __DIR__ . '/lib/TtlHelper.php';
require_once __DIR__ . '/lib/RootDomainLimitHelper.php';
require_once __DIR__ . '/lib/SecurityHelpers.php';
require_once __DIR__ . '/lib/ProviderResolver.php';
require_once __DIR__ . '/lib/AdminMaintenance.php';

$GLOBALS['cfmod_api_json_response_sent'] = false;

function api_clear_output_buffers(): void
{
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
}

function api_emit_boundary_json_error(string $message, int $code = 500, string $errorCode = 'server_error'): void
{
    if (!empty($GLOBALS['cfmod_api_json_response_sent'])) {
        return;
    }
    $GLOBALS['cfmod_api_json_response_sent'] = true;
    if (!headers_sent()) {
        api_clear_output_buffers();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Domain-Hub-API-Error: 1');
    }
    echo json_encode([
        'success' => false,
        'error_code' => $errorCode,
        'message' => $message,
        'error' => $message,
        'details' => new \stdClass(),
    ], JSON_UNESCAPED_UNICODE);
}

function api_install_json_error_boundary(): void
{
    @ini_set('display_errors', '0');
    set_exception_handler(static function (\Throwable $e): void {
        api_emit_boundary_json_error('server error', 500, 'uncaught_exception');
    });
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['cfmod_api_json_response_sent'])) {
            return;
        }
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            api_emit_boundary_json_error('server error', 500, 'fatal_error');
        }
    });
}

api_install_json_error_boundary();

function api_json($arr, $code = 200){
    if (is_array($arr) && !array_key_exists('openapi', $arr)) {
        $arr = api_standardize_payload($arr, intval($code));
    }
    $GLOBALS['cfmod_api_json_response_sent'] = true;
    if (!headers_sent()) {
        api_clear_output_buffers();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
}



function api_standardize_payload(array $payload, int $httpStatus): array
{
    $hasErrorText = isset($payload['error']) && trim((string) $payload['error']) !== '';
    $success = $payload['success'] ?? null;
    if ($success === null) {
        $success = !$hasErrorText && $httpStatus < 400;
    }
    $success = (bool) $success;

    if ($success) {
        if (!array_key_exists('success', $payload)) {
            $payload['success'] = true;
        }
        return $payload;
    }

    $message = trim((string) ($payload['message'] ?? ($payload['error'] ?? '')));
    if ($message === '') {
        $message = api_default_error_message($httpStatus);
    }

    $existingErrorCode = trim((string) ($payload['error_code'] ?? ''));
    $errorCode = $existingErrorCode !== '' ? $existingErrorCode : api_resolve_error_code($message, $httpStatus, $payload);

    $details = api_extract_error_details($payload);

    $payload['success'] = false;
    $payload['error_code'] = $errorCode;
    $payload['message'] = $message;
    $payload['error'] = $message;
    $payload['details'] = !empty($details) ? $details : new \stdClass();

    return $payload;
}

function api_default_error_message(int $httpStatus): string
{
    $map = [
        400 => 'Bad request',
        401 => 'Unauthorized',
        402 => 'Payment required',
        403 => 'Forbidden',
        404 => 'Not found',
        405 => 'Method not allowed',
        409 => 'Conflict',
        410 => 'Gone',
        422 => 'Unprocessable entity',
        429 => 'Rate limit exceeded',
        500 => 'Internal server error',
        502 => 'Upstream provider error',
        503 => 'Service unavailable',
    ];

    return $map[$httpStatus] ?? 'Request failed';
}

function api_resolve_error_code(string $message, int $httpStatus, array $payload): string
{
    $reason = strtolower(trim((string) ($payload['reason'] ?? '')));
    if ($reason !== '') {
        if ($reason === 'limit_exceeded') {
            return 'rate_limit_exceeded';
        }
        if ($reason === 'storage_error') {
            return 'rate_limit_storage_error';
        }
    }

    $normalized = strtolower(trim($message));
    $contains = static function (array $patterns) use ($normalized): bool {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && strpos($normalized, $pattern) !== false) {
                return true;
            }
        }
        return false;
    };

    if ($contains(['invalid api key', 'invalid api secret', 'missing api credentials'])) {
        return 'auth_invalid_credentials';
    }
    if ($contains(['ip not allowed'])) {
        return 'auth_ip_not_allowed';
    }
    if ($contains(['api access disabled'])) {
        return 'api_access_disabled';
    }
    if ($contains(['rate limit'])) {
        return 'rate_limit_exceeded';
    }
    if ($contains(['quota exceeded'])) {
        return 'quota_exceeded';
    }
    if ($contains(['subdomain not found'])) {
        return 'subdomain_not_found';
    }
    if ($contains(['record not found'])) {
        return 'dns_record_not_found';
    }
    if ($contains(['key not found'])) {
        return 'api_key_not_found';
    }
    if ($contains(['invalid domain'])) {
        return 'invalid_domain';
    }
    if ($contains(['invalid parameters', 'subdomain_id required', 'record_id required', 'key id required'])) {
        return 'invalid_parameters';
    }
    if ($contains(['provider unavailable'])) {
        return 'provider_unavailable';
    }
    if ($contains(['provider delete failed', 'create failed', 'update failed'])) {
        return 'provider_operation_failed';
    }

    $httpMap = [
        400 => 'bad_request',
        401 => 'unauthorized',
        402 => 'payment_required',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        410 => 'gone',
        422 => 'unprocessable_entity',
        429 => 'rate_limit_exceeded',
        500 => 'internal_error',
        502 => 'provider_error',
        503 => 'service_unavailable',
    ];

    return $httpMap[$httpStatus] ?? 'request_failed';
}

function api_extract_error_details(array $payload): array
{
    $ignore = [
        'success',
        'error',
        'error_code',
        'message',
        'details',
        'subdomains',
        'records',
        'keys',
        'quota',
        'pagination',
        'count',
    ];

    if (isset($payload['details']) && is_array($payload['details'])) {
        return $payload['details'];
    }

    $details = [];
    foreach ($payload as $key => $value) {
        if (in_array((string) $key, $ignore, true)) {
            continue;
        }
        $details[$key] = $value;
    }

    return $details;
}

function api_get_header($name){
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function api_client_ip(){
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function api_generate_public_subdomain_id(int $length = 10): int
{
    $length = max(10, min(18, $length));
    $firstDigit = random_int(1, 9);
    $digits = (string) $firstDigit;
    for ($i = 1; $i < $length; $i++) {
        $digits .= (string) random_int(0, 9);
    }
    return (int) $digits;
}

function api_has_public_subdomain_id_column(): bool
{
    return CfSubdomainIdResolver::hasPublicIdColumn();
}

function api_get_or_create_public_subdomain_id(int $internalId, bool $legacyUseInternalId = true): int
{
    if ($internalId <= 0) {
        return 0;
    }
    if (!api_has_public_subdomain_id_column()) {
        return $internalId;
    }
    try {
        $row = Capsule::table('mod_cloudflare_subdomain')->where('id', $internalId)->first(['id', 'public_id']);
    } catch (\Throwable $e) {
        return $internalId;
    }
    if (!$row) {
        return 0;
    }
    $existing = (int) ($row->public_id ?? 0);
    if ($existing > 0) {
        return $existing;
    }

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $candidate = $legacyUseInternalId ? $internalId : api_generate_public_subdomain_id(10);
        try {
            $updated = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $internalId)
                ->whereNull('public_id')
                ->update(['public_id' => $candidate, 'updated_at' => date('Y-m-d H:i:s')]);
            if ($updated) {
                return $candidate;
            }
            $latest = Capsule::table('mod_cloudflare_subdomain')->where('id', $internalId)->value('public_id');
            if (is_numeric($latest) && (int) $latest > 0) {
                return (int) $latest;
            }
        } catch (\Throwable $e) {
        }
    }

    return $internalId;
}

function api_assign_new_subdomain_public_id(int $internalId): int
{
    return api_get_or_create_public_subdomain_id($internalId, false);
}

function api_resolve_subdomain_identifier($rawId): int
{
    return CfSubdomainIdResolver::resolveToInternal($rawId);
}

function api_load_settings(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    try {
        $rows = Capsule::table('tbladdonmodules')->where('module', CF_MODULE_NAME)->get();
        if (count($rows) === 0) {
            $legacyRows = Capsule::table('tbladdonmodules')->where('module', CF_MODULE_NAME_LEGACY)->get();
            if (count($legacyRows) > 0) {
                foreach ($legacyRows as $row) {
                    Capsule::table('tbladdonmodules')->updateOrInsert(
                        ['module' => CF_MODULE_NAME, 'setting' => $row->setting],
                        ['value' => $row->value]
                    );
                }
                $rows = Capsule::table('tbladdonmodules')->where('module', CF_MODULE_NAME)->get();
                if (count($rows) === 0) {
                    $rows = $legacyRows;
                }
            }
        }
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r->setting] = $r->value;
        }
        $cache = $settings;
    } catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

function api_setting_enabled($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string)$value));
    if ($normalized === '') {
        return false;
    }
    return in_array($normalized, ['1', 'on', 'yes', 'true', 'enabled'], true);
}

function api_pdns_register_strategy(array $settings): string {
    $strategy = strtolower(trim((string) ($settings['pdns_register_strategy'] ?? '')));
    if (in_array($strategy, ['local_only', 'hybrid', 'strict_remote'], true)) {
        return $strategy;
    }

    $legacyRaw = trim((string) ($settings['pdns_register_local_check_only'] ?? '1'));
    if ($legacyRaw === '') {
        return 'local_only';
    }
    return api_setting_enabled($legacyRaw) ? 'local_only' : 'strict_remote';
}

function api_is_pdns_provider(array $providerContext): bool {
    $providerType = strtolower(trim((string)($providerContext['account']['provider_type'] ?? ($providerContext['provider_type'] ?? ''))));
    if ($providerType !== '') {
        return $providerType === 'powerdns';
    }

    $client = $providerContext['client'] ?? null;
    return is_object($client) && stripos(get_class($client), 'powerdns') !== false;
}

function api_is_alidns_provider(array $providerContext): bool {
    $providerType = strtolower(trim((string)($providerContext['account']['provider_type'] ?? ($providerContext['provider_type'] ?? ''))));
    if ($providerType !== '') {
        return $providerType === 'alidns';
    }

    $client = $providerContext['client'] ?? null;
    return is_object($client) && get_class($client) === 'CloudflareAPI';
}

function api_count_local_subdomains_by_rootdomain(string $rootdomain): int {
    $normalized = strtolower(trim($rootdomain));
    if ($normalized === '') {
        return 0;
    }

    return (int) Capsule::table('mod_cloudflare_subdomain')
        ->whereRaw('LOWER(rootdomain) = ?', [$normalized])
        ->count();
}

function api_should_skip_provider_exists_check(array $providerContext, array $settings, string $rootdomain = ''): bool {
    if (!api_is_pdns_provider($providerContext)) {
        return false;
    }

    $strategy = api_pdns_register_strategy($settings);
    if ($strategy === 'local_only') {
        return true;
    }
    if ($strategy === 'strict_remote') {
        return false;
    }

    $thresholdRaw = trim((string) ($settings['pdns_register_hybrid_local_threshold'] ?? '800'));
    $threshold = is_numeric($thresholdRaw) ? (int) $thresholdRaw : 800;
    $threshold = max(100, min(50000, $threshold));

    try {
        $localSubdomains = api_count_local_subdomains_by_rootdomain($rootdomain);
    } catch (\Throwable $e) {
        return true;
    }

    return $localSubdomains >= $threshold;
}

function api_provider_error_text($result): string {
    if (is_string($result)) {
        return $result;
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

function api_provider_not_found($result): bool {
    if (is_array($result)) {
        $code = $result['code'] ?? ($result['http_code'] ?? null);
        if ($code === 404 || $code === '404') {
            return true;
        }
    }

    $message = api_provider_error_text($result);
    if ($message === '') {
        return false;
    }

    $normalized = strtolower($message);
    return strpos($normalized, 'not found') !== false
        || strpos($normalized, 'does not exist') !== false
        || strpos($normalized, 'no such') !== false
        || strpos($normalized, 'record not found') !== false
        || strpos($message, '不存在') !== false;
}

function api_dns_conflict_auto_repair_enabled(array $settings): bool
{
    if (array_key_exists('dns_conflict_auto_repair_enabled', $settings)) {
        return api_setting_enabled($settings['dns_conflict_auto_repair_enabled']);
    }
    return true;
}


function api_dns_create_semantics_mode(array $settings): string
{
    return CfDnsConflictRepairService::createSemanticsMode($settings);
}

function api_parse_dns_type_csv(string $raw): array
{
    $parts = preg_split('/[\s,;|]+/', strtoupper(trim($raw)));
    $types = [];
    foreach ((array)$parts as $part) {
        $v = trim((string)$part);
        if ($v !== '') { $types[$v] = true; }
    }
    return array_keys($types);
}

function api_conflict_auto_repair_types(array $settings): array
{
    return CfDnsConflictRepairService::conflictAutoRepairTypes($settings);
}

function api_replace_mode_types(array $settings): array
{
    return CfDnsConflictRepairService::replaceModeTypes($settings);
}

function api_dns_type_in_list(string $type, array $list): bool
{
    return in_array(strtoupper(trim($type)), $list, true);
}

function api_is_conflict_error_text(string $message): bool
{
    $normalized = strtolower(trim($message));
    if ($normalized === '') {
        return false;
    }
    return strpos($normalized, 'conflict') !== false
        || strpos($normalized, 'exists') !== false
        || strpos($normalized, 'duplicate') !== false
        || strpos($normalized, 'not unique') !== false
        || strpos($message, '冲突') !== false
        || strpos($message, '已存在') !== false
        || strpos($message, '重复') !== false;
}

function api_normalize_dns_name(string $name): string
{
    $value = strtolower(trim($name));
    if ($value === '') {
        return '';
    }
    return rtrim($value, '.');
}

function api_normalize_dns_content(string $content, string $type = ''): string {
    $value = trim($content);
    if ($value === '') {
        return '';
    }
    if (strlen($value) >= 2 && substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
        $value = substr($value, 1, -1);
    }
    if (strpos($value, ' ') === false && substr($value, -1) === '.') {
        $value = rtrim($value, '.');
    }
    $recordType = strtoupper(trim($type));
    if ($recordType === 'TXT') {
        return $value;
    }
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function api_normalize_line_value($line): string
{
    $value = strtolower(trim((string) $line));
    if ($value === '' || $value === 'default' || $value === '默认') {
        return '';
    }
    return $value;
}

function api_remote_record_matches_local(array $remoteRecord, $localRecord): bool {
    $remoteName = strtolower(trim((string) ($remoteRecord['name'] ?? '')));
    $remoteType = strtoupper(trim((string) ($remoteRecord['type'] ?? '')));
    $remoteContent = api_normalize_dns_content((string) ($remoteRecord['content'] ?? ''), $remoteType);

    $localName = strtolower(trim((string) ($localRecord->name ?? '')));
    $localType = strtoupper(trim((string) ($localRecord->type ?? '')));
    $localContent = api_normalize_dns_content((string) ($localRecord->content ?? ''), $localType);

    return $remoteName === $localName
        && $remoteType === $localType
        && $remoteContent === $localContent;
}

function api_fetch_remote_records_for_local($providerClient, string $zoneId, $localRecord): ?array {
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

function api_find_remote_record_id_for_local($providerClient, string $zoneId, $localRecord): ?string {
    $records = api_fetch_remote_records_for_local($providerClient, $zoneId, $localRecord);
    if ($records === null) {
        return null;
    }
    foreach ($records as $remoteRecord) {
        if (!is_array($remoteRecord)) {
            continue;
        }
        if (!api_remote_record_matches_local($remoteRecord, $localRecord)) {
            continue;
        }
        $id = isset($remoteRecord['id']) ? trim((string) $remoteRecord['id']) : '';
        if ($id !== '') {
            return $id;
        }
    }
    return '';
}

function api_remote_record_exists_for_local($providerClient, string $zoneId, $localRecord): ?bool {
    $records = api_fetch_remote_records_for_local($providerClient, $zoneId, $localRecord);
    if ($records === null) {
        return null;
    }
    foreach ($records as $remoteRecord) {
        if (!is_array($remoteRecord)) {
            continue;
        }
        if (api_remote_record_matches_local($remoteRecord, $localRecord)) {
            return true;
        }
    }
    return false;
}

function api_try_repair_dns_conflict_via_upsert($providerClient, string $zoneId, $subdomainRow, string $type, string $name, string $content, int $ttl, int $priority, string $line, bool $allowReplace = false): array
{
    return CfDnsConflictRepairService::tryRepairViaUpsert($providerClient, $zoneId, $type, $name, $content, $ttl, $priority, $line, $allowReplace);
}

function api_handle_subdomain_register(array $data, $keyRow, array $settings): array {
    $code = 200;
    $result = null;

    if (api_setting_enabled($settings['maintenance_mode'] ?? '0')) {
        $code = 503;
        $result = ['error' => 'System under maintenance'];
        return [$code, $result];
    }

    if (api_setting_enabled($settings['pause_free_registration'] ?? '0')) {
        $code = 403;
        $result = ['error' => 'Registration paused'];
        return [$code, $result];
    }

    $sub = trim((string)($data['subdomain'] ?? ''));
    $root = trim((string)($data['rootdomain'] ?? ''));
    $prefixLimits = cf_get_prefix_length_limits($settings);
    $prefixMinLen = $prefixLimits['min'];
    $prefixMaxLen = $prefixLimits['max'];
    $subLen = strlen($sub);

    if ($sub === '' || $root === '') {
        $code = 400;
        $result = ['error' => 'invalid parameters'];
        return [$code, $result];
    }

    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $sub)) {
        $code = 400;
        $result = ['error' => 'prefix invalid characters'];
        return [$code, $result];
    }

    if ($subLen < $prefixMinLen || $subLen > $prefixMaxLen) {
        $code = 400;
        $result = [
            'error' => 'prefix length invalid',
            'min_length' => $prefixMinLen,
            'max_length' => $prefixMaxLen
        ];
        return [$code, $result];
    }

    $quota = api_get_user_quota($keyRow->userid, $settings);
    if (!$quota) {
        $code = 500;
        $result = ['error' => 'quota unavailable'];
        return [$code, $result];
    }

    $forbiddenList = array_filter(array_map('trim', explode(',', (string)($settings['forbidden_prefix'] ?? 'www,mail,ftp,admin,root,gov,pay,bank'))));
    $forbiddenLower = array_map('strtolower', $forbiddenList);
    if (in_array(strtolower($sub), $forbiddenLower, true)) {
        $code = 400;
        $result = ['error' => 'prefix forbidden'];
        return [$code, $result];
    }

    $full = strtolower($sub) . '.' . strtolower($root);
    try {
        $isForbidden = Capsule::table('mod_cloudflare_forbidden_domains')
            ->whereRaw('LOWER(domain)=?', [$full])
            ->exists();
    } catch (\Throwable $e) {
        $isForbidden = false;
    }
    if ($isForbidden) {
        $code = 403;
        $result = ['error' => 'domain forbidden'];
        return [$code, $result];
    }

    $rootAllowed = api_is_rootdomain_allowed($root, $settings);
    if (!$rootAllowed) {
        $allowSuspendedForPrivileged = false;
        $userId = intval($keyRow->userid ?? 0);
        if ($userId > 0 && function_exists('cf_is_user_privileged') && cf_is_user_privileged($userId)) {
            $allowSuspendedForPrivileged = function_exists('cf_is_privileged_feature_enabled')
                && cf_is_privileged_feature_enabled('allow_register_suspended_root', $settings);
        }

        if ($allowSuspendedForPrivileged) {
            try {
                $rootAllowed = Capsule::table('mod_cloudflare_rootdomains')
                    ->whereRaw('LOWER(domain)=?', [strtolower($root)])
                    ->exists();
            } catch (\Throwable $e) {
                $rootAllowed = false;
            }
        }
    }

    if (!$rootAllowed) {
        $code = 400;
        $result = ['error' => 'root domain not allowed'];
        return [$code, $result];
    }

    $limitCheck = function_exists('cfmod_check_rootdomain_user_limit') ? cfmod_check_rootdomain_user_limit($keyRow->userid, $root, 1) : ['allowed' => true, 'limit' => 0];
    if (!$limitCheck['allowed']) {
        $code = 403;
        $limitMessage = cfmod_format_rootdomain_limit_message($root, $limitCheck['limit']);
        if ($limitMessage === '') {
            $limitValueText = max(1, intval($limitCheck['limit'] ?? 0));
            $limitMessage = $root . ' 每个账号最多注册 ' . $limitValueText . ' 个，您已达到上限';
        }
        $result = ['error' => 'root domain per-user limit exceeded', 'message' => $limitMessage];
        return [$code, $result];
    }

    try {
        $existsLocal = Capsule::table('mod_cloudflare_subdomain')
            ->where('subdomain', $full)
            ->exists();
    } catch (\Throwable $e) {
        $existsLocal = false;
    }
    if ($existsLocal) {
        $code = 400;
        $result = ['error' => 'already registered'];
        return [$code, $result];
    }

    $providerContext = cfmod_acquire_provider_client_for_rootdomain($root, $settings);
    if (!$providerContext || empty($providerContext['client'])) {
        $code = 500;
        $result = ['error' => 'provider unavailable'];
        return [$code, $result];
    }

    $cf = $providerContext['client'];
    $providerAccountId = intval($providerContext['provider_account_id'] ?? 0);
    $zone = $cf->getZoneId($root);
    if (!$zone) {
        $code = 400;
        $result = ['error' => 'root not found'];
        return [$code, $result];
    }
    $skipProviderExistsCheck = api_should_skip_provider_exists_check($providerContext, $settings, $root);
    if (!$skipProviderExistsCheck && $cf->checkDomainExists($zone, $full)) {
        $code = 400;
        $result = ['error' => 'already exists on DNS'];
        return [$code, $result];
    }

    try {
        $created = cf_atomic_register_subdomain($keyRow->userid, $full, $root, $zone, $settings, [
            'dns_record_id' => null,
            'notes' => '已注册，等待解析设置',
            'provider_account_id' => $providerAccountId > 0 ? $providerAccountId : null,
        ]);
        if (is_object($quota)) {
            $quota->used_count = $created['used_count'];
            $quota->max_count = $created['max_count'];
        }
        $result = [
            'success' => true,
            'message' => 'Subdomain registered successfully',
            'subdomain_id' => api_assign_new_subdomain_public_id((int) $created['id']),
            'full_domain' => $full
        ];
    } catch (CfAtomicQuotaExceededException $e) {
        $code = 403;
        $result = ['error' => 'quota exceeded'];
    } catch (CfAtomicAlreadyRegisteredException $e) {
        $code = 400;
        $result = ['error' => 'already registered'];
    } catch (CfAtomicInvalidPrefixLengthException $e) {
        $code = 400;
        $result = [
            'error' => 'prefix length invalid',
            'min_length' => $prefixMinLen,
            'max_length' => $prefixMaxLen
        ];
    } catch (\Throwable $e) {
        $code = 500;
        $result = ['error' => 'registration failed'];
    }

    return [$code, $result];
}

function api_handle_subdomain_renew(array $data, $keyRow, array $settings): array {
    $code = 200;
    $result = null;

    $subId = api_resolve_subdomain_identifier($data['subdomain_id'] ?? ($_POST['subdomain_id'] ?? 0));
    if ($subId <= 0) {
        $code = 400;
        $result = ['error' => 'invalid parameters'];
        return [$code, $result];
    }

    $termYearsRaw = $settings['domain_registration_term_years'] ?? 1;
    $termYears = is_numeric($termYearsRaw) ? (int)$termYearsRaw : 1;
    if ($termYears <= 0) {
        $code = 403;
        $result = ['error' => 'renewal disabled'];
        return [$code, $result];
    }

    $nowTs = time();
    $freeWindowDays = max(0, intval($settings['domain_free_renew_window_days'] ?? 30));
    $freeWindowSeconds = $freeWindowDays * 86400;
    $graceDaysRaw = $settings['domain_grace_period_days'] ?? ($settings['domain_auto_delete_grace_days'] ?? 45);
    $graceDays = is_numeric($graceDaysRaw) ? (int)$graceDaysRaw : 45;
    if ($graceDays < 0) {
        $graceDays = 0;
    }
    $redemptionDaysRaw = $settings['domain_redemption_days'] ?? 0;
    $redemptionDays = is_numeric($redemptionDaysRaw) ? (int)$redemptionDaysRaw : 0;
    if ($redemptionDays < 0) {
        $redemptionDays = 0;
    }
    $graceSeconds = $graceDays * 86400;
    $redemptionSeconds = $redemptionDays * 86400;
    $redemptionModeRaw = strtolower(trim((string)($settings['domain_redemption_mode'] ?? 'manual')));
    if (!in_array($redemptionModeRaw, ['manual', 'auto_charge'], true)) {
        $redemptionModeRaw = 'manual';
    }
    $redemptionFeeRaw = $settings['domain_redemption_fee_amount'] ?? 0;
    $redemptionFee = round(max(0, (float) $redemptionFeeRaw), 2);

    try {
        $renewResult = Capsule::transaction(function () use ($subId, $keyRow, $termYears, $nowTs, $freeWindowSeconds, $graceSeconds, $redemptionSeconds, $redemptionModeRaw, $redemptionFee) {
            $sub = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subId)
                ->where('userid', $keyRow->userid)
                ->lockForUpdate()
                ->first();
            if (!$sub) {
                throw new ApiRenewException('subdomain not found', 404);
            }
            if (intval($sub->never_expires ?? 0) === 1) {
                throw new ApiRenewException('subdomain is set to never expire', 400, ['never_expires' => 1]);
            }
            $statusLower = strtolower((string)($sub->status ?? ''));
            if (!in_array($statusLower, ['active', 'pending'], true)) {
                throw new ApiRenewException('current status does not allow renewal', 403, ['status' => $statusLower]);
            }
            $expiresRaw = $sub->expires_at ?? null;
            if (!$expiresRaw) {
                throw new ApiRenewException('expires_at not set', 400);
            }
            $expiresTs = strtotime($expiresRaw);
            if ($expiresTs === false) {
                throw new ApiRenewException('unable to parse expires_at', 500);
            }
            if ($freeWindowSeconds > 0 && $nowTs < ($expiresTs - $freeWindowSeconds)) {
                $secondsUntil = ($expiresTs - $freeWindowSeconds) - $nowTs;
                $payload = [
                    'seconds_until_window' => max(0, $secondsUntil),
                    'days_until_window' => max(0, (int) ceil($secondsUntil / 86400)),
                ];
                throw new ApiRenewException('renewal not yet available', 422, array_merge($payload, [
                    'error_code' => 'renewal_not_yet_available',
                    'message' => '域名尚未进入可续期时间窗口',
                ]));
            }
            $graceDeadlineTs = $expiresTs + $graceSeconds;
            $chargeAmount = 0.0;
            if ($nowTs > $graceDeadlineTs) {
                $redemptionDeadlineTs = $graceDeadlineTs + $redemptionSeconds;
                if ($redemptionSeconds > 0 && $nowTs <= $redemptionDeadlineTs) {
                    if ($redemptionModeRaw === 'auto_charge') {
                        if ($redemptionFee > 0) {
                            $clientRow = Capsule::table('tblclients')
                                ->where('id', $keyRow->userid)
                                ->lockForUpdate()
                                ->first();
                            if (!$clientRow) {
                                throw new ApiRenewException('unable to load client balance', 500, ['stage' => 'redemption']);
                            }
                            $currentCredit = (float)($clientRow->credit ?? 0.0);
                            if ($currentCredit + 1e-8 < $redemptionFee) {
                                throw new ApiRenewException('insufficient balance for redemption renewal', 402, [
                                    'stage' => 'redemption',
                                    'reason' => 'insufficient_balance',
                                    'balance' => round($currentCredit, 2),
                                    'required' => $redemptionFee,
                                ]);
                            }
                            $newCredit = round($currentCredit - $redemptionFee, 2);
                            Capsule::table('tblclients')
                                ->where('id', $keyRow->userid)
                                ->update(['credit' => number_format($newCredit, 2, '.', '')]);
                            static $creditSchemaInfo = null;
                            if ($creditSchemaInfo === null) {
                                $creditSchemaInfo = [
                                    'has_table' => false,
                                    'has_relid' => false,
                                    'has_refundid' => false,
                                ];
                                try {
                                    $creditSchemaInfo['has_table'] = Capsule::schema()->hasTable('tblcredit');
                                    if ($creditSchemaInfo['has_table']) {
                                        $creditSchemaInfo['has_relid'] = Capsule::schema()->hasColumn('tblcredit', 'relid');
                                        $creditSchemaInfo['has_refundid'] = Capsule::schema()->hasColumn('tblcredit', 'refundid');
                                    }
                                } catch (\Throwable $ignored) {
                                    $creditSchemaInfo = [
                                        'has_table' => false,
                                        'has_relid' => false,
                                        'has_refundid' => false,
                                    ];
                                }
                            }
                            if ($creditSchemaInfo['has_table']) {
                                $creditInsert = [
                                    'clientid' => $keyRow->userid,
                                    'date' => date('Y-m-d H:i:s', $nowTs),
                                    'description' => 'Redeem period renewal charge',
                                    'amount' => 0 - $redemptionFee,
                                ];
                                if ($creditSchemaInfo['has_relid']) {
                                    $creditInsert['relid'] = 0;
                                }
                                if ($creditSchemaInfo['has_refundid']) {
                                    $creditInsert['refundid'] = 0;
                                }
                                Capsule::table('tblcredit')->insert($creditInsert);
                            }
                            $chargeAmount = $redemptionFee;
                        }
                    } else {
                        throw new ApiRenewException('redemption period requires administrator', 403, ['stage' => 'redemption']);
                    }
                } else {
                    throw new ApiRenewException('renewal window expired', 403, ['stage' => $redemptionSeconds > 0 ? 'redemption_expired' : 'expired']);
                }
            }
            $baseTs = max($expiresTs, $nowTs);
            $nowStr = date('Y-m-d H:i:s', $nowTs);
            $newExpiryTs = strtotime('+' . $termYears . ' years', $baseTs);
            if ($newExpiryTs === false) {
                throw new ApiRenewException('renewal calculation failed', 500);
            }
            $newExpiry = date('Y-m-d H:i:s', $newExpiryTs);
            Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subId)
                ->update([
                    'expires_at' => $newExpiry,
                    'renewed_at' => $nowStr,
                    'never_expires' => 0,
                    'auto_deleted_at' => null,
                    'updated_at' => $nowStr,
                ]);
            return [
                'subdomain' => $sub->subdomain,
                'status' => api_present_domain_status($sub->status),
                'previous_expires_at' => $sub->expires_at,
                'new_expires_at' => $newExpiry,
                'renewed_at' => $nowStr,
                'never_expires' => 0,
                'charged_amount' => $chargeAmount,
            ];
        });
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('api_renew_subdomain', [
                'subdomain' => $renewResult['subdomain'],
                'previous_expires_at' => $renewResult['previous_expires_at'],
                'new_expires_at' => $renewResult['new_expires_at'],
                'charged_amount' => $renewResult['charged_amount'] ?? 0,
            ], $keyRow->userid, $subId);
        }
        $newExpiryTs = strtotime($renewResult['new_expires_at']);
        $remainingDays = null;
        if ($newExpiryTs !== false) {
            $remainingDays = max(0, (int) ceil(($newExpiryTs - time()) / 86400));
        }
        $chargedAmount = isset($renewResult['charged_amount']) ? (float)$renewResult['charged_amount'] : 0.0;
        $result = [
            'success' => true,
            'message' => 'Subdomain renewed successfully',
            'subdomain_id' => $subId,
            'subdomain' => $renewResult['subdomain'],
            'previous_expires_at' => $renewResult['previous_expires_at'],
            'new_expires_at' => $renewResult['new_expires_at'],
            'renewed_at' => $renewResult['renewed_at'],
            'never_expires' => $renewResult['never_expires'],
            'status' => $renewResult['status'] ?? null,
            'charged_amount' => $chargedAmount,
        ];
        if ($chargedAmount > 0) {
            $result['message'] = 'Subdomain renewed successfully (charged ' . number_format($chargedAmount, 2, '.', '') . ')';
        }
        if ($remainingDays !== null) {
            $result['remaining_days'] = $remainingDays;
        }
    } catch (ApiRenewException $e) {
        $code = $e->getHttpCode();
        $payload = $e->getPayload();
        $result = array_merge(['error' => $e->getMessage()], $payload);
    } catch (\Throwable $e) {
        if (function_exists('cfmod_report_exception')) {
            cfmod_report_exception('api_renew_subdomain', $e);
        } else {
            error_log('[domain_hub][api_renew_subdomain] ' . $e->getMessage());
        }
        $code = 500;
        $result = ['error' => 'renew failed'];
    }

    return [$code, $result];
}



function api_resolve_dns_record_identifier(array $data): array {
    $recordIdentifierRaw = $data['record_id'] ?? null;
    $recordIdentifier = null;
    if ($recordIdentifierRaw !== null && !is_array($recordIdentifierRaw)) {
        $recordIdentifier = trim((string) $recordIdentifierRaw);
        if ($recordIdentifier === '') {
            $recordIdentifier = null;
        }
    }
    $localId = intval($data['id'] ?? 0);
    return [$recordIdentifier, $localId];
}

function api_find_dns_record_by_identifier(?string $recordIdentifier, int $localId = 0) {
    $rec = null;

    if ($localId > 0) {
        $rec = Capsule::table('mod_cloudflare_dns_records')->where('id', $localId)->first();
        if ($rec) {
            return $rec;
        }
    }

    if ($recordIdentifier !== null) {
        $rec = Capsule::table('mod_cloudflare_dns_records')->where('record_id', $recordIdentifier)->first();
        if ($rec) {
            return $rec;
        }

        if ($localId <= 0 && ctype_digit($recordIdentifier)) {
            $rec = Capsule::table('mod_cloudflare_dns_records')->where('id', intval($recordIdentifier))->first();
            if ($rec) {
                return $rec;
            }
        }
    }

    return null;
}

function api_resolve_record_name_for_subdomain(string $rawName, string $fullSubdomain): string {
    $name = trim($rawName);
    if ($name === '' || $name === '@') {
        return $fullSubdomain;
    }
    if (strpos($name, '.') !== false) {
        return strtolower($name);
    }
    return strtolower($name . '.' . $fullSubdomain);
}

function api_to_relative_record_name(string $name, string $fullSubdomain): string
{
    $normalizedName = strtolower(trim($name));
    $normalizedSubdomain = strtolower(trim($fullSubdomain));
    if ($normalizedName === '' || $normalizedName === '@') {
        return '@';
    }
    if ($normalizedSubdomain === '') {
        return $normalizedName;
    }
    if ($normalizedName === $normalizedSubdomain) {
        return '@';
    }
    $suffix = '.' . $normalizedSubdomain;
    if (substr($normalizedName, -strlen($suffix)) === $suffix) {
        $label = substr($normalizedName, 0, -strlen($suffix));
        return $label !== '' ? $label : '@';
    }
    return $normalizedName;
}

function api_build_dns_content_for_type(string $type, array $data, string $existingContent = ''): array {
    $type = strtoupper(trim($type));
    $contentInput = isset($data['content']) ? trim((string) $data['content']) : '';
    $content = $contentInput !== '' ? $contentInput : trim($existingContent);

    if ($type === 'SRV') {
        $hasSrvFields = isset($data['record_target']) || isset($data['target']) || isset($data['record_port']) || isset($data['port']) || isset($data['record_weight']) || isset($data['weight']) || isset($data['priority']);
        if ($hasSrvFields) {
            $priority = isset($data['priority']) ? intval($data['priority']) : 0;
            $weight = isset($data['record_weight']) ? intval($data['record_weight']) : intval($data['weight'] ?? 0);
            $port = isset($data['record_port']) ? intval($data['record_port']) : intval($data['port'] ?? 0);
            $target = trim((string)($data['record_target'] ?? ($data['target'] ?? '')));
            if ($target === '') {
                return [false, '', ['error' => 'record_target required for SRV']];
            }
            if ($port < 1 || $port > 65535) {
                return [false, '', ['error' => 'record_port invalid for SRV']];
            }
            $priority = max(0, min(65535, $priority));
            $weight = max(0, min(65535, $weight));
            $content = $priority . ' ' . $weight . ' ' . $port . ' ' . rtrim($target, '.');
        }
    } elseif ($type === 'CAA') {
        $hasCaaFields = isset($data['caa_value']) || isset($data['caa_tag']) || isset($data['caa_flag']);
        if ($hasCaaFields) {
            $caaValue = trim((string)($data['caa_value'] ?? ''));
            if ($caaValue === '') {
                return [false, '', ['error' => 'caa_value required for CAA']];
            }
            $caaTag = trim((string)($data['caa_tag'] ?? 'issue'));
            if ($caaTag === '') {
                $caaTag = 'issue';
            }
            $caaFlag = intval($data['caa_flag'] ?? 0);
            $content = $caaFlag . ' ' . $caaTag . ' "' . str_replace('"', '\\"', $caaValue) . '"';
        }
    }

    if ($content === '') {
        return [false, '', ['error' => 'content required']];
    }

    return [true, $content, []];
}

function api_execute_dns_via_client_core(string $action, array $payload, int $userid, array $settings, array $context = []): array
{
    if (!class_exists('CfClientActionService')) {
        return [500, ['success' => false, 'error' => 'client core unavailable']];
    }
    $previousPost = $_POST ?? [];
    $previousRequest = $_REQUEST ?? [];
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $previousUserId = $GLOBALS['userid'] ?? null;
    $previousModuleSettings = $GLOBALS['module_settings'] ?? null;
    $previousMsg = $GLOBALS['msg'] ?? null;
    $previousMsgType = $GLOBALS['msg_type'] ?? null;
    $previousRegisterError = $GLOBALS['registerError'] ?? null;

    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array_merge($payload, ['action' => $action]);
        $_REQUEST = array_merge($_REQUEST ?? [], $_POST);

        $globals = array_merge([
            'module_settings' => $settings,
            'userid' => $userid,
            'msg' => '',
            'msg_type' => '',
            'registerError' => '',
        ], $context);
        $globals['module_settings'] = $settings;
        $globals['userid'] = $userid;

        $result = CfClientActionService::process($globals);
        $msg = trim((string)($result['msg'] ?? ''));
        $msgType = trim((string)($result['msg_type'] ?? ''));
        $registerError = trim((string)($result['registerError'] ?? ''));
        $errorText = $registerError !== '' ? $registerError : $msg;

        if ($errorText !== '' && in_array($msgType, ['danger', 'warning'], true)) {
            $code = $msgType === 'warning' ? 403 : 400;
            return [$code, ['success' => false, 'error' => $errorText, 'msg_type' => $msgType]];
        }
        if ($errorText !== '' && $msgType === '') {
            return [400, ['success' => false, 'error' => $errorText]];
        }
        return [200, [
            'success' => true,
            'message' => $msg !== '' ? $msg : 'ok',
            'msg_type' => $msgType !== '' ? $msgType : 'success',
        ]];
    } catch (\Throwable $e) {
        return [500, ['success' => false, 'error' => 'client core execution failed', 'detail' => $e->getMessage()]];
    } finally {
        $_POST = $previousPost;
        $_REQUEST = $previousRequest;
        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }
        if ($previousUserId === null) {
            unset($GLOBALS['userid']);
        } else {
            $GLOBALS['userid'] = $previousUserId;
        }
        if ($previousModuleSettings === null) {
            unset($GLOBALS['module_settings']);
        } else {
            $GLOBALS['module_settings'] = $previousModuleSettings;
        }
        if ($previousMsg === null) {
            unset($GLOBALS['msg']);
        } else {
            $GLOBALS['msg'] = $previousMsg;
        }
        if ($previousMsgType === null) {
            unset($GLOBALS['msg_type']);
        } else {
            $GLOBALS['msg_type'] = $previousMsgType;
        }
        if ($previousRegisterError === null) {
            unset($GLOBALS['registerError']);
        } else {
            $GLOBALS['registerError'] = $previousRegisterError;
        }
    }
}

function api_handle_subdomain_get(array $data, $keyRow): array {
    $subId = api_resolve_subdomain_identifier($_GET['subdomain_id'] ?? ($data['subdomain_id'] ?? 0));
    if ($subId <= 0) {
        return [400, ['error' => 'subdomain_id required']];
    }

    $sub = Capsule::table('mod_cloudflare_subdomain')
        ->where('id', $subId)
        ->where('userid', $keyRow->userid)
        ->first();

    if (!$sub) {
        return [404, ['error' => 'subdomain not found']];
    }
    $fullDomain = api_build_full_domain((string) ($sub->subdomain ?? ''), (string) ($sub->rootdomain ?? ''));

    $records = Capsule::table('mod_cloudflare_dns_records')
        ->where('subdomain_id', $subId)
        ->orderBy('id', 'asc')
        ->get();

    $rows = [];
    foreach ($records as $record) {
        $rows[] = [
            'id' => intval($record->id),
            'record_id' => $record->record_id,
            'name' => $record->name,
            'type' => $record->type,
            'content' => $record->content,
            'ttl' => intval($record->ttl),
            'priority' => $record->priority,
            'line' => $record->line,
            'proxied' => boolval($record->proxied),
            'status' => $record->status,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    return [200, [
        'success' => true,
        'subdomain' => [
            'id' => api_get_or_create_public_subdomain_id((int) ($sub->id ?? 0)),
            'subdomain' => api_extract_subdomain_label((string) ($sub->subdomain ?? ''), (string) ($sub->rootdomain ?? '')),
            'rootdomain' => $sub->rootdomain,
            'full_domain' => $fullDomain,
            'status' => api_present_domain_status($sub->status),
            'created_at' => $sub->created_at,
            'updated_at' => $sub->updated_at,
            'expires_at' => $sub->expires_at,
            'never_expires' => intval($sub->never_expires ?? 0),
        ],
        'dns_records' => $rows,
        'dns_count' => count($rows),
    ]];
}

function api_build_full_domain(string $subdomain, string $rootdomain): string
{
    $subdomain = strtolower(trim($subdomain));
    $rootdomain = strtolower(trim($rootdomain));
    if ($subdomain === '') {
        return '';
    }
    if ($rootdomain === '') {
        return $subdomain;
    }
    if ($subdomain === $rootdomain) {
        return $subdomain;
    }
    $suffix = '.' . $rootdomain;
    if (substr($subdomain, -strlen($suffix)) === $suffix) {
        return $subdomain;
    }
    return $subdomain . $suffix;
}

function api_extract_subdomain_label(string $subdomain, string $rootdomain): string
{
    $subdomain = strtolower(trim($subdomain));
    $rootdomain = strtolower(trim($rootdomain));
    if ($subdomain === '') {
        return '';
    }
    if ($rootdomain === '') {
        return $subdomain;
    }
    $suffix = '.' . $rootdomain;
    if (substr($subdomain, -strlen($suffix)) === $suffix) {
        return substr($subdomain, 0, -strlen($suffix));
    }
    return $subdomain;
}

function api_handle_subdomain_delete(array $data, $keyRow, array $settings): array {
    if (api_setting_enabled($settings['maintenance_mode'] ?? '0')) {
        return [503, ['error' => 'System under maintenance']];
    }

    $subId = api_resolve_subdomain_identifier($data['subdomain_id'] ?? ($_POST['subdomain_id'] ?? ($_GET['subdomain_id'] ?? 0)));
    if ($subId <= 0) {
        return [400, ['error' => 'subdomain_id required']];
    }

    $sub = Capsule::table('mod_cloudflare_subdomain')
        ->where('id', $subId)
        ->where('userid', $keyRow->userid)
        ->first();
    if (!$sub) {
        return [404, ['error' => 'subdomain not found']];
    }

    $deleteMode = strtolower(trim((string) ($settings['client_domain_delete_mode'] ?? '')));
    if (!in_array($deleteMode, ['disabled', 'allow_all', 'never_had_dns_only', 'no_current_dns_only'], true)) {
        $deleteMode = api_setting_enabled($settings['enable_client_domain_delete'] ?? '0') ? 'never_had_dns_only' : 'disabled';
    }
    if ($deleteMode === 'disabled') {
        return [403, ['error' => 'subdomain self-delete disabled']];
    }

    $statusLower = strtolower(trim((string)($sub->status ?? '')));
    if (in_array($statusLower, ['pending_delete', 'pending_remove'], true)) {
        return [409, ['error' => 'subdomain deletion already pending']];
    }
    if ($statusLower === 'deleted') {
        return [410, ['error' => 'subdomain already deleted']];
    }

    if (intval($sub->gift_lock_id ?? 0) > 0) {
        return [403, ['error' => 'subdomain locked by gift transfer']];
    }

    $isPrivilegedUser = intval($keyRow->userid ?? 0) > 0
        && function_exists('cf_is_user_privileged')
        && cf_is_user_privileged(intval($keyRow->userid ?? 0));
    $privilegedAllowDeleteWithDnsHistory = $isPrivilegedUser
        && function_exists('cf_is_privileged_feature_enabled')
        && cf_is_privileged_feature_enabled('allow_delete_with_dns_history', $settings);

    $everHadDns = intval($sub->has_dns_history ?? 0) === 1;
    $currentDnsExists = false;
    if (!$everHadDns || $deleteMode === 'no_current_dns_only') {
        try {
            $currentDnsExists = (bool) Capsule::table('mod_cloudflare_dns_records')
                ->where('subdomain_id', $subId)
                ->exists();
            if ($currentDnsExists) {
                $everHadDns = true;
                if (class_exists('CfSubdomainService')) {
                    CfSubdomainService::markHasDnsHistory($subId);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    if (!$privilegedAllowDeleteWithDnsHistory && $deleteMode === 'never_had_dns_only' && $everHadDns) {
        return [403, ['error' => 'subdomain with dns history cannot be self-deleted']];
    }
    if (!$privilegedAllowDeleteWithDnsHistory && $deleteMode === 'no_current_dns_only' && $currentDnsExists) {
        return [403, ['error' => 'subdomain with current dns records cannot be self-deleted']];
    }

    $rootdomain = strtolower(trim((string)($sub->rootdomain ?? '')));
    if ($rootdomain !== '' && api_is_rootdomain_in_maintenance($rootdomain)) {
        return [503, ['error' => 'Root domain under maintenance', 'rootdomain' => $rootdomain]];
    }

    if (api_setting_enabled($settings['disable_dns_write'] ?? '0')) {
        return [403, ['error' => 'DNS modifications disabled']];
    }

    try {
        api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_DNS, $keyRow, $settings, 'api_subdomain_delete');
    } catch (CfRateLimitExceededException $e) {
        api_emit_scope_rate_limit_error($e);
    }

    $deletedDnsCount = 0;
    try {
        $providerContext = cfmod_acquire_provider_client_for_subdomain($sub, $settings);
        if (!$providerContext || empty($providerContext['client'])) {
            return [500, ['error' => 'provider unavailable']];
        }

        if (function_exists('cfmod_admin_deep_delete_subdomain')) {
            $deletedDnsCount = intval(cfmod_admin_deep_delete_subdomain($providerContext['client'], $sub));
        }
    } catch (\Throwable $e) {
        return [502, ['error' => 'provider delete failed', 'message' => cfmod_format_provider_error($e->getMessage())]];
    }

    try {
        Capsule::transaction(function () use ($sub, $subId) {
            Capsule::table('mod_cloudflare_subdomain')->where('id', $subId)->delete();

            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $sub->userid)
                ->lockForUpdate()
                ->first();
            if ($quota) {
                $newUsed = max(0, intval($quota->used_count ?? 0) - 1);
                Capsule::table('mod_cloudflare_subdomain_quotas')
                    ->where('userid', $sub->userid)
                    ->update([
                        'used_count' => $newUsed,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        });

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('api_delete_subdomain', [
            'subdomain' => api_extract_subdomain_label((string) ($sub->subdomain ?? ''), (string) ($sub->rootdomain ?? '')),
                'dns_records_deleted' => $deletedDnsCount,
            ], intval($sub->userid ?? 0), $subId);
        }

        return [200, [
            'success' => true,
            'message' => 'Subdomain deleted successfully',
            'subdomain_id' => $subId,
            'full_domain' => api_build_full_domain((string) ($sub->subdomain ?? ''), (string) ($sub->rootdomain ?? '')),
            'dns_records_deleted' => $deletedDnsCount,
        ]];
    } catch (\Throwable $e) {
        return [500, ['error' => 'delete failed']];
    }
}

if (!class_exists('ApiRenewException')) {
    class ApiRenewException extends \RuntimeException {
        protected $httpCode;
        protected $payload;

        public function __construct(string $message, int $httpCode = 400, array $payload = [])
        {
            parent::__construct($message);
            $this->httpCode = $httpCode;
            $this->payload = $payload;
        }

        public function getHttpCode(): int
        {
            return $this->httpCode;
        }

        public function getPayload(): array
        {
            return $this->payload;
        }
    }
}



function api_root_config_list(array $settings): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $domains = [];
    try {
        if (Capsule::schema()->hasTable('mod_cloudflare_rootdomains')) {
            $rows = Capsule::table('mod_cloudflare_rootdomains')
                ->where('status', 'active')
                ->orderBy('id', 'asc')
                ->get();
            foreach ($rows as $row) {
                $value = trim((string)($row->domain ?? ''));
                if ($value !== '') {
                    $domains[] = $value;
                }
            }
        }
    } catch (\Throwable $e) {
        // ignore lookup errors
    }
    $domains = array_values(array_unique($domains));
    $cache = $domains;
    return $domains;
}

function api_is_rootdomain_allowed(string $rootdomain, array $settings): bool {
    $rootdomain = strtolower(trim($rootdomain));
    if ($rootdomain === '') {
        return false;
    }

    $active = array_map('strtolower', api_root_config_list($settings));
    return in_array($rootdomain, $active, true);
}

function api_is_rootdomain_in_maintenance(string $rootdomain): bool {
    $rootdomain = strtolower(trim($rootdomain));
    if ($rootdomain === '') {
        return false;
    }
    try {
        $row = Capsule::table('mod_cloudflare_rootdomains')
            ->select('maintenance')
            ->whereRaw('LOWER(domain) = ?', [$rootdomain])
            ->first();
        if ($row) {
            return intval($row->maintenance ?? 0) === 1;
        }
    } catch (\Throwable $e) {
        // ignore lookup errors
    }
    return false;
}

function api_is_rootdomain_ns_management_disabled(string $rootdomain): bool {
    $rootdomain = strtolower(trim($rootdomain));
    if ($rootdomain === '') {
        return false;
    }
    try {
        $row = Capsule::table('mod_cloudflare_rootdomains')
            ->select('disable_ns_management')
            ->whereRaw('LOWER(domain) = ?', [$rootdomain])
            ->first();
        if ($row) {
            return intval($row->disable_ns_management ?? 0) === 1;
        }
    } catch (\Throwable $e) {
        // ignore lookup errors
    }
    return false;
}

function api_get_subdomain_rootdomain(int $subdomainId): string {
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

function api_get_user_quota(int $userid, array $settings) {
    if ($userid <= 0) {
        return null;
    }
    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_subdomain_quotas')) {
            return null;
        }
    } catch (\Throwable $e) {
        return null;
    }

    try {
        $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
    } catch (\Throwable $e) {
        $quota = null;
    }

    $maxBase = max(0, intval($settings['max_subdomain_per_user'] ?? 0));
    $inviteLimit = intval($settings['invite_bonus_limit_global'] ?? 5);
    if ($inviteLimit <= 0) {
        $inviteLimit = 5;
    }
    $isPrivileged = function_exists('cf_is_user_privileged') && cf_is_user_privileged($userid);
    $privilegedLimit = cf_get_privileged_limit();
    if ($isPrivileged) {
        $maxBase = $privilegedLimit;
    }
    $now = date('Y-m-d H:i:s');

    if (!$quota) {
        try {
            Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                'userid' => $userid,
                'used_count' => 0,
                'max_count' => $maxBase,
                'invite_bonus_count' => 0,
                'invite_bonus_limit' => $inviteLimit,
                'created_at' => $now,
                'updated_at' => $now
            ]);
            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userid)->first();
        } catch (\Throwable $e) {
            $quota = (object)[
                'userid' => $userid,
                'used_count' => 0,
                'max_count' => $maxBase,
                'invite_bonus_count' => 0,
                'invite_bonus_limit' => $inviteLimit,
            ];
        }
    }

    if ($isPrivileged) {
        return cf_ensure_privileged_quota($userid, $quota, $inviteLimit);
    }

    if (function_exists('cf_sync_user_base_quota_if_needed')) {
        $quota = cf_sync_user_base_quota_if_needed($userid, $maxBase, $quota);
    } elseif ($quota && $maxBase > 0 && intval($quota->max_count ?? 0) < $maxBase) {
        try {
            Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userid)
                ->update([
                    'max_count' => $maxBase,
                    'updated_at' => $now
                ]);
            $quota->max_count = $maxBase;
        } catch (\Throwable $e) {
            // ignore update errors
        }
    }

    if (function_exists('cf_sync_user_invite_limit_if_needed')) {
        $quota = cf_sync_user_invite_limit_if_needed($userid, $inviteLimit, $quota);
    } elseif ($quota && $inviteLimit > 0 && intval($quota->invite_bonus_limit ?? 0) < $inviteLimit) {
        try {
            Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userid)
                ->update([
                    'invite_bonus_limit' => $inviteLimit,
                    'updated_at' => $now
                ]);
            $quota->invite_bonus_limit = $inviteLimit;
        } catch (\Throwable $e) {
            // ignore update errors
        }
    }

    return $quota;
}

function api_cleanup_rate_limit_table(int $hours = 48, int $probabilityPerThousand = 10, int $batchSize = 1000): void {
    static $lastCleanupTs = null;
    $hours = max(1, $hours);
    $probabilityPerThousand = max(0, min(1000, $probabilityPerThousand));
    $batchSize = max(100, min(5000, $batchSize));

    if ($probabilityPerThousand <= 0) {
        return;
    }

    try {
        if (random_int(1, 1000) > $probabilityPerThousand) {
            return;
        }
    } catch (\Throwable $e) {
        if (mt_rand(1, 1000) > $probabilityPerThousand) {
            return;
        }
    }

    $now = time();
    if ($lastCleanupTs !== null && ($now - $lastCleanupTs) < 60) {
        return;
    }
    $lastCleanupTs = $now;

    try {
        if (!Capsule::schema()->hasTable('mod_cloudflare_api_rate_limit')) {
            return;
        }
    } catch (\Throwable $e) {
        return;
    }

    try {
        $cutoff = date('Y-m-d H:i:s', $now - $hours * 3600);
        Capsule::statement(
            'DELETE FROM `mod_cloudflare_api_rate_limit` WHERE `window_end` < ? ORDER BY `id` ASC LIMIT ' . intval($batchSize),
            [$cutoff]
        );
    } catch (\Throwable $e) {
        // ignore cleanup errors
    }
}

function api_fetch_api_key_usage_stats(array $keyIds): array {
    $keyIds = array_values(array_unique(array_filter(array_map('intval', $keyIds), function ($id) {
        return $id > 0;
    })));

    if (empty($keyIds)) {
        return [];
    }

    try {
        $rows = Capsule::table('mod_cloudflare_api_logs')
            ->select('api_key_id', Capsule::raw('COUNT(*) as request_count'), Capsule::raw('MAX(created_at) as last_used_at'))
            ->whereIn('api_key_id', $keyIds)
            ->groupBy('api_key_id')
            ->get();
    } catch (\Throwable $e) {
        return [];
    }

    $usage = [];
    foreach ($rows as $row) {
        $apiKeyId = intval($row->api_key_id ?? 0);
        if ($apiKeyId <= 0) {
            continue;
        }
        $usage[$apiKeyId] = [
            'request_count' => intval($row->request_count ?? 0),
            'last_used_at' => $row->last_used_at ?? null,
        ];
    }

    return $usage;
}

function api_auth(){
    $legacyApiKey = trim((string)($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
    $legacyApiSecret = trim((string)($_GET['api_secret'] ?? $_POST['api_secret'] ?? ''));
    if ($legacyApiKey !== '' || $legacyApiSecret !== '') {
        return [false, 'API credentials in URL/body are not allowed. Use X-API-Key and X-API-Secret headers'];
    }

    $apiKey = trim((string)api_get_header('X-API-Key'));
    $apiSecret = trim((string)api_get_header('X-API-Secret'));
    if ($apiKey === '' || $apiSecret === '') { return [false, 'Missing API credentials (use X-API-Key and X-API-Secret headers)']; }
    $row = Capsule::table('mod_cloudflare_api_keys')->where('api_key', $apiKey)->first();
    if (!$row) { return [false, 'Invalid API key']; }
    if (($row->status ?? '') !== 'active') { return [false, 'API key disabled']; }
    if (!password_verify((string)$apiSecret, (string)$row->api_secret)) { return [false, 'Invalid API secret']; }
    // IP whitelist
    $ipwl = trim((string)($row->ip_whitelist ?? ''));
    if ($ipwl !== ''){
        $ips = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $ipwl)));
        $clientIp = api_client_ip();
        $ipMatched = false;
        foreach ($ips as $entry) {
            if (api_ip_matches_whitelist_entry($clientIp, $entry)) {
                $ipMatched = true;
                break;
            }
        }
        if (!$ipMatched) {
            return [false, 'IP not allowed'];
        }
    }

    try {
        $client = Capsule::table('tblclients')->select('status')->where('id', $row->userid)->first();
        if ($client && strtolower($client->status ?? '') !== 'active') {
            return [false, 'User is banned'];
        }
        if (Capsule::schema()->hasTable('mod_cloudflare_user_bans')) {
            $banned = Capsule::table('mod_cloudflare_user_bans')
                ->where('userid', $row->userid)
                ->where('status', 'banned')
                ->exists();
            if ($banned) {
                return [false, 'User is banned'];
            }
        }
    } catch (\Throwable $e) {
        // ignore schema/query errors silently
    }

    return [true, $row];
}

function api_validate_ip_or_cidr(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    if (strpos($value, '/') === false) {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    $parts = explode('/', $value, 2);
    if (count($parts) !== 2) {
        return false;
    }

    $ip = trim($parts[0]);
    $maskRaw = trim($parts[1]);
    if ($ip === '' || $maskRaw === '' || !ctype_digit($maskRaw)) {
        return false;
    }

    $mask = intval($maskRaw);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $mask >= 0 && $mask <= 32;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return $mask >= 0 && $mask <= 128;
    }

    return false;
}

function api_ip_matches_whitelist_entry(string $ip, string $entry): bool
{
    $ip = trim($ip);
    $entry = trim($entry);
    if ($ip === '' || $entry === '') {
        return false;
    }

    if (strpos($entry, '/') === false) {
        return hash_equals($entry, $ip);
    }

    return api_ip_in_cidr($ip, $entry);
}

function api_ip_in_cidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return false;
    }

    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        return false;
    }

    $network = trim($parts[0]);
    $prefixRaw = trim($parts[1]);
    if ($network === '' || $prefixRaw === '' || !ctype_digit($prefixRaw)) {
        return false;
    }

    $ipBin = @inet_pton($ip);
    $networkBin = @inet_pton($network);
    if ($ipBin === false || $networkBin === false) {
        return false;
    }
    if (strlen($ipBin) !== strlen($networkBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    $prefix = intval($prefixRaw);
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }
    if ($prefix === 0) {
        return true;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    $ipByte = ord($ipBin[$fullBytes]);
    $networkByte = ord($networkBin[$fullBytes]);

    return (($ipByte & $mask) === ($networkByte & $mask));
}

function api_normalize_ip_whitelist($raw, &$error = ''): string {
    $error = '';
    $items = preg_split('/[,;\r\n]+/', (string)$raw);
    $normalized = [];

    foreach ($items as $item) {
        $candidate = trim((string)$item);
        if ($candidate === '') {
            continue;
        }
        if (!api_validate_ip_or_cidr($candidate)) {
            $error = 'invalid ip_whitelist entry: ' . $candidate;
            return '';
        }
        $normalized[] = $candidate;
    }

    $normalized = array_values(array_unique($normalized));
    return implode(',', $normalized);
}

function api_rate_limit($keyRow, ?array $settings = null){
    if ($settings === null) {
        $settings = api_load_settings();
    }

    $limit = intval($keyRow->rate_limit ?? 0);
    if ($limit <= 0) {
        $limit = intval($settings['api_rate_limit'] ?? 60);
    }
    $limit = max(1, min(6000, $limit));

    $windowKey = $keyRow->id . '_' . date('Y-m-d_H:i');
    $windowStart = date('Y-m-d H:i:00');
    $windowEnd = date('Y-m-d H:i:59');
    $now = date('Y-m-d H:i:s');
    $currentCount = null;

    try {
        Capsule::statement(
            'INSERT INTO `mod_cloudflare_api_rate_limit` (`api_key_id`, `window_key`, `request_count`, `window_start`, `window_end`, `created_at`, `updated_at`)' .
            ' VALUES (?, ?, 1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `request_count` = `request_count` + 1, `updated_at` = VALUES(`updated_at`)',
            [$keyRow->id, $windowKey, $windowStart, $windowEnd, $now, $now]
        );

        $currentCount = (int) Capsule::table('mod_cloudflare_api_rate_limit')
            ->where('api_key_id', intval($keyRow->id))
            ->where('window_key', $windowKey)
            ->sum('request_count');
    } catch (\Throwable $e) {
        error_log('[domain_hub][api_rate_limit] ' . $e->getMessage());
        return [false, [
            'error' => 'Rate limit temporarily unavailable',
            'reason' => 'storage_error',
            'remaining' => 0,
            'limit' => $limit,
            'reset_at' => $windowEnd,
            'status_code' => 503
        ]];
    }

    if ($currentCount === null || $currentCount <= 0) {
        $currentCount = 1;
    }

    $cleanupProbability = intval($settings['api_rate_limit_cleanup_probability_per_thousand'] ?? 10);
    $cleanupBatchSize = intval($settings['api_rate_limit_cleanup_batch_size'] ?? 1000);
    api_cleanup_rate_limit_table(48, $cleanupProbability, $cleanupBatchSize);

    if ($currentCount > $limit) {
        return [false, [
            'error' => 'Rate limit exceeded',
            'reason' => 'limit_exceeded',
            'remaining' => 0,
            'limit' => $limit,
            'reset_at' => $windowEnd,
            'status_code' => 429
        ]];
    }

    return [true, ['remaining' => max(0, $limit - $currentCount), 'limit' => $limit, 'reset_at' => $windowEnd]];
}

function api_enforce_scope_rate_limit(string $scope, $keyRow, array $settings, string $identifier): void
{
    if (!$keyRow) {
        return;
    }
    $limit = CfRateLimiter::resolveLimit($scope, $settings);
    CfRateLimiter::enforce($scope, $limit, [
        'userid' => intval($keyRow->userid ?? 0),
        'ip' => api_client_ip(),
        'identifier' => $identifier,
    ]);
}

function api_emit_scope_rate_limit_error(CfRateLimitExceededException $e): void
{
    $minutes = CfRateLimiter::formatRetryMinutes($e->getRetryAfterSeconds());
    api_json([
        'error' => 'rate_limit_exceeded',
        'message' => 'Too many requests. Please try again in ' . $minutes . ' minutes.',
        'retry_after_seconds' => $e->getRetryAfterSeconds(),
        'retry_after_minutes' => $minutes,
    ], 429);
    exit;
}

function cfmod_normalize_whois_domain(?string $domain): string {
    $clean = strtolower(trim((string) $domain));
    $clean = trim($clean, '.');
    if ($clean === '' || strpos($clean, '.') === false) {
        return '';
    }
    if (strlen($clean) > 253 || strpos($clean, '..') !== false) {
        return '';
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $clean)) {
        return '';
    }
    return $clean;
}

function cfmod_detect_allowed_rootdomain(string $domain, array $settings): ?string {
    $parts = explode('.', $domain);
    $count = count($parts);
    if ($count < 2) {
        return null;
    }
    for ($i = 1; $i < $count; $i++) {
        $candidateParts = array_slice($parts, $i);
        if (count($candidateParts) < 2) {
            continue;
        }
        $candidate = implode('.', $candidateParts);
        if (api_is_rootdomain_allowed($candidate, $settings)) {
            return $candidate;
        }
    }
    return null;
}

function cfmod_mask_email_for_whois(?string $email): string {
    $email = trim((string) $email);
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    $localMasked = strlen($local) > 2 ? substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) : str_repeat('*', strlen($local));
    $domainMasked = $domain;
    if (strlen($domain) > 4) {
        $parts = explode('.', $domain);
        $parts = array_map(function ($segment) {
            if (strlen($segment) <= 2) {
                return str_repeat('*', strlen($segment));
            }
            return substr($segment, 0, 1) . str_repeat('*', max(1, strlen($segment) - 2)) . substr($segment, -1);
        }, $parts);
        $domainMasked = implode('.', $parts);
    }
    return $localMasked . '@' . $domainMasked;
}

function cfmod_parse_default_nameservers($value): array {
    $list = preg_split('/[\r\n]+/', (string) $value);
    $result = [];
    foreach ($list as $item) {
        $item = trim($item);
        if ($item !== '') {
            $result[] = $item;
        }
    }
    return array_values(array_unique($result));
}

function cfmod_format_whois_api_payload(array $whoisData): array {
    $status = strtolower(trim((string) ($whoisData['status'] ?? '')));
    $registered = array_key_exists('registered', $whoisData)
        ? !empty($whoisData['registered'])
        : ($status !== 'unregistered');

    $source = trim((string) ($whoisData['source'] ?? ''));
    $sourceType = trim((string) ($whoisData['source_type'] ?? ''));
    if ($sourceType === '') {
        if (strtolower($source) === 'external') {
            $sourceType = 'external';
        } elseif ($source !== '') {
            $sourceType = 'internal';
        }
    }

    $nameServers = $whoisData['name_servers'] ?? ($whoisData['nameservers'] ?? []);
    if ($nameServers instanceof \Illuminate\Support\Collection) {
        $nameServers = $nameServers->all();
    }
    if (!is_array($nameServers)) {
        $nameServers = [];
    }

    $normalizedNameServers = [];
    foreach ($nameServers as $nameServer) {
        $value = trim((string) $nameServer);
        if ($value !== '') {
            $normalizedNameServers[] = $value;
        }
    }
    $normalizedNameServers = array_values(array_unique($normalizedNameServers));

    $registeredAt = trim((string) ($whoisData['created_at'] ?? ($whoisData['registered_at'] ?? '')));

    $publicStatus = ($status === 'suspended') ? 'ServerHold' : ($status !== '' ? $status : ($registered ? 'registered' : 'unregistered'));

    $response = [
        'success' => true,
        'domain' => trim((string) ($whoisData['domain'] ?? '')),
        'source' => $source,
        'source_type' => $sourceType,
        'registered' => $registered,
        'status' => $publicStatus,
        'registered_at' => $registeredAt,
        'created_at' => $registeredAt,
        'expires_at' => trim((string) ($whoisData['expires_at'] ?? '')),
        'privacy_enabled' => !empty($whoisData['privacy_enabled']),
        'registrant_name' => (string) ($whoisData['registrant_name'] ?? ''),
        'registrant_email' => (string) ($whoisData['registrant_email'] ?? ''),
        'registrant_phone' => (string) ($whoisData['registrant_phone'] ?? ''),
        'registrant_country' => (string) ($whoisData['registrant_country'] ?? ''),
        'registrant_province' => (string) ($whoisData['registrant_province'] ?? ''),
        'registrant_city' => (string) ($whoisData['registrant_city'] ?? ''),
        'registrant_province_city' => (string) ($whoisData['registrant_province_city'] ?? ''),
        'registrant_address' => (string) ($whoisData['registrant_address'] ?? ''),
        'privacy_notice' => (string) ($whoisData['privacy_notice'] ?? ''),
        'name_servers' => $normalizedNameServers,
        'nameservers' => $normalizedNameServers,
    ];

    if (!$registered) {
        $response['message'] = trim((string) ($whoisData['message'] ?? 'domain not registered'));
    }

    $rootDomain = trim((string) ($whoisData['root_domain'] ?? ''));
    if ($rootDomain !== '') {
        $response['root_domain'] = $rootDomain;
    }

    $rawText = $whoisData['raw'] ?? null;
    if (is_string($rawText) && trim($rawText) !== '') {
        $response['raw'] = $rawText;
    }

    return $response;
}

function api_present_domain_status($status): string
{
    $raw = trim((string) $status);
    if (strtolower($raw) === 'suspended') {
        return 'ServerHold';
    }
    return $raw;
}

function cfmod_build_whois_response($domainInput, array $settings, int $requestUserId = 0): array {
    $domain = cfmod_normalize_whois_domain($domainInput);
    if ($domain === '') {
        return [400, ['success' => false, 'error' => 'invalid domain'], null];
    }

    if (class_exists('CfWhoisService')) {
        try {
            $lookupResult = CfWhoisService::lookupDomain(max(0, $requestUserId), $domain);
            if (!is_array($lookupResult) || empty($lookupResult['success'])) {
                return [500, ['success' => false, 'error' => 'lookup failed'], null];
            }

            $resultData = $lookupResult['result'] ?? [];
            if (!is_array($resultData)) {
                $resultData = [];
            }
            if (empty($resultData['domain'])) {
                $resultData['domain'] = $domain;
            }

            return [200, cfmod_format_whois_api_payload($resultData), null];
        } catch (\RuntimeException $e) {
            $reason = strtolower(trim((string) $e->getMessage()));
            if (in_array($reason, ['invalid_domain', 'invalid domain'], true)) {
                return [400, ['success' => false, 'error' => 'invalid domain'], null];
            }
            return [500, ['success' => false, 'error' => 'lookup failed'], null];
        } catch (\Throwable $e) {
            return [500, ['success' => false, 'error' => 'lookup failed'], null];
        }
    }

    $rootdomain = cfmod_detect_allowed_rootdomain($domain, $settings);
    if ($rootdomain === null) {
        return [403, ['success' => false, 'error' => 'root domain unmanaged', 'domain' => $domain], null];
    }
    try {
        $record = Capsule::table('mod_cloudflare_subdomain as s')
            ->leftJoin('tblclients as c', 's.userid', '=', 'c.id')
            ->select('s.*', 'c.email as client_email')
            ->whereRaw('LOWER(s.subdomain)=?', [$domain])
            ->first();
    } catch (\Throwable $e) {
        return [500, ['success' => false, 'error' => 'lookup failed'], null];
    }
    if (!$record) {
        return [200, [
            'success' => true,
            'domain' => $domain,
            'registered' => false,
            'status' => 'unregistered',
            'message' => 'domain not registered'
        ], null];
    }

    $nameservers = [];
    try {
        $nsRows = Capsule::table('mod_cloudflare_dns_records')
            ->where('subdomain_id', $record->id)
            ->where('type', 'NS')
            ->orderBy('id', 'asc')
            ->pluck('content');
        if ($nsRows instanceof \Illuminate\Support\Collection) {
            $nsRows = $nsRows->all();
        }
        foreach ((array)$nsRows as $ns) {
            $ns = trim((string)$ns);
            if ($ns !== '') {
                $nameservers[] = $ns;
            }
        }
    } catch (\Throwable $e) {}
    if (empty($nameservers)) {
        $nameservers = cfmod_parse_default_nameservers($settings['whois_default_nameservers'] ?? '');
    }

    $emailMode = strtolower((string)($settings['whois_email_mode'] ?? 'anonymous'));
    $anonEmail = trim((string)($settings['whois_anonymous_email'] ?? 'whois@example.com'));
    if ($anonEmail === '') {
        $anonEmail = 'whois@example.com';
    }
    $clientEmail = trim((string)($record->client_email ?? ''));
    if ($emailMode === 'real') {
        $whoisEmail = $clientEmail !== '' ? $clientEmail : $anonEmail;
    } elseif ($emailMode === 'masked') {
        $masked = cfmod_mask_email_for_whois($clientEmail);
        $whoisEmail = $masked !== '' ? $masked : $anonEmail;
    } else {
        $whoisEmail = $anonEmail;
    }

    $neverExpires = (bool) ($record->never_expires ?? false);
    $expiresAt = $record->expires_at;
    if ($neverExpires) {
        $expiresAt = '2999-12-31 23:59';
    }

    $response = [
        'success' => true,
        'domain' => $record->subdomain,
        'status' => $record->status,
        'registered_at' => $record->created_at,
        'expires_at' => $expiresAt,
        'registrant_email' => $whoisEmail,
        'nameservers' => array_values(array_unique($nameservers)),
    ];

    return [200, $response, $record];
}

function cfmod_log_whois_query($record, string $mode): void {
    if (!function_exists('cloudflare_subdomain_log')) {
        return;
    }
    $details = [
        'domain' => $record->subdomain ?? '',
        'mode' => $mode,
        'ip' => api_client_ip(),
    ];
    $userid = isset($record->userid) ? intval($record->userid) : null;
    $subId = isset($record->id) ? intval($record->id) : null;
    cloudflare_subdomain_log('whois_query', $details, $userid > 0 ? $userid : null, $subId > 0 ? $subId : null);
}

function cfmod_log_whois_query_payload(array $payload, string $mode, ?int $requestUserId = null): void {
    if (!function_exists('cloudflare_subdomain_log')) {
        return;
    }
    $domain = trim((string) ($payload['domain'] ?? ''));
    if ($domain === '') {
        return;
    }
    $details = [
        'domain' => $domain,
        'mode' => $mode,
        'ip' => api_client_ip(),
        'source_type' => (string) ($payload['source_type'] ?? ''),
    ];
    $userid = ($requestUserId !== null && $requestUserId > 0) ? $requestUserId : null;
    cloudflare_subdomain_log('whois_query', $details, $userid, null);
}

function cfmod_cleanup_whois_rate_limit_table(int $probabilityPerThousand = 10, int $batchSize = 500): void {
    static $lastCleanup = null;
    $probabilityPerThousand = max(0, min(1000, $probabilityPerThousand));
    if ($probabilityPerThousand <= 0) {
        return;
    }

    try {
        if (random_int(1, 1000) > $probabilityPerThousand) {
            return;
        }
    } catch (\Throwable $e) {
        if (mt_rand(1, 1000) > $probabilityPerThousand) {
            return;
        }
    }

    $now = time();
    if ($lastCleanup !== null && ($now - $lastCleanup) < 60) {
        return;
    }
    $lastCleanup = $now;

    $batchSize = max(100, min(5000, $batchSize));

    try {
        Capsule::statement(
            'DELETE FROM `mod_cloudflare_whois_rate_limit` WHERE `window_end` < ? ORDER BY `id` ASC LIMIT ' . intval($batchSize),
            [date('Y-m-d H:i:s', $now - 3600)]
        );
    } catch (\Throwable $e) {}
}

function cfmod_whois_rate_limit(array $settings): array {
    $limit = intval($settings['whois_rate_limit_per_minute'] ?? 2);
    if ($limit <= 0) {
        return [true, null];
    }
    $ip = api_client_ip() ?: 'unknown';
    $windowKey = date('Y-m-d_H:i');
    $windowStart = date('Y-m-d H:i:00');
    $windowEnd = date('Y-m-d H:i:59');
    $now = date('Y-m-d H:i:s');

    $count = 1;
    try {
        Capsule::statement(
            'INSERT INTO `mod_cloudflare_whois_rate_limit` (`ip`,`window_key`,`request_count`,`window_start`,`window_end`,`created_at`,`updated_at`) VALUES (?,?,LAST_INSERT_ID(1),?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE `request_count` = LAST_INSERT_ID(`request_count` + 1), `updated_at` = VALUES(`updated_at`)',
            [$ip, $windowKey, $windowStart, $windowEnd, $now, $now]
        );
        $lastInsertRows = Capsule::select('SELECT LAST_INSERT_ID() AS current_count');
        if (is_array($lastInsertRows) && !empty($lastInsertRows)) {
            $count = max(1, intval($lastInsertRows[0]->current_count ?? 1));
        }
    } catch (\Throwable $e) {
        return [true, ['limit' => $limit, 'remaining' => $limit, 'reset_at' => $windowEnd]];
    }

    $whoisCleanupProbability = intval($settings['api_rate_limit_cleanup_probability_per_thousand'] ?? 10);
    $whoisCleanupBatch = intval($settings['api_rate_limit_cleanup_batch_size'] ?? 500);
    cfmod_cleanup_whois_rate_limit_table($whoisCleanupProbability, $whoisCleanupBatch);

    $meta = [
        'limit' => $limit,
        'remaining' => max(0, $limit - intval($count)),
        'reset_at' => $windowEnd
    ];
    if (intval($count) > $limit) {
        $meta['remaining'] = 0;
        return [false, $meta];
    }
    return [true, $meta];
}

function cfmod_handle_public_whois(array $settings, string $method, array $data): void {
    if (strtoupper($method) !== 'GET') {
        api_json(['success' => false, 'error' => 'method not allowed'], 405);
        return;
    }
    $domainParam = $_GET['domain'] ?? ($data['domain'] ?? '');
    $normalized = cfmod_normalize_whois_domain($domainParam);
    if ($normalized === '') {
        api_json(['success' => false, 'error' => 'invalid domain'], 400);
        return;
    }
    list($pass, $meta) = cfmod_whois_rate_limit($settings);
    if (!$pass) {
        $payload = ['success' => false, 'error' => 'rate limit exceeded'];
        if ($meta !== null) {
            $payload['rate_limit'] = $meta;
        }
        api_json($payload, 429);
        return;
    }
    list($status, $payload, $record) = cfmod_build_whois_response($normalized, $settings);
    if ($meta !== null) {
        $payload['rate_limit'] = $meta;
    }
    if ($status === 200) {
        if ($record) {
            cfmod_log_whois_query($record, 'public');
        } else {
            cfmod_log_whois_query_payload($payload, 'public');
        }
    }
    api_json($payload, $status);
}



function api_current_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = trim(str_replace('\\', '/', dirname($scriptName)));
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }

    return $scheme . '://' . $host . $dir;
}

function api_docs_spec_url(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $query = [
        'm' => defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub',
        'endpoint' => 'docs',
        'format' => 'openapi',
    ];

    return $scriptName . '?' . http_build_query($query);
}

function api_render_swagger_ui(string $specUrl): void
{
    $encodedSpecUrl = json_encode($specUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encodedSpecUrl) || $encodedSpecUrl === '') {
        $encodedSpecUrl = '""';
    }

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Domain Hub API Docs</title>';
    echo '<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">';
    echo '<style>html,body{margin:0;padding:0;background:#f7f8fa}#swagger-ui{max-width:1200px;margin:0 auto;padding:24px}</style>';
    echo '</head><body><div id="swagger-ui"></div>';
    echo '<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>';
    echo '<script>window.onload=function(){window.ui=SwaggerUIBundle({url:' . $encodedSpecUrl . ',dom_id:"#swagger-ui",deepLinking:true,presets:[SwaggerUIBundle.presets.apis],layout:"BaseLayout"});};</script>';
    echo '</body></html>';
}

function api_handle_docs_request(string $method, string $action, array $data = []): void
{
    if (strtoupper($method) !== 'GET') {
        api_json(['error' => 'method not allowed'], 405);
        return;
    }

    $format = strtolower(trim((string) ($_GET['format'] ?? ($data['format'] ?? 'openapi'))));
    $action = strtolower(trim($action));

    if ($action === 'swagger' || $format === 'swagger' || $format === 'ui') {
        api_render_swagger_ui(api_docs_spec_url());
        return;
    }

    $baseUrl = api_current_base_url();
    $spec = CfOpenApiSpec::build($baseUrl, __FILE__);
    api_json($spec, 200);
}

function handleApiRequest(){
    $t0 = microtime(true);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawIn = file_get_contents('php://input');
    $data = json_decode($rawIn, true);
    if (!is_array($data)) { $data = $_POST; }
    $endpoint = $_GET['endpoint'] ?? ($data['endpoint'] ?? '');
    $action = $_GET['action'] ?? ($data['action'] ?? '');
    $settings = api_load_settings();

    if ($endpoint === 'docs') {
        api_handle_docs_request($method, (string) $action, is_array($data) ? $data : []);
        return;
    }

    if ($endpoint === 'whois' && !api_setting_enabled($settings['whois_require_api_key'] ?? '0')) {
        cfmod_handle_public_whois($settings, $method, $data);
        return;
    }

    try {
        list($ok, $auth) = api_auth();
        if (!$ok) { api_json(['error'=>$auth], 401); return; }
        $keyRow = $auth;

        list($pass, $rl) = api_rate_limit($keyRow, $settings);
        if (!$pass){
            $status = intval($rl['status_code'] ?? 429);
            $payload = $rl;
            unset($payload['status_code']);
            if (!isset($payload['error'])) {
                $payload['error'] = 'Rate limit exceeded';
            }
            api_json($payload, $status);
            return;
        }
        if ($endpoint !== 'whois' && !api_setting_enabled($settings['enable_user_api'] ?? 'on')) {
            api_json(['error' => 'API access disabled'], 403);
            return;
        }

        $result = null;
        $code = 200;

        if ($endpoint === 'whois') {
            if (strtoupper($method) !== 'GET') {
                $code = 405;
                $result = ['error' => 'method not allowed'];
            } else {
                $domainParam = $_GET['domain'] ?? ($data['domain'] ?? '');
                list($code, $payload, $whoisRecord) = cfmod_build_whois_response($domainParam, $settings, intval($keyRow->userid ?? 0));
                $result = $payload;
                if ($code === 200) {
                    if ($whoisRecord) {
                        cfmod_log_whois_query($whoisRecord, 'api');
                    } else {
                        cfmod_log_whois_query_payload($payload, 'api', intval($keyRow->userid ?? 0));
                    }
                }
            }
        } elseif ($endpoint === 'quota' && $method === 'GET') {
            $quota = api_get_user_quota($keyRow->userid, $settings);
            if ($quota) {
                $used = intval($quota->used_count ?? 0);
                $base = intval($quota->max_count ?? 0);
                $bonus = intval($quota->invite_bonus_count ?? 0);
            } else {
                $used = 0;
                $base = intval($settings['max_subdomain_per_user'] ?? 0);
                $bonus = 0;
            }
            $total = $base;
            $available = max(0, $total - $used);
            $result = [
                'success' => true,
                'quota' => [
                    'used' => $used,
                    'base' => $base,
                    'invite_bonus' => $bonus,
                    'total' => $total,
                    'available' => $available
                ]
            ];
        } elseif ($endpoint === 'subdomains') {
            if ($action === 'list' && $method === 'GET') {
                $pageRaw = $_GET['page'] ?? ($data['page'] ?? 1);
                $page = intval($pageRaw);
                if ($page <= 0) {
                    $page = 1;
                }
                $perPageRaw = $_GET['per_page'] ?? ($data['per_page'] ?? 200);
                $perPage = intval($perPageRaw);
                if ($perPage <= 0) {
                    $perPage = 200;
                }
                $perPage = max(1, min(500, $perPage));
                $offset = ($page - 1) * $perPage;
                $includeTotalRaw = $_GET['include_total'] ?? ($data['include_total'] ?? null);
                $includeTotal = false;
                if ($includeTotalRaw !== null) {
                    $includeTotal = api_setting_enabled($includeTotalRaw);
                }

                $baseQuery = Capsule::table('mod_cloudflare_subdomain')
                    ->where('userid', $keyRow->userid);

                // 搜索关键词过滤
                $searchKeyword = trim($_GET['search'] ?? ($data['search'] ?? ''));
                if ($searchKeyword !== '') {
                    $baseQuery->where(function($q) use ($searchKeyword) {
                        $q->where('subdomain', 'like', '%' . $searchKeyword . '%')
                          ->orWhere('rootdomain', 'like', '%' . $searchKeyword . '%');
                    });
                }

                // 根域名过滤
                $filterRoot = trim($_GET['rootdomain'] ?? ($data['rootdomain'] ?? ''));
                if ($filterRoot !== '') {
                    $baseQuery->where('rootdomain', strtolower($filterRoot));
                }

                // 状态过滤
                $filterStatus = trim($_GET['status'] ?? ($data['status'] ?? ''));
                if ($filterStatus !== '' && in_array($filterStatus, ['active', 'suspended', 'expired'], true)) {
                    $baseQuery->where('status', $filterStatus);
                }

                // 创建时间范围过滤
                $dateFrom = trim($_GET['created_from'] ?? ($data['created_from'] ?? ''));
                if ($dateFrom !== '' && strtotime($dateFrom) !== false) {
                    $baseQuery->where('created_at', '>=', $dateFrom . ' 00:00:00');
                }
                $dateTo = trim($_GET['created_to'] ?? ($data['created_to'] ?? ''));
                if ($dateTo !== '' && strtotime($dateTo) !== false) {
                    $baseQuery->where('created_at', '<=', $dateTo . ' 23:59:59');
                }

                // 字段选择
                $fieldsParam = trim($_GET['fields'] ?? ($data['fields'] ?? ''));
                $allowedFields = ['id', 'subdomain', 'rootdomain', 'full_domain', 'status', 'created_at', 'updated_at', 'expires_at', 'never_expires', 'cloudflare_zone_id', 'provider_account_id'];
                $dbSelectableFields = ['id', 'subdomain', 'rootdomain', 'status', 'created_at', 'updated_at', 'expires_at', 'never_expires', 'cloudflare_zone_id', 'provider_account_id'];
                $responseFields = $allowedFields;
                if ($fieldsParam !== '' && strtolower($fieldsParam) !== 'all') {
                    $requestedFields = array_values(array_filter(array_map('trim', explode(',', $fieldsParam)), static function ($value) {
                        return $value !== '';
                    }));
                    $responseFields = array_values(array_intersect($requestedFields, $allowedFields));
                    if (empty($responseFields)) {
                        $responseFields = ['id'];
                    }
                    if (!in_array('id', $responseFields, true)) {
                        array_unshift($responseFields, 'id');
                    }
                }

                $selectFields = array_values(array_intersect($responseFields, $dbSelectableFields));
                if (in_array('full_domain', $responseFields, true) && !in_array('subdomain', $selectFields, true)) {
                    $selectFields[] = 'subdomain';
                }
                if (empty($selectFields)) {
                    $selectFields = ['id'];
                }

                // 排序选项
                $sortBy = trim($_GET['sort_by'] ?? ($data['sort_by'] ?? 'id'));
                $sortDir = strtolower(trim($_GET['sort_dir'] ?? ($data['sort_dir'] ?? 'desc')));
                $allowedSort = ['id', 'created_at', 'updated_at', 'expires_at', 'subdomain'];
                if (!in_array($sortBy, $allowedSort, true)) {
                    $sortBy = 'id';
                }
                if (!in_array($sortDir, ['asc', 'desc'], true)) {
                    $sortDir = 'desc';
                }

                $dataQuery = (clone $baseQuery)->select($selectFields)->orderBy($sortBy, $sortDir);
                $subsCollection = $dataQuery
                    ->offset($offset)
                    ->limit($perPage + 1)
                    ->get();
                if (!($subsCollection instanceof \Illuminate\Support\Collection)) {
                    $subsCollection = new \Illuminate\Support\Collection(is_array($subsCollection) ? $subsCollection : (array) $subsCollection);
                }
                $hasMore = $subsCollection->count() > $perPage;
                $subsLimited = $hasMore ? $subsCollection->slice(0, $perPage)->values() : $subsCollection->values();
                $rows = [];
                foreach ($subsLimited as $s) {
                    $providerAccountId = $s->provider_account_id ?? null;
                    if ($providerAccountId !== null && $providerAccountId !== '') {
                        $providerAccountId = intval($providerAccountId);
                    }
                    $fullDomain = api_build_full_domain((string) ($s->subdomain ?? ''), (string) ($s->rootdomain ?? ''));

                    $fieldValues = [
                        'id' => api_get_or_create_public_subdomain_id((int) ($s->id ?? 0)),
                        'subdomain' => api_extract_subdomain_label((string) ($s->subdomain ?? ''), (string) ($s->rootdomain ?? '')),
                        'rootdomain' => $s->rootdomain ?? null,
                        'full_domain' => $fullDomain !== '' ? $fullDomain : null,
                        'status' => api_present_domain_status($s->status ?? null),
                        'created_at' => $s->created_at ?? null,
                        'updated_at' => $s->updated_at ?? null,
                        'expires_at' => $s->expires_at ?? null,
                        'never_expires' => intval($s->never_expires ?? 0),
                        'cloudflare_zone_id' => $s->cloudflare_zone_id ?? null,
                        'provider_account_id' => $providerAccountId,
                    ];

                    $row = [];
                    foreach ($responseFields as $fieldName) {
                        if (array_key_exists($fieldName, $fieldValues)) {
                            $row[$fieldName] = $fieldValues[$fieldName];
                        }
                    }
                    $rows[] = $row;
                }
                $meta = [
                    'page' => $page,
                    'per_page' => $perPage,
                    'has_more' => $hasMore,
                ];
                if ($hasMore) {
                    $meta['next_page'] = $page + 1;
                }
                if ($page > 1) {
                    $meta['prev_page'] = $page - 1;
                }
                if ($includeTotal) {
                    $meta['total'] = (clone $baseQuery)->count();
                }
                $result = [
                    'success' => true,
                    'count' => count($rows),
                    'subdomains' => $rows,
                    'pagination' => $meta
                ];
            } elseif ($action === 'get' && $method === 'GET') {
                list($code, $result) = api_handle_subdomain_get($data, $keyRow);
            } elseif ($action === 'register' && in_array($method, ['POST', 'PUT'])) {
                try {
                    api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_REGISTER, $keyRow, $settings, 'api_subdomain_register');
                } catch (CfRateLimitExceededException $e) {
                    api_emit_scope_rate_limit_error($e);
                }
                list($code, $result) = api_handle_subdomain_register($data, $keyRow, $settings);
            } elseif ($action === 'renew' && in_array($method, ['POST', 'PUT'])) {
                list($code, $result) = api_handle_subdomain_renew($data, $keyRow, $settings);
            } elseif ($action === 'delete' && in_array($method, ['POST', 'DELETE'])) {
                list($code, $result) = api_handle_subdomain_delete($data, $keyRow, $settings);
            } else {
                $code = 404;
                $result = ['error' => 'unknown action'];
            }
        } elseif ($endpoint === 'gifts') {
            if (!function_exists('cfmod_is_domain_gift_enabled') || !cfmod_is_domain_gift_enabled($settings)) {
                $code = 403;
                $result = ['error' => 'domain gift feature disabled'];
            } elseif ($action === 'initiate' && in_array($method, ['POST', 'PUT'], true)) {
                try {
                    api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_API_KEY, $keyRow, $settings, 'api_gifts_initiate');
                } catch (CfRateLimitExceededException $e) {
                    api_emit_scope_rate_limit_error($e);
                }
                $subdomainId = api_resolve_subdomain_identifier($data['subdomain_id'] ?? 0);
                if ($subdomainId <= 0) {
                    $code = 422;
                    $result = ['error' => 'missing subdomain_id'];
                } else {
                    $ttlHours = function_exists('cfmod_get_domain_gift_ttl_hours') ? cfmod_get_domain_gift_ttl_hours($settings) : 24;
                    $now = time();
                    $nowStr = date('Y-m-d H:i:s', $now);
                    try {
                        $giftResult = Capsule::transaction(function () use ($subdomainId, $keyRow, $ttlHours, $now, $nowStr) {
                            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                                ->where('id', $subdomainId)
                                ->where('userid', $keyRow->userid)
                                ->lockForUpdate()
                                ->first();
                            if (!$subdomain) {
                                throw new \RuntimeException('subdomain not found');
                            }
                            if (strtotime($subdomain->created_at) > strtotime('-7 days', $now)) {
                                throw new \RuntimeException('subdomain age less than 7 days');
                            }
                            if (intval($subdomain->gift_lock_id ?? 0) > 0) {
                                throw new \RuntimeException('subdomain is already in gift transfer');
                            }
                            $attempt = 0;
                            do {
                                $attempt++;
                                $giftCode = function_exists('cfmod_generate_domain_gift_code') ? cfmod_generate_domain_gift_code() : '';
                                $exists = $giftCode !== '' ? Capsule::table('mod_cloudflare_domain_gifts')->where('code', $giftCode)->exists() : true;
                            } while ($exists && $attempt < 5);
                            if ($giftCode === '' || $exists) {
                                throw new \RuntimeException('failed to generate gift code');
                            }
                            $expiresAt = date('Y-m-d H:i:s', $now + (max(1, intval($ttlHours)) * 3600));
                            $giftId = Capsule::table('mod_cloudflare_domain_gifts')->insertGetId([
                                'subdomain_id' => $subdomain->id,
                                'from_userid' => intval($keyRow->userid),
                                'to_userid' => null,
                                'code' => $giftCode,
                                'full_domain' => (string)($subdomain->subdomain ?? ''),
                                'status' => 'pending',
                                'expires_at' => $expiresAt,
                                'created_at' => $nowStr,
                                'updated_at' => $nowStr,
                            ]);
                            Capsule::table('mod_cloudflare_subdomain')
                                ->where('id', $subdomain->id)
                                ->update(['gift_lock_id' => $giftId, 'updated_at' => $nowStr]);
                            return [
                                'gift_id' => intval($giftId),
                                'subdomain_id' => api_get_or_create_public_subdomain_id((int) $subdomain->id),
                                'full_domain' => (string)($subdomain->subdomain ?? ''),
                                'code' => $giftCode,
                                'expires_at' => $expiresAt,
                            ];
                        });
                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_gift_initiate', $giftResult, intval($keyRow->userid), intval($giftResult['subdomain_id'] ?? 0));
                        }
                        $result = ['success' => true, 'data' => $giftResult];
                    } catch (\RuntimeException $e) {
                        $code = 422;
                        $result = ['error' => $e->getMessage()];
                    } catch (\Throwable $e) {
                        $code = 500;
                        $result = ['error' => 'gift initiate failed'];
                    }
                }
            } elseif ($action === 'accept' && in_array($method, ['POST', 'PUT'], true)) {
                try {
                    api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_API_KEY, $keyRow, $settings, 'api_gifts_accept');
                } catch (CfRateLimitExceededException $e) {
                    api_emit_scope_rate_limit_error($e);
                }
                $giftCode = trim((string)($data['code'] ?? ''));
                if ($giftCode === '') {
                    $code = 422;
                    $result = ['error' => 'missing code'];
                } else {
                    $nowStr = date('Y-m-d H:i:s');
                    try {
                        $acceptResult = Capsule::transaction(function () use ($giftCode, $keyRow, $settings, $nowStr) {
                            $gift = Capsule::table('mod_cloudflare_domain_gifts')->where('code', $giftCode)->lockForUpdate()->first();
                            if (!$gift || $gift->status !== 'pending') {
                                throw new \RuntimeException('gift code invalid');
                            }
                            if (strtotime((string)$gift->expires_at) < time()) {
                                throw new \RuntimeException('gift code expired');
                            }
                            if (intval($gift->from_userid) === intval($keyRow->userid)) {
                                throw new \RuntimeException('cannot accept your own gift');
                            }
                            $subdomain = Capsule::table('mod_cloudflare_subdomain')->where('id', $gift->subdomain_id)->lockForUpdate()->first();
                            if (!$subdomain || intval($subdomain->gift_lock_id ?? 0) !== intval($gift->id)) {
                                throw new \RuntimeException('subdomain state invalid');
                            }
                            Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomain->id)->update([
                                'userid' => intval($keyRow->userid),
                                'gift_lock_id' => null,
                                'updated_at' => $nowStr,
                            ]);
                            Capsule::table('mod_cloudflare_domain_gifts')->where('id', $gift->id)->update([
                                'status' => 'accepted',
                                'to_userid' => intval($keyRow->userid),
                                'completed_at' => $nowStr,
                                'updated_at' => $nowStr,
                            ]);
                            return [
                                'gift_id' => intval($gift->id),
                                'subdomain_id' => api_get_or_create_public_subdomain_id((int) $subdomain->id),
                                'full_domain' => (string)($subdomain->subdomain ?? ''),
                                'from_userid' => intval($gift->from_userid),
                            ];
                        });
                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_gift_accept', $acceptResult, intval($keyRow->userid), intval($acceptResult['subdomain_id'] ?? 0));
                        }
                        $result = ['success' => true, 'data' => $acceptResult];
                    } catch (\RuntimeException $e) {
                        $code = 422;
                        $result = ['error' => $e->getMessage()];
                    } catch (\Throwable $e) {
                        $code = 500;
                        $result = ['error' => 'gift accept failed'];
                    }
                }
            } elseif ($action === 'cancel' && in_array($method, ['POST', 'DELETE'], true)) {
                try {
                    api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_API_KEY, $keyRow, $settings, 'api_gifts_cancel');
                } catch (CfRateLimitExceededException $e) {
                    api_emit_scope_rate_limit_error($e);
                }
                $giftId = intval($data['gift_id'] ?? 0);
                if ($giftId <= 0) {
                    $code = 422;
                    $result = ['error' => 'missing gift_id'];
                } else {
                    $nowStr = date('Y-m-d H:i:s');
                    try {
                        $cancelResult = Capsule::transaction(function () use ($giftId, $keyRow, $nowStr) {
                            $gift = Capsule::table('mod_cloudflare_domain_gifts')->where('id', $giftId)->lockForUpdate()->first();
                            if (!$gift || intval($gift->from_userid) !== intval($keyRow->userid) || $gift->status !== 'pending') {
                                throw new \RuntimeException('gift record invalid');
                            }
                            Capsule::table('mod_cloudflare_domain_gifts')->where('id', $giftId)->update([
                                'status' => 'cancelled',
                                'cancelled_at' => $nowStr,
                                'updated_at' => $nowStr,
                            ]);
                            Capsule::table('mod_cloudflare_subdomain')
                                ->where('id', $gift->subdomain_id)
                                ->where('gift_lock_id', $gift->id)
                                ->update(['gift_lock_id' => null, 'updated_at' => $nowStr]);
                            return [
                                'gift_id' => intval($giftId),
                                'subdomain_id' => api_get_or_create_public_subdomain_id((int) $gift->subdomain_id),
                                'full_domain' => (string)($gift->full_domain ?? ''),
                            ];
                        });
                        if (function_exists('cloudflare_subdomain_log')) {
                            cloudflare_subdomain_log('client_gift_cancel', $cancelResult, intval($keyRow->userid), intval($cancelResult['subdomain_id'] ?? 0));
                        }
                        $result = ['success' => true, 'data' => $cancelResult];
                    } catch (\RuntimeException $e) {
                        $code = 422;
                        $result = ['error' => $e->getMessage()];
                    } catch (\Throwable $e) {
                        $code = 500;
                        $result = ['error' => 'gift cancel failed'];
                    }
                }
            } elseif ($action === 'list' && $method === 'GET') {
                $records = Capsule::table('mod_cloudflare_domain_gifts')
                    ->where(function ($q) use ($keyRow) {
                        $q->where('from_userid', intval($keyRow->userid))
                          ->orWhere('to_userid', intval($keyRow->userid));
                    })
                    ->orderBy('id', 'desc')
                    ->limit(50)
                    ->get();
                $items = [];
                foreach ($records as $record) {
                    $items[] = [
                        'id' => intval($record->id ?? 0),
                        'code' => (string)($record->code ?? ''),
                        'full_domain' => (string)($record->full_domain ?? ''),
                        'status' => (string)($record->status ?? ''),
                        'from_userid' => intval($record->from_userid ?? 0),
                        'to_userid' => intval($record->to_userid ?? 0),
                        'expires_at' => $record->expires_at ?? null,
                        'created_at' => $record->created_at ?? null,
                    ];
                }
                $result = ['success' => true, 'gifts' => $items, 'count' => count($items)];
            } else {
                $code = 404;
                $result = ['error' => 'unknown action'];
            }
        } elseif ($endpoint === 'dns_records') {
            if ($action === 'list' && $method === 'GET') {
                $sid = api_resolve_subdomain_identifier($_GET['subdomain_id'] ?? ($data['subdomain_id'] ?? 0));
                $s = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $sid)
                    ->where('userid', $keyRow->userid)
                    ->first();
                if (!$s) {
                    $code = 404;
                    $result = ['error' => 'subdomain not found'];
                } else {
                    $cursor = intval($_GET['cursor'] ?? ($data['cursor'] ?? 0));
                    if ($cursor < 0) {
                        $cursor = 0;
                    }
                    $perPage = intval($_GET['per_page'] ?? ($data['per_page'] ?? 100));
                    if ($perPage <= 0) {
                        $perPage = 100;
                    }
                    $perPage = max(1, min(500, $perPage));

                    $allowedFields = ['id', 'record_id', 'name', 'type', 'content', 'ttl', 'priority', 'line', 'proxied', 'status', 'created_at', 'updated_at'];
                    $dbSelectableFields = $allowedFields;
                    $fieldsParam = trim((string) ($_GET['fields'] ?? ($data['fields'] ?? 'light')));
                    $responseFields = ['id', 'record_id', 'name', 'type', 'content', 'ttl', 'proxied', 'status'];
                    if ($fieldsParam !== '' && strtolower($fieldsParam) !== 'light') {
                        if (strtolower($fieldsParam) === 'all') {
                            $responseFields = $allowedFields;
                        } else {
                            $requestedFields = array_values(array_filter(array_map('trim', explode(',', $fieldsParam)), static function ($value) {
                                return $value !== '';
                            }));
                            $responseFields = array_values(array_intersect($requestedFields, $allowedFields));
                            if (empty($responseFields)) {
                                $responseFields = ['id'];
                            }
                        }
                    }
                    if (!in_array('id', $responseFields, true)) {
                        array_unshift($responseFields, 'id');
                    }
                    $selectFields = array_values(array_intersect($responseFields, $dbSelectableFields));
                    if (empty($selectFields)) {
                        $selectFields = ['id'];
                    }

                    $recordsQuery = Capsule::table('mod_cloudflare_dns_records')
                        ->where('subdomain_id', $sid)
                        ->orderBy('id', 'asc');
                    if ($cursor > 0) {
                        $recordsQuery->where('id', '>', $cursor);
                    }

                    $recs = $recordsQuery
                        ->select($selectFields)
                        ->limit($perPage + 1)
                        ->get();

                    if (!($recs instanceof \Illuminate\Support\Collection)) {
                        $recs = new \Illuminate\Support\Collection(is_array($recs) ? $recs : (array) $recs);
                    }

                    $hasMore = $recs->count() > $perPage;
                    $recsLimited = $hasMore ? $recs->slice(0, $perPage)->values() : $recs->values();

                    $rows = [];
                    $nextCursor = $cursor;
                    foreach ($recsLimited as $r) {
                        $fieldValues = [
                            'id' => intval($r->id ?? 0),
                            'record_id' => $r->record_id ?? null,
                            'name' => $r->name ?? null,
                            'type' => $r->type ?? null,
                            'content' => $r->content ?? null,
                            'ttl' => intval($r->ttl ?? 0),
                            'priority' => $r->priority ?? null,
                            'line' => $r->line ?? null,
                            'proxied' => boolval($r->proxied ?? false),
                            'status' => $r->status ?? null,
                            'created_at' => $r->created_at ?? null,
                            'updated_at' => $r->updated_at ?? null,
                        ];

                        $row = [];
                        foreach ($responseFields as $fieldName) {
                            if (array_key_exists($fieldName, $fieldValues)) {
                                $row[$fieldName] = $fieldValues[$fieldName];
                            }
                        }
                        $rows[] = $row;
                        $nextCursor = intval($r->id ?? $nextCursor);
                    }

                    $result = [
                        'success' => true,
                        'count' => count($rows),
                        'records' => $rows,
                        'pagination' => [
                            'cursor' => $cursor,
                            'per_page' => $perPage,
                            'has_more' => $hasMore,
                            'next_cursor' => $hasMore ? $nextCursor : null,
                        ],
                    ];
                }
            } elseif ($action === 'create' && in_array($method, ['POST', 'PUT'])) {
                $apiDnsCreateSid = api_resolve_subdomain_identifier($data['subdomain_id'] ?? 0);
                $apiDnsCreateRoot = api_get_subdomain_rootdomain($apiDnsCreateSid);
                if (api_setting_enabled($settings['maintenance_mode'] ?? '0')) {
                    $code = 503;
                    $result = ['error' => 'System under maintenance'];
                } elseif (api_is_rootdomain_in_maintenance($apiDnsCreateRoot)) {
                    $code = 503;
                    $result = ['error' => 'Root domain under maintenance', 'rootdomain' => $apiDnsCreateRoot];
                } elseif (api_setting_enabled($settings['disable_dns_write'] ?? '0')) {
                    $code = 403;
                    $result = ['error' => 'DNS modifications disabled'];
                } else {
                    try {
                        api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_DNS, $keyRow, $settings, 'api_dns_create');
                    } catch (CfRateLimitExceededException $e) {
                        api_emit_scope_rate_limit_error($e);
                    }
                    $sid = api_resolve_subdomain_identifier($data['subdomain_id'] ?? 0);
                    $type = strtoupper(trim((string)($data['type'] ?? '')));
                    $ttl = cfmod_normalize_ttl($data['ttl'] ?? 600);
                    $allowedTypes = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'SRV', 'CAA'];
                    if (!in_array($type, $allowedTypes, true)) {
                        $code = 400;
                        $result = ['error' => 'invalid type'];
                    } elseif ($type === 'NS' && (api_setting_enabled($settings['disable_ns_management'] ?? '0') || api_is_rootdomain_ns_management_disabled($apiDnsCreateRoot))) {
                        $code = 403;
                        $result = ['error' => 'NS management disabled'];
                    } else {
                        $s = Capsule::table('mod_cloudflare_subdomain')
                            ->where('id', $sid)
                            ->where('userid', $keyRow->userid)
                            ->first();
                        if (!$s) {
                            $code = 404;
                            $result = ['error' => 'subdomain not found'];
                        } elseif (strtolower($s->status ?? '') === 'suspended') {
                            $code = 403;
                            $result = ['error' => 'subdomain suspended'];
                        } else {
                            $providerContext = cfmod_acquire_provider_client_for_subdomain($s, $settings);
                            $limitPerSub = intval($settings['max_dns_records_per_subdomain'] ?? 0);
                            if (!$providerContext || empty($providerContext['client'])) {
                                $code = 500;
                                $result = ['error' => 'provider unavailable'];
                            } else {
                                $cf = $providerContext['client'];
                                $name = api_resolve_record_name_for_subdomain((string)($data['name'] ?? '@'), (string)$s->subdomain);
                                list($contentOk, $content, $contentError) = api_build_dns_content_for_type($type, $data);
                                if (!$contentOk) {
                                    $code = 400;
                                    $result = $contentError;
                                } else {
                                    $priority = intval($data['priority'] ?? 10);
                                    $priority = max(0, min(65535, $priority));
                                    $line = api_normalize_line_value($data['line'] ?? '');
                                    if ($line !== '' && !api_is_alidns_provider($providerContext)) {
                                        $code = 400;
                                        $result = ['error' => 'line unsupported by provider'];
                                    } else {
                                    $adapterPayload = [
                                        'subdomain_id' => $sid,
                                        'record_type' => $type,
                                        'record_content' => $content,
                                        'record_ttl' => $ttl,
                                        'record_name' => ($data['name'] ?? '@'),
                                        'record_priority' => $priority,
                                        'line' => $line,
                                    ];
                                    if (isset($data['record_target']) || isset($data['target'])) {
                                        $adapterPayload['record_target'] = (string)($data['record_target'] ?? ($data['target'] ?? ''));
                                    }
                                    if (isset($data['record_port']) || isset($data['port'])) {
                                        $adapterPayload['record_port'] = intval($data['record_port'] ?? ($data['port'] ?? 0));
                                    }
                                    if (isset($data['record_weight']) || isset($data['weight'])) {
                                        $adapterPayload['record_weight'] = intval($data['record_weight'] ?? ($data['weight'] ?? 0));
                                    }
                                    if (isset($data['caa_flag'])) $adapterPayload['caa_flag'] = intval($data['caa_flag']);
                                    if (isset($data['caa_tag'])) $adapterPayload['caa_tag'] = (string)$data['caa_tag'];
                                    if (isset($data['caa_value'])) $adapterPayload['caa_value'] = (string)$data['caa_value'];

                                    list($adapterCode, $adapterResult) = api_execute_dns_via_client_core('create_dns', $adapterPayload, intval($keyRow->userid ?? 0), $settings, [
                                        'disableDnsWrite' => api_setting_enabled($settings['disable_dns_write'] ?? '0'),
                                        'isUserBannedOrInactive' => false,
                                        'banReasonText' => '',
                                    ]);
                                    if (!empty($adapterResult['success'])) {
                                        $latestQuery = Capsule::table('mod_cloudflare_dns_records')
                                            ->where('subdomain_id', $sid)
                                            ->where('name', api_normalize_dns_name($name))
                                            ->where('type', strtoupper($type))
                                            ->where('content', $content)
                                            ->whereRaw('COALESCE(`line`, "") = ?', [$line]);
                                        if (in_array(strtoupper($type), ['MX', 'SRV'], true)) {
                                            $latestQuery->where('priority', $priority);
                                        } else {
                                            $latestQuery->whereNull('priority');
                                        }
                                        $latest = $latestQuery->orderBy('id', 'desc')->first();
                                        if (!$latest) {
                                            $latest = Capsule::table('mod_cloudflare_dns_records')
                                                ->where('subdomain_id', $sid)
                                                ->where('name', api_normalize_dns_name($name))
                                                ->where('type', strtoupper($type))
                                                ->whereRaw('COALESCE(`line`, "") = ?', [$line])
                                                ->orderBy('id', 'desc')
                                                ->first();
                                        }
                                        $result = [
                                            'success' => true,
                                            'message' => 'DNS record created successfully',
                                            'id' => intval($latest->id ?? 0),
                                            'record_id' => $latest->record_id ?? null,
                                        ];
                                    } else {
                                        $code = $adapterCode;
                                        $result = ['error' => (string)($adapterResult['error'] ?? 'create failed')];
                                    }
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif ($action === 'delete' && in_array($method, ['POST', 'DELETE'])) {
                list($recordIdentifier, $localId) = api_resolve_dns_record_identifier($data);
                $apiDnsDeleteRoot = '';
                if ($recordIdentifier !== null || $localId > 0) {
                    try {
                        $apiDnsDeleteRec = api_find_dns_record_by_identifier($recordIdentifier, $localId);
                        if ($apiDnsDeleteRec) {
                            $apiDnsDeleteSid = intval($apiDnsDeleteRec->subdomain_id ?? 0);
                            $apiDnsDeleteRoot = api_get_subdomain_rootdomain($apiDnsDeleteSid);
                        }
                    } catch (\Throwable $e) {}
                }
                if (api_setting_enabled($settings['maintenance_mode'] ?? '0')) {
                    $code = 503;
                    $result = ['error' => 'System under maintenance'];
                } elseif ($apiDnsDeleteRoot !== '' && api_is_rootdomain_in_maintenance($apiDnsDeleteRoot)) {
                    $code = 503;
                    $result = ['error' => 'Root domain under maintenance', 'rootdomain' => $apiDnsDeleteRoot];
                } elseif (api_setting_enabled($settings['disable_dns_write'] ?? '0')) {
                    $code = 403;
                    $result = ['error' => 'DNS modifications disabled'];
                } else {
                    try {
                        api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_DNS, $keyRow, $settings, 'api_dns_delete');
                    } catch (CfRateLimitExceededException $e) {
                        api_emit_scope_rate_limit_error($e);
                    }
                    $rec = api_find_dns_record_by_identifier($recordIdentifier, $localId);
                    if (!$rec) {
                        $code = 404;
                        $result = ['error' => 'record not found'];
                    } else {
                        $sid = intval($rec->subdomain_id);
                        $zone = $rec->zone_id;
                        $rid = trim((string) ($rec->record_id ?? ''));
                        $s = Capsule::table('mod_cloudflare_subdomain')
                            ->where('id', $sid)
                            ->where('userid', $keyRow->userid)
                            ->first();
                        if (!$s) {
                            $code = 403;
                            $result = ['error' => 'forbidden'];
                        } elseif (strtolower($s->status ?? '') === 'suspended') {
                            $code = 403;
                            $result = ['error' => 'subdomain suspended'];
                        } else {
                            $providerContext = cfmod_acquire_provider_client_for_subdomain($s, $settings);
                            if (!$providerContext || empty($providerContext['client'])) {
                                $code = 500;
                                $result = ['error' => 'provider unavailable'];
                            } else {
                                $adapterPayload = [
                                    'subdomain_id' => $sid,
                                    'record_id' => $rec->record_id ?? null,
                                    'id' => intval($rec->id ?? 0),
                                ];
                                list($adapterCode, $adapterResult) = api_execute_dns_via_client_core('delete_dns_record', $adapterPayload, intval($keyRow->userid ?? 0), $settings, [
                                    'disableDnsWrite' => api_setting_enabled($settings['disable_dns_write'] ?? '0'),
                                    'isUserBannedOrInactive' => false,
                                    'banReasonText' => '',
                                ]);
                                if (!empty($adapterResult['success'])) {
                                    $result = ['success' => true, 'message' => 'DNS record deleted successfully'];
                                } else {
                                    $code = $adapterCode;
                                    $result = ['error' => (string)($adapterResult['error'] ?? 'delete failed')];
                                }
                            }
                        }
                    }
                }
            } elseif ($action === 'update' && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                list($recordIdentifier, $localId) = api_resolve_dns_record_identifier($data);
                $apiDnsUpdateRoot = '';
                if ($recordIdentifier !== null || $localId > 0) {
                    try {
                        $apiDnsUpdateRec = api_find_dns_record_by_identifier($recordIdentifier, $localId);
                        if ($apiDnsUpdateRec) {
                            $apiDnsUpdateSid = intval($apiDnsUpdateRec->subdomain_id ?? 0);
                            $apiDnsUpdateRoot = api_get_subdomain_rootdomain($apiDnsUpdateSid);
                        }
                    } catch (\Throwable $e) {
                    }
                }

                if (api_setting_enabled($settings['maintenance_mode'] ?? '0')) {
                    $code = 503;
                    $result = ['error' => 'System under maintenance'];
                } elseif ($apiDnsUpdateRoot !== '' && api_is_rootdomain_in_maintenance($apiDnsUpdateRoot)) {
                    $code = 503;
                    $result = ['error' => 'Root domain under maintenance', 'rootdomain' => $apiDnsUpdateRoot];
                } elseif (api_setting_enabled($settings['disable_dns_write'] ?? '0')) {
                    $code = 403;
                    $result = ['error' => 'DNS modifications disabled'];
                } else {
                    try {
                        api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_DNS, $keyRow, $settings, 'api_dns_update');
                    } catch (CfRateLimitExceededException $e) {
                        api_emit_scope_rate_limit_error($e);
                    }

                    if ($recordIdentifier === null && $localId <= 0) {
                        $code = 400;
                        $result = ['error' => 'record_id required'];
                    } else {
                        $rec = api_find_dns_record_by_identifier($recordIdentifier, $localId);
                        if (!$rec) {
                            $code = 404;
                            $result = ['error' => 'record not found'];
                        } else {
                            $sid = intval($rec->subdomain_id ?? 0);
                            $s = Capsule::table('mod_cloudflare_subdomain')
                                ->where('id', $sid)
                                ->where('userid', $keyRow->userid)
                                ->first();
                            if (!$s) {
                                $code = 403;
                                $result = ['error' => 'forbidden'];
                            } elseif (strtolower($s->status ?? '') === 'suspended') {
                                $code = 403;
                                $result = ['error' => 'subdomain suspended'];
                            } else {
                                $targetType = strtoupper(trim((string)($data['type'] ?? $rec->type)));
                                $allowedTypes = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'SRV', 'CAA'];
                                if (!in_array($targetType, $allowedTypes, true)) {
                                    $code = 400;
                                    $result = ['error' => 'invalid type'];
                                } elseif ($targetType === 'NS' && (api_setting_enabled($settings['disable_ns_management'] ?? '0') || api_is_rootdomain_ns_management_disabled($apiDnsUpdateRoot))) {
                                    $code = 403;
                                    $result = ['error' => 'NS management disabled'];
                                } else {
                                    $hasAnyInput = isset($data['content']) || isset($data['ttl']) || isset($data['priority'])
                                        || isset($data['name']) || isset($data['type']) || isset($data['line'])
                                        || isset($data['record_target']) || isset($data['target'])
                                        || isset($data['record_port']) || isset($data['port'])
                                        || isset($data['record_weight']) || isset($data['weight'])
                                        || isset($data['caa_value']) || isset($data['caa_tag']) || isset($data['caa_flag']);
                                    if (!$hasAnyInput) {
                                        $code = 400;
                                        $result = ['error' => 'no updates specified'];
                                    } else {
                                        $zone = $rec->zone_id ?: ($s->cloudflare_zone_id ?: $s->rootdomain);
                                        $targetName = array_key_exists('name', $data)
                                            ? api_resolve_record_name_for_subdomain((string)$data['name'], (string)$s->subdomain)
                                            : (string)($rec->name ?? $s->subdomain);
                                        $ttlValue = array_key_exists('ttl', $data)
                                            ? cfmod_normalize_ttl($data['ttl'])
                                            : cfmod_normalize_ttl($rec->ttl ?? 600);
                                        $priorityValue = array_key_exists('priority', $data)
                                            ? intval($data['priority'])
                                            : intval($rec->priority ?? 10);
                                        $priorityValue = max(0, min(65535, $priorityValue));
                                        $lineValue = array_key_exists('line', $data)
                                            ? api_normalize_line_value($data['line'])
                                            : api_normalize_line_value($rec->line ?? '');

                                        list($contentOk, $targetContent, $contentError) = api_build_dns_content_for_type($targetType, $data, (string)($rec->content ?? ''));
                                        if (!$contentOk) {
                                            $code = 400;
                                            $result = $contentError;
                                        } elseif (trim((string)($rec->record_id ?? '')) === '') {
                                            $code = 400;
                                            $result = ['error' => 'provider record_id missing'];
                                        } else {
                                            $providerContext = cfmod_acquire_provider_client_for_subdomain($s, $settings);
                                            if (!$providerContext || empty($providerContext['client'])) {
                                                $code = 500;
                                                $result = ['error' => 'provider unavailable'];
                                            } else {
                                                $lineUnsupported = false;
                                                if (!api_is_alidns_provider($providerContext)) {
                                                    if (array_key_exists('line', $data) && $lineValue !== '') {
                                                        $code = 400;
                                                        $result = ['error' => 'line unsupported by provider'];
                                                        $lineUnsupported = true;
                                                    } else {
                                                        $lineValue = '';
                                                    }
                                                }
                                                if (!$lineUnsupported) {
                                                $adapterRecordName = api_to_relative_record_name($targetName, (string) $s->subdomain);
                                                $adapterPayload = [
                                                    'subdomain_id' => $sid,
                                                    'record_id' => $rec->record_id ?? null,
                                                    'id' => intval($rec->id ?? 0),
                                                    'record_type' => $targetType,
                                                    'record_name' => $adapterRecordName,
                                                    'record_content' => $targetContent,
                                                    'record_ttl' => $ttlValue,
                                                    'record_priority' => $priorityValue,
                                                    'line' => $lineValue,
                                                ];
                                                if ($targetType === 'SRV') {
                                                    $parts = preg_split('/\s+/', trim((string)$targetContent));
                                                    if (is_array($parts) && count($parts) >= 4) {
                                                        $adapterPayload['record_priority'] = intval($parts[0]);
                                                        $adapterPayload['record_weight'] = intval($parts[1]);
                                                        $adapterPayload['record_port'] = intval($parts[2]);
                                                        $adapterPayload['record_target'] = (string)$parts[3];
                                                    }
                                                }
                                                if ($targetType === 'CAA') {
                                                    $adapterPayload['caa_value'] = (string)($data['caa_value'] ?? '');
                                                    $adapterPayload['caa_tag'] = (string)($data['caa_tag'] ?? 'issue');
                                                    $adapterPayload['caa_flag'] = intval($data['caa_flag'] ?? 0);
                                                }
                                                list($adapterCode, $adapterResult) = api_execute_dns_via_client_core('update_dns', $adapterPayload, intval($keyRow->userid ?? 0), $settings, [
                                                    'disableDnsWrite' => api_setting_enabled($settings['disable_dns_write'] ?? '0'),
                                                    'isUserBannedOrInactive' => false,
                                                    'banReasonText' => '',
                                                ]);
                                                if (!empty($adapterResult['success'])) {
                                                    $latest = Capsule::table('mod_cloudflare_dns_records')->where('id', intval($rec->id))->first();
                                                    $result = [
                                                        'success' => true,
                                                        'message' => 'DNS record updated successfully',
                                                        'id' => intval($latest->id ?? $rec->id),
                                                        'record_id' => $latest->record_id ?? ($rec->record_id ?? null),
                                                    ];
                                                } else {
                                                    $code = $adapterCode;
                                                    $result = ['error' => (string)($adapterResult['error'] ?? 'update failed')];
                                                }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $code = 404;
                $result = ['error' => 'unknown action'];
            }
        } elseif ($endpoint === 'permanent_upgrade') {
            if (!class_exists('CfDomainPermanentUpgradeService')) {
                $code = 503;
                $result = ['error' => 'service_unavailable'];
            } elseif (!CfDomainPermanentUpgradeService::isEnabled($settings)) {
                $code = 403;
                $result = ['error' => 'feature_disabled'];
            } elseif ($action === 'list' && $method === 'GET') {
                $page = max(1, intval($_GET['page'] ?? ($data['page'] ?? 1)));
                $perPage = max(1, min(50, intval($_GET['per_page'] ?? ($data['per_page'] ?? 10))));
                $state = CfDomainPermanentUpgradeService::getUserState((int) ($keyRow->userid ?? 0), $settings, $page, $perPage);
                $result = [
                    'success' => true,
                    'state' => $state,
                ];
            } elseif (in_array($method, ['POST', 'PUT'], true) && $action === 'create') {
                $subdomainId = api_resolve_subdomain_identifier($data['subdomain_id'] ?? ($_POST['subdomain_id'] ?? 0));
                try {
                    $payload = CfDomainPermanentUpgradeService::createOrGetRequest((int) ($keyRow->userid ?? 0), $subdomainId, $settings);
                    $result = ['success' => true, 'request' => $payload];
                } catch (\InvalidArgumentException $e) {
                    $code = 400;
                    $result = ['error' => trim((string) $e->getMessage())];
                } catch (\Throwable $e) {
                    $code = 500;
                    $result = ['error' => 'internal_error'];
                }
            } elseif (in_array($method, ['POST', 'PUT'], true) && $action === 'assist') {
                $assistCode = trim((string) ($data['assist_code'] ?? ($_POST['assist_code'] ?? '')));
                try {
                    $helperClient = Capsule::table('tblclients')
                        ->select('email')
                        ->where('id', (int) ($keyRow->userid ?? 0))
                        ->first();
                    $helperEmail = strtolower(trim((string) ($helperClient->email ?? '')));
                    $helperIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                    $payload = CfDomainPermanentUpgradeService::assistByCode(
                        (int) ($keyRow->userid ?? 0),
                        $assistCode,
                        $helperEmail,
                        $helperIp,
                        $settings
                    );
                    $result = ['success' => true, 'assist' => $payload];
                } catch (\InvalidArgumentException $e) {
                    $code = 400;
                    $result = ['error' => trim((string) $e->getMessage())];
                } catch (\Throwable $e) {
                    $code = 500;
                    $result = ['error' => 'internal_error'];
                }
            } elseif (in_array($method, ['POST', 'DELETE'], true) && $action === 'cancel') {
                $requestId = intval($data['request_id'] ?? ($_POST['request_id'] ?? 0));
                try {
                    $payload = CfDomainPermanentUpgradeService::cancelRequest((int) ($keyRow->userid ?? 0), $requestId);
                    $result = ['success' => true, 'cancel' => $payload];
                } catch (\InvalidArgumentException $e) {
                    $code = 400;
                    $result = ['error' => trim((string) $e->getMessage())];
                } catch (\Throwable $e) {
                    $code = 500;
                    $result = ['error' => 'internal_error'];
                }
            } else {
                $code = 404;
                $result = ['error' => 'unknown action'];
            }
        } elseif ($endpoint === 'keys' && $method === 'GET' && $action === 'list') {
            $keys = Capsule::table('mod_cloudflare_api_keys')
                ->where('userid', $keyRow->userid)
                ->orderBy('id', 'desc')
                ->get();
            $keyIds = [];
            foreach ($keys as $k) {
                $keyIds[] = intval($k->id ?? 0);
            }
            $usageMap = api_fetch_api_key_usage_stats($keyIds);

            $rows = [];
            foreach ($keys as $k) {
                $apiKeyId = intval($k->id ?? 0);
                $usage = $usageMap[$apiKeyId] ?? null;
                $rows[] = [
                    'id' => $k->id,
                    'key_name' => $k->key_name,
                    'api_key' => $k->api_key,
                    'status' => $k->status,
                    'request_count' => intval($usage['request_count'] ?? $k->request_count),
                    'last_used_at' => $usage['last_used_at'] ?? $k->last_used_at,
                    'created_at' => $k->created_at
                ];
            }
            $result = ['success' => true, 'count' => count($rows), 'keys' => $rows];
        } elseif ($endpoint === 'keys' && in_array($method, ['POST', 'PUT']) && $action === 'create') {
            if (api_setting_enabled($settings['maintenance_mode'] ?? '0')) {
                $code = 503;
                $result = ['error' => 'System under maintenance'];
            } else {
                try {
                    api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_API_KEY, $keyRow, $settings, 'api_keys_create');
                } catch (CfRateLimitExceededException $e) {
                    api_emit_scope_rate_limit_error($e);
                }
                $keyName = trim((string)($data['key_name'] ?? ''));
                $ipWhitelistRaw = trim((string)($data['ip_whitelist'] ?? ''));
                $ipWhitelist = '';

                if ($keyName === '') {
                    $code = 400;
                    $result = ['error' => 'key_name required'];
                } else {
                    if ($ipWhitelistRaw !== '') {
                        if (!api_setting_enabled($settings['api_enable_ip_whitelist'] ?? '0')) {
                            $code = 403;
                            $result = ['error' => 'ip whitelist disabled'];
                        } else {
                            $ipWhitelistError = '';
                            $ipWhitelist = api_normalize_ip_whitelist($ipWhitelistRaw, $ipWhitelistError);
                            if ($ipWhitelistError !== '') {
                                $code = 400;
                                $result = ['error' => $ipWhitelistError];
                            }
                        }
                    }

                    if ($code === 200) {
                        try {
                            $existingCount = Capsule::table('mod_cloudflare_api_keys')
                                ->where('userid', $keyRow->userid)
                                ->count();
                            $maxKeys = intval($settings['api_keys_per_user'] ?? 3);
                            if ($existingCount >= $maxKeys) {
                                $code = 403;
                                $result = ['error' => 'key limit exceeded'];
                            } else {
                                $apiKey = 'cfsd_' . bin2hex(random_bytes(16));
                                $apiSecret = bin2hex(random_bytes(32));
                                $hashedSecret = password_hash($apiSecret, PASSWORD_DEFAULT);
                                $now = date('Y-m-d H:i:s');
                                $rateLimit = max(1, intval($settings['api_rate_limit'] ?? 60));
                                Capsule::table('mod_cloudflare_api_keys')->insert([
                                    'userid' => $keyRow->userid,
                                    'key_name' => $keyName,
                                    'api_key' => $apiKey,
                                    'api_secret' => $hashedSecret,
                                    'status' => 'active',
                                    'ip_whitelist' => $ipWhitelist !== '' ? $ipWhitelist : null,
                                    'rate_limit' => $rateLimit,
                                    'request_count' => 0,
                                    'created_at' => $now,
                                    'updated_at' => $now
                                ]);
                                $result = [
                                    'success' => true,
                                    'message' => 'API key created successfully',
                                    'api_key' => $apiKey,
                                    'api_secret' => $apiSecret,
                                    'rate_limit' => $rateLimit
                                ];
                                if ($ipWhitelist !== '') {
                                    $result['ip_whitelist'] = $ipWhitelist;
                                }
                            }
                        } catch (\Throwable $e) {
                            $code = 500;
                            $result = ['error' => 'create failed'];
                        }
                    }
                }
            }
        } elseif ($endpoint === 'keys' && in_array($method, ['POST', 'DELETE']) && $action === 'delete') {
            try {
                api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_API_KEY, $keyRow, $settings, 'api_keys_delete');
            } catch (CfRateLimitExceededException $e) {
                api_emit_scope_rate_limit_error($e);
            }
            $targetId = intval($data['id'] ?? ($data['key_id'] ?? 0));
            if ($targetId <= 0) {
                $code = 400;
                $result = ['error' => 'key id required'];
            } else {
                try {
                    $keyRowDelete = Capsule::table('mod_cloudflare_api_keys')
                        ->where('id', $targetId)
                        ->where('userid', $keyRow->userid)
                        ->first();
                    if (!$keyRowDelete) {
                        $code = 404;
                        $result = ['error' => 'key not found'];
                    } else {
                        Capsule::table('mod_cloudflare_api_keys')->where('id', $targetId)->delete();
                        $result = ['success' => true, 'message' => 'API key deleted successfully'];
                    }
                } catch (\Throwable $e) {
                    $code = 500;
                    $result = ['error' => 'delete failed'];
                }
            }
        } elseif ($endpoint === 'keys' && in_array($method, ['POST', 'PUT']) && $action === 'regenerate') {
            try {
                api_enforce_scope_rate_limit(CfRateLimiter::SCOPE_API_KEY, $keyRow, $settings, 'api_keys_regenerate');
            } catch (CfRateLimitExceededException $e) {
                api_emit_scope_rate_limit_error($e);
            }
            $targetId = intval($data['id'] ?? ($data['key_id'] ?? 0));
            if ($targetId <= 0) {
                $code = 400;
                $result = ['error' => 'key id required'];
            } else {
                try {
                    $keyRowReg = Capsule::table('mod_cloudflare_api_keys')
                        ->where('id', $targetId)
                        ->where('userid', $keyRow->userid)
                        ->first();
                    if (!$keyRowReg) {
                        $code = 404;
                        $result = ['error' => 'key not found'];
                    } else {
                        $apiSecret = bin2hex(random_bytes(32));
                        $hashedSecret = password_hash($apiSecret, PASSWORD_DEFAULT);
                        Capsule::table('mod_cloudflare_api_keys')
                            ->where('id', $targetId)
                            ->update([
                                'api_secret' => $hashedSecret,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        $result = ['success' => true, 'message' => 'API key regenerated successfully', 'api_secret' => $apiSecret];
                    }
                } catch (\Throwable $e) {
                    $code = 500;
                    $result = ['error' => 'regenerate failed'];
                }
            }
        } else {
            $code = 404;
            $result = ['error' => 'Unknown endpoint'];
        }

        cfmod_log_api_request($keyRow, (string)$endpoint, (string)$method, ($data ?: ($_REQUEST ?? [])), $result, $code, $t0);
        api_json($result, $code);
    } catch (\Throwable $e) {
        api_json(['error' => 'server error'], 500);
    }
}
