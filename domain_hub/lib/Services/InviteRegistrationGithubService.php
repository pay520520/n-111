<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfInviteRegistrationGithubException extends \RuntimeException
{
    private string $reason;
    private array $context;

    public function __construct(string $reason, string $message = '', array $context = [], ?\Throwable $previous = null)
    {
        $this->reason = $reason;
        $this->context = $context;
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class CfInviteRegistrationGithubService
{
    private const TABLE_BINDINGS = 'mod_cloudflare_invite_registration_github_bindings';
    private const STATE_SESSION_KEY = 'cfmod_invite_reg_github_oauth_state';
    private const OAUTH_STATE_TTL_SECONDS = 600;
    private const GITHUB_AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const GITHUB_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const GITHUB_USER_API_URL = 'https://api.github.com/user';
    private const SECRET_PREFIX = 'enc::';

    public static function ensureTable(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_BINDINGS)) {
                $schema->create(self::TABLE_BINDINGS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->bigInteger('github_id')->unsigned()->unique();
                    $table->string('github_login', 191)->nullable();
                    $table->string('github_name', 191)->nullable();
                    $table->dateTime('github_created_at')->nullable();
                    $table->string('avatar_url', 255)->nullable();
                    $table->timestamps();
                    $table->index('userid');
                    $table->index('github_id');
                });
                return;
            }

            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'userid')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) {
                    $table->integer('userid')->unsigned()->unique()->after('id');
                });
            }
            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'github_id')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) {
                    $table->bigInteger('github_id')->unsigned()->unique()->after('userid');
                });
            }
            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'github_login')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) {
                    $table->string('github_login', 191)->nullable()->after('github_id');
                });
            }
            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'github_name')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) {
                    $table->string('github_name', 191)->nullable()->after('github_login');
                });
            }
            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'github_created_at')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) {
                    $table->dateTime('github_created_at')->nullable()->after('github_name');
                });
            }
            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'avatar_url')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) {
                    $table->string('avatar_url', 255)->nullable()->after('github_created_at');
                });
            }
            if (!$schema->hasColumn(self::TABLE_BINDINGS, 'created_at') || !$schema->hasColumn(self::TABLE_BINDINGS, 'updated_at')) {
                $schema->table(self::TABLE_BINDINGS, function ($table) use ($schema) {
                    if (!$schema->hasColumn(self::TABLE_BINDINGS, 'created_at')) {
                        $table->timestamp('created_at')->nullable();
                    }
                    if (!$schema->hasColumn(self::TABLE_BINDINGS, 'updated_at')) {
                        $table->timestamp('updated_at')->nullable();
                    }
                });
            }
        } catch (\Throwable $e) {
        }

        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BINDINGS . '` ADD UNIQUE INDEX `uniq_cf_invite_reg_github_user` (`userid`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BINDINGS . '` ADD UNIQUE INDEX `uniq_cf_invite_reg_github_id` (`github_id`)');
        } catch (\Throwable $e) {
        }
    }

    public static function getClientId(array $moduleSettings): string
    {
        return trim((string) ($moduleSettings['invite_registration_github_client_id'] ?? ''));
    }

    public static function getClientSecret(array $moduleSettings): string
    {
        $stored = trim((string) ($moduleSettings['invite_registration_github_client_secret'] ?? ''));
        if ($stored === '') {
            return '';
        }

        if (strpos($stored, self::SECRET_PREFIX) === 0) {
            $encrypted = substr($stored, strlen(self::SECRET_PREFIX));
            return trim((string) cfmod_decrypt_sensitive($encrypted));
        }

        return $stored;
    }

    public static function getMinAccountAgeMonths(array $moduleSettings): int
    {
        $months = (int) ($moduleSettings['invite_registration_github_min_months'] ?? 0);
        if ($months < 0) {
            $months = 0;
        }
        if ($months > 240) {
            $months = 240;
        }
        return $months;
    }

    public static function getMinPublicRepoCount(array $moduleSettings): int
    {
        $repos = (int) ($moduleSettings['invite_registration_github_min_repos'] ?? 0);
        if ($repos < 0) {
            $repos = 0;
        }
        if ($repos > 1000000) {
            $repos = 1000000;
        }
        return $repos;
    }

    public static function isOauthConfigured(array $moduleSettings): bool
    {
        return self::getClientId($moduleSettings) !== '' && self::getClientSecret($moduleSettings) !== '';
    }

    public static function createAuthorizationUrl(int $userId, array $moduleSettings, string $callbackUrl): string
    {
        if ($userId <= 0) {
            throw new CfInviteRegistrationGithubException('invalid_user');
        }
        if (!self::isOauthConfigured($moduleSettings)) {
            throw new CfInviteRegistrationGithubException('oauth_not_configured');
        }

        $state = bin2hex(random_bytes(24));
        $_SESSION[self::STATE_SESSION_KEY] = [
            'state' => $state,
            'userid' => $userId,
            'expires_at' => time() + self::OAUTH_STATE_TTL_SECONDS,
        ];

        $query = http_build_query([
            'client_id' => self::getClientId($moduleSettings),
            'redirect_uri' => $callbackUrl,
            'scope' => 'read:user',
            'state' => $state,
            'allow_signup' => 'true',
        ]);

        return self::GITHUB_AUTH_URL . '?' . $query;
    }

    public static function handleCallback(int $userId, array $moduleSettings, array $query, string $callbackUrl): array
    {
        if ($userId <= 0) {
            throw new CfInviteRegistrationGithubException('invalid_user');
        }
        if (!self::isOauthConfigured($moduleSettings)) {
            throw new CfInviteRegistrationGithubException('oauth_not_configured');
        }

        $state = trim((string) ($query['state'] ?? ''));
        self::validateAndConsumeState($userId, $state);

        $oauthError = trim((string) ($query['error'] ?? ''));
        if ($oauthError !== '') {
            if (strtolower($oauthError) === 'access_denied') {
                throw new CfInviteRegistrationGithubException('user_cancelled');
            }
            throw new CfInviteRegistrationGithubException('oauth_error', $oauthError);
        }

        $code = trim((string) ($query['code'] ?? ''));
        if ($code === '') {
            throw new CfInviteRegistrationGithubException('missing_code');
        }

        $token = self::exchangeAccessToken($code, $moduleSettings, $callbackUrl);
        $profile = self::fetchGithubProfile($token);

        $githubId = (int) ($profile['id'] ?? 0);
        if ($githubId <= 0) {
            throw new CfInviteRegistrationGithubException('invalid_github_id');
        }

        $createdAtRaw = trim((string) ($profile['created_at'] ?? ''));
        if ($createdAtRaw === '') {
            throw new CfInviteRegistrationGithubException('missing_account_created_at');
        }

        $accountAgeMonths = self::calculateAccountAgeMonths($createdAtRaw);
        $requiredMonths = self::getMinAccountAgeMonths($moduleSettings);
        if ($accountAgeMonths < $requiredMonths) {
            throw new CfInviteRegistrationGithubException('account_age_insufficient', '', [
                'required_months' => $requiredMonths,
                'actual_months' => $accountAgeMonths,
            ]);
        }

        $publicRepos = max(0, (int) ($profile['public_repos'] ?? 0));
        $requiredRepos = self::getMinPublicRepoCount($moduleSettings);
        if ($requiredRepos > 0 && $publicRepos < $requiredRepos) {
            throw new CfInviteRegistrationGithubException('repo_count_insufficient', '', [
                'required_repos' => $requiredRepos,
                'actual_repos' => $publicRepos,
            ]);
        }

        $createdAtSql = self::normalizeGithubCreatedAt($createdAtRaw);
        self::ensureTable();

        $login = trim((string) ($profile['login'] ?? ''));
        $name = trim((string) ($profile['name'] ?? ''));
        $avatarUrl = trim((string) ($profile['avatar_url'] ?? ''));
        $now = date('Y-m-d H:i:s');

        Capsule::connection()->transaction(function () use ($userId, $githubId, $login, $name, $createdAtSql, $avatarUrl, $now) {
            $existingGithub = Capsule::table(self::TABLE_BINDINGS)
                ->where('github_id', $githubId)
                ->lockForUpdate()
                ->first();
            if ($existingGithub && (int) ($existingGithub->userid ?? 0) !== $userId) {
                throw new CfInviteRegistrationGithubException('github_already_bound');
            }

            $existingUser = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $payload = [
                'github_id' => $githubId,
                'github_login' => $login !== '' ? $login : null,
                'github_name' => $name !== '' ? $name : null,
                'github_created_at' => $createdAtSql,
                'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                'updated_at' => $now,
            ];

            if ($existingUser) {
                Capsule::table(self::TABLE_BINDINGS)
                    ->where('id', (int) $existingUser->id)
                    ->update($payload);
            } else {
                $payload['userid'] = $userId;
                $payload['created_at'] = $now;
                Capsule::table(self::TABLE_BINDINGS)->insert($payload);
            }

            CfInviteRegistrationService::unlockByGithub($userId, $githubId, $login, $createdAtSql);
        });

        return [
            'github_id' => $githubId,
            'github_login' => $login,
            'github_name' => $name,
            'github_created_at' => $createdAtSql,
            'account_age_months' => $accountAgeMonths,
            'public_repos' => $publicRepos,
        ];
    }

    public static function getBindingForUser(int $userId): array
    {
        $empty = [
            'bound' => false,
            'github_id' => 0,
            'github_login' => '',
            'github_name' => '',
            'github_created_at' => null,
        ];

        if ($userId <= 0) {
            return $empty;
        }

        self::ensureTable();

        try {
            $row = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->first();
            if (!$row) {
                return $empty;
            }

            return [
                'bound' => true,
                'github_id' => (int) ($row->github_id ?? 0),
                'github_login' => (string) ($row->github_login ?? ''),
                'github_name' => (string) ($row->github_name ?? ''),
                'github_created_at' => $row->github_created_at ?? null,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    private static function validateAndConsumeState(int $userId, string $state): void
    {
        $state = trim($state);
        $stored = $_SESSION[self::STATE_SESSION_KEY] ?? null;
        unset($_SESSION[self::STATE_SESSION_KEY]);

        if (!is_array($stored)) {
            throw new CfInviteRegistrationGithubException('invalid_state');
        }

        $storedState = trim((string) ($stored['state'] ?? ''));
        $storedUser = (int) ($stored['userid'] ?? 0);
        $expiresAt = (int) ($stored['expires_at'] ?? 0);

        if ($state === '' || $storedState === '' || !hash_equals($storedState, $state)) {
            throw new CfInviteRegistrationGithubException('invalid_state');
        }
        if ($storedUser <= 0 || $storedUser !== $userId) {
            throw new CfInviteRegistrationGithubException('invalid_state');
        }
        if ($expiresAt <= 0 || time() > $expiresAt) {
            throw new CfInviteRegistrationGithubException('state_expired');
        }
    }

    private static function exchangeAccessToken(string $code, array $moduleSettings, string $callbackUrl): string
    {
        $response = self::httpRequest(
            self::GITHUB_TOKEN_URL,
            'POST',
            [
                'Accept: application/json',
            ],
            [
                'client_id' => self::getClientId($moduleSettings),
                'client_secret' => self::getClientSecret($moduleSettings),
                'code' => $code,
                'redirect_uri' => $callbackUrl,
            ]
        );

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            throw new CfInviteRegistrationGithubException('oauth_exchange_failed', 'http_' . $status);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new CfInviteRegistrationGithubException('oauth_exchange_failed', 'invalid_json');
        }

        if (!empty($payload['error'])) {
            throw new CfInviteRegistrationGithubException('oauth_exchange_failed', (string) $payload['error']);
        }

        $token = trim((string) ($payload['access_token'] ?? ''));
        if ($token === '') {
            throw new CfInviteRegistrationGithubException('oauth_exchange_failed', 'missing_access_token');
        }

        return $token;
    }

    private static function fetchGithubProfile(string $accessToken): array
    {
        $response = self::httpRequest(
            self::GITHUB_USER_API_URL,
            'GET',
            [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $accessToken,
                'X-GitHub-Api-Version: 2022-11-28',
            ]
        );

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            throw new CfInviteRegistrationGithubException('oauth_user_fetch_failed', 'http_' . $status);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new CfInviteRegistrationGithubException('oauth_user_fetch_failed', 'invalid_json');
        }

        return $payload;
    }

    private static function calculateAccountAgeMonths(string $createdAtRaw): int
    {
        try {
            $createdAt = new \DateTimeImmutable($createdAtRaw);
            $now = new \DateTimeImmutable('now', $createdAt->getTimezone());
            if ($createdAt > $now) {
                return 0;
            }
            $diff = $createdAt->diff($now);
            return max(0, ((int) $diff->y * 12) + (int) $diff->m);
        } catch (\Throwable $e) {
            throw new CfInviteRegistrationGithubException('invalid_account_created_at');
        }
    }

    private static function normalizeGithubCreatedAt(string $createdAtRaw): string
    {
        try {
            return (new \DateTimeImmutable($createdAtRaw))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new CfInviteRegistrationGithubException('invalid_account_created_at');
        }
    }

    private static function httpRequest(string $url, string $method = 'GET', array $headers = [], ?array $postFields = null): array
    {
        if (!function_exists('curl_init')) {
            throw new CfInviteRegistrationGithubException('http_unavailable', 'curl_missing');
        }

        $curl = curl_init();
        if ($curl === false) {
            throw new CfInviteRegistrationGithubException('http_unavailable', 'curl_init_failed');
        }

        $normalizedMethod = strtoupper($method);
        $requestHeaders = array_values(array_filter($headers));
        $requestHeaders[] = 'User-Agent: WHMCS-DomainHub/InviteRegistration';

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ];

        if ($normalizedMethod === 'POST') {
            $options[CURLOPT_POST] = true;
            if (is_array($postFields)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($postFields);
            }
        }

        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new CfInviteRegistrationGithubException('http_request_failed', $error !== '' ? $error : 'curl_exec_failed');
        }

        return [
            'status' => $status,
            'body' => (string) $body,
        ];
    }
}
