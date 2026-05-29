<?php

declare(strict_types=1);

class CfDigService
{
    private const DEFAULT_RESOLVERS = [
        [
            'key' => 'cloudflare',
            'name' => 'Cloudflare DNS',
            'mode' => 'json',
            'accept' => 'application/dns-json',
            'url' => 'https://cloudflare-dns.com/dns-query?name={domain}&type={type}',
        ],
        [
            'key' => 'google',
            'name' => 'Google DNS',
            'mode' => 'json',
            'accept' => 'application/dns-json',
            'url' => 'https://dns.google/resolve?name={domain}&type={type}',
        ],
        [
            'key' => 'alidns',
            'name' => 'AliDNS DoH',
            'mode' => 'wire',
            'accept' => 'application/dns-message',
            'url' => 'https://dns.alidns.com/dns-query?dns={dns_query}',
        ],
    ];

    private const TYPE_TO_CODE = [
        'A' => 1,
        'NS' => 2,
        'CNAME' => 5,
        'SOA' => 6,
        'PTR' => 12,
        'MX' => 15,
        'TXT' => 16,
        'AAAA' => 28,
        'SRV' => 33,
    ];

    public static function isEnabled(array $moduleSettings): bool
    {
        if (!array_key_exists('enable_dig_center', $moduleSettings)) {
            return true;
        }

        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($moduleSettings['enable_dig_center']);
        }

        $raw = strtolower(trim((string) $moduleSettings['enable_dig_center']));
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getSupportedTypes(): array
    {
        return array_keys(self::TYPE_TO_CODE);
    }

    public static function lookup(int $requestUserId, string $domainInput, string $recordTypeInput, array $moduleSettings = [], array $context = []): array
    {
        if (empty($moduleSettings) && function_exists('cf_get_module_settings_cached')) {
            $moduleSettings = cf_get_module_settings_cached();
            if (!is_array($moduleSettings)) {
                $moduleSettings = [];
            }
        }

        $domain = self::normalizeDomain($domainInput);
        if ($domain === '') {
            throw new \RuntimeException('请输入有效域名后再查询。');
        }

        $recordType = self::normalizeRecordType($recordTypeInput);
        if ($recordType === '') {
            throw new \RuntimeException('不支持的记录类型。');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前环境缺少 cURL 扩展，无法执行 Dig 查询。');
        }

        $timeoutSeconds = self::resolveTimeoutSeconds($moduleSettings);
        $queryStart = microtime(true);
        $resolverResults = self::queryResolvers($domain, $recordType, $timeoutSeconds);
        $durationMs = (int) round((microtime(true) - $queryStart) * 1000);
        $summary = self::buildSummary($resolverResults);

        self::logLookupMeta($moduleSettings, $requestUserId, $domain, $recordType, $summary, $durationMs, $context);

        return [
            'success' => true,
            'result' => [
                'domain' => $domain,
                'record_type' => $recordType,
                'queried_at' => date('Y-m-d H:i:s'),
                'duration_ms' => $durationMs,
                'summary' => $summary,
                'resolvers' => $resolverResults,
            ],
        ];
    }

    private static function queryResolvers(string $domain, string $recordType, int $timeoutSeconds): array
    {
        $resolvers = self::DEFAULT_RESOLVERS;
        $dnsQuery = self::buildDnsQueryBase64Url($domain, $recordType);
        if (function_exists('curl_multi_init') && function_exists('curl_multi_exec')) {
            return self::queryResolversByMultiCurl($domain, $recordType, $resolvers, $timeoutSeconds, $dnsQuery);
        }

        $results = [];
        foreach ($resolvers as $resolver) {
            $results[] = self::querySingleResolver($resolver, $domain, $recordType, $timeoutSeconds, $dnsQuery);
        }
        return $results;
    }

    private static function queryResolversByMultiCurl(string $domain, string $recordType, array $resolvers, int $timeoutSeconds, string $dnsQuery): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($resolvers as $resolver) {
            $url = self::buildResolverUrl((string) ($resolver['url'] ?? ''), $domain, $recordType, $dnsQuery);
            $headers = self::buildResolverHeaders($resolver);
            $httpVersion = self::resolveResolverHttpVersion($resolver);

            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'DomainHub-Dig/1.0',
            ];
            if ($httpVersion > 0) {
                $curlOptions[CURLOPT_HTTP_VERSION] = $httpVersion;
            }
            curl_setopt_array($ch, $curlOptions);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = [
                'resolver' => $resolver,
                'handle' => $ch,
            ];
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && $status === CURLM_OK) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $item) {
            $resolver = $item['resolver'];
            $ch = $item['handle'];
            $body = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseMs = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $curlError = trim((string) curl_error($ch));

            $results[] = self::formatResolverResponse($resolver, $recordType, $httpCode, $responseMs, (string) $body, $curlError);

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
        return $results;
    }

    private static function querySingleResolver(array $resolver, string $domain, string $recordType, int $timeoutSeconds, string $dnsQuery): array
    {
        $url = self::buildResolverUrl((string) ($resolver['url'] ?? ''), $domain, $recordType, $dnsQuery);
        $headers = self::buildResolverHeaders($resolver);
        $httpVersion = self::resolveResolverHttpVersion($resolver);

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'DomainHub-Dig/1.0',
        ];
        if ($httpVersion > 0) {
            $curlOptions[CURLOPT_HTTP_VERSION] = $httpVersion;
        }
        curl_setopt_array($ch, $curlOptions);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseMs = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $curlError = trim((string) curl_error($ch));
        curl_close($ch);

        return self::formatResolverResponse($resolver, $recordType, $httpCode, $responseMs, is_string($body) ? $body : '', $curlError);
    }

    private static function formatResolverResponse(array $resolver, string $recordType, int $httpCode, int $responseMs, string $body, string $curlError): array
    {
        $resolverKey = (string) ($resolver['key'] ?? 'resolver');
        $resolverName = (string) ($resolver['name'] ?? $resolverKey);
        $mode = strtolower(trim((string) ($resolver['mode'] ?? 'json')));

        $dnsStatus = null;
        $answers = [];
        $values = [];
        $success = false;
        $payload = null;

        if ($mode === 'wire') {
            if ($httpCode >= 200 && $httpCode < 300 && $body !== '') {
                $parsed = self::parseDnsWireResponse($body);
                if ($parsed !== null) {
                    $success = true;
                    $dnsStatus = (int) ($parsed['status'] ?? 0);
                    $answers = is_array($parsed['answers'] ?? null) ? $parsed['answers'] : [];
                    foreach ($answers as $answer) {
                        $answerType = strtoupper((string) ($answer['type'] ?? ''));
                        $answerData = trim((string) ($answer['data'] ?? ''));
                        if ($answerType === $recordType && $answerData !== '') {
                            $values[] = $answerData;
                        }
                    }
                }
            }
        } else {
            if ($body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $success = $httpCode >= 200 && $httpCode < 300 && is_array($payload);
            $dnsStatus = $success ? (int) ($payload['Status'] ?? 0) : null;

            if ($success) {
                $answerRows = $payload['Answer'] ?? [];
                if (is_array($answerRows)) {
                    foreach ($answerRows as $answer) {
                        if (!is_array($answer)) {
                            continue;
                        }
                        $answerTypeCode = (int) ($answer['type'] ?? 0);
                        $answerType = self::codeToType($answerTypeCode);
                        $answerData = trim((string) ($answer['data'] ?? ''));
                        if ($answerData === '') {
                            continue;
                        }
                        $answers[] = [
                            'name' => trim((string) ($answer['name'] ?? '')),
                            'type' => $answerType,
                            'ttl' => max(0, (int) ($answer['TTL'] ?? 0)),
                            'data' => $answerData,
                        ];
                        if ($answerType === $recordType) {
                            $values[] = $answerData;
                        }
                    }
                }
            }
        }

        $values = array_values(array_unique($values));

        $errorMessage = '';
        if ($curlError !== '') {
            $errorMessage = $curlError;
        } elseif (!$success) {
            $snippet = self::extractErrorSnippet($body);
            $errorMessage = $snippet !== '' ? $snippet : ('HTTP ' . $httpCode);
        } elseif ($dnsStatus !== null && $dnsStatus !== 0 && empty($answers)) {
            $errorMessage = 'DNS Status ' . $dnsStatus;
        }

        return [
            'resolver_key' => $resolverKey,
            'resolver_name' => $resolverName,
            'success' => $success,
            'http_code' => $httpCode,
            'dns_status' => $dnsStatus,
            'response_ms' => max(0, $responseMs),
            'answers' => $answers,
            'values' => $values,
            'error' => $errorMessage,
        ];
    }

    private static function extractErrorSnippet(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $sample = substr($body, 0, 220);
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $sample)) {
            return '';
        }
        $sampleText = trim($sample);
        if ($sampleText === '') {
            return '';
        }

        if (stripos($sampleText, 'requires HTTP/2') !== false) {
            return '上游 DNS 服务器要求 HTTP/2，请检查 PHP cURL 是否已启用 HTTP/2（nghttp2）支持。';
        }

        return $sampleText;
    }

    private static function buildSummary(array $resolverResults): array
    {
        $resolverTotal = count($resolverResults);
        $resolverSuccess = 0;
        $resolverWithAnswer = 0;
        $signatures = [];
        $allValues = [];

        foreach ($resolverResults as $resolverResult) {
            if (!is_array($resolverResult)) {
                continue;
            }
            if (!empty($resolverResult['success'])) {
                $resolverSuccess++;
            }
            $values = is_array($resolverResult['values'] ?? null) ? $resolverResult['values'] : [];
            if (!empty($values)) {
                $resolverWithAnswer++;
                sort($values, SORT_NATURAL);
                $signatures[] = sha1(implode('|', $values));
                foreach ($values as $value) {
                    $allValues[$value] = $value;
                }
            }
        }

        $consensus = !empty($signatures) && count(array_unique($signatures)) === 1;

        return [
            'resolver_total' => $resolverTotal,
            'resolver_success' => $resolverSuccess,
            'resolver_with_answer' => $resolverWithAnswer,
            'consensus' => $consensus,
            'flatten_values' => array_values($allValues),
        ];
    }

    private static function logLookupMeta(array $moduleSettings, int $requestUserId, string $domain, string $recordType, array $summary, int $durationMs, array $context): void
    {
        $logMode = strtolower(trim((string) ($moduleSettings['dig_log_mode'] ?? 'meta')));
        if ($logMode === 'off') {
            return;
        }

        if (!function_exists('cloudflare_subdomain_log')) {
            return;
        }

        $details = [
            'domain' => $domain,
            'record_type' => $recordType,
            'resolver_total' => (int) ($summary['resolver_total'] ?? 0),
            'resolver_success' => (int) ($summary['resolver_success'] ?? 0),
            'resolver_with_answer' => (int) ($summary['resolver_with_answer'] ?? 0),
            'consensus' => !empty($summary['consensus']) ? 1 : 0,
            'duration_ms' => max(0, $durationMs),
        ];

        if (isset($context['cache_hit'])) {
            $details['cache_hit'] = !empty($context['cache_hit']) ? 1 : 0;
        }

        cloudflare_subdomain_log('client_dig_lookup', $details, $requestUserId, null);
    }

    private static function buildResolverUrl(string $template, string $domain, string $recordType, string $dnsQuery): string
    {
        return strtr($template, [
            '{domain}' => rawurlencode($domain),
            '{type}' => rawurlencode($recordType),
            '{dns_query}' => rawurlencode($dnsQuery),
        ]);
    }

    private static function buildResolverHeaders(array $resolver): array
    {
        $accept = trim((string) ($resolver['accept'] ?? 'application/dns-json'));
        if ($accept === '') {
            $accept = 'application/dns-json';
        }

        return [
            'Accept: ' . $accept,
        ];
    }

    private static function resolveResolverHttpVersion(array $resolver): int
    {
        $forceHttp2 = !empty($resolver['force_http2']);
        if ($forceHttp2) {
            if (defined('CURL_HTTP_VERSION_2TLS')) {
                return (int) CURL_HTTP_VERSION_2TLS;
            }
            if (defined('CURL_HTTP_VERSION_2_0')) {
                return (int) CURL_HTTP_VERSION_2_0;
            }
        }

        if (defined('CURL_HTTP_VERSION_1_1')) {
            return (int) CURL_HTTP_VERSION_1_1;
        }

        return 0;
    }

    private static function buildDnsQueryBase64Url(string $domain, string $recordType): string
    {
        $typeCode = self::TYPE_TO_CODE[$recordType] ?? 1;
        $labels = explode('.', $domain);

        $query = '';
        $query .= pack('n', random_int(0, 65535));
        $query .= pack('n', 0x0100);
        $query .= pack('n', 1);
        $query .= pack('n', 0);
        $query .= pack('n', 0);
        $query .= pack('n', 0);

        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $length = strlen($label);
            if ($length > 63) {
                $label = substr($label, 0, 63);
                $length = strlen($label);
            }
            $query .= chr($length) . $label;
        }

        $query .= "\x00";
        $query .= pack('n', $typeCode);
        $query .= pack('n', 1);

        return rtrim(strtr(base64_encode($query), '+/', '-_'), '=');
    }

    private static function parseDnsWireResponse(string $packet): ?array
    {
        $packetLength = strlen($packet);
        if ($packetLength < 12) {
            return null;
        }

        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', substr($packet, 0, 12));
        if (!is_array($header)) {
            return null;
        }

        $offset = 12;
        $questionCount = (int) ($header['qdcount'] ?? 0);
        for ($i = 0; $i < $questionCount; $i++) {
            self::readDnsName($packet, $offset);
            if ($offset + 4 > $packetLength) {
                return null;
            }
            $offset += 4;
        }

        $answerCount = (int) ($header['ancount'] ?? 0);
        $answers = [];

        for ($i = 0; $i < $answerCount; $i++) {
            $name = self::readDnsName($packet, $offset);
            if ($offset + 10 > $packetLength) {
                return null;
            }

            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($packet, $offset, 10));
            if (!is_array($rr)) {
                return null;
            }
            $offset += 10;

            $typeCode = (int) ($rr['type'] ?? 0);
            $ttl = (int) ($rr['ttl'] ?? 0);
            $rdLength = (int) ($rr['rdlength'] ?? 0);
            if ($rdLength < 0 || $offset + $rdLength > $packetLength) {
                return null;
            }

            $rdataOffset = $offset;
            $data = self::decodeDnsRdata($packet, $typeCode, $rdataOffset, $rdLength);
            $offset += $rdLength;

            $answers[] = [
                'name' => $name,
                'type' => self::codeToType($typeCode),
                'ttl' => max(0, $ttl),
                'data' => $data,
            ];
        }

        $flags = (int) ($header['flags'] ?? 0);
        $rcode = $flags & 0x000F;

        return [
            'status' => $rcode,
            'answers' => $answers,
        ];
    }

    private static function readDnsName(string $packet, int &$offset, int $depth = 0): string
    {
        $max = strlen($packet);
        if ($depth > 15 || $offset >= $max) {
            return '';
        }

        $labels = [];
        $cursor = $offset;
        $jumped = false;
        $guard = 0;

        while ($guard < 256) {
            $guard++;
            if ($cursor >= $max) {
                if (!$jumped) {
                    $offset = $max;
                }
                break;
            }

            $len = ord($packet[$cursor]);
            if (($len & 0xC0) === 0xC0) {
                if ($cursor + 1 >= $max) {
                    if (!$jumped) {
                        $offset = $max;
                    }
                    break;
                }

                $pointer = (($len & 0x3F) << 8) | ord($packet[$cursor + 1]);
                if (!$jumped) {
                    $offset = $cursor + 2;
                }
                $cursor = $pointer;
                $jumped = true;
                if (++$depth > 15) {
                    break;
                }
                continue;
            }

            $cursor++;
            if ($len === 0) {
                if (!$jumped) {
                    $offset = $cursor;
                }
                break;
            }

            if ($cursor + $len > $max) {
                if (!$jumped) {
                    $offset = $max;
                }
                break;
            }

            $labels[] = substr($packet, $cursor, $len);
            $cursor += $len;
            if (!$jumped) {
                $offset = $cursor;
            }
        }

        $name = implode('.', array_filter($labels, static function ($label) {
            return $label !== '';
        }));

        return strtolower(trim($name, '.'));
    }

    private static function decodeDnsRdata(string $packet, int $typeCode, int $rdataOffset, int $rdLength): string
    {
        if ($rdLength <= 0) {
            return '';
        }

        $rdata = substr($packet, $rdataOffset, $rdLength);

        if ($typeCode === 1 && $rdLength === 4) {
            $ip = @inet_ntop($rdata);
            return is_string($ip) && $ip !== '' ? $ip : '';
        }

        if ($typeCode === 28 && $rdLength === 16) {
            $ip = @inet_ntop($rdata);
            return is_string($ip) && $ip !== '' ? $ip : '';
        }

        if (in_array($typeCode, [2, 5, 12], true)) {
            $nameOffset = $rdataOffset;
            return self::readDnsName($packet, $nameOffset);
        }

        if ($typeCode === 15) {
            if ($rdLength < 3) {
                return '';
            }
            $pref = unpack('npreference', substr($packet, $rdataOffset, 2));
            $preference = is_array($pref) ? (int) ($pref['preference'] ?? 0) : 0;
            $nameOffset = $rdataOffset + 2;
            $exchange = self::readDnsName($packet, $nameOffset);
            return trim($preference . ' ' . $exchange);
        }

        if ($typeCode === 16) {
            $cursor = $rdataOffset;
            $end = $rdataOffset + $rdLength;
            $chunks = [];
            while ($cursor < $end) {
                $len = ord($packet[$cursor]);
                $cursor++;
                if ($cursor + $len > $end) {
                    break;
                }
                $chunks[] = substr($packet, $cursor, $len);
                $cursor += $len;
            }
            return implode(' ', $chunks);
        }

        if ($typeCode === 33) {
            if ($rdLength < 7) {
                return '';
            }
            $srv = unpack('npriority/nweight/nport', substr($packet, $rdataOffset, 6));
            if (!is_array($srv)) {
                return '';
            }
            $nameOffset = $rdataOffset + 6;
            $target = self::readDnsName($packet, $nameOffset);
            return trim((int) $srv['priority'] . ' ' . (int) $srv['weight'] . ' ' . (int) $srv['port'] . ' ' . $target);
        }

        if ($typeCode === 6) {
            $nameOffset = $rdataOffset;
            $mname = self::readDnsName($packet, $nameOffset);
            $rname = self::readDnsName($packet, $nameOffset);
            if ($nameOffset + 20 <= strlen($packet)) {
                $soa = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr($packet, $nameOffset, 20));
                if (is_array($soa)) {
                    return trim($mname . ' ' . $rname . ' ' . (int) $soa['serial']);
                }
            }
            return trim($mname . ' ' . $rname);
        }

        return '';
    }

    private static function resolveTimeoutSeconds(array $moduleSettings): int
    {
        $timeout = (int) ($moduleSettings['dig_timeout_seconds'] ?? 6);
        if ($timeout <= 0) {
            $timeout = 6;
        }
        return max(2, min(15, $timeout));
    }

    private static function normalizeDomain(string $input): string
    {
        $input = trim(strtolower($input));
        if ($input === '') {
            return '';
        }

        $input = preg_replace('#^https?://#i', '', $input);
        $input = explode('/', $input)[0] ?? $input;
        $input = trim($input, '.');

        if ($input === '') {
            return '';
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $input)) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = idn_to_ascii($input, IDNA_DEFAULT, $variant);
            if ($ascii === false) {
                $ascii = idn_to_ascii($input);
            }
            if (is_string($ascii) && $ascii !== '') {
                $input = strtolower($ascii);
            }
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $input)) {
            return '';
        }

        return $input;
    }

    private static function normalizeRecordType(string $input): string
    {
        $value = strtoupper(trim($input));
        return array_key_exists($value, self::TYPE_TO_CODE) ? $value : '';
    }

    private static function codeToType(int $code): string
    {
        $type = array_search($code, self::TYPE_TO_CODE, true);
        if (is_string($type) && $type !== '') {
            return $type;
        }
        return (string) $code;
    }
}
