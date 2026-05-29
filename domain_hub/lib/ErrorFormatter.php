<?php

if (!function_exists('cfmod_normalize_provider_error')) {
    /**
     * 统一供应商错误语义，向前端返回可读文案，向日志保留原始细节。
     */
    function cfmod_normalize_provider_error($error, string $fallback = '云解析服务暂时不可用，请稍后再试。'): array
    {
        $translate = function (string $key, string $default): string {
            if (function_exists('cfmod_trans')) {
                return cfmod_trans($key, $default);
            }
            return $default;
        };

        if ($error instanceof \Throwable) {
            $error = $error->getMessage();
        }

        if (is_array($error)) {
            $error = implode('; ', array_map(function ($item) {
                if ($item instanceof \Throwable) {
                    return $item->getMessage();
                }
                if (is_scalar($item)) {
                    return (string) $item;
                }
                if (is_object($item) && method_exists($item, '__toString')) {
                    return (string) $item;
                }
                return json_encode($item, JSON_UNESCAPED_UNICODE);
            }, $error));
        } elseif (is_object($error) && !method_exists($error, '__toString')) {
            $error = json_encode($error, JSON_UNESCAPED_UNICODE);
        } elseif (is_object($error)) {
            $error = (string) $error;
        }

        $fallback = trim($fallback) !== '' ? $fallback : '云解析服务暂时不可用，请稍后再试。';
        $fallbackText = $translate('cfclient.error.provider.unavailable', $fallback);
        $raw = trim((string) $error);
        if ($raw === '') {
            return [
                'error_class' => 'provider_unavailable',
                'user_message_key' => 'cfclient.error.provider.unavailable',
                'user_message' => $fallbackText,
                'admin_detail' => ''
            ];
        }

        $clean = preg_replace('/^\[[^\]]+\]\s*/', '', $raw);
        $clean = preg_replace('/Ali(?:yun|dns)/i', '云解析服务', $clean);
        $clean = preg_replace('/Cloudflare/i', '云解析服务', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);

        $rules = [
            ['class' => 'provider_5xx', 'keywords' => ['internal server error', 'http 500', 'status 500', 'server error', 'upstream', 'backend'], 'key' => 'cfclient.error.provider.server_error', 'message' => '云解析服务暂时异常（服务端错误），请稍后重试。'],
            ['class' => 'provider_conflict', 'keywords' => ['conflict', 'duplicate', 'already exist', 'exist'], 'key' => 'cfclient.error.provider.conflict', 'message' => '记录冲突，请检查是否存在同名记录或删除旧记录后再试。'],
            ['class' => 'provider_invalid', 'keywords' => ['format', 'invalid', 'syntax', 'illegal', '参数'], 'key' => 'cfclient.error.provider.invalid', 'message' => '记录内容或格式无效，请核对主机记录、记录值以及 TTL/优先级。'],
            ['class' => 'provider_permission', 'keywords' => ['denied', 'permission', 'auth', 'forbidden'], 'key' => 'cfclient.error.provider.permission', 'message' => '权限不足，请检查 AccessKey 配置或域名授权。'],
            ['class' => 'provider_quota', 'keywords' => ['quota', 'limit', 'too many', 'exceed', 'over limit', 'rate'], 'key' => 'cfclient.error.provider.quota', 'message' => '已达到云解析的数量或频率限制，请稍后再试或联系管理员提升额度。'],
            ['class' => 'provider_not_found', 'keywords' => ['not found', 'not exist', 'no such', 'zone not'], 'key' => 'cfclient.error.provider.not_found', 'message' => '目标域名不存在或未授权，请确认根域配置后重试。'],
            ['class' => 'provider_timeout', 'keywords' => ['timeout', 'timed out', 'busy', 'unavailable', 'temporarily unable'], 'key' => 'cfclient.error.provider.timeout', 'message' => '云解析服务响应超时或繁忙，请稍后再试。'],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if ($keyword !== '' && strpos($lower, $keyword) !== false) {
                    return [
                        'error_class' => $rule['class'],
                        'user_message_key' => $rule['key'],
                        'user_message' => $translate($rule['key'], $rule['message']),
                        'admin_detail' => $raw,
                    ];
                }
            }
        }

        return [
            'error_class' => 'provider_unavailable',
            'user_message_key' => 'cfclient.error.provider.unavailable',
            'user_message' => $fallbackText,
            'admin_detail' => $raw,
        ];
    }
}

if (!function_exists('cfmod_format_provider_error')) {
    function cfmod_format_provider_error($error, string $fallback = '云解析服务暂时不可用，请稍后再试。'): string
    {
        $normalized = cfmod_normalize_provider_error($error, $fallback);
        return (string) ($normalized['user_message'] ?? $fallback);
    }
}
