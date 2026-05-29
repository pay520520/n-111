<?php

declare(strict_types=1);

class CfSafeBrowsingService
{
    private const ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    public static function isEnabled(array $settings): bool
    {
        $value = strtolower(trim((string) ($settings['safe_browsing_enabled'] ?? '0')));
        return in_array($value, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function checkUrl(string $url, array $settings): array
    {
        $apiKey = trim((string) ($settings['safe_browsing_api_key'] ?? ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'missing_api_key'];
        }
        $payload = [
            'client' => [
                'clientId' => 'domain_hub',
                'clientVersion' => '1.0',
            ],
            'threatInfo' => [
                'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                'platformTypes' => ['ANY_PLATFORM'],
                'threatEntryTypes' => ['URL'],
                'threatEntries' => [['url' => $url]],
            ],
        ];
        $target = self::ENDPOINT . '?key=' . rawurlencode($apiKey);
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
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
            return ['success' => false, 'error' => 'invalid_json'];
        }
        $matches = $json['matches'] ?? [];
        return [
            'success' => true,
            'matched' => is_array($matches) && !empty($matches),
            'matches' => is_array($matches) ? $matches : [],
            'http_code' => $http,
        ];
    }
}

