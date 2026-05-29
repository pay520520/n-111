<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfLoggingSupport
{
    public static function log(string $action, $details = '', $userid = null, $subdomainId = null): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $normalized = self::normalizeSubdomainId($subdomainId);
            $normalizedSubdomainId = $normalized['internal_id'];
            $normalizedDetails = self::normalizeDetails($details, $normalized);

            Capsule::table('mod_cloudflare_logs')->insert([
                'userid' => $userid,
                'subdomain_id' => $normalizedSubdomainId,
                'action' => $action,
                'details' => is_array($normalizedDetails) ? json_encode($normalizedDetails, JSON_UNESCAPED_UNICODE) : $normalizedDetails,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if ($userid) {
                $stats = Capsule::table('mod_cloudflare_user_stats')->where('userid', $userid)->first();
                if (!$stats) {
                    Capsule::table('mod_cloudflare_user_stats')->insert([
                        'userid' => $userid,
                        'subdomains_created' => 0,
                        'dns_records_created' => 0,
                        'dns_records_updated' => 0,
                        'dns_records_deleted' => 0,
                        'last_activity' => date('Y-m-d H:i:s'),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $update = ['last_activity' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
                    switch ($action) {
                        case 'client_register_subdomain':
                            $update['subdomains_created'] = ($stats->subdomains_created ?? 0) + 1;
                            break;
                        case 'client_create_dns':
                            $update['dns_records_created'] = ($stats->dns_records_created ?? 0) + 1;
                            break;
                        case 'client_update_dns':
                            $update['dns_records_updated'] = ($stats->dns_records_updated ?? 0) + 1;
                            break;
                        case 'client_delete_dns_record':
                            $update['dns_records_deleted'] = ($stats->dns_records_deleted ?? 0) + 1;
                            break;
                    }
                    Capsule::table('mod_cloudflare_user_stats')->where('userid', $userid)->update($update);
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore logging errors
        }
    }

    /**
     * @param mixed $rawSubdomainId
     * @return array{raw_id:int, internal_id:?int, public_id:?int, unresolved:bool}
     */
    private static function normalizeSubdomainId($rawSubdomainId): array
    {
        $rawId = intval($rawSubdomainId);
        if ($rawId <= 0) {
            return [
                'raw_id' => 0,
                'internal_id' => null,
                'public_id' => null,
                'unresolved' => false,
            ];
        }

        $internalId = $rawId;
        $publicId = null;

        if (class_exists('CfSubdomainIdResolver') && CfSubdomainIdResolver::hasPublicIdColumn()) {
            try {
                $resolvedInternalId = CfSubdomainIdResolver::resolveToInternal($rawId);
                $internalId = $resolvedInternalId > 0 ? $resolvedInternalId : $rawId;
            } catch (\Throwable $e) {
                $internalId = $rawId;
            }

            try {
                $row = Capsule::table('mod_cloudflare_subdomain')
                    ->where('id', $internalId)
                    ->first(['id', 'public_id']);
                if ($row && intval($row->id ?? 0) > 0) {
                    $pid = intval($row->public_id ?? 0);
                    if ($pid > 0) {
                        $publicId = $pid;
                    }
                    return [
                        'raw_id' => $rawId,
                        'internal_id' => $internalId,
                        'public_id' => $publicId,
                        'unresolved' => false,
                    ];
                }
            } catch (\Throwable $e) {
            }

            return [
                'raw_id' => $rawId,
                'internal_id' => null,
                'public_id' => null,
                'unresolved' => true,
            ];
        }

        try {
            $exists = Capsule::table('mod_cloudflare_subdomain')->where('id', $internalId)->exists();
            if ($exists) {
                return [
                    'raw_id' => $rawId,
                    'internal_id' => $internalId,
                    'public_id' => null,
                    'unresolved' => false,
                ];
            }
        } catch (\Throwable $e) {
        }

        return [
            'raw_id' => $rawId,
            'internal_id' => null,
            'public_id' => null,
            'unresolved' => true,
        ];
    }

    /**
     * @param mixed $details
     * @param array{raw_id:int, internal_id:?int, public_id:?int, unresolved:bool} $normalized
     * @return mixed
     */
    private static function normalizeDetails($details, array $normalized)
    {
        if (!is_array($details)) {
            return $details;
        }

        if (($normalized['public_id'] ?? null) !== null && !array_key_exists('public_id', $details)) {
            $details['public_id'] = $normalized['public_id'];
        }

        if (!empty($normalized['unresolved']) && !array_key_exists('raw_subdomain_id', $details)) {
            $details['raw_subdomain_id'] = $normalized['raw_id'];
        }

        if (($normalized['internal_id'] ?? null) !== null) {
            $details['internal_subdomain_id'] = $normalized['internal_id'];
        }

        return $details;
    }
}

if (!function_exists('cloudflare_subdomain_log')) {
    function cloudflare_subdomain_log($action, $details = '', $userid = null, $subdomain_id = null): void
    {
        CfLoggingSupport::log((string) $action, $details, $userid, $subdomain_id);
    }
}
