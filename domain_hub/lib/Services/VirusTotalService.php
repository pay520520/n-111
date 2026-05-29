<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfVirusTotalService
{
    private const API_BASE = 'https://www.virustotal.com/api/v3';
    private static float $lastRequestAtMicro = 0.0;
    private static bool $tableEnsured = false;

    public static function isEnabled(array $settings): bool
    {
        $value = strtolower(trim((string) ($settings['virustotal_enabled'] ?? '0')));
        return in_array($value, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function checkDomain(string $domain, array $settings, array $options = []): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['success' => false, 'error' => 'invalid_domain'];
        }

        $apiKey = trim((string) ($settings['virustotal_api_key'] ?? ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'missing_api_key'];
        }

        self::ensureCacheTable();

        $ttlHours = max(6, min(24, intval($settings['virustotal_cache_ttl_hours'] ?? 12)));
        $forceRefresh = !empty($options['force_refresh']);
        if (!$forceRefresh) {
            $cache = self::loadCache($domain, $ttlHours);
            if ($cache !== null) {
                $cache['from_cache'] = true;
                return $cache;
            }
        }

        $minIntervalMs = max(100, min(10000, intval($settings['virustotal_min_interval_ms'] ?? 300)));
        self::applyRateLimit($minIntervalMs);

        $domainId = rtrim(strtr(base64_encode($domain), '+/', '-_'), '=');
        $endpoint = self::API_BASE . '/domains/' . rawurlencode($domainId);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'x-apikey: ' . $apiKey,
            ],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return ['success' => false, 'error' => 'curl_error'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['success' => false, 'error' => 'invalid_json', 'http_code' => $http];
        }

        if ($http < 200 || $http >= 300) {
            return ['success' => false, 'error' => 'http_' . $http, 'http_code' => $http];
        }

        $stats = (array) (($json['data']['attributes']['last_analysis_stats'] ?? []));
        $malicious = max(0, intval($stats['malicious'] ?? 0));
        $suspicious = max(0, intval($stats['suspicious'] ?? 0));
        $harmless = max(0, intval($stats['harmless'] ?? 0));
        $undetected = max(0, intval($stats['undetected'] ?? 0));

        $highThreshold = max(1, intval($settings['vt_high_malicious_threshold'] ?? 1));
        $mediumThreshold = max(1, intval($settings['vt_medium_suspicious_threshold'] ?? 3));

        $riskLevel = 'low';
        $riskScore = 0;
        if ($malicious >= $highThreshold) {
            $riskLevel = 'high';
            $riskScore = 95;
        } elseif ($suspicious >= $mediumThreshold) {
            $riskLevel = 'medium';
            $riskScore = 65;
        } elseif ($suspicious > 0) {
            $riskLevel = 'low';
            $riskScore = 30;
        }

        $result = [
            'success' => true,
            'matched' => ($malicious >= $highThreshold || $suspicious >= $mediumThreshold),
            'risk_level' => $riskLevel,
            'risk_score' => $riskScore,
            'stats' => [
                'malicious' => $malicious,
                'suspicious' => $suspicious,
                'harmless' => $harmless,
                'undetected' => $undetected,
            ],
            'http_code' => $http,
            'from_cache' => false,
        ];

        self::saveCache($domain, $result, $http);

        return $result;
    }

    private static function ensureCacheTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }
        self::$tableEnsured = true;

        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_virustotal_cache')) {
                Capsule::schema()->create('mod_cloudflare_virustotal_cache', function ($table) {
                    $table->increments('id');
                    $table->string('domain', 255)->unique();
                    $table->text('result_json')->nullable();
                    $table->integer('http_code')->default(0);
                    $table->timestamp('checked_at')->nullable();
                });
            }
        } catch (\Throwable $e) {
            // best effort cache table
        }
    }

    private static function loadCache(string $domain, int $ttlHours): ?array
    {
        try {
            $row = Capsule::table('mod_cloudflare_virustotal_cache')->where('domain', $domain)->first();
            if (!$row) {
                return null;
            }
            $checkedAt = strtotime((string) ($row->checked_at ?? ''));
            if ($checkedAt <= 0 || $checkedAt < (time() - $ttlHours * 3600)) {
                return null;
            }
            $result = json_decode((string) ($row->result_json ?? ''), true);
            if (!is_array($result)) {
                return null;
            }
            return $result;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function saveCache(string $domain, array $result, int $httpCode): void
    {
        try {
            Capsule::table('mod_cloudflare_virustotal_cache')->updateOrInsert(
                ['domain' => $domain],
                [
                    'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'http_code' => $httpCode,
                    'checked_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // best effort cache write
        }
    }

    private static function applyRateLimit(int $minIntervalMs): void
    {
        $now = microtime(true);
        if (self::$lastRequestAtMicro > 0) {
            $elapsedMs = (int) floor(($now - self::$lastRequestAtMicro) * 1000);
            if ($elapsedMs < $minIntervalMs) {
                usleep(($minIntervalMs - $elapsedMs) * 1000);
                $now = microtime(true);
            }
        }
        self::$lastRequestAtMicro = $now;
    }

    public static function clearDomainCache(string $domain): int
    {
        self::ensureCacheTable();
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return 0;
        }
        try {
            return intval(Capsule::table('mod_cloudflare_virustotal_cache')->where('domain', $domain)->delete());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function clearExpiredCache(int $ttlHours): int
    {
        self::ensureCacheTable();
        $ttlHours = max(1, min(240, $ttlHours));
        $cutoff = date('Y-m-d H:i:s', time() - $ttlHours * 3600);
        try {
            return intval(Capsule::table('mod_cloudflare_virustotal_cache')->where(function ($q) use ($cutoff) {
                $q->whereNull('checked_at')->orWhere('checked_at', '<', $cutoff);
            })->delete());
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
