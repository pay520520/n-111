<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

/**
 * 邀请注册服务
 * 类似DNS解锁功能，用户需要输入邀请码才能解锁注册功能
 */
class CfInviteRegistrationService
{
    private const CODE_LENGTH = 12;
    private const TABLE_UNLOCK = 'mod_cloudflare_invite_registration_unlock';
    private const TABLE_LOGS = 'mod_cloudflare_invite_registration_logs';
    private const TABLE_CODE_POOL = 'mod_cloudflare_invite_registration_code_pool';
    private const TABLE_GITHUB_BINDINGS = 'mod_cloudflare_invite_registration_github_bindings';
    private const TABLE_TELEGRAM_BINDINGS = 'mod_cloudflare_telegram_reward_bindings';
    private const TABLE_QUOTAS = 'mod_cloudflare_subdomain_quotas';
    private const TABLE_REWARD_LOGS = 'mod_cloudflare_invite_reward_logs';

    private static function isEnabledSetting($value): bool
    {
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($value);
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function requireTelegramGroupMemberForInvite(array $moduleSettings): bool
    {
        if (self::isEnabledSetting($moduleSettings['invite_registration_telegram_group_guidance_only'] ?? '0')) {
            return false;
        }
        return self::isEnabledSetting($moduleSettings['invite_registration_telegram_require_group_member'] ?? '0');
    }

    private static function isTelegramBindingTestMode(array $moduleSettings): bool
    {
        return self::isEnabledSetting($moduleSettings['invite_registration_telegram_test_mode'] ?? '0');
    }

    public static function resolveGateMode(array $moduleSettings): string
    {
        $mode = strtolower(trim((string) ($moduleSettings['invite_registration_gate_mode'] ?? '')));
        if (in_array($mode, ['invite_only', 'github_only', 'telegram_only', 'invite_or_github', 'invite_or_telegram', 'github_or_telegram', 'invite_or_github_or_telegram'], true)) {
            return $mode;
        }

        $legacyEnabled = function_exists('cfmod_setting_enabled')
            ? cfmod_setting_enabled($moduleSettings['enable_invite_registration_gate'] ?? '0')
            : in_array(strtolower(trim((string) ($moduleSettings['enable_invite_registration_gate'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true);

        return $legacyEnabled ? 'invite_only' : 'disabled';
    }

    public static function isGateEnabled(array $moduleSettings): bool
    {
        return self::resolveGateMode($moduleSettings) !== 'disabled';
    }

    public static function isInviteOptionEnabled(array $moduleSettings): bool
    {
        $mode = self::resolveGateMode($moduleSettings);
        if (in_array($mode, ['invite_only', 'invite_or_github', 'invite_or_telegram', 'invite_or_github_or_telegram'], true)) {
            return true;
        }

        if ($mode === 'github_only' && !self::isGithubOauthReady($moduleSettings)) {
            return true;
        }
        if ($mode === 'telegram_only' && !self::isTelegramSilentReady($moduleSettings)) {
            return true;
        }
        if ($mode === 'github_or_telegram' && !self::isGithubOauthReady($moduleSettings) && !self::isTelegramSilentReady($moduleSettings)) {
            return true;
        }

        return false;
    }

    public static function isGithubOptionEnabled(array $moduleSettings): bool
    {
        $mode = self::resolveGateMode($moduleSettings);
        if (!in_array($mode, ['github_only', 'invite_or_github', 'github_or_telegram', 'invite_or_github_or_telegram'], true)) {
            return false;
        }

        return self::isGithubOauthReady($moduleSettings);
    }

    public static function isTelegramOptionEnabled(array $moduleSettings): bool
    {
        $mode = self::resolveGateMode($moduleSettings);
        if (!in_array($mode, ['telegram_only', 'invite_or_telegram', 'github_or_telegram', 'invite_or_github_or_telegram'], true)) {
            return false;
        }

        return self::isTelegramSilentReady($moduleSettings);
    }

    private static function isGithubOauthReady(array $moduleSettings): bool
    {
        if (!class_exists('CfInviteRegistrationGithubService')) {
            return false;
        }

        try {
            return CfInviteRegistrationGithubService::isOauthConfigured($moduleSettings);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function resolveTelegramBotUsername(array $moduleSettings): string
    {
        $username = trim((string) ($moduleSettings['invite_registration_telegram_bot_username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($moduleSettings['telegram_group_bot_username'] ?? ''));
        }
        $username = ltrim($username, '@');
        if ($username === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_]{5,64}$/', $username)) {
            return '';
        }

        return $username;
    }

    public static function resolveTelegramBotToken(array $moduleSettings): string
    {
        $raw = trim((string) ($moduleSettings['invite_registration_telegram_bot_token'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($moduleSettings['telegram_group_bot_token'] ?? ''));
        }
        if ($raw === '') {
            return '';
        }
        if (strpos($raw, 'enc::') === 0) {
            $raw = substr($raw, strlen('enc::'));
            $raw = trim((string) cfmod_decrypt_sensitive($raw));
        }

        return trim($raw);
    }

    public static function resolveTelegramAuthMaxAge(array $moduleSettings): int
    {
        $value = (int) ($moduleSettings['invite_registration_telegram_auth_max_age_seconds'] ?? ($moduleSettings['telegram_reward_auth_max_age_seconds'] ?? 86400));
        return max(60, min(604800, $value));
    }

    public static function isTelegramSilentReady(array $moduleSettings): bool
    {
        $botUsername = self::resolveTelegramBotUsername($moduleSettings);
        $botToken = self::resolveTelegramBotToken($moduleSettings);
        if ($botUsername === '' || $botToken === '') {
            return false;
        }

        return (bool) preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,120}$/', $botToken);
    }

    /**
     * 检查表是否存在，如果不存在则创建
     */
    public static function ensureTables(): void
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE_UNLOCK)) {
                Capsule::schema()->create(self::TABLE_UNLOCK, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->string('invite_code', 20)->unique();
                    $table->integer('code_generate_count')->unsigned()->default(1);
                    $table->dateTime('unlocked_at')->nullable();
                    $table->timestamps();
                    $table->index('invite_code');
                });
            }
            if (Capsule::schema()->hasTable(self::TABLE_UNLOCK) && !Capsule::schema()->hasColumn(self::TABLE_UNLOCK, 'invite_mode_lock')) {
                Capsule::schema()->table(self::TABLE_UNLOCK, function ($table) {
                    $table->string('invite_mode_lock', 16)->default('')->after('invite_code');
                    $table->index('invite_mode_lock');
                });
            }

            if (!Capsule::schema()->hasTable(self::TABLE_LOGS)) {
                Capsule::schema()->create(self::TABLE_LOGS, function ($table) {
                    $table->increments('id');
                    $table->integer('invite_code_id')->unsigned();
                    $table->integer('inviter_userid')->unsigned();
                    $table->integer('invitee_userid')->unsigned()->nullable();
                    $table->string('invitee_email', 191)->nullable();
                    $table->string('invitee_ip', 64)->nullable();
                    $table->string('invite_code', 20);
                    $table->timestamps();
                    $table->index('invite_code_id');
                    $table->index('inviter_userid');
                    $table->index('invitee_userid');
                    $table->index('invitee_email');
                    $table->index('invite_code');
                    $table->index('created_at');
                });
            }
            if (!Capsule::schema()->hasTable(self::TABLE_CODE_POOL)) {
                Capsule::schema()->create(self::TABLE_CODE_POOL, function ($table) {
                    $table->increments('id');
                    $table->integer('owner_userid')->unsigned();
                    $table->string('invite_code', 20)->unique();
                    $table->string('status', 16)->default('unused');
                    $table->integer('used_by_userid')->unsigned()->nullable();
                    $table->dateTime('used_at')->nullable();
                    $table->timestamps();
                    $table->index(['owner_userid', 'status']);
                    $table->index('invite_code');
                });
            }
            if (Capsule::schema()->hasTable(self::TABLE_CODE_POOL)) {
                if (!Capsule::schema()->hasColumn(self::TABLE_CODE_POOL, 'expires_at')) {
                    Capsule::schema()->table(self::TABLE_CODE_POOL, function ($table) {
                        $table->dateTime('expires_at')->nullable()->after('used_at');
                        $table->index('expires_at');
                    });
                }
                if (!Capsule::schema()->hasColumn(self::TABLE_CODE_POOL, 'code_purpose')) {
                    Capsule::schema()->table(self::TABLE_CODE_POOL, function ($table) {
                        $table->string('code_purpose', 32)->default('general')->after('invite_code');
                        $table->index(['code_purpose', 'status'], 'idx_code_purpose_status');
                    });
                }
                if (!Capsule::schema()->hasColumn(self::TABLE_CODE_POOL, 'issued_to_telegram_user_id')) {
                    Capsule::schema()->table(self::TABLE_CODE_POOL, function ($table) {
                        $table->bigInteger('issued_to_telegram_user_id')->unsigned()->nullable()->after('code_purpose');
                        $table->index('issued_to_telegram_user_id', 'idx_issued_to_telegram_user');
                    });
                }
            }
            if (!Capsule::schema()->hasTable(self::TABLE_REWARD_LOGS)) {
                Capsule::schema()->create(self::TABLE_REWARD_LOGS, function ($table) {
                    $table->increments('id');
                    $table->integer('inviter_userid')->unsigned();
                    $table->integer('invitee_userid')->unsigned();
                    $table->integer('invite_log_id')->unsigned()->nullable();
                    $table->tinyInteger('reward_to_inviter')->default(0);
                    $table->tinyInteger('reward_to_invitee')->default(0);
                    $table->string('skip_reason', 64)->default('');
                    $table->integer('cap_snapshot')->default(0);
                    $table->timestamps();
                    $table->index('inviter_userid');
                    $table->index('invitee_userid');
                    $table->index('invite_log_id');
                    $table->index('created_at');
                });
            } else {
                CfQuotaRewardService::ensureRewardLogColumns();
            }
        } catch (\Throwable $e) {
            // ignore schema creation errors
        }
    }

    /**
     * 确保用户有邀请注册配置文件
     */
    public static function ensureProfile(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user');
        }

        self::ensureTables();

        $row = Capsule::table(self::TABLE_UNLOCK)->where('userid', $userId)->first();
        if (!$row) {
            $row = self::createProfile($userId);
        }
        return self::normalizeRow($row);
    }

    /**
     * 检查用户是否已解锁注册功能
     */
    public static function userHasUnlocked(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        self::ensureTables();

        try {
            $row = Capsule::table(self::TABLE_UNLOCK)
                ->select('id', 'unlocked_at')
                ->where('userid', $userId)
                ->first();
            if (!$row || empty($row->unlocked_at)) {
                return self::autoUnlockLegacyWhmcsUserIfEligible($userId);
            }

            if (!self::isUserCreatedAfterGateEnabledAt($userId)) {
                return true;
            }

            if (self::isExistingUser($userId) || self::hasUnlockAuditTrail($userId)) {
                return true;
            }

            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', (int) ($row->id ?? 0))
                ->update([
                    'unlocked_at' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function autoUnlockLegacyWhmcsUserIfEligible(int $userId): bool
    {
        if (!self::isLegacyWhmcsUserBeforeGateEnabledAt($userId)) {
            return false;
        }

        try {
            $profile = self::ensureProfile($userId);
            if (!empty($profile['unlocked_at'])) {
                return true;
            }

            $now = date('Y-m-d H:i:s');
            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', intval($profile['id'] ?? 0))
                ->update([
                    'unlocked_at' => $now,
                    'updated_at' => $now,
                ]);

            Capsule::table(self::TABLE_LOGS)->insert([
                'invite_code_id' => intval($profile['id'] ?? 0),
                'inviter_userid' => 0,
                'invitee_userid' => $userId,
                'invitee_email' => 'legacy_whmcs_user',
                'invitee_ip' => 'auto:gate_enabled_at',
                'invite_code' => 'AUTO_LEGACY_BYPASS',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isLegacyWhmcsUserBeforeGateEnabledAt(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $cutoffUserId = self::resolveInviteGateCutoffUserId();
            if ($cutoffUserId > 0) {
                return $userId <= $cutoffUserId;
            }

            $enabledAtTs = self::resolveInviteGateEnabledAtTimestamp();
            if ($enabledAtTs <= 0) {
                return false;
            }

            $dateCreated = Capsule::table('tblclients')
                ->where('id', $userId)
                ->value('datecreated');
            if ($dateCreated === null || $dateCreated === '') {
                return false;
            }

            $userCreatedTs = strtotime((string) $dateCreated);
            if ($userCreatedTs === false || $userCreatedTs <= 0) {
                return false;
            }

            return $userCreatedTs <= $enabledAtTs;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isUserCreatedAfterGateEnabledAt(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $cutoffUserId = self::resolveInviteGateCutoffUserId();
            if ($cutoffUserId > 0) {
                return $userId > $cutoffUserId;
            }

            $enabledAtTs = self::resolveInviteGateEnabledAtTimestamp();
            if ($enabledAtTs <= 0) {
                return self::isInviteGateCurrentlyEnabled();
            }

            $dateCreated = Capsule::table('tblclients')
                ->where('id', $userId)
                ->value('datecreated');
            if ($dateCreated === null || $dateCreated === '') {
                return false;
            }
            $userCreatedTs = strtotime((string) $dateCreated);
            if ($userCreatedTs === false || $userCreatedTs <= 0) {
                return false;
            }

            return $userCreatedTs > $enabledAtTs;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolveInviteGateEnabledAtTimestamp(): int
    {
        try {
            $modules = ['domain_hub'];
            if (defined('CF_MODULE_NAME')) {
                $modules[] = (string) CF_MODULE_NAME;
            }
            if (defined('CF_MODULE_NAME_LEGACY')) {
                $modules[] = (string) CF_MODULE_NAME_LEGACY;
            } else {
                $modules[] = 'cloudflare_subdomain';
            }
            $modules = array_values(array_unique(array_filter(array_map('trim', $modules))));
            if (empty($modules)) {
                return 0;
            }

            $rows = Capsule::table('tbladdonmodules')
                ->whereIn('module', $modules)
                ->where('setting', 'invite_registration_gate_enabled_at')
                ->get();

            $byModule = [];
            foreach ($rows as $row) {
                $mod = (string) ($row->module ?? '');
                $raw = trim((string) ($row->value ?? ''));
                if ($mod !== '' && $raw !== '' && !isset($byModule[$mod])) {
                    $byModule[$mod] = $raw;
                }
            }

            foreach ($modules as $moduleName) {
                $raw = trim((string) ($byModule[$moduleName] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $ts = strtotime($raw);
                if ($ts !== false && $ts > 0) {
                    return (int) $ts;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }

    private static function resolveInviteGateCutoffUserId(): int
    {
        try {
            $modules = ['domain_hub'];
            if (defined('CF_MODULE_NAME')) {
                $modules[] = (string) CF_MODULE_NAME;
            }
            if (defined('CF_MODULE_NAME_LEGACY')) {
                $modules[] = (string) CF_MODULE_NAME_LEGACY;
            } else {
                $modules[] = 'cloudflare_subdomain';
            }
            $modules = array_values(array_unique(array_filter(array_map('trim', $modules))));
            if (empty($modules)) {
                return 0;
            }

            $rows = Capsule::table('tbladdonmodules')
                ->whereIn('module', $modules)
                ->where('setting', 'invite_registration_gate_cutoff_userid')
                ->get();
            if (!$rows || count($rows) === 0) {
                return 0;
            }

            $byModule = [];
            foreach ($rows as $row) {
                $mod = (string) ($row->module ?? '');
                $raw = intval($row->value ?? 0);
                if ($mod !== '' && $raw > 0 && !isset($byModule[$mod])) {
                    $byModule[$mod] = $raw;
                }
            }
            foreach ($modules as $moduleName) {
                $cutoff = intval($byModule[$moduleName] ?? 0);
                if ($cutoff > 0) {
                    return $cutoff;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }

    private static function isInviteGateCurrentlyEnabled(): bool
    {
        try {
            $modules = ['domain_hub'];
            if (defined('CF_MODULE_NAME')) {
                $modules[] = (string) CF_MODULE_NAME;
            }
            if (defined('CF_MODULE_NAME_LEGACY')) {
                $modules[] = (string) CF_MODULE_NAME_LEGACY;
            } else {
                $modules[] = 'cloudflare_subdomain';
            }
            $modules = array_values(array_unique(array_filter(array_map('trim', $modules))));
            if (empty($modules)) {
                return false;
            }

            $rows = Capsule::table('tbladdonmodules')
                ->whereIn('module', $modules)
                ->get();
            if (!$rows || count($rows) === 0) {
                return false;
            }

            $settings = [];
            foreach ($rows as $row) {
                $key = (string) ($row->setting ?? '');
                if ($key === '') {
                    continue;
                }
                $module = (string) ($row->module ?? '');
                if (!array_key_exists($key, $settings) || $module === 'domain_hub' || (defined('CF_MODULE_NAME') && $module === (string) CF_MODULE_NAME)) {
                    $settings[$key] = (string) ($row->value ?? '');
                }
            }

            return self::resolveGateMode($settings) !== 'disabled';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 使用邀请码解锁用户的注册功能
     */
    public static function unlockForUser(int $userId, string $inputCode, string $usedEmail, string $ipAddress = ''): void
    {
        self::ensureTables();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }

        $cleanCode = strtoupper(trim($inputCode));
        if ($cleanCode === '') {
            throw new \InvalidArgumentException('invalid_code');
        }

        self::ensureProfile($userId);

        $now = date('Y-m-d H:i:s');
        $normalizedEmail = strtolower(trim((string) $usedEmail));

        Capsule::connection()->transaction(function () use ($userId, $cleanCode, $normalizedEmail, $ipAddress, $now) {
            $poolRow = Capsule::table(self::TABLE_CODE_POOL)
                ->where('invite_code', $cleanCode)
                ->where('status', 'unused')
                ->lockForUpdate()
                ->first();
            $inviterRow = null;
            if ($poolRow) {
                $expiresAtRaw = trim((string) ($poolRow->expires_at ?? ''));
                if ($expiresAtRaw !== '' && strtotime($expiresAtRaw) !== false && strtotime($expiresAtRaw) < time()) {
                    Capsule::table(self::TABLE_CODE_POOL)
                        ->where('id', (int) ($poolRow->id ?? 0))
                        ->update([
                            'status' => 'disabled',
                            'updated_at' => $now,
                        ]);
                    throw new \InvalidArgumentException('invalid_code');
                }
                $inviterRow = (object) [
                    'id' => (int) ($poolRow->id ?? 0),
                    'userid' => (int) ($poolRow->owner_userid ?? 0),
                    'invite_code' => (string) ($poolRow->invite_code ?? ''),
                    'code_purpose' => (string) ($poolRow->code_purpose ?? ''),
                    'issued_to_telegram_user_id' => (int) ($poolRow->issued_to_telegram_user_id ?? 0),
                    '__source' => 'pool',
                ];
            } else {
                $inviterRow = Capsule::table(self::TABLE_UNLOCK)
                    ->where('invite_code', $cleanCode)
                    ->lockForUpdate()
                    ->first();
                if ($inviterRow) {
                    $inviterRow->__source = 'legacy';
                }
            }

            if (!$inviterRow) {
                throw new \InvalidArgumentException('invalid_code');
            }

            $inviterId = (int) ($inviterRow->userid ?? 0);
            if ($inviterId === $userId) {
                throw new \InvalidArgumentException('self_code');
            }

            if (($inviterRow->__source ?? 'legacy') === 'pool'
                && strtolower((string) ($inviterRow->code_purpose ?? '')) === 'telegram_gate'
                && (int) ($inviterRow->issued_to_telegram_user_id ?? 0) > 0
            ) {
                $binding = Capsule::table(self::TABLE_TELEGRAM_BINDINGS)
                    ->where('userid', $userId)
                    ->lockForUpdate()
                    ->first();
                $bindingTelegramUserId = (int) ($binding->telegram_user_id ?? 0);
                if ($bindingTelegramUserId <= 0 || $bindingTelegramUserId !== (int) ($inviterRow->issued_to_telegram_user_id ?? 0)) {
                    throw new \InvalidArgumentException('invalid_code');
                }
            }

            if (!self::inviterCanShare($inviterId)) {
                throw new \InvalidArgumentException('inviter_banned');
            }

            if (!self::inviterMeetsMinimumMonths($inviterId)) {
                throw new \InvalidArgumentException('inviter_age_insufficient');
            }

            if (!self::checkInviterLimit($inviterId)) {
                self::expireUnusedOneTimeCodesForLimit($inviterId, $now);
                self::expireFixedInviteCodeForLimit($inviterId, $now);
                throw new \InvalidArgumentException('inviter_limit_reached');
            }

            $inviteeRow = Capsule::table(self::TABLE_UNLOCK)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$inviteeRow) {
                throw new \InvalidArgumentException('invalid_user');
            }

            if (!empty($inviteeRow->unlocked_at)) {
                throw new \InvalidArgumentException('already_unlocked');
            }

            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', (int) ($inviteeRow->id ?? 0))
                ->update([
                    'unlocked_at' => $now,
                    'updated_at' => $now,
                ]);

            $inviteLogId = (int) Capsule::table(self::TABLE_LOGS)->insertGetId([
                'invite_code_id' => (int) ($inviterRow->id ?? 0),
                'inviter_userid' => $inviterId,
                'invitee_userid' => $userId,
                'invitee_email' => $normalizedEmail,
                'invitee_ip' => $ipAddress,
                'invite_code' => $cleanCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $skipQuotaBonus = self::shouldSkipInviteUnlockQuotaBonus($inviterId, $inviterRow);
            if (!$skipQuotaBonus) {
                self::grantInviteUnlockQuotaBonus($inviterId, $userId, $inviteLogId, $now);
            } else {
                self::recordInviteRewardLog($inviterId, $userId, $inviteLogId, 0, 0, 'bot_issuer_excluded', self::resolveInviterQuotaBonusCap(), $now);
            }

            if (($inviterRow->__source ?? 'legacy') === 'pool') {
                Capsule::table(self::TABLE_CODE_POOL)
                    ->where('id', (int) ($inviterRow->id ?? 0))
                    ->update([
                        'status' => 'used',
                        'used_by_userid' => $userId,
                        'used_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                if (!self::isFixedInviteModeLocked($inviterId)) {
                    self::rotateInviteCode((int) ($inviterRow->id ?? 0));
                }
            }
        });
    }

    private static function grantInviteUnlockQuotaBonus(int $inviterUserId, int $inviteeUserId, int $inviteLogId, string $now): void
    {
        if ($inviterUserId <= 0 || $inviteeUserId <= 0) {
            return;
        }
        if (!self::isInviteUnlockQuotaRewardEnabled()) {
            self::recordInviteRewardLog($inviterUserId, $inviteeUserId, $inviteLogId, 0, 0, 'reward_disabled', self::resolveInviterQuotaBonusCap(), $now);
            return;
        }
        if (!Capsule::schema()->hasTable(self::TABLE_QUOTAS)) {
            self::recordInviteRewardLog($inviterUserId, $inviteeUserId, $inviteLogId, 0, 0, 'quota_table_missing', self::resolveInviterQuotaBonusCap(), $now);
            return;
        }

        $inviterCap = self::resolveInviterQuotaBonusCap();
        $inviterCanReceive = true;
        $skipReason = '';
        if ($inviterCap > 0) {
            $inviterRewardedCount = (int) Capsule::table(self::TABLE_REWARD_LOGS)
                ->where('inviter_userid', $inviterUserId)
                ->where('reward_to_inviter', 1)
                ->count();
            $inviterCanReceive = $inviterRewardedCount < $inviterCap;
            if (!$inviterCanReceive) {
                $skipReason = 'inviter_cap_reached';
            }
        }

        $inviterChange = CfQuotaRewardService::defaultChangeSet();
        $inviteeChange = CfQuotaRewardService::defaultChangeSet();
        $rewardToInviter = 0;
        if ($inviterCanReceive) {
            $inviterChange = CfQuotaRewardService::grantSingleReward($inviterUserId, $now);
            $rewardToInviter = 1;
        }
        $inviteeChange = CfQuotaRewardService::grantSingleReward($inviteeUserId, $now);
        self::recordInviteRewardLog($inviterUserId, $inviteeUserId, $inviteLogId, $rewardToInviter, 1, $skipReason, $inviterCap, $now, $inviterChange, $inviteeChange);
    }

    private static function expireUnusedOneTimeCodesForLimit(int $inviterId, string $now): void
    {
        if ($inviterId <= 0) {
            return;
        }
        $rows = Capsule::table(self::TABLE_CODE_POOL)
            ->select('id', 'invite_code')
            ->where('owner_userid', $inviterId)
            ->where('status', 'unused')
            ->where(function ($query) {
                $query->whereNull('code_purpose')->orWhere('code_purpose', 'general');
            })
            ->lockForUpdate()
            ->get();
        if (!$rows || count($rows) === 0) {
            return;
        }
        $ids = [];
        foreach ($rows as $row) {
            $poolId = (int) ($row->id ?? 0);
            $code = strtoupper(trim((string) ($row->invite_code ?? '')));
            if ($poolId <= 0 || $code === '') {
                continue;
            }
            $ids[] = $poolId;
            Capsule::table(self::TABLE_LOGS)->insert([
                'invite_code_id' => $poolId,
                'inviter_userid' => $inviterId,
                'invitee_userid' => null,
                'invitee_email' => '-',
                'invitee_ip' => 'system_limit_reached',
                'invite_code' => $code,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        if (!empty($ids)) {
            Capsule::table(self::TABLE_CODE_POOL)
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'disabled',
                    'updated_at' => $now,
                ]);
        }
    }

    private static function expireFixedInviteCodeForLimit(int $inviterId, string $now): void
    {
        if ($inviterId <= 0) {
            return;
        }
        $profile = Capsule::table(self::TABLE_UNLOCK)
            ->where('userid', $inviterId)
            ->lockForUpdate()
            ->first(['id', 'invite_code', 'invite_mode_lock']);
        if (!$profile) {
            return;
        }
        $modeLock = strtolower(trim((string) ($profile->invite_mode_lock ?? '')));
        $fixedCode = strtoupper(trim((string) ($profile->invite_code ?? '')));
        if ($modeLock !== 'fixed' || $fixedCode === '') {
            return;
        }

        Capsule::table(self::TABLE_LOGS)->insert([
            'invite_code_id' => 0,
            'inviter_userid' => $inviterId,
            'invitee_userid' => null,
            'invitee_email' => '-',
            'invitee_ip' => 'system_limit_reached',
            'invite_code' => $fixedCode,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Capsule::table(self::TABLE_UNLOCK)
            ->where('userid', $inviterId)
            ->update([
                'invite_code' => self::generateUniqueCode(),
                'invite_mode_lock' => '',
                'updated_at' => $now,
            ]);
    }

    private static function isInviteUnlockQuotaRewardEnabled(): bool
    {
        // 配置语义：仅控制“邀请注册准入链路”是否发放双方奖励；关闭时不会增加 bonus/max。
        $raw = self::resolveModuleSettingValue('invite_registration_bidirectional_quota_reward_enabled');

        if ($raw === null || trim((string) $raw) === '') {
            return true;
        }
        return self::isEnabledSetting($raw);
    }

    private static function shouldSkipInviteUnlockQuotaBonus(int $inviterUserId, $inviterRow): bool
    {
        if ($inviterUserId <= 0) {
            return true;
        }

        $issuerUserId = self::resolveInviteRegistrationBotIssuerUserId();
        if ($issuerUserId <= 0) {
            return false;
        }

        $source = strtolower(trim((string) ($inviterRow->__source ?? 'legacy')));
        if ($source !== 'pool') {
            return false;
        }

        return $inviterUserId === $issuerUserId;
    }

    private static function resolveInviteRegistrationBotIssuerUserId(): int
    {
        $raw = self::resolveModuleSettingValue('invite_registration_bot_issuer_userid');

        $uid = (int) $raw;
        return $uid > 0 ? $uid : 0;
    }

    private static function resolveInviterQuotaBonusCap(): int
    {
        // 配置语义：仅限制邀请人可获得奖励的“累计次数”，不是直接把用户总名额设为该值。
        $raw = self::resolveModuleSettingValue('invite_registration_inviter_bonus_cap');

        $cap = max(0, (int) $raw);
        return min(1000000000, $cap);
    }

    private static function resolveModuleSettingValue(string $setting): ?string
    {
        try {
            if (function_exists('cf_get_module_settings_cached')) {
                $settings = cf_get_module_settings_cached();
                if (is_array($settings) && array_key_exists($setting, $settings)) {
                    return (string) $settings[$setting];
                }
            }
            $moduleCandidates = [];
            if (defined('CF_MODULE_NAME')) {
                $moduleCandidates[] = (string) CF_MODULE_NAME;
            }
            $moduleCandidates[] = 'domain_hub';
            if (defined('CF_MODULE_NAME_LEGACY')) {
                $moduleCandidates[] = (string) CF_MODULE_NAME_LEGACY;
            }
            $moduleCandidates = array_values(array_unique(array_filter($moduleCandidates, static function ($m) {
                return trim((string) $m) !== '';
            })));

            if (empty($moduleCandidates)) {
                return null;
            }

            $rows = Capsule::table('tbladdonmodules')
                ->whereIn('module', $moduleCandidates)
                ->where('setting', $setting)
                ->get();
            $raw = null;
            foreach ($rows as $row) {
                $raw = (string) ($row->value ?? '');
                $module = (string) ($row->module ?? '');
                if ($module === 'domain_hub' || (defined('CF_MODULE_NAME') && $module === (string) CF_MODULE_NAME)) {
                    break;
                }
            }
            return $raw;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function recordInviteRewardLog(
        int $inviterUserId,
        int $inviteeUserId,
        int $inviteLogId,
        int $rewardToInviter,
        int $rewardToInvitee,
        string $skipReason,
        int $capSnapshot,
        string $now,
        array $inviterChangeSet = [],
        array $inviteeChangeSet = []
    ): void {
        if (!Capsule::schema()->hasTable(self::TABLE_REWARD_LOGS)) {
            return;
        }
        $inviterBeforeMax = (int) ($inviterChangeSet['before_max_count'] ?? 0);
        $inviterAfterMax = (int) ($inviterChangeSet['after_max_count'] ?? 0);
        $inviterBeforeBonus = (int) ($inviterChangeSet['before_bonus_count'] ?? 0);
        $inviterAfterBonus = (int) ($inviterChangeSet['after_bonus_count'] ?? 0);
        $inviterAppliedToMax = (int) ($inviterChangeSet['applied_to_max_count'] ?? 0);

        Capsule::table(self::TABLE_REWARD_LOGS)->insert([
            'inviter_userid' => max(0, $inviterUserId),
            'invitee_userid' => max(0, $inviteeUserId),
            'invite_log_id' => $inviteLogId > 0 ? $inviteLogId : null,
            'reward_to_inviter' => $rewardToInviter ? 1 : 0,
            'reward_to_invitee' => $rewardToInvitee ? 1 : 0,
            'skip_reason' => substr(trim($skipReason), 0, 64),
            'cap_snapshot' => max(0, $capSnapshot),
            'before_max_count' => $inviterBeforeMax,
            'after_max_count' => $inviterAfterMax,
            'before_bonus_count' => $inviterBeforeBonus,
            'after_bonus_count' => $inviterAfterBonus,
            'applied_to_max_count' => $inviterAppliedToMax,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($rewardToInviter && $inviterUserId > 0) {
            cloudflare_subdomain_log('client_invite_quota_reward_inviter', [
                'delta' => 1,
                'role' => 'inviter',
                'counterparty_userid' => max(0, $inviteeUserId),
                'invite_log_id' => $inviteLogId > 0 ? $inviteLogId : null,
                'cap_snapshot' => max(0, $capSnapshot),
                'skip_reason' => $skipReason,
                'before_max_count' => $inviterBeforeMax,
                'after_max_count' => $inviterAfterMax,
                'before_bonus_count' => $inviterBeforeBonus,
                'after_bonus_count' => $inviterAfterBonus,
                'applied_to_max_count' => $inviterAppliedToMax,
            ], $inviterUserId, null);
        }

        if ($rewardToInvitee && $inviteeUserId > 0) {
            $inviteeBeforeMax = (int) ($inviteeChangeSet['before_max_count'] ?? 0);
            $inviteeAfterMax = (int) ($inviteeChangeSet['after_max_count'] ?? 0);
            $inviteeBeforeBonus = (int) ($inviteeChangeSet['before_bonus_count'] ?? 0);
            $inviteeAfterBonus = (int) ($inviteeChangeSet['after_bonus_count'] ?? 0);
            $inviteeAppliedToMax = (int) ($inviteeChangeSet['applied_to_max_count'] ?? 0);
            cloudflare_subdomain_log('client_invite_quota_reward_invitee', [
                'delta' => 1,
                'role' => 'invitee',
                'counterparty_userid' => max(0, $inviterUserId),
                'invite_log_id' => $inviteLogId > 0 ? $inviteLogId : null,
                'cap_snapshot' => max(0, $capSnapshot),
                'skip_reason' => $skipReason,
                'before_max_count' => $inviteeBeforeMax,
                'after_max_count' => $inviteeAfterMax,
                'before_bonus_count' => $inviteeBeforeBonus,
                'after_bonus_count' => $inviteeAfterBonus,
                'applied_to_max_count' => $inviteeAppliedToMax,
            ], $inviteeUserId, null);
        }
    }

    public static function generateInviteCodes(int $userId, int $count): int
    {
        self::ensureTables();
        if (self::isFixedInviteModeLocked($userId)) {
            throw new \InvalidArgumentException('fixed_mode_locked');
        }
        $count = max(0, $count);
        $batchMax = self::getGenerateBatchMax();
        if ($count > $batchMax) {
            throw new \InvalidArgumentException('count_exceeds_batch_max');
        }
        if ($userId <= 0 || $count <= 0) {
            return 0;
        }
        if (!self::inviterCanShare($userId) || !self::inviterMeetsMinimumMonths($userId)) {
            return 0;
        }
        $remaining = self::getInviterRemainingQuota($userId);
        if ($remaining !== PHP_INT_MAX) {
            if ($count > $remaining) {
                throw new \InvalidArgumentException('count_exceeds_remaining');
            }
            $count = min($count, $remaining);
        }
        if ($count <= 0) {
            return 0;
        }
        $created = 0;
        $now = date('Y-m-d H:i:s');
        Capsule::connection()->transaction(function () use ($userId, $count, $now, &$created) {
            for ($i = 0; $i < $count; $i++) {
                Capsule::table(self::TABLE_CODE_POOL)->insert([
                    'owner_userid' => $userId,
                    'invite_code' => self::generateUniqueCode(),
                    'status' => 'unused',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        });
        return $created;
    }

    public static function canUserUseCustomInviteCode(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $raw = (string) self::resolveModuleSettingValue('invite_registration_custom_code_user_whitelist');
        if (trim($raw) === '') {
            return false;
        }
        $tokens = preg_split('/[\s,;|]+/', $raw) ?: [];
        foreach ($tokens as $token) {
            $id = (int) trim((string) $token);
            if ($id > 0 && $id === $userId) {
                return true;
            }
        }
        return false;
    }

    public static function generateCustomInviteCode(int $userId, string $customCode): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::canUserUseCustomInviteCode($userId)) {
            throw new \InvalidArgumentException('custom_not_allowed');
        }
        if (self::isFixedInviteModeLocked($userId)) {
            throw new \InvalidArgumentException('fixed_mode_locked');
        }
        if (!self::inviterCanShare($userId) || !self::inviterMeetsMinimumMonths($userId)) {
            throw new \InvalidArgumentException('inviter_not_eligible');
        }

        $code = strtoupper(trim($customCode));
        if (!preg_match('/^[A-Z0-9]{6,20}$/', $code)) {
            throw new \InvalidArgumentException('custom_invalid_format');
        }

        $remaining = self::getInviterRemainingQuota($userId);
        if ($remaining !== PHP_INT_MAX && $remaining <= 0) {
            throw new \InvalidArgumentException('count_exceeds_remaining');
        }
        if (self::isInviteCodeOccupied($code, $userId)) {
            throw new \InvalidArgumentException('custom_code_exists');
        }

        $now = date('Y-m-d H:i:s');
        try {
            Capsule::table(self::TABLE_CODE_POOL)->insert([
                'owner_userid' => $userId,
                'invite_code' => $code,
                'status' => 'unused',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('custom_code_exists');
        }

        return ['invite_code' => $code];
    }

    public static function enableFixedInviteModeWithCustomCode(int $userId, string $customCode): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::canUserUseCustomInviteCode($userId)) {
            throw new \InvalidArgumentException('custom_not_allowed');
        }
        if (!self::inviterCanShare($userId) || !self::inviterMeetsMinimumMonths($userId)) {
            throw new \InvalidArgumentException('inviter_not_eligible');
        }
        $code = strtoupper(trim($customCode));
        if (!preg_match('/^[A-Z0-9]{6,20}$/', $code)) {
            throw new \InvalidArgumentException('custom_invalid_format');
        }
        $remaining = self::getInviterRemainingQuota($userId);
        if ($remaining !== PHP_INT_MAX && $remaining <= 0) {
            throw new \InvalidArgumentException('count_exceeds_remaining');
        }
        if (self::isInviteCodeOccupied($code, $userId)) {
            throw new \InvalidArgumentException('custom_code_exists');
        }
        $now = date('Y-m-d H:i:s');
        try {
            self::ensureProfile($userId);
            Capsule::table(self::TABLE_UNLOCK)
                ->where('userid', $userId)
                ->update([
                    'invite_code' => $code,
                    'invite_mode_lock' => 'fixed',
                    'updated_at' => $now,
                ]);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('custom_code_exists');
        }
        return ['invite_code' => $code];
    }

    private static function isInviteCodeOccupied(string $code, int $userId = 0): bool
    {
        $cleanCode = strtoupper(trim($code));
        if ($cleanCode === '') {
            return true;
        }
        $inPool = Capsule::table(self::TABLE_CODE_POOL)->where('invite_code', $cleanCode)->exists();
        if ($inPool) {
            return true;
        }
        $unlockRow = Capsule::table(self::TABLE_UNLOCK)
            ->where('invite_code', $cleanCode)
            ->first(['userid', 'invite_mode_lock']);
        if (!$unlockRow) {
            return false;
        }
        $ownerId = (int) ($unlockRow->userid ?? 0);
        $modeLock = strtolower(trim((string) ($unlockRow->invite_mode_lock ?? '')));
        if ($ownerId > 0 && $ownerId === $userId && $modeLock !== 'fixed') {
            return false;
        }
        return true;
    }

    public static function fetchUnusedCodes(int $userId, int $page, int $perPage = 5): array
    {
        self::ensureTables();
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        // Only invalidate outstanding codes after the inviter has actually reached
        // their successful-invite limit.  getInviterRemainingQuota() also subtracts
        // currently unused one-time codes because it represents remaining issue
        // capacity; using it here would incorrectly expire valid pre-issued codes
        // as soon as the user generated up to their quota.
        if (self::shouldExpireUnusedCodesForActualLimit($userId)) {
            $now = date('Y-m-d H:i:s');
            Capsule::connection()->transaction(function () use ($userId, $now) {
                self::expireUnusedOneTimeCodesForLimit($userId, $now);
                self::expireFixedInviteCodeForLimit($userId, $now);
            });
        }
        $remainingQuota = self::getInviterRemainingQuota($userId);
        $query = Capsule::table(self::TABLE_CODE_POOL)
            ->where('owner_userid', $userId)
            ->where('status', 'unused');
        $total = (int) $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $rows = $query->orderBy('id', 'desc')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row->id ?? 0),
                'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
                'created_at' => $row->created_at ?? null,
                'mode' => 'one_time',
            ];
        }
        $fixedMeta = self::getFixedInviteCodeMeta($userId);
        $fixedCode = (string) ($fixedMeta['invite_code'] ?? '');
        if ($fixedCode !== '' && ($remainingQuota === PHP_INT_MAX || $remainingQuota > 0)) {
            array_unshift($items, [
                'id' => -1,
                'invite_code' => $fixedCode,
                'created_at' => $fixedMeta['created_at'] ?? null,
                'mode' => 'fixed',
            ]);
        }
        return ['items' => $items, 'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => $totalPages]];
    }

    public static function enableFixedInviteMode(int $userId): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        $profile = self::ensureProfile($userId);
        Capsule::table(self::TABLE_UNLOCK)
            ->where('userid', $userId)
            ->update([
                'invite_mode_lock' => 'fixed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        return [
            'invite_code' => strtoupper((string) ($profile['invite_code'] ?? '')),
        ];
    }

    public static function isFixedInviteModeLocked(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $mode = (string) Capsule::table(self::TABLE_UNLOCK)->where('userid', $userId)->value('invite_mode_lock');
        return strtolower(trim($mode)) === 'fixed';
    }

    public static function getFixedInviteCode(int $userId): string
    {
        if (!self::isFixedInviteModeLocked($userId)) {
            return '';
        }
        $code = (string) Capsule::table(self::TABLE_UNLOCK)->where('userid', $userId)->value('invite_code');
        return strtoupper(trim($code));
    }


    public static function getFixedInviteCodeMeta(int $userId): array
    {
        if (!self::isFixedInviteModeLocked($userId)) {
            return ['invite_code' => '', 'created_at' => null];
        }
        $row = Capsule::table(self::TABLE_UNLOCK)
            ->where('userid', $userId)
            ->first(['invite_code', 'created_at']);
        return [
            'invite_code' => strtoupper(trim((string) ($row->invite_code ?? ''))),
            'created_at' => $row->created_at ?? null,
        ];
    }

    public static function invalidateFixedInviteCode(int $userId): bool
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::isFixedInviteModeLocked($userId)) {
            throw new \InvalidArgumentException('fixed_mode_not_enabled');
        }

        $invalidated = false;
        Capsule::connection()->transaction(function () use ($userId, &$invalidated) {
            $row = Capsule::table(self::TABLE_UNLOCK)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first(['invite_code', 'invite_mode_lock']);
            $currentCode = strtoupper(trim((string) ($row->invite_code ?? '')));
            $modeLock = strtolower(trim((string) ($row->invite_mode_lock ?? '')));
            if ($modeLock !== 'fixed' || $currentCode === '') {
                return;
            }

            $placeholderCode = self::generateUniqueCode();
            $now = date('Y-m-d H:i:s');
            Capsule::table(self::TABLE_UNLOCK)
                ->where('userid', $userId)
                ->update([
                    'invite_code' => $placeholderCode,
                    'invite_mode_lock' => '',
                    'updated_at' => $now,
                ]);

            Capsule::table(self::TABLE_LOGS)->insert([
                'invite_code_id' => 0,
                'inviter_userid' => $userId,
                'invitee_userid' => null,
                'invitee_email' => '用户主动作废',
                'invitee_ip' => 'user_manual_invalidate',
                'invite_code' => $currentCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $invalidated = true;
        });

        return $invalidated;
    }

    public static function invalidateOneTimeInviteCode(int $userId, int $codeId): bool
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if ($codeId <= 0) {
            throw new \InvalidArgumentException('invalid_code_id');
        }

        $changed = false;
        Capsule::connection()->transaction(function () use ($userId, $codeId, &$changed) {
            $row = Capsule::table(self::TABLE_CODE_POOL)
                ->where('id', $codeId)
                ->where('owner_userid', $userId)
                ->lockForUpdate()
                ->first(['id', 'invite_code', 'status']);
            if (!$row) {
                throw new \InvalidArgumentException('code_not_found');
            }
            $status = strtolower(trim((string) ($row->status ?? '')));
            if ($status !== 'unused') {
                return;
            }
            Capsule::table(self::TABLE_CODE_POOL)
                ->where('id', (int) $row->id)
                ->update([
                    'status' => 'revoked',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $now = date('Y-m-d H:i:s');
            Capsule::table(self::TABLE_LOGS)->insert([
                'invite_code_id' => (int) ($row->id ?? 0),
                'inviter_userid' => $userId,
                'invitee_userid' => null,
                'invitee_email' => '用户主动作废',
                'invitee_ip' => 'user_manual_invalidate_one_time',
                'invite_code' => strtoupper(trim((string) ($row->invite_code ?? ''))),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $changed = true;
        });

        return $changed;
    }

    public static function issueTelegramBindingShortLivedCode(int $inviteeUserId, int $telegramUserId, array $moduleSettings = []): array
    {
        self::ensureTables();
        if ($inviteeUserId <= 0 || $telegramUserId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        $ttlRaw = (int) ($moduleSettings['invite_registration_telegram_invite_ttl_seconds'] ?? 0);
        $ttl = $ttlRaw > 0 ? max(60, min(86400, $ttlRaw)) : 0;
        $ownerUserId = (int) ($moduleSettings['invite_registration_bot_issuer_userid'] ?? 0);
        if ($ownerUserId <= 0) {
            $ownerUserId = 1;
        }
        $nowTs = time();
        $now = date('Y-m-d H:i:s', $nowTs);
        $expiresAt = $ttl > 0 ? date('Y-m-d H:i:s', $nowTs + $ttl) : null;

        $code = '';
        $reusedExpiresAt = '';
        Capsule::connection()->transaction(function () use (&$code, &$reusedExpiresAt, $ownerUserId, $telegramUserId, $ttl, $expiresAt, $now, $nowTs) {
            $usedBefore = Capsule::table(self::TABLE_CODE_POOL)
                ->where('code_purpose', 'telegram_gate')
                ->where('issued_to_telegram_user_id', $telegramUserId)
                ->where('status', 'used')
                ->lockForUpdate()
                ->exists();
            if ($usedBefore) {
                throw new \InvalidArgumentException('telegram_already_consumed');
            }

            $existingQuery = Capsule::table(self::TABLE_CODE_POOL)
                ->where('owner_userid', $ownerUserId)
                ->where('code_purpose', 'telegram_gate')
                ->where('issued_to_telegram_user_id', $telegramUserId)
                ->where('status', 'unused');

            if ($ttl > 0) {
                $existingQuery->whereNotNull('expires_at')->where('expires_at', '>', $now);
            }

            $existing = $existingQuery
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $code = strtoupper((string) ($existing->invite_code ?? ''));
                $reusedExpiresAt = trim((string) ($existing->expires_at ?? ''));
                return;
            }

            $code = self::generateUniqueCode();
            $reusedExpiresAt = $expiresAt ?: '';
            Capsule::table(self::TABLE_CODE_POOL)->insert([
                'owner_userid' => $ownerUserId,
                'invite_code' => $code,
                'code_purpose' => 'telegram_gate',
                'issued_to_telegram_user_id' => $telegramUserId,
                'status' => 'unused',
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($ttl > 0) {
                Capsule::table(self::TABLE_CODE_POOL)
                    ->where('owner_userid', $ownerUserId)
                    ->where('code_purpose', 'telegram_gate')
                    ->where('issued_to_telegram_user_id', $telegramUserId)
                    ->where('status', 'unused')
                    ->where('invite_code', '!=', $code)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', date('Y-m-d H:i:s', $nowTs))
                    ->update([
                        'status' => 'disabled',
                        'updated_at' => $now,
                    ]);
            }
        });

        return [
            'invite_code' => $code,
            'expires_at' => $reusedExpiresAt,
            'ttl_seconds' => $ttl,
            'owner_userid' => $ownerUserId,
        ];
    }

    /**
     * 管理员直接解锁用户
     */
    public static function adminUnlock(int $userId, int $adminId = 0): void
    {
        self::ensureTables();

        $profile = self::ensureProfile($userId);
        if (!empty($profile['unlocked_at'])) {
            return; // 已解锁，无需操作
        }

        Capsule::table(self::TABLE_UNLOCK)
            ->where('id', $profile['id'])
            ->update([
                'unlocked_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        // 记录管理员操作日志
        Capsule::table(self::TABLE_LOGS)->insert([
            'invite_code_id' => $profile['id'],
            'inviter_userid' => 0,
            'invitee_userid' => $userId,
            'invitee_email' => 'admin_unlock',
            'invitee_ip' => 'admin:' . $adminId,
            'invite_code' => 'ADMIN_BYPASS',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function unlockByGithub(int $userId, int $githubId, string $githubLogin = '', string $githubCreatedAt = ''): void
    {
        self::ensureTables();

        if ($userId <= 0 || $githubId <= 0) {
            throw new \InvalidArgumentException('invalid_github_payload');
        }

        $profile = self::ensureProfile($userId);
        $now = date('Y-m-d H:i:s');

        if (empty($profile['unlocked_at'])) {
            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', $profile['id'])
                ->update([
                    'unlocked_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $emailTag = 'github:' . $githubId;
        if ($githubLogin !== '') {
            $emailTag .= ':' . strtolower(trim($githubLogin));
        }

        $ipTag = 'github_oauth';
        if ($githubCreatedAt !== '') {
            $ipTag .= ':' . $githubCreatedAt;
        }

        Capsule::table(self::TABLE_LOGS)->insert([
            'invite_code_id' => $profile['id'],
            'inviter_userid' => 0,
            'invitee_userid' => $userId,
            'invitee_email' => $emailTag,
            'invitee_ip' => $ipTag,
            'invite_code' => 'GITHUB_OAUTH',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function unlockByTelegram(int $userId, array $moduleSettings, array $authPayload = []): void
    {
        self::ensureTables();
        self::ensureTelegramBindingTable();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::isGateEnabled($moduleSettings)) {
            throw new \InvalidArgumentException('gate_disabled');
        }
        if (!self::isTelegramOptionEnabled($moduleSettings)) {
            throw new \InvalidArgumentException('telegram_not_enabled');
        }

        $botToken = self::resolveTelegramBotToken($moduleSettings);
        if (!self::isTelegramSilentReady($moduleSettings)) {
            throw new \InvalidArgumentException('telegram_invalid_config');
        }

        $authData = self::verifyTelegramAuthPayload($authPayload, $botToken, self::resolveTelegramAuthMaxAge($moduleSettings));
        $telegramUserId = (int) ($authData['telegram_user_id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new \InvalidArgumentException('telegram_auth_invalid');
        }
        if (!self::isTelegramBindingTestMode($moduleSettings) && self::telegramAlreadyUsedByAnotherInviteUser($telegramUserId, $userId)) {
            throw new \InvalidArgumentException('telegram_used');
        }

        $now = date('Y-m-d H:i:s');
        $bindingTable = self::TABLE_TELEGRAM_BINDINGS;

        Capsule::connection()->transaction(function () use ($userId, $telegramUserId, $authData, $now, $bindingTable) {
            $profile = Capsule::table(self::TABLE_UNLOCK)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();
            if (!$profile) {
                $profile = self::createProfile($userId);
            }

            $bindingExisting = Capsule::table($bindingTable)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $bindingByTelegram = Capsule::table($bindingTable)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first();
            if (!self::isTelegramBindingTestMode($moduleSettings) && $bindingByTelegram && (int) ($bindingByTelegram->userid ?? 0) !== $userId) {
                throw new \InvalidArgumentException('telegram_used');
            }

            $bindingPayload = [
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => ($authData['telegram_username'] ?? '') !== '' ? (string) $authData['telegram_username'] : null,
                'first_name' => ($authData['first_name'] ?? '') !== '' ? (string) $authData['first_name'] : null,
                'last_name' => ($authData['last_name'] ?? '') !== '' ? (string) $authData['last_name'] : null,
                'photo_url' => ($authData['photo_url'] ?? '') !== '' ? (string) $authData['photo_url'] : null,
                'auth_date' => max(0, (int) ($authData['auth_date'] ?? 0)),
                'updated_at' => $now,
            ];
            if ($bindingExisting) {
                Capsule::table($bindingTable)
                    ->where('id', (int) ($bindingExisting->id ?? 0))
                    ->update($bindingPayload);
            } else {
                $bindingPayload['userid'] = $userId;
                $bindingPayload['created_at'] = $now;
                Capsule::table($bindingTable)->insert($bindingPayload);
            }

            if (empty($profile->unlocked_at)) {
                Capsule::table(self::TABLE_UNLOCK)
                    ->where('id', (int) ($profile->id ?? 0))
                    ->update([
                        'unlocked_at' => $now,
                        'updated_at' => $now,
                    ]);

                $emailTag = 'telegram:' . $telegramUserId;
                if (($authData['telegram_username'] ?? '') !== '') {
                    $emailTag .= ':' . (string) $authData['telegram_username'];
                }

                Capsule::table(self::TABLE_LOGS)->insert([
                    'invite_code_id' => (int) ($profile->id ?? 0),
                    'inviter_userid' => 0,
                    'invitee_userid' => $userId,
                    'invitee_email' => $emailTag,
                    'invitee_ip' => 'telegram_oauth',
                    'invite_code' => 'TELEGRAM_OAUTH',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public static function unlockByTelegramBotBinding(int $userId, array $moduleSettings): void
    {
        self::ensureTables();
        self::ensureTelegramBindingTable();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::isGateEnabled($moduleSettings)) {
            throw new \InvalidArgumentException('gate_disabled');
        }
        if (!self::isTelegramOptionEnabled($moduleSettings)) {
            throw new \InvalidArgumentException('telegram_not_enabled');
        }

        $binding = Capsule::table(self::TABLE_TELEGRAM_BINDINGS)->where('userid', $userId)->first();
        if (!$binding || (int) ($binding->telegram_user_id ?? 0) <= 0) {
            throw new \InvalidArgumentException('telegram_auth_required');
        }
        if (!self::isTelegramBindingTestMode($moduleSettings) && self::telegramAlreadyUsedByAnotherInviteUser((int) ($binding->telegram_user_id ?? 0), $userId)) {
            throw new \InvalidArgumentException('telegram_used');
        }
        if (self::requireTelegramGroupMemberForInvite($moduleSettings)) {
            $isGroupMember = (int) ($binding->is_group_member ?? 0);
            if ($isGroupMember <= 0) {
                throw new \InvalidArgumentException('telegram_group_required');
            }
            $requiredChatId = trim((string) ($moduleSettings['telegram_group_chat_id'] ?? ''));
            $verifiedChatId = trim((string) ($binding->group_chat_id ?? ''));
            if ($requiredChatId !== '' && $verifiedChatId !== '' && $requiredChatId !== $verifiedChatId) {
                throw new \InvalidArgumentException('telegram_group_required');
            }
        }

        $profile = self::ensureProfile($userId);
        if (!empty($profile['unlocked_at'])) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE_UNLOCK)->where('id', (int) ($profile['id'] ?? 0))->update([
            'unlocked_at' => $now,
            'updated_at' => $now,
        ]);

        $emailTag = 'telegram:' . (int) ($binding->telegram_user_id ?? 0);
        $username = trim((string) ($binding->telegram_username ?? ''));
        if ($username !== '') {
            $emailTag .= ':' . $username;
        }
        Capsule::table(self::TABLE_LOGS)->insert([
            'invite_code_id' => (int) ($profile['id'] ?? 0),
            'inviter_userid' => 0,
            'invitee_userid' => $userId,
            'invitee_email' => $emailTag,
            'invitee_ip' => 'telegram_bot',
            'invite_code' => 'TELEGRAM_BOT',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function verifyTelegramAuthPayload(array $authPayload, string $botToken, int $maxAgeSeconds): array
    {
        $payload = self::normalizeTelegramAuthPayload($authPayload);
        $telegramUserId = (int) ($payload['id'] ?? 0);
        $authDate = (int) ($payload['auth_date'] ?? 0);
        $hash = strtolower(trim((string) ($payload['hash'] ?? '')));

        if ($telegramUserId <= 0 || $authDate <= 0 || $hash === '') {
            throw new \InvalidArgumentException('telegram_auth_invalid');
        }
        if ($maxAgeSeconds > 0 && (time() - $authDate) > $maxAgeSeconds) {
            throw new \InvalidArgumentException('telegram_auth_expired');
        }

        $dataCheckString = self::buildTelegramDataCheckString($payload);
        if ($dataCheckString === '') {
            throw new \InvalidArgumentException('telegram_auth_invalid');
        }

        $secretKey = hash('sha256', $botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        if (!hash_equals($expectedHash, $hash)) {
            throw new \InvalidArgumentException('telegram_auth_invalid');
        }

        return [
            'telegram_user_id' => $telegramUserId,
            'telegram_username' => self::normalizeTelegramUsername((string) ($payload['username'] ?? '')),
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'photo_url' => trim((string) ($payload['photo_url'] ?? '')),
            'auth_date' => $authDate,
        ];
    }

    private static function telegramAlreadyUsedByAnotherInviteUser(int $telegramUserId, int $currentUserId): bool
    {
        if ($telegramUserId <= 0 || $currentUserId <= 0) {
            return false;
        }

        try {
            $prefix = 'telegram:' . $telegramUserId;
            $row = Capsule::table(self::TABLE_LOGS)
                ->where('invitee_userid', '<>', $currentUserId)
                ->where(function ($query) use ($prefix) {
                    $query->where('invitee_ip', 'telegram_bot')
                        ->orWhere('invitee_ip', 'telegram_oauth');
                })
                ->where('invitee_email', 'like', $prefix . '%')
                ->orderBy('id', 'desc')
                ->first();

            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function normalizeTelegramAuthPayload(array $authPayload): array
    {
        $normalized = [];
        foreach ($authPayload as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }
            $normalized[$key] = trim((string) $value);
        }
        return $normalized;
    }

    private static function buildTelegramDataCheckString(array $payload): string
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($key === 'hash' || $value === '') {
                continue;
            }
            $pairs[$key] = $key . '=' . $value;
        }
        ksort($pairs, SORT_STRING);
        return implode("\n", array_values($pairs));
    }

    private static function normalizeTelegramUsername(string $username): string
    {
        $username = trim($username);
        $username = ltrim($username, '@');
        if ($username === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_]{5,64}$/', $username)) {
            return '';
        }
        return strtolower($username);
    }

    private static function ensureTelegramBindingTable(): void
    {
        if (class_exists('CfTelegramGroupRewardService')) {
            try {
                CfTelegramGroupRewardService::ensureTables();
                return;
            } catch (\Throwable $e) {
            }
        }

        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable('mod_cloudflare_telegram_reward_bindings')) {
                $schema->create('mod_cloudflare_telegram_reward_bindings', function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->bigInteger('telegram_user_id')->unsigned()->unique();
                    $table->string('telegram_username', 64)->nullable();
                    $table->string('first_name', 255)->nullable();
                    $table->string('last_name', 255)->nullable();
                    $table->string('photo_url', 255)->nullable();
                    $table->integer('auth_date')->unsigned()->default(0);
                    $table->timestamps();
                    $table->index(['userid'], 'idx_cf_tg_binding_user');
                    $table->index(['telegram_user_id'], 'idx_cf_tg_binding_telegram_user');
                });
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 批量为老用户自动解锁（向后兼容迁移）
     * 返回已自动解锁的用户数量
     */
    public static function migrateExistingUsers(): int
    {
        self::ensureTables();
        $unlocked = 0;
        $now = date('Y-m-d H:i:s');

        try {
            // 获取所有未解锁的记录
            $unlockedRecords = Capsule::table(self::TABLE_UNLOCK)
                ->whereNull('unlocked_at')
                ->get();

            foreach ($unlockedRecords as $record) {
                $userId = (int) $record->userid;
                if (self::isExistingUser($userId)) {
                    Capsule::table(self::TABLE_UNLOCK)
                        ->where('id', $record->id)
                        ->update([
                            'unlocked_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $unlocked++;
                }
            }

            // 获取系统中所有有真实业务活动的用户，为他们创建解锁记录
            $existingUserIds = [];

            // 从子域名表获取用户
            if (Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                $subdomainUsers = Capsule::table('mod_cloudflare_subdomain')
                    ->distinct()
                    ->pluck('userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $subdomainUsers);
            }

            // 从邀请使用记录表获取用户（邀请人/被邀请人）
            if (Capsule::schema()->hasTable('mod_cloudflare_invitation_claims')) {
                $inviterUsers = Capsule::table('mod_cloudflare_invitation_claims')
                    ->distinct()
                    ->pluck('inviter_userid')
                    ->toArray();
                $inviteeUsers = Capsule::table('mod_cloudflare_invitation_claims')
                    ->distinct()
                    ->pluck('invitee_userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $inviterUsers, $inviteeUsers);
            }

            // 从 DNS 解锁码表获取用户
            if (Capsule::schema()->hasTable('mod_cloudflare_dns_unlock_codes')) {
                $unlockUsers = Capsule::table('mod_cloudflare_dns_unlock_codes')
                    ->distinct()
                    ->pluck('userid')
                    ->toArray();
                $existingUserIds = array_merge($existingUserIds, $unlockUsers);
            }

            $existingUserIds = array_unique(array_filter($existingUserIds));

            // 为这些用户创建解锁记录
            foreach ($existingUserIds as $userId) {
                $userId = (int) $userId;
                if ($userId <= 0) continue;

                $exists = Capsule::table(self::TABLE_UNLOCK)
                    ->where('userid', $userId)
                    ->exists();
                if (!$exists) {
                    // 创建配置（会自动检测并解锁老用户）
                    self::createProfile($userId);
                    $unlocked++;
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }

        return $unlocked;
    }

    /**
     * 获取后台管理日志
     */
    public static function fetchAdminLogs(string $search, int $page, int $perPage = 20): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $search = trim($search);

        try {
            $query = Capsule::table(self::TABLE_LOGS . ' as l')
                ->leftJoin(self::TABLE_UNLOCK . ' as u', 'l.invite_code_id', '=', 'u.id')
                ->leftJoin('tblclients as inviter', 'l.inviter_userid', '=', 'inviter.id')
                ->leftJoin('tblclients as invitee', 'l.invitee_userid', '=', 'invitee.id')
                ->leftJoin(self::TABLE_GITHUB_BINDINGS . ' as igb', 'l.invitee_userid', '=', 'igb.userid')
                ->leftJoin(self::TABLE_TELEGRAM_BINDINGS . ' as itb', 'l.invitee_userid', '=', 'itb.userid')
                ->leftJoin('mod_cloudflare_github_star_oauth_bindings as gsob', 'l.invitee_userid', '=', 'gsob.userid')
                ->select(
                    'l.*',
                    'u.invite_code as current_code',
                    'inviter.email as inviter_email',
                    'inviter.id as inviter_id',
                    'invitee.email as invitee_account_email',
                    'igb.github_login as invitee_github_login',
                    'igb.github_name as invitee_github_name',
                    'itb.telegram_username as invitee_telegram_username',
                    'itb.telegram_user_id as invitee_telegram_user_id',
                    'itb.verify_source as telegram_verify_source',
                    'itb.is_group_member as telegram_is_group_member',
                    'itb.group_verified_at as telegram_group_verified_at',
                    'itb.group_chat_id as telegram_group_chat_id',
                    'gsob.github_login as invitee_github_star_login',
                    'gsob.github_id as invitee_github_star_id'
                );

            if ($search !== '') {
                if (strpos($search, '@') !== false) {
                    $like = '%' . $search . '%';
                    $query->where(function ($q) use ($like) {
                        $q->where('l.invitee_email', 'like', $like)
                            ->orWhere('inviter.email', 'like', $like)
                            ->orWhere('invitee.email', 'like', $like)
                            ->orWhere('igb.github_login', 'like', $like)
                            ->orWhere('igb.github_name', 'like', $like)
                            ->orWhere('gsob.github_login', 'like', $like)
                            ->orWhere('itb.telegram_username', 'like', $like)
                            ->orWhere('l.invitee_email', 'like', $like);
                    });
                } else {
                    $like = '%' . $search . '%';
                    $query->where(function ($q) use ($search, $like) {
                        $q->whereRaw('UPPER(l.invite_code) LIKE ?', [strtoupper($search) . '%'])
                            ->orWhere('igb.github_login', 'like', $like)
                            ->orWhere('igb.github_name', 'like', $like)
                            ->orWhere('gsob.github_login', 'like', $like)
                            ->orWhere('itb.telegram_username', 'like', $like)
                            ->orWhere('l.invitee_email', 'like', $like)
                            ->orWhere('invitee.email', 'like', $like)
                            ->orWhere('inviter.email', 'like', $like);
                        if (ctype_digit($search)) {
                            $uid = (int) $search;
                            $q->orWhere('l.invitee_userid', $uid)
                              ->orWhere('l.inviter_userid', $uid)
                              ->orWhere('itb.telegram_user_id', $uid)
                              ->orWhere('gsob.github_id', $uid);
                        }
                    });
                }
            }

            $total = $query->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $rows = $query
                ->orderBy('l.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'id' => (int) ($row->id ?? 0),
                    'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
                    'inviter_userid' => (int) ($row->inviter_userid ?? 0),
                    'inviter_email' => (string) ($row->inviter_email ?? ''),
                    'invitee_userid' => (int) ($row->invitee_userid ?? 0),
                    'invitee_email' => (string) ($row->invitee_email ?? ($row->invitee_account_email ?? '')),
                    'invitee_ip' => (string) ($row->invitee_ip ?? ''),
                    'invitee_github_login' => (string) ($row->invitee_github_login ?? ''),
                    'invitee_github_name' => (string) ($row->invitee_github_name ?? ''),
                    'invitee_github_star_login' => (string) ($row->invitee_github_star_login ?? ''),
                    'invitee_telegram_username' => (string) ($row->invitee_telegram_username ?? ''),
                    'invitee_telegram_user_id' => (int) ($row->invitee_telegram_user_id ?? 0),
                    'verify_source' => (string) ($row->telegram_verify_source ?? ''),
                    'is_group_member' => (int) ($row->telegram_is_group_member ?? 0),
                    'group_verified_at' => (string) ($row->telegram_group_verified_at ?? ''),
                    'group_chat_id' => (string) ($row->telegram_group_chat_id ?? ''),
                    'created_at' => $row->created_at ?? '',
                ];
            }

            return [
                'items' => $items,
                'search' => $search,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'totalPages' => $totalPages,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'search' => $search,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => 0,
                    'totalPages' => 1,
                ],
            ];
        }
    }

    /**
     * 获取用户的邀请历史记录
     */
    public static function fetchUserLogs(int $userId, int $page, int $perPage = 10): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        try {
            $query = Capsule::table(self::TABLE_LOGS . ' as l')
                ->leftJoin('tblclients as c', 'l.invitee_userid', '=', 'c.id')
                ->select('l.*', 'c.email as joined_email')
                ->where('l.inviter_userid', $userId);

            $total = $query->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $logs = $query
                ->orderBy('l.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $items = [];
            foreach ($logs as $log) {
                $email = $log->invitee_email ?: ($log->joined_email ?? '');
                $items[] = [
                    'id' => (int) $log->id,
                    'email' => $email,
                    'email_masked' => self::maskEmail($email),
                    'invitee_ip' => (string) ($log->invitee_ip ?? ''),
                    'invite_code' => strtoupper((string) ($log->invite_code ?? '')),
                    'created_at' => $log->created_at,
                ];
            }

            return [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'totalPages' => $totalPages,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'pagination' => [
                    'page' => 1,
                    'perPage' => $perPage,
                    'total' => 0,
                    'totalPages' => 1,
                ],
            ];
        }
    }

    /**
     * 获取用户邀请成功的总数
     */
    public static function getUserInviteCount(int $userId): int
    {
        self::ensureTables();

        try {
            return Capsule::table(self::TABLE_LOGS)
                ->where('inviter_userid', $userId)
                ->where(function ($query) {
                    $query->whereNull('invitee_ip')
                        ->orWhere('invitee_ip', '=', '')
                        ->orWhere(function ($subQuery) {
                            $subQuery->where('invitee_ip', 'not like', 'user_manual_invalidate%')
                                ->where('invitee_ip', 'not like', 'system_%');
                        });
                })
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 检查邀请人是否达到邀请上限
     */
    public static function checkInviterLimit(int $inviterId): bool
    {
        $maxLimit = self::getInviterMaxInviteLimit($inviterId);
        if ($maxLimit <= 0) {
            return true; // 0 表示不限制
        }

        $currentCount = self::getUserInviteCount($inviterId);
        return $currentCount < $maxLimit;
    }

    /**
     * 获取每个用户最多可生成多少次邀请码
     */
    public static function getMaxInviteCodesPerUser(): int
    {
        try {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $value = Capsule::table('tbladdonmodules')
                ->where('module', $moduleSlug)
                ->where('setting', 'invite_registration_max_per_user')
                ->value('value');
            return max(0, intval($value ?? 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function getInviterMinMonthsRequirement(): int
    {
        $policy = self::resolveInviterQuotaPolicy(0);
        return intval($policy['required_months'] ?? self::resolveInviterMinMonths());
    }

    public static function getInviterMaxInviteLimit(int $inviterId): int
    {
        if (self::isPrivilegedUnlimitedInviter($inviterId)) {
            return defined('CF_PRIVILEGED_MAX_SUBDOMAIN') ? CF_PRIVILEGED_MAX_SUBDOMAIN : 99999999999;
        }
        $policy = self::resolveInviterQuotaPolicy($inviterId);
        return max(0, intval($policy['max_limit'] ?? self::getMaxInviteCodesPerUser()));
    }

    public static function getInviterRemainingQuota(int $inviterId): int
    {
        $maxLimit = self::getInviterMaxInviteLimit($inviterId);
        if ($maxLimit <= 0) {
            return PHP_INT_MAX;
        }
        self::ensureTables();
        $usedCount = self::getUserInviteCount($inviterId);
        $unusedCount = 0;
        try {
            $unusedCount = (int) Capsule::table(self::TABLE_CODE_POOL)
                ->where('owner_userid', $inviterId)
                ->where('status', 'unused')
                ->count();
        } catch (\Throwable $e) {
            $unusedCount = 0;
        }
        return max(0, $maxLimit - $usedCount - $unusedCount);
    }

    private static function shouldExpireUnusedCodesForActualLimit(int $inviterId): bool
    {
        if ($inviterId <= 0) {
            return false;
        }

        $maxLimit = self::getInviterMaxInviteLimit($inviterId);
        if ($maxLimit <= 0) {
            return false;
        }

        return self::getUserInviteCount($inviterId) >= $maxLimit;
    }

    public static function getGenerateBatchMax(): int
    {
        try {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $value = Capsule::table('tbladdonmodules')
                ->where('module', $moduleSlug)
                ->where('setting', 'invite_registration_generate_batch_max')
                ->value('value');
            $max = (int) ($value ?? 50);
            return max(1, min(1000, $max));
        } catch (\Throwable $e) {
            return 50;
        }
    }

    public static function getInviterCodeEligibility(int $inviterId): array
    {
        if (self::isPrivilegedUnlimitedInviter($inviterId)) {
            return [
                'eligible' => true,
                'reason' => 'privileged_unlimited',
                'required_months' => 0,
                'current_months' => 0,
                'reference_time' => null,
            ];
        }
        $policy = self::resolveInviterQuotaPolicy($inviterId);
        $requiredMonths = intval($policy['required_months'] ?? 0);
        if ($requiredMonths <= 0) {
            return [
                'eligible' => true,
                'reason' => 'none',
                'required_months' => 0,
                'current_months' => 0,
                'reference_time' => null,
            ];
        }

        $referenceTime = self::resolveInviterOldestSubdomainAt($inviterId);
        if ($referenceTime === null) {
            return [
                'eligible' => false,
                'reason' => 'min_months',
                'required_months' => $requiredMonths,
                'current_months' => 0,
                'reference_time' => null,
            ];
        }

        $currentMonths = self::calculateAgeMonths($referenceTime);

        $eligible = $currentMonths >= $requiredMonths;
        return [
            'eligible' => $eligible,
            'reason' => $eligible ? 'none' : 'min_months',
            'required_months' => $requiredMonths,
            'current_months' => $currentMonths,
            'reference_time' => $referenceTime,
        ];
    }

    private static function isPrivilegedUnlimitedInviter(int $inviterId): bool
    {
        if ($inviterId <= 0) {
            return false;
        }
        $isPrivileged = function_exists('cf_is_user_privileged') && cf_is_user_privileged($inviterId);
        if (!$isPrivileged) {
            return false;
        }
        return function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('unlimited_invite_generation');
    }

    private static function resolveInviterQuotaPolicy(int $inviterId): array
    {
        $requiredMonths = self::resolveInviterMinMonths();
        $maxLimit = self::getMaxInviteCodesPerUser();
        $tieredEnabled = false;
        $settings = [];
        try {
            if (function_exists('cf_get_module_settings_cached')) {
                $raw = cf_get_module_settings_cached();
                if (is_array($raw)) {
                    $settings = $raw;
                }
            }
        } catch (\Throwable $e) {}
        $overrideLimit = self::resolveUserInviteLimitOverride($inviterId, $settings);
        if ($overrideLimit !== null) {
            return ['required_months' => 0, 'max_limit' => $overrideLimit, 'tiered_enabled' => true];
        }
        $tieredEnabled = in_array(strtolower(trim((string) ($settings['invite_registration_tiered_policy_enabled'] ?? '0'))), ['1','on','yes','true','enabled'], true);
        if (!$tieredEnabled) {
            return ['required_months' => $requiredMonths, 'max_limit' => $maxLimit, 'tiered_enabled' => false];
        }
        $rulesRaw = trim((string) ($settings['invite_registration_tiered_policy_rules'] ?? ''));
        $rules = self::parseTieredInviteRules($rulesRaw);
        if (empty($rules)) {
            return ['required_months' => $requiredMonths, 'max_limit' => $maxLimit, 'tiered_enabled' => false];
        }
        $currentMonths = 0;
        if ($inviterId > 0) {
            $ref = self::resolveInviterOldestSubdomainAt($inviterId);
            $currentMonths = $ref ? self::calculateAgeMonths($ref) : 0;
        }
        $best = null;
        foreach ($rules as $rule) {
            if ($currentMonths >= $rule['months']) {
                $best = $rule;
            }
        }
        $requiredFromRules = intval($rules[0]['months'] ?? 0);
        if ($best === null) {
            return ['required_months' => $requiredFromRules, 'max_limit' => 0, 'tiered_enabled' => true];
        }
        return [
            'required_months' => intval($best['months']),
            'max_limit' => intval($best['limit']),
            'tiered_enabled' => true,
        ];
    }

    private static function parseTieredInviteRules(string $rulesRaw): array
    {
        if ($rulesRaw === '') {
            return [];
        }
        $items = preg_split('/[\r\n,;]+/', $rulesRaw) ?: [];
        $rules = [];
        foreach ($items as $item) {
            $entry = trim((string) $item);
            if ($entry === '' || strpos($entry, ':') === false) {
                continue;
            }
            [$monthsRaw, $limitRaw] = array_map('trim', explode(':', $entry, 2));
            if (!is_numeric($monthsRaw) || !is_numeric($limitRaw)) {
                continue;
            }
            $months = max(0, min(240, intval($monthsRaw)));
            $limit = max(0, min(999999999, intval($limitRaw)));
            $rules[] = ['months' => $months, 'limit' => $limit];
        }
        if (empty($rules)) {
            return [];
        }
        usort($rules, static function ($a, $b) {
            if ($a['months'] === $b['months']) {
                return $a['limit'] <=> $b['limit'];
            }
            return $a['months'] <=> $b['months'];
        });
        return array_values($rules);
    }

    private static function resolveUserInviteLimitOverride(int $inviterId, array $settings): ?int
    {
        if ($inviterId <= 0) {
            return null;
        }
        $raw = trim((string) ($settings['invite_registration_user_limit_overrides'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $entries = preg_split('/[\r\n,;]+/', $raw) ?: [];
        foreach ($entries as $entryRaw) {
            $entry = trim((string) $entryRaw);
            if ($entry === '' || strpos($entry, ':') === false) {
                continue;
            }
            [$userIdRaw, $limitRaw] = array_map('trim', explode(':', $entry, 2));
            if (!is_numeric($userIdRaw) || !is_numeric($limitRaw)) {
                continue;
            }
            if (intval($userIdRaw) !== $inviterId) {
                continue;
            }
            return max(0, min(999999999, intval($limitRaw)));
        }
        return null;
    }

    private static function inviterMeetsMinimumMonths(int $inviterId): bool
    {
        $eligibility = self::getInviterCodeEligibility($inviterId);
        return !empty($eligibility['eligible']);
    }

    private static function resolveInviterMinMonths(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $months = 0;
        try {
            if (function_exists('cf_get_module_settings_cached')) {
                $settings = cf_get_module_settings_cached();
                if (is_array($settings)) {
                    $months = intval($settings['invite_registration_inviter_min_months'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
        }

        if ($months <= 0) {
            try {
                $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
                $legacySlug = defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain';
                $value = Capsule::table('tbladdonmodules')
                    ->where('module', $moduleSlug)
                    ->where('setting', 'invite_registration_inviter_min_months')
                    ->value('value');

                if ($value === null && $legacySlug !== $moduleSlug) {
                    $value = Capsule::table('tbladdonmodules')
                        ->where('module', $legacySlug)
                        ->where('setting', 'invite_registration_inviter_min_months')
                        ->value('value');
                }

                $months = intval($value ?? 0);
            } catch (\Throwable $e) {
                $months = 0;
            }
        }

        if ($months < 0) {
            $months = 0;
        }
        if ($months > 240) {
            $months = 240;
        }

        $cached = $months;
        return $months;
    }

    private static function resolveInviterOldestSubdomainAt(int $inviterId): ?string
    {
        if ($inviterId <= 0) {
            return null;
        }

        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                return null;
            }

            $oldest = Capsule::table('mod_cloudflare_subdomain')
                ->where('userid', $inviterId)
                ->whereNotNull('created_at')
                ->orderBy('created_at', 'asc')
                ->value('created_at');

            if ($oldest !== null && trim((string) $oldest) !== '') {
                return (string) $oldest;
            }

            $hasSubdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('userid', $inviterId)
                ->exists();
            if (!$hasSubdomain) {
                return null;
            }

            $fallback = Capsule::table('tblclients')
                ->where('id', $inviterId)
                ->value('datecreated');
            if ($fallback !== null && trim((string) $fallback) !== '') {
                return (string) $fallback;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private static function calculateAgeMonths(string $dateTime): int
    {
        try {
            $createdAt = new \DateTimeImmutable($dateTime);
            $now = new \DateTimeImmutable('now', $createdAt->getTimezone());
            if ($createdAt > $now) {
                return 0;
            }
            $diff = $createdAt->diff($now);
            return max(0, ((int) $diff->y * 12) + (int) $diff->m);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 邮箱脱敏
     */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || strpos($email, '@') === false) {
            return $email !== '' ? $email : '-';
        }
        [$user, $domain] = explode('@', $email, 2);
        $userLen = strlen($user);
        if ($userLen <= 2) {
            $maskedUser = substr($user, 0, 1) . '*';
        } else {
            $maskedUser = substr($user, 0, 2) . str_repeat('*', max(1, $userLen - 3)) . substr($user, -1);
        }
        $domainParts = explode('.', $domain);
        $maskedDomainParts = array_map(function ($part) {
            $len = strlen($part);
            if ($len <= 2) {
                return substr($part, 0, 1) . '*';
            }
            return substr($part, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($part, -1);
        }, $domainParts);
        return $maskedUser . '@' . implode('.', $maskedDomainParts);
    }

    /**
     * 邀请码脱敏
     */
    public static function maskInviteCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '***';
        }
        $len = strlen($code);
        $maskLen = 5;
        if ($len <= $maskLen) {
            return str_repeat('*', min($maskLen, $len));
        }
        $maxPrefix = min(3, max(0, $len - $maskLen - 1));
        $prefixLen = $maxPrefix;
        $suffixLen = $len - $prefixLen - $maskLen;
        if ($suffixLen < 1) {
            $suffixLen = 1;
            $prefixLen = max(0, $len - $suffixLen - $maskLen);
        }
        $prefix = $prefixLen > 0 ? substr($code, 0, $prefixLen) : '';
        $suffix = $suffixLen > 0 ? substr($code, -$suffixLen) : '';
        return $prefix . str_repeat('*', $maskLen) . $suffix;
    }

    private static function hasUnlockAuditTrail(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            return Capsule::table(self::TABLE_LOGS)
                ->where('invitee_userid', $userId)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 创建用户配置
     * 向后兼容：已有真实业务活动的老用户自动解锁
     */
    private static function createProfile(int $userId)
    {
        $code = self::generateUniqueCode();
        $now = date('Y-m-d H:i:s');
        
        // 向后兼容检查：判断是否为老用户
        $isExistingUser = self::isExistingUser($userId);
        
        $data = [
            'userid' => $userId,
            'invite_code' => $code,
            'code_generate_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        
        // 老用户自动解锁
        if ($isExistingUser) {
            $data['unlocked_at'] = $now;
        }
        
        $id = Capsule::table(self::TABLE_UNLOCK)->insertGetId($data);
        return Capsule::table(self::TABLE_UNLOCK)->where('id', $id)->first();
    }

    /**
     * 检查是否为老用户（向后兼容）
     * 满足以下任一条件即视为老用户，自动解锁：
     * 1. 已注册过子域名
     * 2. 有邀请使用记录（作为邀请人或被邀请人）
     * 3. 有DNS解锁记录
     * 4.（已移除）用户统计/插件日志不再作为判定依据，避免新用户仅访问页面即被误判为老用户
     */
    private static function isExistingUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            // 检查是否注册过子域名
            if (Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                $hasSubdomain = Capsule::table('mod_cloudflare_subdomain')
                    ->where('userid', $userId)
                    ->exists();
                if ($hasSubdomain) {
                    return true;
                }
            }

            // 注意：不再使用配额表/邀请码表作为“老用户”判定依据。
            // 这两类记录会在用户首次访问时自动创建，无法用于区分新老用户。

            // 检查是否有邀请使用记录（作为邀请人或被邀请人）
            if (Capsule::schema()->hasTable('mod_cloudflare_invitation_claims')) {
                $hasInviteClaim = Capsule::table('mod_cloudflare_invitation_claims')
                    ->where('inviter_userid', $userId)
                    ->orWhere('invitee_userid', $userId)
                    ->exists();
                if ($hasInviteClaim) {
                    return true;
                }
            }

            // 检查是否有DNS解锁记录
            if (Capsule::schema()->hasTable('mod_cloudflare_dns_unlock_codes')) {
                $hasDnsUnlock = Capsule::table('mod_cloudflare_dns_unlock_codes')
                    ->where('userid', $userId)
                    ->exists();
                if ($hasDnsUnlock) {
                    return true;
                }
            }

        } catch (\Throwable $e) {
            // 查询出错时默认为新用户，需要邀请码
            return false;
        }

        return false;
    }

    /**
     * 通过邀请码查找配置
     */
    private static function findProfileByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        try {
            $row = Capsule::table(self::TABLE_UNLOCK)->where('invite_code', $code)->first();
            return $row ? self::normalizeRow($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 轮换邀请码
     */
    private static function rotateInviteCode(int $profileId): ?string
    {
        if ($profileId <= 0) {
            return null;
        }
        try {
            $newCode = self::generateUniqueCode();
            Capsule::table(self::TABLE_UNLOCK)
                ->where('id', $profileId)
                ->update([
                    'invite_code' => $newCode,
                    'code_generate_count' => Capsule::raw('code_generate_count + 1'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            return $newCode;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 生成唯一邀请码
     */
    private static function generateUniqueCode(): string
    {
        do {
            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $maxIndex = strlen($characters) - 1;
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $characters[random_int(0, $maxIndex)];
            }
            $exists = Capsule::table(self::TABLE_UNLOCK)->where('invite_code', $code)->exists()
                || Capsule::table(self::TABLE_CODE_POOL)->where('invite_code', $code)->exists();
        } while ($exists);
        return $code;
    }

    /**
     * 标准化数据库行
     */
    private static function normalizeRow($row): array
    {
        if (!$row) {
            return [
                'id' => 0,
                'userid' => 0,
                'invite_code' => '',
                'code_generate_count' => 0,
                'unlocked_at' => null,
            ];
        }
        return [
            'id' => (int) ($row->id ?? 0),
            'userid' => (int) ($row->userid ?? 0),
            'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
            'code_generate_count' => (int) ($row->code_generate_count ?? 0),
            'unlocked_at' => $row->unlocked_at ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * 检查邀请人是否可以分享邀请码
     */
    private static function inviterCanShare(int $inviterId): bool
    {
        if ($inviterId <= 0) {
            return false;
        }
        try {
            $status = Capsule::table('tblclients')->where('id', $inviterId)->value('status');
            if ($status !== null && strtolower((string) $status) !== 'active') {
                return false;
            }
        } catch (\Throwable $e) {
            // ignore status lookup errors, default to allowing
        }
        try {
            if (function_exists('cfmod_resolve_user_ban_state')) {
                $banState = cfmod_resolve_user_ban_state($inviterId);
                if (!empty($banState['is_banned'])) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // ignore ban lookup errors
        }
        return true;
    }
}
