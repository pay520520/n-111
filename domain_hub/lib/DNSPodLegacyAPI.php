<?php

class DNSPodLegacyAPI {
    private const MAX_RETRIES = 3;
    private const RETRY_BASE_DELAY_MS = 200;

    private $tokenId;
    private $tokenValue;
    private $baseUrl = 'https://api.dnspod.com/';
    private $userAgent = 'DomainHub-DNSPod-Legacy/1.0 (+https://domainhub.local)';
    private $rateLimitPerMinute = 0;
    private $lastRequestAtMicro = 0.0;
    private $requestTimeoutSeconds = 30;

    public function __construct($tokenId, $tokenValue)
    {
        $tokenId = trim((string) $tokenId);
        $tokenValue = trim((string) $tokenValue);
        if ($tokenValue === '' && strpos($tokenId, ',') !== false) {
            [$tokenId, $tokenValue] = array_map('trim', explode(',', $tokenId, 2));
        }
        $this->tokenId = $tokenId;
        $this->tokenValue = $tokenValue;
    }

    public function setRequestTimeout(int $seconds): void
    {
        $this->requestTimeoutSeconds = max(3, min(120, $seconds));
    }

    private function request(string $action, array $params = []): array
    {
        $attempt = 0;
        $response = [];
        do {
            $attempt++;
            $response = $this->performRequest($action, $params);
            if (!$this->shouldRetry($response) || $attempt >= self::MAX_RETRIES) {
                break;
            }
            usleep($this->retryDelayMicros($attempt));
        } while (true);
        return $response;
    }

    private function performRequest(string $action, array $params = []): array
    {
        $this->applyRequestRateLimit();
        $token = $this->tokenId !== '' || $this->tokenValue !== ''
            ? $this->tokenId . ',' . $this->tokenValue
            : '';
        $postFields = array_merge([
            'login_token' => $token,
            'format' => 'json',
            'lang' => 'en',
            'error_on_empty' => 'no',
        ], $params);

        $ch = curl_init($this->baseUrl . ltrim($action, '/'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeoutSeconds);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            return ['success' => false, 'errors' => ['curl_error' => $error], 'http_code' => $httpCode];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'errors' => ['decode_error' => 'Invalid JSON'], 'http_code' => $httpCode];
        }
        $code = (string) ($decoded['status']['code'] ?? '');
        $decoded['success'] = ($code === '1');
        $decoded['http_code'] = $httpCode;
        return $decoded;
    }

    private function shouldRetry(array $response): bool
    {
        if ($response['success'] ?? false) {
            return false;
        }
        $httpCode = $response['http_code'] ?? 0;
        if ($httpCode === 0 || ($httpCode >= 500 && $httpCode < 600)) {
            return true;
        }
        $errors = $response['errors'] ?? [];
        if (!is_array($errors)) {
            return false;
        }
        if (isset($errors['curl_error']) || isset($errors['decode_error'])) {
            return true;
        }
        foreach ($errors as $key => $value) {
            if (is_string($key) && stripos($key, 'curl_error') !== false) {
                return true;
            }
            if (is_string($value)) {
                $lower = strtolower($value);
                if (strpos($lower, 'timeout') !== false || strpos($lower, 'temporarily unavailable') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    private function retryDelayMicros(int $attempt): int
    {
        $delayMs = self::RETRY_BASE_DELAY_MS * max(1, $attempt);
        return min(1500, $delayMs) * 1000;
    }

    private function applyRequestRateLimit(): void
    {
        $limit = intval($this->rateLimitPerMinute);
        if ($limit <= 0) {
            return;
        }
        $minIntervalMicro = (int) floor((60 * 1000000) / max(1, $limit));
        if ($minIntervalMicro <= 0) {
            return;
        }
        $nowMicro = microtime(true);
        if ($this->lastRequestAtMicro > 0) {
            $elapsed = (int) floor(($nowMicro - $this->lastRequestAtMicro) * 1000000);
            if ($elapsed < $minIntervalMicro) {
                usleep($minIntervalMicro - $elapsed);
                $nowMicro = microtime(true);
            }
        }
        $this->lastRequestAtMicro = $nowMicro;
    }

    public function setRequestRateLimit(int $ratePerMinute): void
    {
        $this->rateLimitPerMinute = max(0, $ratePerMinute);
    }

    private function normalizeTtl($ttl): int
    {
        $value = intval($ttl);
        if ($value <= 0) {
            $value = 600;
        }
        return max(60, $value);
    }

    private function normalizeLine(?string $line = null): string
    {
        $line = trim((string) $line);
        if ($line === '' || strtolower($line) === 'default') {
            return '默认';
        }
        return $line;
    }

    private function splitSubDomain(string $name, string $domain): string
    {
        $name = strtolower(rtrim($name, '.'));
        $domain = strtolower(rtrim($domain, '.'));
        if ($name === '' || $name === $domain) {
            return '@';
        }
        if (substr($name, -strlen($domain)) === $domain) {
            $trimmed = rtrim(substr($name, 0, -strlen($domain)), '.');
            return $trimmed === '' ? '@' : $trimmed;
        }
        return $name;
    }

    private function mapRecord(array $record, string $domain): array
    {
        $name = $record['name'] ?? '';
        $full = $name;
        $domainLower = strtolower(rtrim($domain, '.'));
        $nameLower = strtolower(rtrim($name, '.'));
        if ($nameLower === '' || $nameLower === '@') {
            $full = $domainLower;
        } elseif (!preg_match('/\.' . preg_quote($domainLower, '/') . '$/', $nameLower)) {
            $full = $nameLower . '.' . $domainLower;
        }
        return [
            'id' => $record['id'] ?? null,
            'type' => strtoupper($record['type'] ?? ''),
            'name' => $full,
            'content' => $record['value'] ?? '',
            'ttl' => intval($record['ttl'] ?? 600),
            'proxied' => false,
        ];
    }

    private function buildError(array $response): array
    {
        $status = $response['status'] ?? [];
        $code = $status['code'] ?? '';
        $message = $status['message'] ?? 'unknown error';
        return ['success' => false, 'errors' => [($code ? ($code . ': ') : '') . $message]];
    }

    public function validateCredentials(): bool
    {
        $res = $this->request('Domain.List', ['length' => 1]);
        return $res['success'] ?? false;
    }

    public function getZoneId($domain)
    {
        $res = $this->request('Domain.Info', ['domain' => $domain]);
        if ($res['success'] ?? false) {
            return $res['domain']['domain'] ?? $domain;
        }
        return false;
    }

    public function checkDomainExists($zone_id, $domain_name)
    {
        $rr = $this->splitSubDomain($domain_name, $zone_id);
        $res = $this->request('Record.List', [
            'domain' => $zone_id,
            'sub_domain' => $rr === '@' ? '' : $rr,
            'length' => 1,
        ]);
        if (!($res['success'] ?? false)) {
            return false;
        }
        $records = $res['records'] ?? [];
        return !empty($records);
    }

    public function getDomainRecords($zone_id, $domain_name)
    {
        $records = $this->getDnsRecords($zone_id, $domain_name);
        return $records['result'] ?? [];
    }

    public function getDnsRecords($zone_id, $name = null, $params = [])
    {
        $pageSize = intval($params['per_page'] ?? 3000);
        $pageSize = max(100, min(3000, $pageSize));
        $offset = isset($params['offset']) ? max(0, intval($params['offset'])) : 0;
        $maxLoops = max(1, min(1000, intval($params['max_pages'] ?? 300)));

        $queryBase = [
            'domain' => $zone_id,
            'length' => $pageSize,
        ];
        if ($name) {
            $rr = $this->splitSubDomain($name, $zone_id);
            if ($rr !== '@') {
                $queryBase['sub_domain'] = $rr;
            }
        }
        if (!empty($params['type'])) {
            $queryBase['record_type'] = strtoupper($params['type']);
        }

        $records = [];
        $loops = 0;
        $total = null;
        do {
            $loops++;
            $query = $queryBase;
            $query['offset'] = $offset;
            $res = $this->request('Record.List', $query);
            if (!($res['success'] ?? false)) {
                return $this->buildError($res);
            }

            $batch = $res['records'] ?? [];
            foreach ($batch as $record) {
                $records[] = $this->mapRecord($record, $zone_id);
            }

            if ($total === null) {
                $totalRaw = $res['info']['record_total'] ?? ($res['info']['records_num'] ?? null);
                if ($totalRaw !== null && is_numeric($totalRaw)) {
                    $total = max(0, intval($totalRaw));
                }
            }

            $count = count($batch);
            $offset += $count;
            if ($count < $pageSize) {
                break;
            }
            if ($total !== null && $offset >= $total) {
                break;
            }
        } while ($loops < $maxLoops);

        return ['success' => true, 'result' => $records];
    }

    public function createSubdomain($zone_id, $subdomain, $ip = '192.0.2.1', $proxied = true, $type = 'A')
    {
        return $this->createDnsRecord($zone_id, $subdomain, $type, $ip, 600, $proxied);
    }

    public function createThirdLevelDomain($zone_id, $third_level_name, $parent_subdomain, $ip = '192.0.2.1', $proxied = true)
    {
        $full = $third_level_name . '.' . $parent_subdomain;
        return $this->createSubdomain($zone_id, $full, $ip, $proxied);
    }

    public function createDnsRecord($zone_id, $name, $type, $content, $ttl = 600, $proxied = true, $line = null)
    {
        $rr = $this->splitSubDomain($name, $zone_id);
        $res = $this->request('Record.Create', [
            'domain' => $zone_id,
            'sub_domain' => $rr,
            'record_type' => strtoupper($type),
            'record_line' => $this->normalizeLine($line),
            'value' => $content,
            'ttl' => $this->normalizeTtl($ttl),
        ]);
        if (!($res['success'] ?? false)) {
            return $this->buildError($res);
        }
        $recordId = $res['record']['id'] ?? null;
        return ['success' => true, 'result' => ['id' => $recordId, 'name' => $name, 'type' => strtoupper($type), 'content' => $content, 'ttl' => $this->normalizeTtl($ttl), 'proxied' => false]];
    }

    public function createCNAMERecord($zone_id, $name, $target, $ttl = 600, $proxied = false)
    {
        return $this->createDnsRecord($zone_id, $name, 'CNAME', $target, $ttl, $proxied);
    }

    public function createMXRecord($zone_id, $name, $mail_server, $priority = 10, $ttl = 600)
    {
        $rr = $this->splitSubDomain($name, $zone_id);
        $res = $this->request('Record.Create', [
            'domain' => $zone_id,
            'sub_domain' => $rr,
            'record_type' => 'MX',
            'record_line' => $this->normalizeLine(),
            'value' => $mail_server,
            'mx' => intval($priority),
            'ttl' => $this->normalizeTtl($ttl),
        ]);
        if (!($res['success'] ?? false)) {
            return $this->buildError($res);
        }
        return ['success' => true, 'result' => ['id' => $res['record']['id'] ?? null]];
    }

    public function createSRVRecord($zone_id, $name, $target, $port, $priority = 0, $weight = 0, $ttl = 600)
    {
        $value = intval($priority) . ' ' . intval($weight) . ' ' . intval($port) . ' ' . $target;
        return $this->createDnsRecord($zone_id, $name, 'SRV', $value, $ttl, false);
    }

    public function createCAARecord($zone_id, $name, $flags, $tag, $value, $ttl = 600)
    {
        $escapedValue = str_replace('"', '\\"', $value);
        $content = intval($flags) . ' ' . trim($tag) . ' "' . $escapedValue . '"';
        return $this->createDnsRecord($zone_id, $name, 'CAA', $content, $ttl, false);
    }

    public function createTXTRecord($zone_id, $name, $content, $ttl = 600)
    {
        return $this->createDnsRecord($zone_id, $name, 'TXT', $content, $ttl, false);
    }

    public function updateSubdomain($zone_id, $record_id, $subdomain, $ip, $proxied = true)
    {
        return $this->updateDnsRecord($zone_id, $record_id, [
            'type' => 'A',
            'name' => $subdomain,
            'content' => $ip,
            'ttl' => 600,
        ]);
    }

    public function updateDnsRecord($zone_id, $record_id, $data)
    {
        $name = $data['name'] ?? $zone_id;
        $type = strtoupper($data['type'] ?? 'A');
        $value = $data['content'] ?? '';
        $ttl = $this->normalizeTtl($data['ttl'] ?? 600);
        $line = $this->normalizeLine($data['line'] ?? 'default');
        $payload = [
            'domain' => $zone_id,
            'record_id' => $record_id,
            'sub_domain' => $this->splitSubDomain($name, $zone_id),
            'record_type' => $type,
            'record_line' => $line,
            'value' => $value,
            'ttl' => $ttl,
        ];
        if ($type === 'MX' && isset($data['priority'])) {
            $payload['mx'] = intval($data['priority']);
        }
        $res = $this->request('Record.Modify', $payload);
        if (!($res['success'] ?? false)) {
            return $this->buildError($res);
        }
        return ['success' => true, 'result' => ['id' => $record_id]];
    }

    public function deleteSubdomain($zone_id, $record_id, array $context = [])
    {
        $res = $this->request('Record.Remove', [
            'domain' => $zone_id,
            'record_id' => $record_id,
        ]);
        if (!($res['success'] ?? false)) {
            return $this->buildError($res);
        }
        return ['success' => true];
    }

    public function deleteDomainRecords($zone_id, $domain_name)
    {
        $records = $this->getDnsRecords($zone_id, $domain_name, ['per_page' => 3000]);
        if (!($records['success'] ?? false)) {
            return $records;
        }
        $deleted = 0;
        $failed = [];
        foreach (($records['result'] ?? []) as $record) {
            $res = $this->deleteSubdomain($zone_id, $record['id']);
            if ($res['success'] ?? false) {
                $deleted++;
            } else {
                $failed[] = [
                    'record_id' => $record['id'] ?? null,
                    'name' => $record['name'] ?? null,
                    'type' => $record['type'] ?? null,
                    'error' => $res['errors'] ?? 'delete failed',
                ];
            }
        }
        return [
            'success' => empty($failed),
            'requested_count' => count($records['result'] ?? []),
            'deleted_count' => $deleted,
            'failed_count' => count($failed),
            'failed_items' => $failed,
        ];
    }

    public function deleteDomainRecordsDeep($zone_id, $subdomain_root)
    {
        $records = $this->getDnsRecords($zone_id, null, ['per_page' => 3000]);
        if (!($records['success'] ?? false)) {
            return $records;
        }
        $target = strtolower(rtrim($subdomain_root, '.'));
        $candidates = [];
        foreach (($records['result'] ?? []) as $record) {
            $name = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
            if ($target !== '' && ($name === $target || (strlen($name) > strlen($target) && substr($name, - (strlen($target) + 1)) === ('.' . $target)))) {
                $candidates[] = $record;
            }
        }

        $deleted = 0;
        $failed = [];
        foreach ($candidates as $record) {
            $res = $this->deleteSubdomain($zone_id, $record['id']);
            if ($res['success'] ?? false) {
                $deleted++;
            } else {
                $failed[] = [
                    'record_id' => $record['id'] ?? null,
                    'name' => $record['name'] ?? null,
                    'type' => $record['type'] ?? null,
                    'error' => $res['errors'] ?? 'delete failed',
                ];
            }
        }
        return [
            'success' => empty($failed),
            'requested_count' => count($candidates),
            'deleted_count' => $deleted,
            'failed_count' => count($failed),
            'failed_items' => $failed,
        ];
    }

    public function getDnsRecord($zone_id, $record_id)
    {
        $res = $this->request('Record.Info', [
            'domain' => $zone_id,
            'record_id' => $record_id,
        ]);
        if (!($res['success'] ?? false)) {
            return $this->buildError($res);
        }
        $record = $res['record'] ?? [];
        return ['success' => true, 'result' => $this->mapRecord($record, $zone_id)];
    }

    public function toggleProxy($zone_id, $record_id, $proxied)
    {
        return ['success' => true, 'result' => ['proxied' => false]];
    }

    public function createDnsRecordRaw($zone_id, $payload)
    {
        $type = strtoupper($payload['type'] ?? 'A');
        $name = $payload['name'] ?? $zone_id;
        $content = $payload['content'] ?? '';
        $ttl = $payload['ttl'] ?? 600;
        $line = $payload['line'] ?? null;
        return $this->createDnsRecord($zone_id, $name, $type, $content, $ttl, false, $line);
    }

    public function updateDnsRecordRaw($zone_id, $record_id, $payload)
    {
        return $this->updateDnsRecord($zone_id, $record_id, $payload);
    }

    public function batchUpdateDnsRecords($zone_id, $updates)
    {
        $results = [];
        foreach ($updates as $update) {
            if (isset($update['id'])) {
                $results[] = $this->updateDnsRecordRaw($zone_id, $update['id'], $update);
            } else {
                $results[] = $this->createDnsRecordRaw($zone_id, $update);
            }
        }
        return $results;
    }
}
