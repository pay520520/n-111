<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfWhoisService
{
    private const TABLE_PRIVACY = 'mod_cloudflare_whois_privacy_profiles';
    private const REDACTED_TEXT = 'REDACTED FOR PRIVACY';
    private const PRIVACY_PROVIDER_TEXT = 'Privacy Protected by DNSHE';
    private const FREE_SUBDOMAIN_SOURCE = 'whois.dnshe.com';
    private const UNREGISTERED_STATUS = 'unregistered';
    private const PERMANENT_EXPIRES_AT = '2999-12-31 23:59';

    public static function ensureTable(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_PRIVACY)) {
                $schema->create(self::TABLE_PRIVACY, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->boolean('privacy_enabled')->default(true);
                    $table->timestamps();
                    $table->index(['userid'], 'idx_cf_whois_profile_user');
                });
            }
        } catch (\Throwable $e) {
        }
    }

    public static function isEnabled(array $moduleSettings): bool
    {
        if (!array_key_exists('enable_whois_center', $moduleSettings)) {
            return true;
        }

        $value = $moduleSettings['enable_whois_center'];
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($value);
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }

        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getUserWhoisSettings(int $userId): array
    {
        self::ensureTable();
        self::ensureDefaultPrivacyProfile($userId);

        return [
            'privacy_enabled' => self::getUserPrivacyState($userId),
            'managed_domains' => self::countUserDomains($userId),
        ];
    }

    public static function setUserPrivacy(int $userId, bool $enabled): array
    {
        self::ensureTable();

        if ($userId <= 0) {
            throw new \RuntimeException('invalid_user');
        }

        $now = date('Y-m-d H:i:s');

        Capsule::connection()->transaction(function () use ($userId, $enabled, $now) {
            $existing = Capsule::table(self::TABLE_PRIVACY)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                Capsule::table(self::TABLE_PRIVACY)
                    ->where('userid', $userId)
                    ->update([
                        'privacy_enabled' => $enabled ? 1 : 0,
                        'updated_at' => $now,
                    ]);
            } else {
                Capsule::table(self::TABLE_PRIVACY)->insert([
                    'userid' => $userId,
                    'privacy_enabled' => $enabled ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return self::getUserWhoisSettings($userId);
    }

    public static function lookupDomain(int $requestUserId, string $domainInput): array
    {
        self::ensureTable();

        $domain = self::normalizeDomain($domainInput);
        if ($domain === '') {
            throw new \RuntimeException('invalid_domain');
        }

        $localSubdomain = Capsule::table('mod_cloudflare_subdomain')
            ->where('subdomain', $domain)
            ->whereNotIn('status', ['deleted', 'cancelled', 'canceled', 'pending_delete', 'pending_remove'])
            ->first();

        if ($localSubdomain) {
            return self::buildLocalWhois($localSubdomain);
        }

        $managedRoot = self::resolveManagedRootDomain($domain);
        if ($managedRoot !== null && $domain !== $managedRoot) {
            return self::buildUnregisteredManagedSubdomainWhois($domain, $managedRoot);
        }

        return self::buildExternalWhois($domain);
    }

    private static function buildLocalWhois($subdomain): array
    {
        $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];

        $ownerUserId = intval($subdomain->userid ?? 0);
        $privacyEnabled = self::getUserPrivacyState($ownerUserId);

        $client = null;
        if ($ownerUserId > 0) {
            $client = Capsule::table('tblclients')->where('id', $ownerUserId)->first();
        }

        $ownerName = trim((string) (($client->firstname ?? '') . ' ' . ($client->lastname ?? '')));
        $ownerEmail = trim((string) ($client->email ?? ''));
        $ownerPhone = trim((string) ($client->phonenumber ?? ''));
        $ownerCountry = trim((string) ($client->country ?? ''));
        $ownerProvince = trim((string) ($client->state ?? ''));
        $ownerCity = trim((string) ($client->city ?? ''));
        $ownerProvinceCity = self::buildProvinceCityValue($ownerProvince, $ownerCity);
        $ownerAddress = self::buildClientAddress($client);

        $nameServers = self::resolveNameServersForSubdomain((int) ($subdomain->id ?? 0), $settings);
        $anonymousEmail = self::resolveAnonymousEmail($settings);

        $expiresAt = self::formatTime($subdomain->expires_at ?? null);
        if (intval($subdomain->never_expires ?? 0) === 1) {
            $expiresAt = self::PERMANENT_EXPIRES_AT;
        }

        $result = [
            'domain' => (string) ($subdomain->subdomain ?? ''),
            'source' => self::FREE_SUBDOMAIN_SOURCE,
            'source_type' => 'internal',
            'registered' => true,
            'privacy_enabled' => $privacyEnabled,
            'registrant_name' => '',
            'registrant_email' => '',
            'registrant_phone' => '',
            'registrant_country' => '-',
            'registrant_province' => '-',
            'registrant_city' => '-',
            'registrant_province_city' => '-',
            'registrant_address' => '-',
            'privacy_notice' => '',
            'created_at' => self::formatTime($subdomain->created_at ?? null),
            'expires_at' => $expiresAt,
            'name_servers' => $nameServers,
            'status' => self::presentWhoisStatus((string) ($subdomain->status ?? '')),
            'owner_userid' => $ownerUserId,
        ];

        if ($privacyEnabled) {
            $result['registrant_name'] = self::REDACTED_TEXT;
            $result['registrant_phone'] = self::REDACTED_TEXT;
            $result['registrant_email'] = $anonymousEmail !== '' ? $anonymousEmail : self::REDACTED_TEXT;
            $result['registrant_country'] = self::REDACTED_TEXT;
            $result['registrant_province'] = self::REDACTED_TEXT;
            $result['registrant_city'] = self::REDACTED_TEXT;
            $result['registrant_province_city'] = self::REDACTED_TEXT;
            $result['registrant_address'] = self::REDACTED_TEXT;
            $result['privacy_notice'] = self::PRIVACY_PROVIDER_TEXT;
        } else {
            $result['registrant_name'] = $ownerName !== '' ? $ownerName : '-';
            $result['registrant_phone'] = $ownerPhone !== '' ? $ownerPhone : '-';
            $result['registrant_email'] = ($ownerEmail !== '' && filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) ? $ownerEmail : '-';
            $result['registrant_country'] = $ownerCountry !== '' ? $ownerCountry : '-';
            $result['registrant_province'] = $ownerProvince !== '' ? $ownerProvince : '-';
            $result['registrant_city'] = $ownerCity !== '' ? $ownerCity : '-';
            $result['registrant_province_city'] = $ownerProvinceCity !== '' ? $ownerProvinceCity : '-';
            $result['registrant_address'] = $ownerAddress !== '' ? $ownerAddress : '-';
        }

        return [
            'success' => true,
            'result' => $result,
        ];
    }

    private static function resolveNameServersForSubdomain(int $subdomainId, array $settings): array
    {
        $items = [];

        if ($subdomainId > 0) {
            try {
                $rows = Capsule::table('mod_cloudflare_dns_records')
                    ->where('subdomain_id', $subdomainId)
                    ->where('type', 'NS')
                    ->orderBy('id', 'asc')
                    ->pluck('content');
                foreach ($rows as $row) {
                    $ns = strtolower(trim((string) $row));
                    if ($ns !== '' && !in_array($ns, $items, true)) {
                        $items[] = $ns;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (!empty($items)) {
            return $items;
        }

        $defaultNsRaw = trim((string) ($settings['whois_default_nameservers'] ?? ($settings['whois_default_ns_list'] ?? '')));
        if ($defaultNsRaw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;]+/', $defaultNsRaw) ?: [];
        foreach ($parts as $part) {
            $candidate = strtolower(trim((string) $part));
            if ($candidate !== '' && !in_array($candidate, $items, true)) {
                $items[] = $candidate;
            }
        }

        return $items;
    }

    private static function resolveAnonymousEmail(array $settings): string
    {
        $email = trim((string) ($settings['whois_anonymous_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return '';
    }

    private static function buildProvinceCityValue(string $province, string $city): string
    {
        $province = trim($province);
        $city = trim($city);
        if ($province !== '' && $city !== '') {
            return $province . '-' . $city;
        }
        if ($province !== '') {
            return $province;
        }
        return $city;
    }

    private static function buildClientProvinceCity($client): string
    {
        if (!$client) {
            return '';
        }

        $province = trim((string) ($client->state ?? ''));
        $city = trim((string) ($client->city ?? ''));
        return self::buildProvinceCityValue($province, $city);
    }

    private static function buildClientAddress($client): string
    {
        if (!$client) {
            return '';
        }

        $parts = [];
        foreach (['address1', 'address2', 'postcode'] as $field) {
            $value = trim((string) ($client->{$field} ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    private static function ensureDefaultPrivacyProfile(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $exists = Capsule::table(self::TABLE_PRIVACY)
                ->where('userid', $userId)
                ->exists();
            if ($exists) {
                return;
            }

            $now = date('Y-m-d H:i:s');
            Capsule::table(self::TABLE_PRIVACY)->insert([
                'userid' => $userId,
                'privacy_enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function resolveManagedRootDomain(string $domain): ?string
    {
        $roots = self::getManagedRootDomains();
        if (empty($roots)) {
            return null;
        }

        usort($roots, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        foreach ($roots as $root) {
            if ($domain === $root || substr($domain, -strlen('.' . $root)) === '.' . $root) {
                return $root;
            }
        }

        return null;
    }

    private static function getManagedRootDomains(): array
    {
        $domains = [];

        if (function_exists('cfmod_get_known_rootdomains')) {
            try {
                $known = cfmod_get_known_rootdomains();
                if (is_array($known)) {
                    foreach ($known as $item) {
                        $value = trim(strtolower((string) $item));
                        if ($value !== '') {
                            $domains[$value] = $value;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (!empty($domains)) {
            return array_values($domains);
        }

        try {
            $rows = Capsule::table('mod_cloudflare_rootdomains')
                ->select('domain')
                ->where('status', 'active')
                ->get();
            foreach ($rows as $row) {
                $value = trim(strtolower((string) ($row->domain ?? '')));
                if ($value !== '') {
                    $domains[$value] = $value;
                }
            }
        } catch (\Throwable $e) {
        }

        return array_values($domains);
    }

    private static function buildUnregisteredManagedSubdomainWhois(string $domain, string $rootDomain): array
    {
        return [
            'success' => true,
            'result' => [
                'domain' => $domain,
                'root_domain' => $rootDomain,
                'source' => self::FREE_SUBDOMAIN_SOURCE,
                'source_type' => 'internal',
                'registered' => false,
                'status' => self::UNREGISTERED_STATUS,
                'message' => 'domain not registered',
                'privacy_enabled' => false,
                'registrant_name' => '',
                'registrant_email' => '',
                'registrant_phone' => '',
                'registrant_country' => '-',
                'registrant_province' => '-',
                'registrant_city' => '-',
                'registrant_province_city' => '-',
                'registrant_address' => '-',
                'privacy_notice' => '',
                'created_at' => '',
                'expires_at' => '',
                'name_servers' => [],
                'raw' => '',
            ],
        ];
    }

    private static function getUserPrivacyState(int $userId): bool
    {
        if ($userId <= 0) {
            return true;
        }

        try {
            $value = Capsule::table(self::TABLE_PRIVACY)
                ->where('userid', $userId)
                ->value('privacy_enabled');
            if ($value === null) {
                return true;
            }
            return intval($value) === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private static function countUserDomains(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            return (int) Capsule::table('mod_cloudflare_subdomain')
                ->where('userid', $userId)
                ->whereNotIn('status', ['deleted', 'cancelled', 'canceled', 'pending_delete', 'pending_remove'])
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function buildExternalWhois(string $domain): array
    {
        $raw = self::queryWhoisRaw($domain);

        $createdAt = self::extractWhoisField($raw, [
            'Creation Date',
            'Created On',
            'Created Date',
            'Registered On',
            'Registration Time',
        ]);
        $expiresAt = self::extractWhoisField($raw, [
            'Registry Expiry Date',
            'Registrar Registration Expiration Date',
            'Expiry Date',
            'Expiration Date',
            'Expires On',
        ]);

        $registrantName = self::extractWhoisField($raw, ['Registrant Name', 'Registrant', 'Holder Name']);
        $registrantEmail = self::extractWhoisField($raw, ['Registrant Email', 'Registrant E-mail', 'Email']);
        $registrantPhone = self::extractWhoisField($raw, ['Registrant Phone', 'Phone', 'Phone Number']);
        $registrantCountry = self::extractWhoisField($raw, ['Registrant Country', 'Country']);
        $registrantState = self::extractWhoisField($raw, ['Registrant State/Province', 'Registrant State', 'State/Province', 'State']);
        $registrantCity = self::extractWhoisField($raw, ['Registrant City', 'City']);
        $registrantProvinceCity = trim($registrantState) !== '' && trim($registrantCity) !== ''
            ? trim($registrantState) . '-' . trim($registrantCity)
            : (trim($registrantState) !== '' ? trim($registrantState) : trim($registrantCity));
        $registrantAddress = self::extractWhoisField($raw, ['Registrant Street', 'Registrant Address', 'Street', 'Address']);

        $nameServers = [];
        if (preg_match_all('/^\s*Name Server\s*:\s*(.+)$/mi', $raw, $matches)) {
            foreach (($matches[1] ?? []) as $nameServer) {
                $candidate = trim((string) $nameServer);
                if ($candidate !== '' && !in_array($candidate, $nameServers, true)) {
                    $nameServers[] = $candidate;
                }
            }
        }

        return [
            'success' => true,
            'result' => [
                'domain' => $domain,
                'source' => 'external',
                'source_type' => 'external',
                'registered' => true,
                'privacy_enabled' => false,
                'registrant_name' => $registrantName,
                'registrant_email' => $registrantEmail,
                'registrant_phone' => $registrantPhone,
                'registrant_country' => $registrantCountry,
                'registrant_province' => $registrantState,
                'registrant_city' => $registrantCity,
                'registrant_province_city' => $registrantProvinceCity,
                'registrant_address' => $registrantAddress,
                'privacy_notice' => '',
                'created_at' => self::normalizeWhoisDate($createdAt),
                'expires_at' => self::normalizeWhoisDate($expiresAt),
                'name_servers' => $nameServers,
                'raw' => self::truncateRaw($raw),
            ],
        ];
    }

    private static function presentWhoisStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'suspended') {
            return 'ServerHold';
        }
        return trim($status);
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

        if ($input === '' || strpos($input, '.') === false) {
            return '';
        }

        if (!preg_match('/^[a-z0-9][a-z0-9\-\.]{1,253}[a-z0-9]$/', $input)) {
            return '';
        }

        return $input;
    }

    private static function queryWhoisRaw(string $domain): string
    {
        $tld = strtolower((string) substr(strrchr($domain, '.') ?: '', 1));
        $whoisServer = self::guessWhoisServer($tld);
        $raw = self::requestWhoisServer($whoisServer, $domain);

        if ($raw === '') {
            $raw = self::requestWhoisServer('whois.iana.org', $domain);
        }

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\s*refer:\s*(.+)$/mi', $raw, $match)) {
            $referServer = trim((string) ($match[1] ?? ''));
            if ($referServer !== '' && stripos($referServer, 'whois.') === 0) {
                $referRaw = self::requestWhoisServer($referServer, $domain);
                if ($referRaw !== '') {
                    $raw = $referRaw;
                }
            }
        }

        return $raw;
    }

    private static function guessWhoisServer(string $tld): string
    {
        $map = [
            'com' => 'whois.verisign-grs.com',
            'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org',
            'info' => 'whois.afilias.net',
            'biz' => 'whois.nic.biz',
            'io' => 'whois.nic.io',
            'app' => 'whois.nic.google',
            'dev' => 'whois.nic.google',
            'xyz' => 'whois.nic.xyz',
            'me' => 'whois.nic.me',
            'cc' => 'ccwhois.verisign-grs.com',
            'top' => 'whois.nic.top',
            'cn' => 'whois.cnnic.cn',
        ];

        return $map[$tld] ?? 'whois.iana.org';
    }

    private static function requestWhoisServer(string $server, string $domain): string
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($server, 43, $errno, $errstr, 8);
        if (!$fp) {
            return '';
        }

        stream_set_timeout($fp, 8);
        fwrite($fp, $domain . "\r\n");

        $response = '';
        while (!feof($fp)) {
            $chunk = fgets($fp, 4096);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
            if (strlen($response) > 200000) {
                break;
            }
        }

        fclose($fp);

        return trim($response);
    }

    private static function extractWhoisField(string $raw, array $keys): string
    {
        if ($raw === '') {
            return '';
        }

        foreach ($keys as $key) {
            $pattern = '/^\s*' . preg_quote($key, '/') . '\s*:\s*(.+)$/mi';
            if (preg_match($pattern, $raw, $match)) {
                $value = trim((string) ($match[1] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function normalizeWhoisDate(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $ts = strtotime($input);
        if ($ts === false) {
            return $input;
        }

        return date('Y-m-d H:i', $ts);
    }

    private static function formatTime($value): string
    {
        if (empty($value)) {
            return '';
        }

        $ts = strtotime((string) $value);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d H:i', $ts);
    }

    private static function truncateRaw(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strlen($raw) > 8000) {
            return substr($raw, 0, 8000) . "\n...";
        }

        return $raw;
    }
}
