<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfGithubStarRewardException extends \RuntimeException
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

class CfGithubStarRewardService
{
    private const HISTORY_PER_PAGE = 10;
    private const TABLE_NAME = 'mod_cloudflare_github_star_rewards';
    private const OAUTH_BINDING_TABLE = 'mod_cloudflare_github_star_oauth_bindings';
    private const OAUTH_STATE_SESSION_KEY = 'cfmod_github_star_oauth_state';
    private const OAUTH_STATE_TTL_SECONDS = 600;
    private const GITHUB_API_BASE_URL = 'https://api.github.com';
    private const GITHUB_AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const GITHUB_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const GITHUB_USER_API_URL = 'https://api.github.com/user';
    private const GITHUB_STAR_SCAN_PAGE_SIZE = 100;
    private const GITHUB_STAR_SCAN_MAX_PAGES = 20;

    public static function ensureTable(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_NAME)) {
                $schema->create(self::TABLE_NAME, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->string('repo_url', 255)->default('');
                    $table->string('repo_hash', 64)->default('');
                    $table->string('github_username', 39)->default('');
                    $table->integer('reward_amount')->unsigned()->default(0);
                    $table->integer('before_quota')->unsigned()->default(0);
                    $table->integer('after_quota')->unsigned()->default(0);
                    $table->string('status', 20)->default('granted');
                    $table->string('client_ip', 45)->nullable();
                    $table->string('user_agent', 255)->nullable();
                    $table->timestamps();
                    $table->index(['userid', 'id'], 'idx_cf_github_reward_user_id');
                    $table->index(['repo_hash'], 'idx_cf_github_reward_repo_hash');
                    $table->index(['repo_hash', 'github_username'], 'idx_cf_github_reward_repo_user');
                    $table->unique(['userid', 'repo_hash', 'status'], 'uniq_cf_github_reward_user_repo_status');
                });
                return;
            }

            if (!$schema->hasColumn(self::TABLE_NAME, 'repo_url')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('repo_url', 255)->default('');
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'repo_hash')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('repo_hash', 64)->default('');
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'github_username')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('github_username', 39)->default('');
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'reward_amount')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->integer('reward_amount')->unsigned()->default(0);
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'before_quota')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->integer('before_quota')->unsigned()->default(0);
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'after_quota')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->integer('after_quota')->unsigned()->default(0);
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'status')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('status', 20)->default('granted');
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'client_ip')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('client_ip', 45)->nullable();
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'user_agent')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('user_agent', 255)->nullable();
                });
            }
            if (!$schema->hasColumn(self::TABLE_NAME, 'created_at') || !$schema->hasColumn(self::TABLE_NAME, 'updated_at')) {
                $schema->table(self::TABLE_NAME, function ($table) use ($schema) {
                    if (!$schema->hasColumn(self::TABLE_NAME, 'created_at')) {
                        $table->timestamp('created_at')->nullable();
                    }
                    if (!$schema->hasColumn(self::TABLE_NAME, 'updated_at')) {
                        $table->timestamp('updated_at')->nullable();
                    }
                });
            }

            self::backfillRepoHashes();
            self::ensureIndexes();
        } catch (\Throwable $e) {
        }
    }

    private static function backfillRepoHashes(): void
    {
        try {
            $batch = 200;
            do {
                $rows = Capsule::table(self::TABLE_NAME)
                    ->select('id', 'repo_url', 'repo_hash')
                    ->where(function ($query) {
                        $query->whereNull('repo_hash')->orWhere('repo_hash', '');
                    })
                    ->limit($batch)
                    ->get();
                $count = 0;
                foreach ($rows as $row) {
                    $repoUrl = self::normalizeRepoUrl((string) ($row->repo_url ?? ''));
                    if ($repoUrl === '') {
                        $repoUrl = trim((string) ($row->repo_url ?? ''));
                    }
                    $repoHash = $repoUrl !== '' ? hash('sha256', strtolower($repoUrl)) : hash('sha256', 'repo-' . intval($row->id ?? 0));
                    Capsule::table(self::TABLE_NAME)
                        ->where('id', intval($row->id ?? 0))
                        ->update([
                            'repo_hash' => $repoHash,
                            'repo_url' => $repoUrl,
                        ]);
                    $count++;
                }
            } while ($count > 0);
        } catch (\Throwable $e) {
        }
    }

    private static function ensureIndexes(): void
    {
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_NAME . '` ADD INDEX `idx_cf_github_reward_user_id` (`userid`, `id`)');
        } catch (\Throwable $e) {
        }
        if (self::hasColumn('repo_hash')) {
            try {
                Capsule::statement('ALTER TABLE `' . self::TABLE_NAME . '` ADD INDEX `idx_cf_github_reward_repo_hash` (`repo_hash`)');
            } catch (\Throwable $e) {
            }
            if (self::hasColumn('github_username')) {
                try {
                    Capsule::statement('ALTER TABLE `' . self::TABLE_NAME . '` ADD INDEX `idx_cf_github_reward_repo_user` (`repo_hash`, `github_username`)');
                } catch (\Throwable $e) {
                }
            }
            try {
                Capsule::statement('ALTER TABLE `' . self::TABLE_NAME . '` ADD UNIQUE `uniq_cf_github_reward_user_repo_status` (`userid`, `repo_hash`, `status`)');
            } catch (\Throwable $e) {
            }
        }
    }

    private static function hasColumn(string $column): bool
    {
        try {
            return Capsule::schema()->hasTable(self::TABLE_NAME) && Capsule::schema()->hasColumn(self::TABLE_NAME, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isEnabled(array $moduleSettings): bool
    {
        return in_array(($moduleSettings['enable_github_star_reward'] ?? '0'), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function resolveRewardAmount(array $moduleSettings): int
    {
        return max(1, min(1000, (int) ($moduleSettings['github_star_reward_amount'] ?? 1)));
    }

    public static function normalizeRepoUrl(string $repoUrl): string
    {
        $repoUrl = trim($repoUrl);
        if ($repoUrl === '') {
            return '';
        }

        if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $repoUrl)) {
            $repoUrl = 'https://github.com/' . $repoUrl;
        } elseif (!preg_match('#^https?://#i', $repoUrl)) {
            $repoUrl = 'https://' . ltrim($repoUrl, '/');
        }

        $parts = @parse_url($repoUrl);
        if (!$parts) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($host, ['github.com', 'www.github.com'], true)) {
            return '';
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static function ($item) {
            return $item !== '';
        }));
        if (count($segments) < 2) {
            return '';
        }

        $owner = $segments[0];
        $repo = preg_replace('/\.git$/i', '', $segments[1]);
        if ($owner === '' || $repo === '') {
            return '';
        }

        return 'https://github.com/' . $owner . '/' . $repo;
    }

    public static function normalizeGithubUsername(string $githubUsername): string
    {
        $githubUsername = trim($githubUsername);
        if ($githubUsername === '') {
            return '';
        }

        if (strpos($githubUsername, '@') === 0) {
            $githubUsername = ltrim($githubUsername, '@');
        }

        if (!preg_match('/^(?!-)(?!.*--)[A-Za-z0-9-]{1,39}(?<!-)$/', $githubUsername)) {
            return '';
        }

        return strtolower($githubUsername);
    }

    private static function extractRepoCoordinates(string $repoUrl): array
    {
        $repoUrl = self::normalizeRepoUrl($repoUrl);
        if ($repoUrl === '') {
            return ['owner' => '', 'repo' => '', 'full_name' => ''];
        }

        $parts = @parse_url($repoUrl);
        if (!$parts) {
            return ['owner' => '', 'repo' => '', 'full_name' => ''];
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = array_values(array_filter(explode('/', $path), static function ($segment) {
            return $segment !== '';
        }));

        if (count($segments) < 2) {
            return ['owner' => '', 'repo' => '', 'full_name' => ''];
        }

        $owner = trim((string) ($segments[0] ?? ''));
        $repo = trim((string) ($segments[1] ?? ''));
        if ($owner === '' || $repo === '') {
            return ['owner' => '', 'repo' => '', 'full_name' => ''];
        }

        $repo = preg_replace('/\.git$/i', '', $repo);
        if ($repo === '') {
            return ['owner' => '', 'repo' => '', 'full_name' => ''];
        }

        return [
            'owner' => $owner,
            'repo' => $repo,
            'full_name' => strtolower($owner . '/' . $repo),
        ];
    }

    private static function verifyUserStarredRepo(string $githubUsername, string $repoUrl): void
    {
        $githubUsername = self::normalizeGithubUsername($githubUsername);
        if ($githubUsername === '') {
            throw new CfGithubStarRewardException('invalid_username');
        }

        $repoCoordinates = self::extractRepoCoordinates($repoUrl);
        $targetRepoFullName = $repoCoordinates['full_name'] ?? '';
        if ($targetRepoFullName === '') {
            throw new CfGithubStarRewardException('invalid_repo');
        }

        $page = 1;
        while ($page <= self::GITHUB_STAR_SCAN_MAX_PAGES) {
            $apiUrl = self::GITHUB_API_BASE_URL
                . '/users/' . rawurlencode($githubUsername)
                . '/starred?per_page=' . self::GITHUB_STAR_SCAN_PAGE_SIZE
                . '&page=' . $page;

            $response = self::githubApiRequest($apiUrl);
            $statusCode = (int) ($response['status'] ?? 0);
            $payload = (string) ($response['body'] ?? '');

            if ($statusCode === 404 && $page === 1) {
                throw new CfGithubStarRewardException('invalid_username');
            }
            if (in_array($statusCode, [403, 429], true)) {
                throw new CfGithubStarRewardException('verify_rate_limited');
            }
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new CfGithubStarRewardException('verify_failed', 'http_' . $statusCode);
            }

            $items = json_decode($payload, true);
            if (!is_array($items)) {
                throw new CfGithubStarRewardException('verify_failed', 'invalid_json');
            }

            foreach ($items as $item) {
                $fullName = strtolower(trim((string) ($item['full_name'] ?? '')));
                if ($fullName !== '' && $fullName === $targetRepoFullName) {
                    return;
                }
            }

            if (count($items) < self::GITHUB_STAR_SCAN_PAGE_SIZE) {
                break;
            }

            $page++;
        }

        throw new CfGithubStarRewardException('not_starred');
    }

    private static function githubApiRequest(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new CfGithubStarRewardException('verify_failed', 'curl_unavailable');
        }

        $responseHeaders = [];
        $ch = curl_init();
        if ($ch === false) {
            throw new CfGithubStarRewardException('verify_failed', 'curl_init_failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_USERAGENT => 'WHMCS-DomainHub/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, $headerLine) use (&$responseHeaders) {
                $length = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || strpos($headerLine, ':') === false) {
                    return $length;
                }
                [$name, $value] = explode(':', $headerLine, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                if ($name !== '') {
                    $responseHeaders[$name] = $value;
                }
                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new CfGithubStarRewardException('verify_failed', $error !== '' ? $error : 'curl_exec_failed');
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $responseHeaders,
        ];
    }

    public static function getUserClaimState(int $userId, array $moduleSettings): array
    {
        $repoUrl = self::normalizeRepoUrl((string) ($moduleSettings['github_star_repo_url'] ?? ''));
        $state = [
            'enabled' => self::isEnabled($moduleSettings),
            'repo_url' => $repoUrl,
            'reward_amount' => self::resolveRewardAmount($moduleSettings),
            'claimed' => false,
            'github_username' => '',
        ];

        if ($userId <= 0 || $repoUrl === '') {
            return $state;
        }

        self::ensureTable();

        try {
            $repoHash = hash('sha256', strtolower($repoUrl));
            $query = Capsule::table(self::TABLE_NAME)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if (self::hasColumn('repo_hash')) {
                $query->where('repo_hash', $repoHash);
            } else {
                $query->where('repo_url', $repoUrl);
            }
            $row = $query->orderBy('id', 'desc')->first();
            $state['claimed'] = !empty($row);
            if ($row && self::hasColumn('github_username')) {
                $state['github_username'] = (string) ($row->github_username ?? '');
            }
        } catch (\Throwable $e) {
            $state['claimed'] = false;
            $state['github_username'] = '';
        }

        return $state;
    }

    public static function claim(int $userId, array $moduleSettings, string $clientIp = '', string $userAgent = '', string $githubUsername = ''): array
    {
        if ($userId <= 0) {
            throw new CfGithubStarRewardException('invalid_user');
        }
        if (!self::isEnabled($moduleSettings)) {
            throw new CfGithubStarRewardException('disabled');
        }

        $repoUrl = self::normalizeRepoUrl((string) ($moduleSettings['github_star_repo_url'] ?? ''));
        if ($repoUrl === '') {
            throw new CfGithubStarRewardException('invalid_repo');
        }

        $normalizedGithubUsername = self::normalizeGithubUsername($githubUsername);
        if ($normalizedGithubUsername === '') {
            $binding = self::getOauthBindingForUser($userId);
            $normalizedGithubUsername = self::normalizeGithubUsername((string) ($binding['github_login'] ?? ''));
        }
        if ($normalizedGithubUsername === '') {
            throw new CfGithubStarRewardException('invalid_username');
        }

        $rewardAmount = self::resolveRewardAmount($moduleSettings);
        $repoHash = hash('sha256', strtolower($repoUrl));
        $now = date('Y-m-d H:i:s');
        $safeIp = trim($clientIp);
        $safeUa = trim($userAgent);
        if ($safeUa !== '') {
            $safeUa = function_exists('mb_substr') ? mb_substr($safeUa, 0, 255, 'UTF-8') : substr($safeUa, 0, 255);
        }

        self::ensureTable();

        $hasRepoHashColumn = self::hasColumn('repo_hash');
        $hasGithubUsernameColumn = self::hasColumn('github_username');

        try {
            $preCheck = Capsule::table(self::TABLE_NAME)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if ($hasRepoHashColumn) {
                $preCheck->where('repo_hash', $repoHash);
            } else {
                $preCheck->where('repo_url', $repoUrl);
            }
            if ($preCheck->exists()) {
                throw new CfGithubStarRewardException('already_claimed');
            }

            if ($hasGithubUsernameColumn) {
                $usernameUsedQuery = Capsule::table(self::TABLE_NAME)
                    ->where('status', 'granted')
                    ->where('userid', '<>', $userId)
                    ->where('github_username', $normalizedGithubUsername);
                if ($hasRepoHashColumn) {
                    $usernameUsedQuery->where('repo_hash', $repoHash);
                } else {
                    $usernameUsedQuery->where('repo_url', $repoUrl);
                }
                if ($usernameUsedQuery->exists()) {
                    throw new CfGithubStarRewardException('username_used');
                }
            }
        } catch (CfGithubStarRewardException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CfGithubStarRewardException('verify_failed', $e->getMessage(), $e);
        }

        self::verifyUserStarredRepo($normalizedGithubUsername, $repoUrl);

        return Capsule::connection()->transaction(function () use ($userId, $moduleSettings, $repoUrl, $repoHash, $rewardAmount, $now, $safeIp, $safeUa, $normalizedGithubUsername, $hasRepoHashColumn, $hasGithubUsernameColumn) {
            $existsQuery = Capsule::table(self::TABLE_NAME)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if ($hasRepoHashColumn) {
                $existsQuery->where('repo_hash', $repoHash);
            } else {
                $existsQuery->where('repo_url', $repoUrl);
            }
            $exists = $existsQuery
                ->lockForUpdate()
                ->first();
            if ($exists) {
                throw new CfGithubStarRewardException('already_claimed');
            }

            if ($hasGithubUsernameColumn) {
                $usernameUsedQuery = Capsule::table(self::TABLE_NAME)
                    ->where('status', 'granted')
                    ->where('userid', '<>', $userId)
                    ->where('github_username', $normalizedGithubUsername);
                if ($hasRepoHashColumn) {
                    $usernameUsedQuery->where('repo_hash', $repoHash);
                } else {
                    $usernameUsedQuery->where('repo_url', $repoUrl);
                }
                $usernameUsed = $usernameUsedQuery->lockForUpdate()->first();
                if ($usernameUsed) {
                    throw new CfGithubStarRewardException('username_used');
                }
            }

            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$quota) {
                $baseMax = max(0, (int) ($moduleSettings['max_subdomain_per_user'] ?? 5));
                $inviteLimit = max(0, (int) ($moduleSettings['invite_bonus_limit_global'] ?? 5));
                $usedCount = 0;
                try {
                    $usedCount = (int) Capsule::table('mod_cloudflare_subdomain')->where('userid', $userId)->count();
                } catch (\Throwable $e) {
                    $usedCount = 0;
                }
                Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                    'userid' => $userId,
                    'used_count' => $usedCount,
                    'max_count' => $baseMax,
                    'invite_bonus_count' => 0,
                    'invite_bonus_limit' => $inviteLimit,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                    ->where('userid', $userId)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$quota) {
                throw new CfGithubStarRewardException('quota_unavailable');
            }

            $beforeQuota = (int) ($quota->max_count ?? 0);
            $afterQuota = $beforeQuota + $rewardAmount;

            Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userId)
                ->update([
                    'max_count' => $afterQuota,
                    'updated_at' => $now,
                ]);

            $insertData = [
                'userid' => $userId,
                'repo_url' => $repoUrl,
                'reward_amount' => $rewardAmount,
                'before_quota' => $beforeQuota,
                'after_quota' => $afterQuota,
                'status' => 'granted',
                'client_ip' => $safeIp !== '' ? $safeIp : null,
                'user_agent' => $safeUa !== '' ? $safeUa : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($hasRepoHashColumn) {
                $insertData['repo_hash'] = $repoHash;
            }
            if ($hasGithubUsernameColumn) {
                $insertData['github_username'] = $normalizedGithubUsername;
            }
            Capsule::table(self::TABLE_NAME)->insert($insertData);

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_github_star_reward', [
                    'repo_url' => $repoUrl,
                    'github_username' => $normalizedGithubUsername,
                    'reward_amount' => $rewardAmount,
                    'before_quota' => $beforeQuota,
                    'after_quota' => $afterQuota,
                ], $userId, null);
            }

            return [
                'repo_url' => $repoUrl,
                'github_username' => $normalizedGithubUsername,
                'reward_amount' => $rewardAmount,
                'before_quota' => $beforeQuota,
                'after_quota' => $afterQuota,
            ];
        });
    }

    public static function getUserHistory(int $userId, int $page = 1, int $perPage = self::HISTORY_PER_PAGE): array
    {
        self::ensureTable();
        $page = max(1, $page);
        $perPage = max(1, min(30, $perPage));

        if ($userId <= 0) {
            return [
                'items' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ];
        }

        $query = Capsule::table(self::TABLE_NAME)
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
                'repo_url' => (string) ($row->repo_url ?? ''),
                'github_username' => (string) ($row->github_username ?? ''),
                'reward_amount' => (int) ($row->reward_amount ?? 0),
                'status' => (string) ($row->status ?? 'granted'),
                'before_quota' => (int) ($row->before_quota ?? 0),
                'after_quota' => (int) ($row->after_quota ?? 0),
                'created_at' => !empty($row->created_at) ? date('Y-m-d H:i', strtotime((string) $row->created_at)) : '',
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

    public static function getOauthClientId(array $moduleSettings): string
    {
        $clientId = trim((string) ($moduleSettings['github_star_oauth_client_id'] ?? ''));
        if ($clientId !== '') {
            return $clientId;
        }
        return class_exists('CfInviteRegistrationGithubService')
            ? CfInviteRegistrationGithubService::getClientId($moduleSettings)
            : trim((string) ($moduleSettings['invite_registration_github_client_id'] ?? ''));
    }

    public static function getOauthClientSecret(array $moduleSettings): string
    {
        $secret = trim((string) ($moduleSettings['github_star_oauth_client_secret'] ?? ''));
        if ($secret !== '') {
            if (class_exists('CfInviteRegistrationGithubService') && method_exists('CfInviteRegistrationGithubService', 'getClientSecret')) {
                $shadowSettings = $moduleSettings;
                $shadowSettings['invite_registration_github_client_secret'] = $secret;
                return CfInviteRegistrationGithubService::getClientSecret($shadowSettings);
            }
            return $secret;
        }
        return class_exists('CfInviteRegistrationGithubService')
            ? CfInviteRegistrationGithubService::getClientSecret($moduleSettings)
            : trim((string) ($moduleSettings['invite_registration_github_client_secret'] ?? ''));
    }

    public static function isOauthConfigured(array $moduleSettings): bool
    {
        return self::getOauthClientId($moduleSettings) !== '' && self::getOauthClientSecret($moduleSettings) !== '';
    }

    public static function createAuthorizationUrl(int $userId, array $moduleSettings, string $callbackUrl): string
    {
        if ($userId <= 0) {
            throw new CfGithubStarRewardException('invalid_user');
        }
        if (!self::isOauthConfigured($moduleSettings)) {
            throw new CfGithubStarRewardException('oauth_not_configured');
        }

        $state = bin2hex(random_bytes(24));
        $_SESSION[self::OAUTH_STATE_SESSION_KEY] = [
            'state' => $state,
            'userid' => $userId,
            'expires_at' => time() + self::OAUTH_STATE_TTL_SECONDS,
        ];

        $query = http_build_query([
            'client_id' => self::getOauthClientId($moduleSettings),
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
            throw new CfGithubStarRewardException('invalid_user');
        }
        if (!self::isOauthConfigured($moduleSettings)) {
            throw new CfGithubStarRewardException('oauth_not_configured');
        }

        self::validateAndConsumeOauthState($userId, trim((string) ($query['state'] ?? '')));
        $oauthError = trim((string) ($query['error'] ?? ''));
        if ($oauthError !== '') {
            throw new CfGithubStarRewardException(strtolower($oauthError) === 'access_denied' ? 'user_cancelled' : 'oauth_error', $oauthError);
        }

        $code = trim((string) ($query['code'] ?? ''));
        if ($code === '') {
            throw new CfGithubStarRewardException('missing_code');
        }

        $token = self::exchangeAccessToken($code, $moduleSettings, $callbackUrl);
        $profile = self::fetchGithubProfile($token);

        $githubId = (int) ($profile['id'] ?? 0);
        if ($githubId <= 0) {
            throw new CfGithubStarRewardException('invalid_github_id');
        }
        $login = trim((string) ($profile['login'] ?? ''));
        $normalizedLogin = self::normalizeGithubUsername($login);
        if ($normalizedLogin === '') {
            throw new CfGithubStarRewardException('profile_invalid');
        }

        self::ensureOauthBindingTable();
        $now = date('Y-m-d H:i:s');
        Capsule::connection()->transaction(function () use ($userId, $githubId, $normalizedLogin, $now) {
            $existingByGithub = Capsule::table(self::OAUTH_BINDING_TABLE)
                ->where('github_id', $githubId)
                ->lockForUpdate()
                ->first();
            if ($existingByGithub && (int) ($existingByGithub->userid ?? 0) !== $userId) {
                throw new CfGithubStarRewardException('github_already_bound');
            }

            $existingByUser = Capsule::table(self::OAUTH_BINDING_TABLE)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $payload = [
                'github_id' => $githubId,
                'github_login' => $normalizedLogin,
                'updated_at' => $now,
            ];
            if ($existingByUser) {
                Capsule::table(self::OAUTH_BINDING_TABLE)
                    ->where('id', (int) $existingByUser->id)
                    ->update($payload);
            } else {
                $payload['userid'] = $userId;
                $payload['created_at'] = $now;
                Capsule::table(self::OAUTH_BINDING_TABLE)->insert($payload);
            }
        });

        return [
            'github_id' => $githubId,
            'github_login' => $normalizedLogin,
        ];
    }

    public static function getOauthBindingForUser(int $userId): array
    {
        $empty = ['bound' => false, 'github_id' => 0, 'github_login' => ''];
        if ($userId <= 0) {
            return $empty;
        }
        self::ensureOauthBindingTable();
        try {
            $row = Capsule::table(self::OAUTH_BINDING_TABLE)->where('userid', $userId)->first();
            if (!$row) {
                return $empty;
            }
            return [
                'bound' => true,
                'github_id' => (int) ($row->github_id ?? 0),
                'github_login' => (string) ($row->github_login ?? ''),
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    private static function ensureOauthBindingTable(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::OAUTH_BINDING_TABLE)) {
                $schema->create(self::OAUTH_BINDING_TABLE, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->bigInteger('github_id')->unsigned()->unique();
                    $table->string('github_login', 39)->default('');
                    $table->timestamps();
                    $table->index('userid');
                    $table->index('github_id');
                });
                return;
            }

            if (!$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'userid')) {
                $schema->table(self::OAUTH_BINDING_TABLE, function ($table) {
                    $table->integer('userid')->unsigned()->unique()->after('id');
                });
            }
            if (!$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'github_id')) {
                $schema->table(self::OAUTH_BINDING_TABLE, function ($table) {
                    $table->bigInteger('github_id')->unsigned()->unique()->after('userid');
                });
            }
            if (!$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'github_login')) {
                $schema->table(self::OAUTH_BINDING_TABLE, function ($table) {
                    $table->string('github_login', 39)->default('')->after('github_id');
                });
            }
            if (!$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'created_at') || !$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'updated_at')) {
                $schema->table(self::OAUTH_BINDING_TABLE, function ($table) use ($schema) {
                    if (!$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'created_at')) {
                        $table->timestamp('created_at')->nullable();
                    }
                    if (!$schema->hasColumn(self::OAUTH_BINDING_TABLE, 'updated_at')) {
                        $table->timestamp('updated_at')->nullable();
                    }
                });
            }
        } catch (\Throwable $e) {
        }
    }

    private static function validateAndConsumeOauthState(int $userId, string $state): void
    {
        $stored = $_SESSION[self::OAUTH_STATE_SESSION_KEY] ?? null;
        unset($_SESSION[self::OAUTH_STATE_SESSION_KEY]);
        if (!is_array($stored)) {
            throw new CfGithubStarRewardException('invalid_state');
        }
        $storedState = trim((string) ($stored['state'] ?? ''));
        $storedUser = (int) ($stored['userid'] ?? 0);
        $expiresAt = (int) ($stored['expires_at'] ?? 0);
        if ($state === '' || $storedState === '' || !hash_equals($storedState, $state)) {
            throw new CfGithubStarRewardException('invalid_state');
        }
        if ($storedUser !== $userId || $expiresAt <= 0 || time() > $expiresAt) {
            throw new CfGithubStarRewardException('invalid_state');
        }
    }

    private static function exchangeAccessToken(string $code, array $moduleSettings, string $callbackUrl): string
    {
        $response = self::oauthHttpRequest(
            self::GITHUB_TOKEN_URL,
            'POST',
            ['Accept: application/json'],
            [
                'client_id' => self::getOauthClientId($moduleSettings),
                'client_secret' => self::getOauthClientSecret($moduleSettings),
                'code' => $code,
                'redirect_uri' => $callbackUrl,
            ]
        );
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            throw new CfGithubStarRewardException('oauth_exchange_failed', 'http_' . $status);
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || !empty($payload['error'])) {
            throw new CfGithubStarRewardException('oauth_exchange_failed');
        }
        $token = trim((string) ($payload['access_token'] ?? ''));
        if ($token === '') {
            throw new CfGithubStarRewardException('oauth_exchange_failed');
        }
        return $token;
    }

    private static function fetchGithubProfile(string $accessToken): array
    {
        $response = self::oauthHttpRequest(
            self::GITHUB_USER_API_URL,
            'GET',
            [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $accessToken,
                'X-GitHub-Api-Version: 2022-11-28',
            ]
        );
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new CfGithubStarRewardException('oauth_user_fetch_failed', 'http_' . $status);
        }
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($payload)) {
            throw new CfGithubStarRewardException('oauth_user_fetch_failed');
        }
        return $payload;
    }

    private static function oauthHttpRequest(string $url, string $method = 'GET', array $headers = [], ?array $postFields = null): array
    {
        if (!function_exists('curl_init')) {
            throw new CfGithubStarRewardException('http_unavailable');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new CfGithubStarRewardException('http_unavailable');
        }
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(array_values(array_filter($headers)), ['User-Agent: WHMCS-DomainHub/GithubStarReward']),
        ];
        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query((array) $postFields);
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new CfGithubStarRewardException('http_request_failed', $error !== '' ? $error : 'curl_exec_failed');
        }
        return ['status' => $status, 'body' => (string) $body];
    }
}
