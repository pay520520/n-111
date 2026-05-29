<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/ProviderResolver.php';

if (!function_exists('cfmod_admin_provider_error_text')) {
    function cfmod_admin_provider_error_text($result): string
    {
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
}

if (!function_exists('cfmod_admin_provider_not_found')) {
    function cfmod_admin_provider_not_found($result): bool
    {
        if (is_array($result)) {
            $code = $result['code'] ?? ($result['http_code'] ?? null);
            if ($code === 404 || $code === '404') {
                return true;
            }
        }

        $message = strtolower(cfmod_admin_provider_error_text($result));
        if ($message === '') {
            return false;
        }

        return strpos($message, 'not found') !== false
            || strpos($message, 'record not found') !== false
            || strpos($message, 'does not exist') !== false
            || strpos($message, 'no such') !== false
            || strpos($message, '不存在') !== false;
    }
}

if (!function_exists('cfmod_admin_normalize_delete_result')) {
    function cfmod_admin_normalize_delete_result($result): array
    {
        if (!is_array($result)) {
            return [
                'success' => false,
                'deleted_count' => 0,
                'failed_count' => 1,
                'failed_items' => [['error' => 'invalid_delete_response']],
            ];
        }
        $failedItems = [];
        if (isset($result['failed_items']) && is_array($result['failed_items'])) {
            $failedItems = $result['failed_items'];
        }
        $failedCount = intval($result['failed_count'] ?? count($failedItems));
        if ($failedCount < 0) {
            $failedCount = 0;
        }
        $success = !empty($result['success']);
        if ($failedCount > 0) {
            $success = false;
        }
        return [
            'success' => $success,
            'deleted_count' => max(0, intval($result['deleted_count'] ?? 0)),
            'failed_count' => $failedCount,
            'failed_items' => $failedItems,
        ];
    }
}

if (!function_exists('cfmod_admin_verify_subdomain_remote_empty')) {
    function cfmod_admin_verify_subdomain_remote_empty($cf, string $zoneId, string $subdomainName): bool
    {
        static $zoneCache = [];
        static $zoneCacheTs = [];
        if (!$cf || !method_exists($cf, 'getDnsRecords')) {
            return false;
        }

        $target = strtolower(rtrim($subdomainName, '.'));
        if ($target === '') {
            return false;
        }

        $cacheKey = strtolower(trim($zoneId));
        $nowTs = time();
        $full = null;
        if (isset($zoneCache[$cacheKey], $zoneCacheTs[$cacheKey]) && ($nowTs - intval($zoneCacheTs[$cacheKey])) <= 20) {
            $full = $zoneCache[$cacheKey];
        } else {
            $full = $cf->getDnsRecords($zoneId, null, ['per_page' => 2000]);
            if ($full['success'] ?? false) {
                $zoneCache[$cacheKey] = $full;
                $zoneCacheTs[$cacheKey] = $nowTs;
            }
        }
        if ($full['success'] ?? false) {
            foreach (($full['result'] ?? []) as $remoteRecord) {
                $name = strtolower(rtrim((string) ($remoteRecord['name'] ?? ''), '.'));
                if ($name === $target || (strlen($name) > strlen($target) && substr($name, - (strlen($target) + 1)) === ('.' . $target))) {
                    return false;
                }
            }
            return true;
        }

        $partial = $cf->getDnsRecords($zoneId, $subdomainName, ['per_page' => 1000]);
        if (!($partial['success'] ?? false)) {
            return false;
        }
        foreach (($partial['result'] ?? []) as $remoteRecord) {
            $name = strtolower(rtrim((string) ($remoteRecord['name'] ?? ''), '.'));
            if ($name === $target || (strlen($name) > strlen($target) && substr($name, - (strlen($target) + 1)) === ('.' . $target))) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('cfmod_admin_deep_delete_subdomain')) {
    function cfmod_admin_deep_delete_subdomain($cf, $record, string $errorMessage = '当前子域绑定的 DNS 供应商不可用，请联系管理员'): int
    {
        if (!$record) {
            return 0;
        }

        $zoneId = $record->cloudflare_zone_id ?? '';
        if (!$zoneId && !empty($record->rootdomain)) {
            $zoneId = $record->rootdomain;
        }
        $subdomainName = strtolower(trim($record->subdomain ?? ''));
        $deletedCount = 0;

        if (!$cf) {
            $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
            $providerContext = cfmod_acquire_provider_client_for_subdomain($record, $settings);
            if ($providerContext && !empty($providerContext['client'])) {
                $cf = $providerContext['client'];
            } else {
                $cf = null;
            }
        }

        if (!$cf || !$zoneId || $subdomainName === '') {
            throw new \RuntimeException($errorMessage);
        }

        $remoteSuccess = false;
        $remoteNotFound = false;
        $lastRemoteError = '';

        $tryDelete = static function ($res, int &$deletedCount, bool &$remoteSuccess, bool &$remoteNotFound, string &$lastRemoteError): void {
            if (!is_array($res)) {
                return;
            }
            $normalized = cfmod_admin_normalize_delete_result($res);
            if ($normalized['success']) {
                $deletedCount = max($deletedCount, intval($normalized['deleted_count'] ?? 0));
                $remoteSuccess = true;
                return;
            }
            if (cfmod_admin_provider_not_found($res)) {
                $remoteNotFound = true;
                return;
            }
            $detail = cfmod_admin_provider_error_text($res);
            if ($detail === '' && !empty($normalized['failed_items'])) {
                $encoded = json_encode(array_slice($normalized['failed_items'], 0, 3), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') {
                    $detail = $encoded;
                }
            }
            $lastRemoteError = $detail !== '' ? $detail : 'partial_delete_failed';
        };

        try {
            $deepRes = $cf->deleteDomainRecordsDeep($zoneId, $subdomainName);
            $tryDelete($deepRes, $deletedCount, $remoteSuccess, $remoteNotFound, $lastRemoteError);
        } catch (\Throwable $e) {
            $lastRemoteError = $e->getMessage();
        }

        if (!$remoteSuccess && !$remoteNotFound) {
            try {
                $fallbackRes = $cf->deleteDomainRecords($zoneId, $subdomainName);
                $tryDelete($fallbackRes, $deletedCount, $remoteSuccess, $remoteNotFound, $lastRemoteError);
            } catch (\Throwable $e) {
                $lastRemoteError = $e->getMessage();
            }
        }

        if (!$remoteSuccess && !$remoteNotFound && !empty($record->dns_record_id)) {
            try {
                $singleRes = $cf->deleteSubdomain($zoneId, $record->dns_record_id, [
                    'name' => $subdomainName,
                ]);
                if (($singleRes['success'] ?? false) || cfmod_admin_provider_not_found($singleRes)) {
                    $remoteSuccess = true;
                    $deletedCount = max($deletedCount, 1);
                } else {
                    $lastRemoteError = cfmod_admin_provider_error_text($singleRes);
                }
            } catch (\Throwable $e) {
                $lastRemoteError = $e->getMessage();
            }
        }

        if (!$remoteSuccess && !$remoteNotFound) {
            $detail = trim($lastRemoteError);
            if ($detail !== '') {
                $detail = function_exists('cfmod_format_provider_error') ? cfmod_format_provider_error($detail) : $detail;
                throw new \RuntimeException($errorMessage . '：' . $detail);
            }
            throw new \RuntimeException($errorMessage);
        }

        if (!$remoteNotFound) {
            $verifiedEmpty = false;
            try {
                $verifiedEmpty = cfmod_admin_verify_subdomain_remote_empty($cf, $zoneId, $subdomainName);
            } catch (\Throwable $e) {
                $verifiedEmpty = false;
            }
            if (!$verifiedEmpty) {
                throw new \RuntimeException('远端记录删除结果无法确认，已阻止本地清理以避免数据不一致');
            }
        }

        Capsule::table('mod_cloudflare_dns_records')->where('subdomain_id', $record->id)->delete();

        return $deletedCount;
    }
}
