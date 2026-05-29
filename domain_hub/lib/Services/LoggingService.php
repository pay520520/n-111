<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../SecurityHelpers.php';

class CfLoggingService
{
    private static ?self $instance = null;
    private ?array $apiLogOptionsCache = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function reportException(string $context, \Throwable $exception): void
    {
        $details = [
            'context' => $context,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
        $trace = $exception->getTraceAsString();
        if ($trace !== '') {
            $details['trace'] = substr($trace, 0, 2000);
        }

        $this->insertGeneralLog('job_error', $details, null, null);
    }

    public function logJobEvent(string $action, array $details = [], ?int $userId = null, ?int $subdomainId = null): void
    {
        $this->insertGeneralLog($action, $details, $userId, $subdomainId);
    }

    public function logApiRequest($keyRow, string $endpoint, string $method, $request, $response, int $code, float $startedAt): void
    {
        $options = $this->resolveApiLogOptions();
        $now = date('Y-m-d H:i:s');

        $this->touchApiKeyLastUsed($keyRow, $now, $options);

        if (!$this->shouldWriteApiLog($code, $options)) {
            return;
        }

        $requestData = null;
        $responseData = null;

        if ($options['mode'] === 'full') {
            $requestPayload = cfmod_sanitize_log_payload($request);
            $responsePayload = cfmod_sanitize_log_payload($response);
            $requestData = is_string($requestPayload) ? $requestPayload : json_encode($requestPayload, JSON_UNESCAPED_UNICODE);
            $responseData = is_string($responsePayload) ? $responsePayload : json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
            if ($requestData === false) {
                $requestData = '{}';
            }
            if ($responseData === false) {
                $responseData = '{}';
            }

            $maxPayloadBytes = $options['payload_max_bytes'];
            if ($maxPayloadBytes > 0) {
                $requestData = $this->truncateLogString($requestData, $maxPayloadBytes);
                $responseData = $this->truncateLogString($responseData, $maxPayloadBytes);
            }
        }

        $ip = function_exists('api_client_ip') ? (string) api_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        try {
            Capsule::table('mod_cloudflare_api_logs')->insert([
                'api_key_id' => intval($keyRow->id ?? 0),
                'userid' => intval($keyRow->userid ?? 0),
                'endpoint' => substr($endpoint, 0, 100),
                'method' => strtoupper(substr($method, 0, 10)),
                'request_data' => $requestData,
                'response_data' => $responseData,
                'response_code' => $code,
                'ip' => substr($ip, 0, 45),
                'user_agent' => ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'execution_time' => round(max(0, microtime(true) - $startedAt), 3),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // ignore log failures
        }
    }

    private function insertGeneralLog(string $action, array $details, ?int $userId, ?int $subdomainId): void
    {
        try {
            Capsule::table('mod_cloudflare_logs')->insert([
                'userid' => $userId,
                'subdomain_id' => $subdomainId,
                'action' => $action,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // swallow logging failures
        }
    }

    private function resolveApiLogOptions(): array
    {
        if ($this->apiLogOptionsCache !== null) {
            return $this->apiLogOptionsCache;
        }

        $settings = [];
        try {
            if (function_exists('cf_get_module_settings_cached')) {
                $settings = cf_get_module_settings_cached();
            } elseif (function_exists('cfmod_get_settings')) {
                $settings = cfmod_get_settings();
            }
        } catch (\Throwable $e) {
            $settings = [];
        }

        if (!is_array($settings)) {
            $settings = [];
        }

        $mode = strtolower(trim((string) ($settings['api_log_mode'] ?? 'full')));
        if (!in_array($mode, ['off', 'meta', 'full'], true)) {
            $mode = 'full';
        }

        $successSamplePercent = intval($settings['api_log_success_sample_percent'] ?? 100);
        $successSamplePercent = max(0, min(100, $successSamplePercent));

        $payloadMaxBytes = intval($settings['api_log_payload_max_bytes'] ?? 0);
        $payloadMaxBytes = max(0, min(200000, $payloadMaxBytes));

        $lastUsedInterval = intval($settings['api_key_last_used_update_interval_seconds'] ?? 60);
        $lastUsedInterval = max(0, min(86400, $lastUsedInterval));

        $this->apiLogOptionsCache = [
            'mode' => $mode,
            'success_sample_percent' => $successSamplePercent,
            'payload_max_bytes' => $payloadMaxBytes,
            'last_used_update_interval_seconds' => $lastUsedInterval,
        ];

        return $this->apiLogOptionsCache;
    }

    private function shouldWriteApiLog(int $code, array $options): bool
    {
        $mode = $options['mode'] ?? 'full';
        if ($mode === 'off') {
            return false;
        }

        if ($code >= 400) {
            return true;
        }

        $samplePercent = intval($options['success_sample_percent'] ?? 100);
        if ($samplePercent >= 100) {
            return true;
        }
        if ($samplePercent <= 0) {
            return false;
        }

        try {
            return random_int(1, 100) <= $samplePercent;
        } catch (\Throwable $e) {
            return mt_rand(1, 100) <= $samplePercent;
        }
    }

    private function touchApiKeyLastUsed($keyRow, string $now, array $options): void
    {
        $keyId = intval($keyRow->id ?? 0);
        if ($keyId <= 0) {
            return;
        }

        $interval = intval($options['last_used_update_interval_seconds'] ?? 60);
        $shouldUpdate = true;
        if ($interval > 0) {
            $lastUsedRaw = trim((string) ($keyRow->last_used_at ?? ''));
            if ($lastUsedRaw !== '') {
                $lastUsedTs = strtotime($lastUsedRaw);
                if ($lastUsedTs !== false && (time() - $lastUsedTs) < $interval) {
                    $shouldUpdate = false;
                }
            }
        }

        if (!$shouldUpdate) {
            return;
        }

        try {
            $query = Capsule::table('mod_cloudflare_api_keys')->where('id', $keyId);
            if ($interval > 0) {
                $cutoff = date('Y-m-d H:i:s', time() - $interval);
                $query->where(function ($inner) use ($cutoff) {
                    $inner->whereNull('last_used_at')->orWhere('last_used_at', '<=', $cutoff);
                });
            }
            $query->update([
                'last_used_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // ignore update failures
        }
    }

    private function truncateLogString(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes);
    }
}

if (!function_exists('cfmod_report_exception')) {
    function cfmod_report_exception(string $context, \Throwable $exception): void
    {
        CfLoggingService::instance()->reportException($context, $exception);
    }
}

if (!function_exists('cfmod_log_job')) {
    function cfmod_log_job(string $action, array $details = [], ?int $userId = null, ?int $subdomainId = null): void
    {
        CfLoggingService::instance()->logJobEvent($action, $details, $userId, $subdomainId);
    }
}

if (!function_exists('cfmod_log_api_request')) {
    function cfmod_log_api_request($keyRow, string $endpoint, string $method, $request, $response, int $code, float $startedAt): void
    {
        CfLoggingService::instance()->logApiRequest($keyRow, $endpoint, $method, $request, $response, $code, $startedAt);
    }
}
