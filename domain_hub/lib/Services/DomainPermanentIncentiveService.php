<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfDomainPermanentIncentiveService
{
    private const TABLE_LOGS = 'mod_cloudflare_domain_permanent_incentive_logs';
    private const DOMAIN_SUBMIT_COOLDOWN_SECONDS = 300;
    private const USER_SUCCESS_LIMIT_MAX = 10000;

    public static function isEnabled(array $settings): bool
    {
        return function_exists('cfmod_setting_enabled')
            ? cfmod_setting_enabled($settings['enable_domain_permanent_incentive_center'] ?? '0')
            : in_array(strtolower(trim((string)($settings['enable_domain_permanent_incentive_center'] ?? '0'))), ['1','on','yes','true','enabled'], true);
    }

    public static function isInCampaignWindow(array $settings, ?int $nowTs = null): bool
    {
        $nowTs = $nowTs ?? time();
        $start = strtotime((string)($settings['domain_permanent_incentive_start_at'] ?? ''));
        $end = strtotime((string)($settings['domain_permanent_incentive_end_at'] ?? ''));
        if (!$start || !$end) return false;
        return $nowTs >= $start && $nowTs <= $end;
    }

    public static function isGrayHit(int $userId, array $settings): bool
    {
        $grayEnabled = $settings['domain_permanent_incentive_gray_enabled'] ?? '0';
        $grayRatio = intval($settings['domain_permanent_incentive_gray_ratio'] ?? 100);
        $grayRatio = max(0, min(100, $grayRatio));

        if (function_exists('cfmod_rootdomain_gray_hit')) {
            return cfmod_rootdomain_gray_hit(
                $userId,
                'domain_permanent_incentive_center',
                $grayEnabled,
                $grayRatio,
                'perm_incentive_v1'
            );
        }

        $enabled = in_array(strtolower(trim((string) $grayEnabled)), ['1', 'on', 'yes', 'true', 'enabled'], true);
        if (!$enabled) {
            return true;
        }
        if ($grayRatio <= 0) {
            return false;
        }
        if ($grayRatio >= 100) {
            return true;
        }
        $bucket = abs((int) sprintf('%u', crc32('uid:' . max(0, $userId) . '|feature:perm_incentive|salt:perm_incentive_v1'))) % 100;
        return $bucket < $grayRatio;
    }

    public static function getConditionMode(array $settings): string
    {
        $mode = strtolower(trim((string)($settings['domain_permanent_incentive_condition_mode'] ?? 'any')));
        return in_array($mode, ['any','all'], true) ? $mode : 'any';
    }

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_LOGS)) {
                $schema->create(self::TABLE_LOGS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->integer('subdomain_id')->unsigned();
                    $table->string('domain', 255);
                    $table->string('rootdomain', 255)->nullable();
                    $table->string('condition_mode', 8)->default('any');
                    $table->tinyInteger('ssl_check_result')->default(0);
                    $table->tinyInteger('site_check_result')->default(0);
                    $table->string('final_result', 16)->default('failed');
                    $table->text('fail_reason')->nullable();
                    $table->string('request_ip', 64)->nullable();
                    $table->timestamps();
                    $table->index(['userid', 'id']);
                });
            }
        } catch (\Throwable $e) {}
    }

    public static function getUserState(int $userId, array $settings, int $logsPage = 1, int $logsPerPage = 5): array
    {
        self::ensureTables();
        $logsPage = max(1, $logsPage);
        $logsPerPage = max(1, min(20, $logsPerPage));
        $rows = Capsule::table('mod_cloudflare_subdomain')->select('id','subdomain','status','never_expires')
            ->where('userid', $userId)->where('never_expires', 0)->whereIn('status',['active','pending'])->orderBy('id','desc')->limit(200)->get();
        $eligible=[];
        foreach($rows as $r){
            $domain = (string)($r->subdomain ?? '');
            $root = self::extractRootDomain($domain);
            if (!self::isRootDomainAllowed($root, $settings)) {
                continue;
            }
            $eligible[]=['id'=>(int)$r->id,'domain'=>$domain,'status'=>(string)$r->status];
        }
        $logsQuery = Capsule::table(self::TABLE_LOGS)->where('userid',$userId);
        $total = (int) (clone $logsQuery)->count();
        $totalPages = max(1, (int) ceil($total / $logsPerPage));
        if ($logsPage > $totalPages) {
            $logsPage = $totalPages;
        }
        $logs = $logsQuery->orderBy('id','desc')->offset(($logsPage - 1) * $logsPerPage)->limit($logsPerPage)->get();
        return [
            'eligible_domains'=>$eligible,
            'logs'=>json_decode(json_encode($logs),true) ?: [],
            'logs_pagination' => [
                'page' => $logsPage,
                'perPage' => $logsPerPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public static function checkAndUpgrade(int $userId, int $subdomainId, array $settings, string $requestIp=''): array
    {
        self::ensureTables();
        $row = Capsule::table('mod_cloudflare_subdomain')->where('id',$subdomainId)->where('userid',$userId)->first();
        if (!$row) throw new \InvalidArgumentException('invalid_subdomain');
        if ((int)($row->never_expires ?? 0) === 1) throw new \InvalidArgumentException('already_permanent');
        $domain = trim((string)($row->subdomain ?? ''));
        if ($domain === '') throw new \InvalidArgumentException('invalid_subdomain');
        $root = self::extractRootDomain($domain);
        if (!self::isRootDomainAllowed($root, $settings)) throw new \InvalidArgumentException('rootdomain_not_allowed');
        self::assertDomainCooldown($userId, $subdomainId);
        self::assertUserSuccessLimit($userId, $settings);

        $sslOk = self::probeTlsCertificate($domain);
        $siteOk = self::probeWebsiteReachable($domain);
        $mode = self::getConditionMode($settings);
        $pass = $mode === 'all' ? ($sslOk && $siteOk) : ($sslOk || $siteOk);
        $reason = $pass ? '' : (!$sslOk && !$siteOk ? 'ssl_and_site_unavailable' : (!$sslOk ? 'ssl_unavailable' : 'site_unavailable'));

        if ($pass) {
            Capsule::table('mod_cloudflare_subdomain')->where('id',$subdomainId)->update(['never_expires'=>1,'updated_at'=>date('Y-m-d H:i:s')]);
        }

        Capsule::table(self::TABLE_LOGS)->insert([
            'userid'=>$userId,'subdomain_id'=>$subdomainId,'domain'=>$domain,'rootdomain'=>$root,'condition_mode'=>$mode,
            'ssl_check_result'=>$sslOk?1:0,'site_check_result'=>$siteOk?1:0,'final_result'=>$pass?'success':'failed',
            'fail_reason'=>$reason !== '' ? $reason : null,'request_ip'=>substr($requestIp,0,64),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
        ]);

        return ['success'=>$pass,'domain'=>$domain,'ssl_ok'=>$sslOk,'site_ok'=>$siteOk,'mode'=>$mode,'reason'=>$reason];
    }

    private static function getUserSuccessLimit(array $settings): int
    {
        $raw = intval($settings['domain_permanent_incentive_user_success_limit'] ?? 0);
        if ($raw <= 0) {
            return 0;
        }
        return min(self::USER_SUCCESS_LIMIT_MAX, $raw);
    }

    private static function assertUserSuccessLimit(int $userId, array $settings): void
    {
        $limit = self::getUserSuccessLimit($settings);
        if ($limit <= 0) {
            return;
        }

        $successCount = (int) Capsule::table(self::TABLE_LOGS)
            ->where('userid', $userId)
            ->where('final_result', 'success')
            ->count();
        if ($successCount >= $limit) {
            throw new \InvalidArgumentException('user_upgrade_limit_reached');
        }
    }

    private static function assertDomainCooldown(int $userId, int $subdomainId): void
    {
        $lastLog = Capsule::table(self::TABLE_LOGS)
            ->where('userid', $userId)
            ->where('subdomain_id', $subdomainId)
            ->orderBy('id', 'desc')
            ->first();
        if (!$lastLog) {
            return;
        }

        $lastAtRaw = (string) ($lastLog->created_at ?? '');
        $lastTs = strtotime($lastAtRaw);
        if (!$lastTs) {
            return;
        }

        $elapsed = time() - $lastTs;
        if ($elapsed < self::DOMAIN_SUBMIT_COOLDOWN_SECONDS) {
            $remain = self::DOMAIN_SUBMIT_COOLDOWN_SECONDS - max(0, $elapsed);
            throw new \InvalidArgumentException('rate_limited:' . $remain);
        }
    }

    private static function isRootDomainAllowed(string $root, array $settings): bool
    {
        $raw = trim((string)($settings['domain_permanent_incentive_rootdomain_whitelist'] ?? ''));
        if ($raw === '') return true;
        $allowed = preg_split('/[\r\n,]+/', $raw) ?: [];
        $allowed = array_filter(array_map(static fn($v)=>strtolower(trim((string)$v)), $allowed));
        return in_array(strtolower($root), $allowed, true);
    }

    private static function extractRootDomain(string $domain): string
    {
        $parts = array_values(array_filter(explode('.', strtolower(trim($domain))), 'strlen'));
        $count = count($parts);
        if ($count <= 2) return implode('.', $parts);
        return $parts[$count-2] . '.' . $parts[$count-1];
    }

    private static function probeTlsCertificate(string $domain): bool
    {
        $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false,'verify_peer_name'=>false,'SNI_enabled'=>true,'peer_name'=>$domain]]);
        $stream = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$stream) return false;
        $params = stream_context_get_params($stream);
        fclose($stream);
        $peerCert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (empty($peerCert)) {
            return false;
        }

        $parsed = @openssl_x509_parse($peerCert, false);
        if (!is_array($parsed) || empty($parsed)) {
            return false;
        }

        $now = time();
        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : 0;
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : 0;
        if ($validFrom > 0 && $now < $validFrom) {
            return false;
        }
        if ($validTo > 0 && $now > $validTo) {
            return false;
        }

        $domain = strtolower(trim($domain, '.'));
        $patterns = [];
        $extensions = $parsed['extensions'] ?? [];
        $sanRaw = is_array($extensions) ? (string)($extensions['subjectAltName'] ?? '') : '';
        if ($sanRaw !== '') {
            $parts = explode(',', $sanRaw);
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if (stripos($part, 'DNS:') === 0) {
                    $patterns[] = strtolower(trim(substr($part, 4), " \t\n\r\0\x0B."));
                }
            }
        }

        if (isset($parsed['subject']['CN'])) {
            $cn = $parsed['subject']['CN'];
            if (is_string($cn) && trim($cn) !== '') {
                $patterns[] = strtolower(trim($cn, " \t\n\r\0\x0B."));
            } elseif (is_array($cn)) {
                foreach ($cn as $cnItem) {
                    $cnItem = trim((string) $cnItem);
                    if ($cnItem !== '') {
                        $patterns[] = strtolower(trim($cnItem, " \t\n\r\0\x0B."));
                    }
                }
            }
        }

        $patterns = array_values(array_unique(array_filter($patterns, static fn($v) => $v !== '')));
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (self::matchesDomainPattern($domain, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private static function matchesDomainPattern(string $domain, string $pattern): bool
    {
        $domain = strtolower(trim($domain, '.'));
        $pattern = strtolower(trim($pattern, '.'));
        if ($domain === '' || $pattern === '') {
            return false;
        }
        if ($domain === $pattern) {
            return true;
        }
        if (strpos($pattern, '*.') !== 0) {
            return false;
        }
        $suffix = substr($pattern, 2);
        if ($suffix === '' || substr($domain, -strlen($suffix)) !== $suffix) {
            return false;
        }
        $prefix = substr($domain, 0, -strlen($suffix));
        $prefix = rtrim($prefix, '.');
        if ($prefix === '') {
            return false;
        }
        return strpos($prefix, '.') === false;
    }

    private static function probeWebsiteReachable(string $domain): bool
    {
        foreach (['https://','http://'] as $scheme) {
            $ch = curl_init($scheme . $domain . '/');
            curl_setopt_array($ch, [CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_USERAGENT=>'DomainHubIncentiveProbe/1.0']);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 400) return true;
        }
        return false;
    }
}
