<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfSslCertificateException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message = '', ?\Throwable $previous = null)
    {
        $this->reason = $reason;
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

class CfSslCertificateService
{
    private const TABLE_CERTIFICATES = 'mod_cloudflare_ssl_certificates';

    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_ISSUED = 'issued';
    private const STATUS_FAILED = 'failed';
    private const STATUS_EXPIRED = 'expired';

    private const LETSENCRYPT_PRODUCTION = 'https://acme-v02.api.letsencrypt.org/directory';
    private const LETSENCRYPT_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_CERTIFICATES)) {
                $schema->create(self::TABLE_CERTIFICATES, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->integer('subdomain_id')->unsigned();
                    $table->string('domain', 255)->default('');
                    $table->string('rootdomain', 255)->default('');
                    $table->string('status', 32)->default(self::STATUS_PENDING);
                    $table->string('cert_path', 255)->nullable();
                    $table->string('key_path', 255)->nullable();
                    $table->string('fullchain_path', 255)->nullable();
                    $table->string('issuer', 255)->nullable();
                    $table->string('serial_number', 128)->nullable();
                    $table->string('fingerprint_sha256', 128)->nullable();
                    $table->dateTime('not_before')->nullable();
                    $table->dateTime('expires_at')->nullable();
                    $table->text('last_error')->nullable();
                    $table->longText('acme_output')->nullable();
                    $table->dateTime('requested_at')->nullable();
                    $table->dateTime('issued_at')->nullable();
                    $table->timestamps();
                    $table->index(['userid', 'id'], 'idx_cf_ssl_user_id');
                    $table->index(['subdomain_id'], 'idx_cf_ssl_subdomain_id');
                    $table->index(['status'], 'idx_cf_ssl_status');
                    $table->index(['expires_at'], 'idx_cf_ssl_expires_at');
                });
                return;
            }

            self::ensureColumn('cert_path', function ($table) {
                $table->string('cert_path', 255)->nullable();
            });
            self::ensureColumn('key_path', function ($table) {
                $table->string('key_path', 255)->nullable();
            });
            self::ensureColumn('fullchain_path', function ($table) {
                $table->string('fullchain_path', 255)->nullable();
            });
            self::ensureColumn('issuer', function ($table) {
                $table->string('issuer', 255)->nullable();
            });
            self::ensureColumn('serial_number', function ($table) {
                $table->string('serial_number', 128)->nullable();
            });
            self::ensureColumn('fingerprint_sha256', function ($table) {
                $table->string('fingerprint_sha256', 128)->nullable();
            });
            self::ensureColumn('not_before', function ($table) {
                $table->dateTime('not_before')->nullable();
            });
            self::ensureColumn('expires_at', function ($table) {
                $table->dateTime('expires_at')->nullable();
            });
            self::ensureColumn('last_error', function ($table) {
                $table->text('last_error')->nullable();
            });
            self::ensureColumn('acme_output', function ($table) {
                $table->longText('acme_output')->nullable();
            });
            self::ensureColumn('requested_at', function ($table) {
                $table->dateTime('requested_at')->nullable();
            });
            self::ensureColumn('issued_at', function ($table) {
                $table->dateTime('issued_at')->nullable();
            });

            self::ensureIndex('idx_cf_ssl_user_id', '(`userid`, `id`)');
            self::ensureIndex('idx_cf_ssl_subdomain_id', '(`subdomain_id`)');
            self::ensureIndex('idx_cf_ssl_status', '(`status`)');
            self::ensureIndex('idx_cf_ssl_expires_at', '(`expires_at`)');
        } catch (\Throwable $e) {
        }
    }

    private static function ensureColumn(string $columnName, callable $callback): void
    {
        try {
            if (!Capsule::schema()->hasColumn(self::TABLE_CERTIFICATES, $columnName)) {
                Capsule::schema()->table(self::TABLE_CERTIFICATES, $callback);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function ensureIndex(string $indexName, string $definition): void
    {
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_CERTIFICATES . '` ADD INDEX `' . $indexName . '` ' . $definition);
        } catch (\Throwable $e) {
        }
    }

    public static function isEnabled(array $moduleSettings): bool
    {
        if (!array_key_exists('enable_ssl_request', $moduleSettings)) {
            return true;
        }

        $value = $moduleSettings['enable_ssl_request'];
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($value);
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }

        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getUserDomainOptions(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = Capsule::table('mod_cloudflare_subdomain')
            ->select('id', 'subdomain', 'rootdomain', 'status')
            ->where('userid', $userId)
            ->whereIn('status', ['active', 'pending'])
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row->id ?? 0),
                'domain' => (string) ($row->subdomain ?? ''),
                'rootdomain' => (string) ($row->rootdomain ?? ''),
                'status' => (string) ($row->status ?? ''),
            ];
        }

        return $items;
    }

    public static function getUserCertificates(int $userId, int $page = 1, int $perPage = 10): array
    {
        self::ensureTables();
        self::refreshExpiredStatuses($userId);

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        if ($userId <= 0) {
            return [
                'items' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ];
        }

        $query = Capsule::table(self::TABLE_CERTIFICATES)
            ->where('userid', $userId)
            ->orderBy('id', 'desc');

        $total = (int) $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row->id ?? 0),
                'subdomain_id' => (int) ($row->subdomain_id ?? 0),
                'domain' => (string) ($row->domain ?? ''),
                'status' => (string) ($row->status ?? ''),
                'issuer' => (string) ($row->issuer ?? ''),
                'serial_number' => (string) ($row->serial_number ?? ''),
                'fingerprint_sha256' => (string) ($row->fingerprint_sha256 ?? ''),
                'not_before' => self::formatTime($row->not_before ?? null),
                'expires_at' => self::formatTime($row->expires_at ?? null),
                'requested_at' => self::formatTime($row->requested_at ?? null),
                'issued_at' => self::formatTime($row->issued_at ?? null),
                'last_error' => (string) ($row->last_error ?? ''),
                'cert_path' => (string) ($row->cert_path ?? ''),
                'key_path' => (string) ($row->key_path ?? ''),
                'fullchain_path' => (string) ($row->fullchain_path ?? ''),
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    public static function requestCertificate(int $userId, int $subdomainId, array $moduleSettings, string $clientIp = ''): array
    {
        if ($userId <= 0) {
            throw new CfSslCertificateException('invalid_user');
        }
        if ($subdomainId <= 0) {
            throw new CfSslCertificateException('invalid_subdomain');
        }
        if (!self::isEnabled($moduleSettings)) {
            throw new CfSslCertificateException('disabled');
        }

        self::ensureTables();
        $now = date('Y-m-d H:i:s');

        $requestData = Capsule::connection()->transaction(function () use ($userId, $subdomainId, $now) {
            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();
            if (!$subdomain) {
                throw new CfSslCertificateException('subdomain_not_found');
            }

            $status = strtolower((string) ($subdomain->status ?? ''));
            if (!in_array($status, ['active', 'pending'], true)) {
                throw new CfSslCertificateException('subdomain_inactive');
            }

            $domain = strtolower(trim((string) ($subdomain->subdomain ?? '')));
            $rootdomain = strtolower(trim((string) ($subdomain->rootdomain ?? '')));
            if ($domain === '' || strpos($domain, '.') === false) {
                throw new CfSslCertificateException('invalid_domain');
            }

            $existingPending = Capsule::table(self::TABLE_CERTIFICATES)
                ->where('userid', $userId)
                ->where('subdomain_id', $subdomainId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                ->lockForUpdate()
                ->first();
            if ($existingPending) {
                throw new CfSslCertificateException('request_exists');
            }

            $requestId = (int) Capsule::table(self::TABLE_CERTIFICATES)->insertGetId([
                'userid' => $userId,
                'subdomain_id' => $subdomainId,
                'domain' => $domain,
                'rootdomain' => $rootdomain,
                'status' => self::STATUS_PENDING,
                'last_error' => null,
                'acme_output' => null,
                'requested_at' => $now,
                'issued_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'request_id' => $requestId,
                'subdomain_id' => $subdomainId,
                'domain' => $domain,
            ];
        });

        self::updateRequestStatus((int) $requestData['request_id'], self::STATUS_PROCESSING, null, null);

        try {
            $issued = self::runLetsencryptIssue(
                (int) $requestData['request_id'],
                (int) $requestData['subdomain_id'],
                (string) $requestData['domain'],
                $moduleSettings
            );

            Capsule::table(self::TABLE_CERTIFICATES)
                ->where('id', (int) $requestData['request_id'])
                ->update([
                    'status' => self::STATUS_ISSUED,
                    'cert_path' => $issued['cert_path'] ?? null,
                    'key_path' => $issued['key_path'] ?? null,
                    'fullchain_path' => $issued['fullchain_path'] ?? null,
                    'issuer' => $issued['issuer'] ?? null,
                    'serial_number' => $issued['serial_number'] ?? null,
                    'fingerprint_sha256' => $issued['fingerprint_sha256'] ?? null,
                    'not_before' => $issued['not_before'] ?? null,
                    'expires_at' => $issued['expires_at'] ?? null,
                    'issued_at' => date('Y-m-d H:i:s'),
                    'last_error' => null,
                    'acme_output' => $issued['acme_output'] ?? null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_ssl_issue_success', [
                    'request_id' => (int) $requestData['request_id'],
                    'domain' => (string) $requestData['domain'],
                    'expires_at' => $issued['expires_at'] ?? null,
                ], $userId, $subdomainId);
            }

            return [
                'request_id' => (int) $requestData['request_id'],
                'domain' => (string) $requestData['domain'],
                'expires_at' => $issued['expires_at'] ?? null,
            ];
        } catch (CfSslCertificateException $e) {
            self::updateRequestStatus((int) $requestData['request_id'], self::STATUS_FAILED, $e->getMessage(), null);
            throw $e;
        } catch (\Throwable $e) {
            self::updateRequestStatus((int) $requestData['request_id'], self::STATUS_FAILED, $e->getMessage(), null);
            throw new CfSslCertificateException('issue_failed', $e->getMessage(), $e);
        }
    }

    private static function runLetsencryptIssue(int $requestId, int $subdomainId, string $domain, array $moduleSettings): array
    {
        self::ensureAcmeAutoloaders();

        $clientMode = strtolower(trim((string) ($moduleSettings['ssl_acme_client'] ?? 'auto')));
        if ($clientMode === '') {
            $clientMode = 'auto';
        }

        if ($clientMode === 'acmephp' || $clientMode === 'auto') {
            if (self::hasAcmePhpCoreDependencies()) {
                return self::issueWithAcmePhpCore($requestId, $subdomainId, $domain, $moduleSettings);
            }
            if ($clientMode === 'acmephp') {
                throw new CfSslCertificateException('acme_library_missing', '未检测到 acme-php/core 依赖。');
            }
        }

        if ($clientMode === 'yourivw' || $clientMode === 'auto') {
            if (self::hasYourivwDependencies()) {
                return self::issueWithYourivwClient($requestId, $subdomainId, $domain, $moduleSettings);
            }
            if ($clientMode === 'yourivw') {
                throw new CfSslCertificateException('acme_library_missing', '未检测到 yourivw/leclient 依赖。');
            }
        }

        throw new CfSslCertificateException('acme_library_missing', '未检测到可用 ACME PHP 库，请安装 acme-php/core 或 yourivw/leclient。');
    }

    private static function issueWithAcmePhpCore(int $requestId, int $subdomainId, string $domain, array $moduleSettings): array
    {
        $email = self::resolveLetsencryptEmail($moduleSettings);
        if ($email === '') {
            throw new CfSslCertificateException('email_missing', '请先在模块设置中配置 Let\'s Encrypt 邮箱地址。');
        }

        $directoryUrl = trim((string) ($moduleSettings['letsencrypt_directory_url'] ?? ''));
        if ($directoryUrl === '') {
            $directoryUrl = self::LETSENCRYPT_PRODUCTION;
        }
        if ($directoryUrl === 'staging') {
            $directoryUrl = self::LETSENCRYPT_STAGING;
        }

        $dnsWaitSeconds = max(5, min(120, (int) ($moduleSettings['letsencrypt_dns_wait_seconds'] ?? 25)));

        $storageBase = self::resolveStorageBase($moduleSettings);
        $accountDir = rtrim($storageBase, '/\\') . '/acme-account';
        $workspaceDir = rtrim($storageBase, '/\\') . '/requests/request_' . $requestId . '_' . time();
        self::ensureDirectory($accountDir);
        self::ensureDirectory($workspaceDir);

        $accountKeyPair = self::loadOrCreateAcmeAccountKeyPair($accountDir);

        try {
            $factory = new \AcmePhp\Core\Http\SecureHttpClientFactory(
                new \GuzzleHttp\Client(),
                new \AcmePhp\Core\Http\Base64SafeEncoder(),
                new \AcmePhp\Ssl\Parser\KeyParser(),
                new \AcmePhp\Ssl\Signer\DataSigner(),
                new \AcmePhp\Core\Http\ServerErrorHandler()
            );
            $secureHttpClient = $factory->createSecureHttpClient($accountKeyPair);
            $acmeClient = new \AcmePhp\Core\AcmeClient($secureHttpClient, $directoryUrl);
            try {
                $acmeClient->registerAccount($email);
            } catch (\Throwable $e) {
            }

            $order = $acmeClient->requestOrder([$domain]);
            $challenges = $order->getAuthorizationChallenges($domain);
            $dnsChallenge = null;
            foreach ($challenges as $challenge) {
                if (method_exists($challenge, 'getType') && strtolower((string) $challenge->getType()) === 'dns-01') {
                    $dnsChallenge = $challenge;
                    break;
                }
            }
            if (!$dnsChallenge) {
                throw new CfSslCertificateException('dns_challenge_missing', 'ACME 订单未返回 dns-01 挑战。');
            }

            $keyAuthorization = (string) $dnsChallenge->getPayload();
            $dnsValue = self::buildDnsChallengeValue($keyAuthorization);
            $challengeContext = self::addDnsChallengeRecord($subdomainId, $domain, $dnsValue);

            try {
                sleep($dnsWaitSeconds);
                $acmeClient->challengeAuthorization($dnsChallenge, 240);
            } finally {
                self::removeDnsChallengeRecord($challengeContext);
            }

            $domainKeyPair = (new \AcmePhp\Ssl\Generator\KeyPairGenerator())->generateKeyPair();
            $csr = new \AcmePhp\Ssl\CertificateRequest(
                new \AcmePhp\Ssl\DistinguishedName($domain),
                $domainKeyPair
            );

            $certificateResponse = $acmeClient->finalizeOrder($order, $csr, 300);
            $leafCertificate = $certificateResponse->getCertificate();
            $certPem = trim((string) $leafCertificate->getPEM()) . "\n";

            $fullChainPem = $certPem;
            $issuer = $leafCertificate->getIssuerCertificate();
            while ($issuer !== null) {
                $fullChainPem .= trim((string) $issuer->getPEM()) . "\n";
                $issuer = $issuer->getIssuerCertificate();
            }

            $privatePem = trim((string) $domainKeyPair->getPrivateKey()->getPEM()) . "\n";
            $paths = self::saveIssuedCertificateFiles($domain, $storageBase, $certPem, $privatePem, $fullChainPem);
            $meta = self::parseCertificateMeta($paths['fullchain_path']);

            return array_merge($meta, [
                'cert_path' => $paths['cert_path'],
                'key_path' => $paths['key_path'],
                'fullchain_path' => $paths['fullchain_path'],
                'acme_output' => 'Issued via acme-php/core',
            ]);
        } catch (CfSslCertificateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CfSslCertificateException('acme_issue_failed', $e->getMessage(), $e);
        }
    }

    private static function issueWithYourivwClient(int $requestId, int $subdomainId, string $domain, array $moduleSettings): array
    {
        $email = self::resolveLetsencryptEmail($moduleSettings);
        if ($email === '') {
            throw new CfSslCertificateException('email_missing', '请先在模块设置中配置 Let\'s Encrypt 邮箱地址。');
        }

        $storageBase = self::resolveStorageBase($moduleSettings);
        $workspaceDir = rtrim($storageBase, '/\\') . '/yourivw/request_' . $requestId . '_' . time();
        self::ensureDirectory($workspaceDir);

        $dnsWaitSeconds = max(5, min(120, (int) ($moduleSettings['letsencrypt_dns_wait_seconds'] ?? 25)));
        $environment = strtolower(trim((string) ($moduleSettings['letsencrypt_directory_url'] ?? '')));
        $mode = ($environment === 'staging') ? \LEClient\LEClient::LE_STAGING : \LEClient\LEClient::LE_PRODUCTION;

        $accountKeyPath = $workspaceDir . '/account.key';
        $certificatePath = $workspaceDir . '/cert.pem';
        $privatePath = $workspaceDir . '/privkey.pem';
        $fullchainPath = $workspaceDir . '/fullchain.pem';

        $certMap = [
            'private' => $privatePath,
            'fullchain' => $fullchainPath,
            'certificate' => $certificatePath,
        ];
        $accountMap = ['private' => $accountKeyPath];

        try {
            $client = new \LEClient\LEClient([$email], $mode, \LEClient\LEClient::LOG_OFF, $certMap, $accountMap);
            $order = $client->getOrCreateOrder($domain, [$domain], 'rsa-2048');
            $pendingAuthorizations = $order->getPendingAuthorizations(\LEClient\LEOrder::CHALLENGE_TYPE_DNS);

            $contexts = [];
            foreach ($pendingAuthorizations as $authorization) {
                if (empty($authorization['identifier']) || empty($authorization['DNSDigest'])) {
                    continue;
                }
                $contexts[] = self::addDnsChallengeRecord($subdomainId, (string) $authorization['identifier'], (string) $authorization['DNSDigest']);
            }

            try {
                sleep($dnsWaitSeconds);
                foreach ($pendingAuthorizations as $authorization) {
                    if (empty($authorization['identifier'])) {
                        continue;
                    }
                    $order->verifyPendingOrderAuthorization((string) $authorization['identifier'], \LEClient\LEOrder::CHALLENGE_TYPE_DNS);
                }
                $order->finalizeOrder();
                $order->getCertificate();
            } finally {
                foreach ($contexts as $context) {
                    self::removeDnsChallengeRecord($context);
                }
            }

            if (!is_file($certificatePath) || !is_file($privatePath)) {
                throw new CfSslCertificateException('acme_issue_failed', 'ACME 证书文件未生成。');
            }

            if (!is_file($fullchainPath)) {
                $fullchainContent = trim((string) @file_get_contents($certificatePath)) . "\n";
                @file_put_contents($fullchainPath, $fullchainContent);
            }

            $certPem = (string) @file_get_contents($certificatePath);
            $privatePem = (string) @file_get_contents($privatePath);
            $fullchainPem = (string) @file_get_contents($fullchainPath);

            $paths = self::saveIssuedCertificateFiles($domain, $storageBase, $certPem, $privatePem, $fullchainPem);
            $meta = self::parseCertificateMeta($paths['fullchain_path']);

            return array_merge($meta, [
                'cert_path' => $paths['cert_path'],
                'key_path' => $paths['key_path'],
                'fullchain_path' => $paths['fullchain_path'],
                'acme_output' => 'Issued via yourivw/leclient',
            ]);
        } catch (CfSslCertificateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CfSslCertificateException('acme_issue_failed', $e->getMessage(), $e);
        }
    }

    private static function hasAcmePhpCoreDependencies(): bool
    {
        return class_exists('\AcmePhp\Core\AcmeClient')
            && class_exists('\AcmePhp\Core\Http\SecureHttpClientFactory')
            && class_exists('\AcmePhp\Ssl\Generator\KeyPairGenerator')
            && class_exists('\GuzzleHttp\Client');
    }

    private static function hasYourivwDependencies(): bool
    {
        return class_exists('\LEClient\LEClient') && class_exists('\LEClient\LEOrder');
    }

    private static function ensureAcmeAutoloaders(): void
    {
        if (self::hasAcmePhpCoreDependencies() || self::hasYourivwDependencies()) {
            return;
        }

        $candidates = [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__, 5) . '/vendor/autoload.php',
            dirname(__DIR__, 4) . '/vendor/autoload.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    private static function loadOrCreateAcmeAccountKeyPair(string $accountDir)
    {
        $privatePath = rtrim($accountDir, '/\\') . '/account_private.pem';
        $publicPath = rtrim($accountDir, '/\\') . '/account_public.pem';

        if (is_file($privatePath) && is_file($publicPath)) {
            $privatePem = @file_get_contents($privatePath);
            $publicPem = @file_get_contents($publicPath);
            if (is_string($privatePem) && trim($privatePem) !== '' && is_string($publicPem) && trim($publicPem) !== '') {
                return new \AcmePhp\Ssl\KeyPair(
                    new \AcmePhp\Ssl\PublicKey($publicPem),
                    new \AcmePhp\Ssl\PrivateKey($privatePem)
                );
            }
        }

        $keyPair = (new \AcmePhp\Ssl\Generator\KeyPairGenerator())->generateKeyPair();
        @file_put_contents($privatePath, $keyPair->getPrivateKey()->getPEM());
        @file_put_contents($publicPath, $keyPair->getPublicKey()->getPEM());

        return $keyPair;
    }

    private static function resolveStorageBase(array $moduleSettings): string
    {
        $storageBase = trim((string) ($moduleSettings['letsencrypt_storage_path'] ?? ''));
        if ($storageBase === '') {
            $storageBase = dirname(__DIR__, 2) . '/storage/ssl';
        }
        self::ensureDirectory($storageBase);
        return $storageBase;
    }

    private static function addDnsChallengeRecord(int $subdomainId, string $domain, string $value): array
    {
        $subdomain = Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomainId)
            ->first();

        if (!$subdomain) {
            throw new CfSslCertificateException('subdomain_not_found', '未找到目标域名记录。');
        }

        $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
        list($cf, $providerError,) = cfmod_client_acquire_provider_for_subdomain($subdomain, is_array($settings) ? $settings : []);
        if (!$cf) {
            throw new CfSslCertificateException('provider_missing', $providerError ?: 'DNS 供应商不可用。');
        }

        $zoneId = (string) ($subdomain->cloudflare_zone_id ?? '');
        if ($zoneId === '') {
            throw new CfSslCertificateException('zone_missing', '域名 Zone 信息缺失。');
        }

        $challengeName = '_acme-challenge.' . trim($domain, '.');
        $response = $cf->createDnsRecordRaw($zoneId, [
            'type' => 'TXT',
            'name' => $challengeName,
            'content' => $value,
            'ttl' => 60,
            'line' => 'default',
        ]);

        if (!is_array($response) || empty($response['success'])) {
            $error = '';
            if (is_array($response['errors'] ?? null)) {
                $error = implode('; ', array_map('strval', $response['errors']));
            } else {
                $error = (string) ($response['errors'] ?? 'create challenge failed');
            }
            throw new CfSslCertificateException('dns_challenge_create_failed', $error);
        }

        $recordId = '';
        if (!empty($response['result']['id'])) {
            $recordId = (string) $response['result']['id'];
        } elseif (!empty($response['RecordId'])) {
            $recordId = (string) $response['RecordId'];
        }

        return [
            'subdomain_id' => $subdomainId,
            'zone_id' => $zoneId,
            'challenge_name' => $challengeName,
            'challenge_value' => $value,
            'record_id' => $recordId,
        ];
    }

    private static function removeDnsChallengeRecord(array $context): void
    {
        $subdomainId = (int) ($context['subdomain_id'] ?? 0);
        if ($subdomainId <= 0) {
            return;
        }

        $subdomain = Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomainId)
            ->first();
        if (!$subdomain) {
            return;
        }

        $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
        list($cf,,) = cfmod_client_acquire_provider_for_subdomain($subdomain, is_array($settings) ? $settings : []);
        if (!$cf) {
            return;
        }

        $zoneId = (string) ($context['zone_id'] ?? ($subdomain->cloudflare_zone_id ?? ''));
        $recordId = trim((string) ($context['record_id'] ?? ''));
        if ($zoneId === '' || $recordId === '') {
            return;
        }

        try {
            $cf->deleteSubdomain($zoneId, $recordId, [
                'type' => 'TXT',
                'name' => (string) ($context['challenge_name'] ?? ''),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function buildDnsChallengeValue(string $keyAuthorization): string
    {
        $hash = hash('sha256', $keyAuthorization, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    private static function saveIssuedCertificateFiles(string $domain, string $storageBase, string $certPem, string $privatePem, string $fullChainPem): array
    {
        if (trim($certPem) === '' || trim($privatePem) === '' || trim($fullChainPem) === '') {
            throw new CfSslCertificateException('cert_missing', '签发成功但证书文件不完整。');
        }

        $domainSafe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $domain);
        $targetDir = rtrim($storageBase, '/\\') . '/' . $domainSafe . '/' . date('Ymd_His');
        self::ensureDirectory($targetDir);

        $certPath = $targetDir . '/cert.pem';
        $keyPath = $targetDir . '/privkey.pem';
        $fullchainPath = $targetDir . '/fullchain.pem';

        if (@file_put_contents($certPath, trim($certPem) . "\n") === false
            || @file_put_contents($keyPath, trim($privatePem) . "\n") === false
            || @file_put_contents($fullchainPath, trim($fullChainPem) . "\n") === false) {
            throw new CfSslCertificateException('cert_copy_failed', '证书文件写入失败，请检查目录权限。');
        }

        return [
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'fullchain_path' => $fullchainPath,
        ];
    }

    private static function resolveLetsencryptEmail(array $moduleSettings): string
    {
        $email = trim((string) ($moduleSettings['letsencrypt_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        try {
            $candidate = trim((string) Capsule::table('tblconfiguration')->where('setting', 'SystemEmailsFromEmail')->value('value'));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        } catch (\Throwable $e) {
        }

        try {
            $candidate = trim((string) Capsule::table('tblconfiguration')->where('setting', 'Email')->value('value'));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    private static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new CfSslCertificateException('mkdir_failed', '无法创建目录：' . $path);
        }
    }

    private static function parseCertificateMeta(string $certificatePath): array
    {
        $content = @file_get_contents($certificatePath);
        if ($content === false || trim($content) === '') {
            throw new CfSslCertificateException('cert_parse_failed', '无法读取证书内容。');
        }

        $certResource = @openssl_x509_read($content);
        if ($certResource === false) {
            throw new CfSslCertificateException('cert_parse_failed', '无法解析证书内容。');
        }

        $parsed = openssl_x509_parse($certResource, false);
        if ($parsed === false || !is_array($parsed)) {
            throw new CfSslCertificateException('cert_parse_failed', '无法解析证书元数据。');
        }

        $issuer = '';
        if (!empty($parsed['issuer']) && is_array($parsed['issuer'])) {
            if (!empty($parsed['issuer']['CN'])) {
                $issuer = (string) $parsed['issuer']['CN'];
            } else {
                $issuer = trim(implode(', ', array_map('strval', $parsed['issuer'])));
            }
        }

        $serialNumber = (string) ($parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? ''));
        $fingerprint = '';
        if (function_exists('openssl_x509_fingerprint')) {
            $fp = @openssl_x509_fingerprint($content, 'sha256');
            if (is_string($fp)) {
                $fingerprint = strtoupper(str_replace(':', '', $fp));
            }
        }

        $notBefore = !empty($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validFrom_time_t']) : null;
        $expiresAt = !empty($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t']) : null;

        return [
            'issuer' => $issuer,
            'serial_number' => $serialNumber,
            'fingerprint_sha256' => $fingerprint,
            'not_before' => $notBefore,
            'expires_at' => $expiresAt,
        ];
    }

    private static function refreshExpiredStatuses(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        try {
            Capsule::table(self::TABLE_CERTIFICATES)
                ->where('userid', $userId)
                ->where('status', self::STATUS_ISSUED)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->update([
                    'status' => self::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);
        } catch (\Throwable $e) {
        }
    }

    private static function updateRequestStatus(int $requestId, string $status, ?string $errorMessage, ?string $acmeOutput): void
    {
        $payload = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($errorMessage !== null) {
            $payload['last_error'] = self::truncateText($errorMessage, 65535);
        }
        if ($acmeOutput !== null) {
            $payload['acme_output'] = self::truncateText($acmeOutput, 65535);
        }

        Capsule::table(self::TABLE_CERTIFICATES)
            ->where('id', $requestId)
            ->update($payload);
    }

    private static function truncateText(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return substr($text, 0, $maxBytes);
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
}
