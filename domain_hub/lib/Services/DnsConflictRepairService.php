<?php

declare(strict_types=1);

class CfDnsConflictRepairService
{
    public static function postUpdateVerifyEnabled(array $settings): bool
    {
        if (array_key_exists('dns_repair_post_update_verify_enabled', $settings)) {
            $value = strtolower(trim((string) ($settings['dns_repair_post_update_verify_enabled'] ?? '1')));
            return in_array($value, ['1', 'on', 'yes', 'true', 'enabled'], true);
        }
        return true;
    }
    public static function createSemanticsMode(array $settings): string
    {
        $raw = strtolower(trim((string)($settings['dns_create_semantics_mode'] ?? 'local_empty_add_as_replace')));
        return $raw === 'append' ? 'append' : 'local_empty_add_as_replace';
    }

    public static function conflictAutoRepairTypes(array $settings): array
    {
        return self::parseDnsTypeCsv((string)($settings['conflict_auto_repair_types'] ?? 'A,AAAA,CNAME,TXT'), ['A','AAAA','CNAME','TXT']);
    }

    public static function replaceModeTypes(array $settings): array
    {
        return self::parseDnsTypeCsv((string)($settings['replace_mode_types'] ?? 'A,AAAA,CNAME'), ['A','AAAA','CNAME']);
    }

    public static function normalizeDnsName(string $name): string { return strtolower(rtrim(trim($name), '.')); }
    public static function normalizeDnsContent(string $content, string $type = ''): string {
        $value = trim($content);
        $upper = strtoupper(trim($type));
        if (in_array($upper, ['TXT', 'SPF'], true)) { return $value; }
        return strtolower(rtrim($value, '.'));
    }
    public static function normalizeLineValue($line): string {
        $value = strtolower(trim((string)$line));
        if ($value === '' || $value === 'default' || $value === '默认') return '';
        return $value;
    }
    public static function normalizeType(string $type): string { return strtoupper(trim($type)); }
    public static function comparePriorityApplies(string $type): bool { return in_array(self::normalizeType($type), ['MX', 'SRV'], true); }

    public static function tryRepairViaUpsert($providerClient, string $zoneId, string $type, string $name, string $content, int $ttl, int $priority, string $line, bool $allowReplace = false, bool $verifyAfterUpdate = true): array
    {
        if (!$providerClient || !method_exists($providerClient, 'getDnsRecords')) return ['success' => false, 'error' => 'provider unavailable'];
        $typeU = self::normalizeType($type); $line = self::normalizeLineValue($line); $targetName = self::normalizeDnsName($name);
        try {
            $listRes = $providerClient->getDnsRecords($zoneId, $name, ['per_page' => 1000, 'type' => $typeU]);
            if (!($listRes['success'] ?? false)) return ['success' => false, 'error' => 'provider list failed'];
            $candidates = [];
            foreach ((array)($listRes['result'] ?? []) as $record) {
                if (!is_array($record)) continue;
                if (self::normalizeDnsName((string)($record['name'] ?? '')) === $targetName && self::normalizeType((string)($record['type'] ?? '')) === $typeU && trim((string)($record['id'] ?? '')) !== '') $candidates[] = $record;
            }
            $target = null;
            foreach ($candidates as $cand) {
                if (self::normalizeDnsContent((string)($cand['content'] ?? ''), $typeU) !== self::normalizeDnsContent($content, $typeU)) continue;
                $prioritySame = !self::comparePriorityApplies($typeU) || intval($cand['priority'] ?? 0) === $priority;
                $ttlSame = intval($cand['ttl'] ?? 600) === $ttl;
                $lineSame = self::normalizeLineValue($cand['line'] ?? '') === $line;
                if ($prioritySame && $ttlSame && $lineSame) { $target = $cand; break; }
            }
            if ($target) return self::successFromTarget($target, $typeU, $content, $ttl, $priority, $line, true, count($candidates));
            if (!$allowReplace) return ['success'=>false,'error'=>'replace_not_allowed','remote_candidates_count'=>count($candidates)];
            if (count($candidates) !== 1) return ['success'=>false,'error'=>count($candidates)===0?'no unique conflict target':'multiple conflict targets','remote_candidates_count'=>count($candidates)];
            $target = $candidates[0]; $targetId = trim((string)($target['id'] ?? ''));
            $payload = ['type'=>$typeU,'name'=>$targetName,'content'=>$content,'ttl'=>$ttl];
            if (self::comparePriorityApplies($typeU)) $payload['priority'] = $priority;
            if ($line !== '') $payload['line'] = $line;
            $updateRes = method_exists($providerClient, 'updateDnsRecordRaw') ? $providerClient->updateDnsRecordRaw($zoneId, $targetId, $payload) : $providerClient->updateDnsRecord($zoneId, $targetId, $payload);
            if (!($updateRes['success'] ?? false)) return ['success' => false, 'error' => self::providerErrorText($updateRes)];
            if ($verifyAfterUpdate) {
                $verifiedRecord = null;
                if (method_exists($providerClient, 'getDnsRecord')) {
                    $verifyRes = $providerClient->getDnsRecord($zoneId, $targetId);
                    if (($verifyRes['success'] ?? false) && is_array($verifyRes['result'] ?? null)) {
                        $verifiedRecord = (array) $verifyRes['result'];
                    }
                }
                if ($verifiedRecord === null && method_exists($providerClient, 'getDnsRecords')) {
                    $listRes = $providerClient->getDnsRecords($zoneId, $name, ['per_page' => 1000, 'type' => $typeU]);
                    if (($listRes['success'] ?? false) && is_array($listRes['result'] ?? null)) {
                        foreach ((array) ($listRes['result'] ?? []) as $record) {
                            if (!is_array($record)) { continue; }
                            if (trim((string) ($record['id'] ?? '')) !== $targetId) { continue; }
                            $verifiedRecord = $record;
                            break;
                        }
                    }
                }
                if ($verifiedRecord === null) {
                    return ['success' => false, 'error' => 'post_update_verify_unavailable', 'record_id' => $targetId];
                }
                if (!self::verifyRemoteRecord($verifiedRecord, $typeU, $name, $content, $ttl, $priority, $line)) {
                    return ['success' => false, 'error' => 'post_update_verify_failed', 'record_id' => $targetId];
                }
            }
            return self::successFromTarget($target, $typeU, $content, $ttl, $priority, $line, false, count($candidates));
        } catch (\Throwable $e) {
            return ['success'=>false,'error'=>$e->getMessage()];
        }
    }

    public static function verifyRemoteRecord(array $remoteRecord, string $type, string $name, string $content, int $ttl, int $priority, string $line): bool
    {
        $typeU = self::normalizeType($type);
        if (self::normalizeDnsName((string)($remoteRecord['name'] ?? '')) !== self::normalizeDnsName($name)) return false;
        if (self::normalizeType((string)($remoteRecord['type'] ?? '')) !== $typeU) return false;
        if (trim((string)($remoteRecord['content'] ?? '')) !== trim((string)$content)) return false;
        if (intval($remoteRecord['ttl'] ?? 0) !== intval($ttl)) return false;
        if (self::normalizeLineValue($remoteRecord['line'] ?? '') !== self::normalizeLineValue($line)) return false;
        if (self::comparePriorityApplies($typeU) && intval($remoteRecord['priority'] ?? 0) !== intval($priority)) return false;
        return true;
    }

    public static function logCatch(string $scene, array $ctx, \Throwable $e): void
    {
        if (!function_exists('cloudflare_subdomain_log')) return;
        $stack = $e->getTraceAsString();
        cloudflare_subdomain_log('dns_conflict_repair_exception', array_merge($ctx, [
            'scene' => $scene,
            'provider_error' => $e->getMessage(),
            'stack_hash' => substr(sha1($stack), 0, 16),
        ]), intval($ctx['userid'] ?? 0), intval($ctx['subdomain_id'] ?? 0));
    }

    private static function parseDnsTypeCsv(string $raw, array $defaults): array
    {
        $parts = preg_split('/[\s,;|]+/', strtoupper(trim($raw)));
        $types = [];
        foreach ((array)$parts as $p) { $v = trim((string)$p); if ($v !== '') $types[$v] = true; }
        return empty($types) ? $defaults : array_keys($types);
    }
    private static function providerErrorText(array $res): string { $m = $res['errors'][0] ?? ($res['errors'] ?? 'provider update failed'); return is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE) : (string)$m; }
    private static function successFromTarget(array $target, string $typeU, string $content, int $ttl, int $priority, string $line, bool $noop, int $count): array {
        return ['success'=>true,'record_id'=>trim((string)($target['id'] ?? '')),'name'=>self::normalizeDnsName((string)($target['name'] ?? '')),'type'=>$typeU,'content'=>$content,'ttl'=>$ttl,'priority'=>self::comparePriorityApplies($typeU)?$priority:null,'line'=>$line!==''?$line:null,'noop'=>$noop,'decision_path'=>$noop?'noop':'update','remote_candidates_count'=>$count];
    }
}
