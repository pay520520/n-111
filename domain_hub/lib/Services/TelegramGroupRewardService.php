<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfTelegramGroupRewardException extends \RuntimeException
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

class CfTelegramGroupRewardService
{
    private const HISTORY_PER_PAGE = 10;
    private const TABLE_REWARDS = 'mod_cloudflare_telegram_group_rewards';
    private const TABLE_BINDINGS = 'mod_cloudflare_telegram_reward_bindings';
    private const TABLE_BIND_SESSIONS = 'mod_cloudflare_telegram_bind_sessions';
    private const TELEGRAM_API_BASE = 'https://api.telegram.org';
    private const VERIFY_SOURCE_WIDGET = 'widget';
    private const VERIFY_SOURCE_BOT = 'bot';
    private const VERIFY_SOURCE_GROUP = 'group';

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE_REWARDS)) {
                $schema->create(self::TABLE_REWARDS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->string('group_link', 255)->default('');
                    $table->string('group_hash', 64)->default('');
                    $table->bigInteger('telegram_user_id')->unsigned();
                    $table->string('telegram_username', 64)->default('');
                    $table->integer('reward_amount')->unsigned()->default(0);
                    $table->integer('before_quota')->unsigned()->default(0);
                    $table->integer('after_quota')->unsigned()->default(0);
                    $table->string('status', 20)->default('granted');
                    $table->string('client_ip', 45)->nullable();
                    $table->string('user_agent', 255)->nullable();
                    $table->timestamps();
                    $table->index(['userid', 'id'], 'idx_cf_tg_reward_user_id');
                    $table->index(['group_hash'], 'idx_cf_tg_reward_group_hash');
                    $table->index(['group_hash', 'telegram_user_id'], 'idx_cf_tg_reward_group_telegram');
                    $table->unique(['userid', 'group_hash', 'status'], 'uniq_cf_tg_reward_user_group_status');
                });
            }

            if (!$schema->hasTable(self::TABLE_BINDINGS)) {
                $schema->create(self::TABLE_BINDINGS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->bigInteger('telegram_user_id')->unsigned()->unique();
                    $table->string('telegram_username', 64)->nullable();
                    $table->string('first_name', 255)->nullable();
                    $table->string('last_name', 255)->nullable();
                    $table->string('photo_url', 255)->nullable();
                    $table->integer('auth_date')->unsigned()->default(0);
                    $table->string('verify_source', 16)->default('widget');
                    $table->tinyInteger('is_group_member')->unsigned()->default(0);
                    $table->dateTime('group_verified_at')->nullable();
                    $table->string('group_chat_id', 64)->nullable();
                    $table->timestamps();
                    $table->index(['userid'], 'idx_cf_tg_binding_user');
                    $table->index(['telegram_user_id'], 'idx_cf_tg_binding_telegram_user');
                });
            }

            if (!$schema->hasTable(self::TABLE_BIND_SESSIONS)) {
                $schema->create(self::TABLE_BIND_SESSIONS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->string('bind_token', 96)->unique();
                    $table->string('status', 20)->default('pending');
                    $table->bigInteger('telegram_user_id')->unsigned()->nullable();
                    $table->string('telegram_username', 64)->nullable();
                    $table->string('return_url', 1024)->nullable();
                    $table->dateTime('expires_at')->nullable();
                    $table->dateTime('consumed_at')->nullable();
                    $table->timestamps();
                    $table->index(['userid', 'status'], 'idx_cf_tg_bind_session_user_status');
                    $table->index(['expires_at'], 'idx_cf_tg_bind_session_expires');
                });
            }

            self::ensureColumns();
            self::ensureIndexes();
        } catch (\Throwable $e) {
        }
    }

    private static function ensureColumns(): void
    {
        try {
            $schema = Capsule::schema();
            if ($schema->hasTable(self::TABLE_REWARDS)) {
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'group_link')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('group_link', 255)->default('');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('group_hash', 64)->default('');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->bigInteger('telegram_user_id')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'telegram_username')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('telegram_username', 64)->default('');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'reward_amount')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->integer('reward_amount')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'before_quota')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->integer('before_quota')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'after_quota')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->integer('after_quota')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'status')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('status', 20)->default('granted');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'client_ip')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('client_ip', 45)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'user_agent')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) {
                        $table->string('user_agent', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_REWARDS, 'created_at') || !$schema->hasColumn(self::TABLE_REWARDS, 'updated_at')) {
                    $schema->table(self::TABLE_REWARDS, function ($table) use ($schema) {
                        if (!$schema->hasColumn(self::TABLE_REWARDS, 'created_at')) {
                            $table->timestamp('created_at')->nullable();
                        }
                        if (!$schema->hasColumn(self::TABLE_REWARDS, 'updated_at')) {
                            $table->timestamp('updated_at')->nullable();
                        }
                    });
                }
            }

            if ($schema->hasTable(self::TABLE_BINDINGS)) {
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'telegram_username')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('telegram_username', 64)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'first_name')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('first_name', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'last_name')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('last_name', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'photo_url')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('photo_url', 255)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'auth_date')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->integer('auth_date')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'verify_source')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('verify_source', 16)->default('widget');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'is_group_member')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->tinyInteger('is_group_member')->unsigned()->default(0);
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'group_verified_at')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->dateTime('group_verified_at')->nullable();
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BINDINGS, 'group_chat_id')) {
                    $schema->table(self::TABLE_BINDINGS, function ($table) {
                        $table->string('group_chat_id', 64)->nullable();
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
            }

            if ($schema->hasTable(self::TABLE_BIND_SESSIONS)) {
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'userid')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->integer('userid')->unsigned()->after('id');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'bind_token')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->string('bind_token', 96)->unique()->after('userid');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'status')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->string('status', 20)->default('pending')->after('bind_token');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'telegram_user_id')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->bigInteger('telegram_user_id')->unsigned()->nullable()->after('status');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'telegram_username')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->string('telegram_username', 64)->nullable()->after('telegram_user_id');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'return_url')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->string('return_url', 1024)->nullable()->after('telegram_username');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'expires_at')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->dateTime('expires_at')->nullable()->after('return_url');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'consumed_at')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) {
                        $table->dateTime('consumed_at')->nullable()->after('expires_at');
                    });
                }
                if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'created_at') || !$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'updated_at')) {
                    $schema->table(self::TABLE_BIND_SESSIONS, function ($table) use ($schema) {
                        if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'created_at')) {
                            $table->timestamp('created_at')->nullable();
                        }
                        if (!$schema->hasColumn(self::TABLE_BIND_SESSIONS, 'updated_at')) {
                            $table->timestamp('updated_at')->nullable();
                        }
                    });
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private static function ensureIndexes(): void
    {
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD INDEX `idx_cf_tg_reward_user_id` (`userid`, `id`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD INDEX `idx_cf_tg_reward_group_hash` (`group_hash`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD INDEX `idx_cf_tg_reward_group_telegram` (`group_hash`, `telegram_user_id`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_REWARDS . '` ADD UNIQUE `uniq_cf_tg_reward_user_group_status` (`userid`, `group_hash`, `status`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BINDINGS . '` ADD UNIQUE `uniq_cf_tg_binding_userid` (`userid`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BINDINGS . '` ADD UNIQUE `uniq_cf_tg_binding_telegram_user` (`telegram_user_id`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BIND_SESSIONS . '` ADD UNIQUE `uniq_cf_tg_bind_token` (`bind_token`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BIND_SESSIONS . '` ADD INDEX `idx_cf_tg_bind_session_user_status` (`userid`, `status`)');
        } catch (\Throwable $e) {
        }
        try {
            Capsule::statement('ALTER TABLE `' . self::TABLE_BIND_SESSIONS . '` ADD INDEX `idx_cf_tg_bind_session_expires` (`expires_at`)');
        } catch (\Throwable $e) {
        }
    }

    public static function createBotBindSession(int $userId, array $moduleSettings, int $ttlSeconds = 900, string $returnUrl = ''): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new CfTelegramGroupRewardException('invalid_user');
        }
        $botUsername = self::resolveBotUsername($moduleSettings);
        $botToken = self::resolveBotToken($moduleSettings);
        if ($botUsername === '' || !self::validateBotToken($botToken)) {
            throw new CfTelegramGroupRewardException('invalid_bot_token');
        }

        $ttlSeconds = max(120, min(3600, $ttlSeconds));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $bindToken = 'cfbind_' . bin2hex(random_bytes(18));

        $returnUrl = self::normalizeReturnUrl($returnUrl);
        $targetScene = self::extractBindSceneFromUrl($returnUrl);
        $reusedSession = Capsule::connection()->transaction(function () use ($userId, $now, $expiresAt, $bindToken, $returnUrl, $targetScene) {
            $pendingSessions = Capsule::table(self::TABLE_BIND_SESSIONS)
                ->where('userid', $userId)
                ->where('status', 'pending')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->get();

            $sameSceneSessionIds = [];
            foreach ($pendingSessions as $session) {
                $sessionId = (int) ($session->id ?? 0);
                if ($sessionId <= 0) {
                    continue;
                }
                $existingReturnUrl = trim((string) ($session->return_url ?? ''));
                $existingScene = self::extractBindSceneFromUrl($existingReturnUrl);
                if ($existingScene !== $targetScene) {
                    continue;
                }
                $sameSceneSessionIds[] = $sessionId;

                $existingExpiresAt = trim((string) ($session->expires_at ?? ''));
                if ($existingExpiresAt !== '' && strtotime($existingExpiresAt) !== false && time() <= strtotime($existingExpiresAt)) {
                    return [
                        'bind_token' => (string) ($session->bind_token ?? ''),
                        'expires_at' => $existingExpiresAt,
                    ];
                }
            }

            if (!empty($sameSceneSessionIds)) {
                Capsule::table(self::TABLE_BIND_SESSIONS)
                    ->whereIn('id', $sameSceneSessionIds)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'expired',
                        'updated_at' => $now,
                    ]);
            }

            Capsule::table(self::TABLE_BIND_SESSIONS)->insert([
                'userid' => $userId,
                'bind_token' => $bindToken,
                'status' => 'pending',
                'return_url' => $returnUrl !== '' ? $returnUrl : null,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return null;
        });

        if (is_array($reusedSession) && !empty($reusedSession['bind_token']) && !empty($reusedSession['expires_at'])) {
            return [
                'bind_token' => (string) $reusedSession['bind_token'],
                'bot_username' => $botUsername,
                'bind_url' => 'https://t.me/' . rawurlencode($botUsername) . '?start=' . rawurlencode((string) $reusedSession['bind_token']),
                'expires_at' => (string) $reusedSession['expires_at'],
            ];
        }

        return [
            'bind_token' => $bindToken,
            'bot_username' => $botUsername,
            'bind_url' => 'https://t.me/' . rawurlencode($botUsername) . '?start=' . rawurlencode($bindToken),
            'expires_at' => $expiresAt,
        ];
    }

    public static function handleBotBindWebhook(array $moduleSettings, array $update): array
    {
        self::ensureTables();
        if (!self::isEnabled($moduleSettings)) {
            self::logWebhookTrace('feature_disabled', $update, []);
            return ['ok' => true, 'handled' => false];
        }
        $botToken = self::resolveBotToken($moduleSettings);
        if (!self::validateBotToken($botToken)) {
            self::logWebhookTrace('invalid_bot_token', $update, []);
            return ['ok' => true, 'handled' => false];
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $text = trim((string) ($message['text'] ?? ''));
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        if ($text === '' || empty($from)) {
            self::logWebhookTrace('ignore_empty_message', $update, []);
            return ['ok' => true, 'handled' => false];
        }

        if (!preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{1,128}))?$/', $text, $matches)) {
            self::handleGroupKeywordAutoReply($moduleSettings, $botToken, $message, $text);
            self::logWebhookTrace('ignore_not_start_command', $update, ['text' => $text]);
            return ['ok' => true, 'handled' => false];
        }

        $token = trim((string) ($matches[1] ?? ''));
        $telegramUserId = intval($from['id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }
        $telegramUsername = self::normalizeTelegramUsername((string) ($from['username'] ?? ''));
        $languageCode = strtolower(trim((string) ($from['language_code'] ?? '')));
        $firstName = trim((string) ($from['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? ''));
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = (string) ($chat['id'] ?? '');
        if ($token === '' || strpos($token, 'cfbind_') !== 0) {
            self::sendBotChatMessage(
                $botToken,
                $chatId,
                self::botWebhookText($languageCode, 'missing_bind_token')
            );
            return [
                'ok' => true,
                'handled' => true,
                'reason' => 'missing_bind_token',
                'telegram_user_id' => $telegramUserId,
            ];
        }

        $binding = [];
        $resultReason = 'bound';
        $returnUrl = '';
        try {
            $binding = self::confirmBindByToken(
                $token,
                $telegramUserId,
                $telegramUsername,
                $firstName,
                $lastName,
                $moduleSettings,
                $botToken
            );
            $returnUrl = (string) ($binding['return_url'] ?? '');
            self::sendBotChatMessage(
                $botToken,
                $chatId,
                self::botWebhookText($languageCode, 'bind_success'),
                self::buildBindReturnUrl($returnUrl, true, $resultReason),
                self::botWebhookText($languageCode, 'return_button')
            );
            if (class_exists('CfInviteRegistrationService') && !empty($binding['userid']) && self::shouldIssueInviteCodeForBinding($moduleSettings, $binding, $returnUrl)) {
                try {
                    $issued = CfInviteRegistrationService::issueTelegramBindingShortLivedCode(
                        (int) $binding['userid'],
                        $telegramUserId,
                        $moduleSettings
                    );
                    $inviteCode = strtoupper((string) ($issued['invite_code'] ?? ''));
                    $expiresAt = trim((string) ($issued['expires_at'] ?? ''));
                    if ($inviteCode !== '') {
                        $message = self::buildInviteCodeMessage($moduleSettings, $languageCode, [
                            'code' => $inviteCode,
                            'expires_at' => $expiresAt,
                            'ttl_seconds' => (string) intval($issued['ttl_seconds'] ?? 0),
                        ]);
                        self::sendBotChatMessage($botToken, $chatId, $message);
                    }
                } catch (\Throwable $ignored) {
                }
            }
        } catch (CfTelegramGroupRewardException $e) {
            $resultReason = $e->getReason();
            $errorMessage = self::botWebhookText($languageCode, 'bind_failed');
            if ($resultReason === 'auth_expired') {
                $errorMessage = self::botWebhookText($languageCode, 'auth_expired');
            } elseif ($resultReason === 'telegram_used') {
                $errorMessage = self::botWebhookText($languageCode, 'telegram_used');
            } elseif ($resultReason === 'auth_invalid') {
                $errorMessage = self::botWebhookText($languageCode, 'auth_invalid');
            }
            self::sendBotChatMessage($botToken, $chatId, $errorMessage, self::buildBindReturnUrl($returnUrl, false, $resultReason), self::botWebhookText($languageCode, 'return_button'));
        }

        return [
            'ok' => true,
            'handled' => true,
            'reason' => $resultReason,
            'userid' => (int) ($binding['userid'] ?? 0),
            'telegram_user_id' => $telegramUserId,
        ];
    }

    private static function handleGroupKeywordAutoReply(array $moduleSettings, string $botToken, array $message, string $rawText): void
    {
        if (!self::isKeywordAutoReplyEnabled($moduleSettings)) {
            return;
        }
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatType = strtolower(trim((string) ($chat['type'] ?? '')));
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            return;
        }
        $chatId = trim((string) ($chat['id'] ?? ''));
        if ($chatId === '' || !preg_match('/^-?[0-9]{5,20}$/', $chatId)) {
            return;
        }
        $allowedChatIds = self::parseKeywordReplyAllowedChatIds($moduleSettings);
        if (!empty($allowedChatIds) && !in_array($chatId, $allowedChatIds, true)) {
            return;
        }

        $rules = self::parseKeywordReplyRules($moduleSettings);
        if (empty($rules)) {
            return;
        }
        $matched = self::matchKeywordReplyRule($rules, $rawText);
        if (empty($matched['reply']) || empty($matched['rule_id'])) {
            return;
        }

        $cooldownSeconds = max(10, min(300, intval($moduleSettings['telegram_group_keyword_reply_cooldown_seconds'] ?? 30)));
        if (self::isKeywordReplyInCooldown($chatId, (string) $matched['rule_id'], $cooldownSeconds)) {
            return;
        }

        self::sendBotChatMessage($botToken, $chatId, (string) $matched['reply']);
    }

    private static function isKeywordAutoReplyEnabled(array $moduleSettings): bool
    {
        $raw = strtolower(trim((string) ($moduleSettings['telegram_group_keyword_reply_enabled'] ?? '0')));
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    private static function parseKeywordReplyAllowedChatIds(array $moduleSettings): array
    {
        $raw = (string) ($moduleSettings['telegram_group_keyword_reply_chat_ids'] ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $ids = [];
        foreach ($parts as $part) {
            $id = trim((string) $part);
            if ($id !== '' && preg_match('/^-?[0-9]{5,20}$/', $id)) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private static function parseKeywordReplyRules(array $moduleSettings): array
    {
        $raw = trim((string) ($moduleSettings['telegram_group_keyword_reply_rules_json'] ?? ''));
        if ($raw === '') {
            return [[
                'id' => 'delegated_default',
                'keywords' => ['已委派', '委派', 'delegated'],
                'match_mode' => 'contains',
                'reply' => '已委派：表示该域名当前使用的是非 DNSHE 的 DNS 服务器（NS）。如需使用 DNSHE 解析，请将 NS 修改为系统提供的 DNSHE 服务器地址。',
            ]];
        }

        $decoded = null;
        $firstChar = substr($raw, 0, 1);
        if ($firstChar === '[') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
        } else {
            $decoded = [];
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $lineDecoded = json_decode($line, true);
                if (is_array($lineDecoded)) {
                    $decoded[] = $lineDecoded;
                    continue;
                }

                // 兼容简易配置：每行 “关键词,回复内容” 或 “关键词，回复内容”
                // 示例：
                // 已委派,已委派：表示该域名当前使用的是非 DNSHE 的 DNS 服务器（NS）。
                $parts = preg_split('/\s*[，,]\s*/u', $line, 2);
                if (is_array($parts) && count($parts) === 2) {
                    $keyword = trim((string) ($parts[0] ?? ''));
                    $reply = trim((string) ($parts[1] ?? ''));
                    if ($keyword !== '' && $reply !== '') {
                        $decoded[] = [
                            'keywords' => [$keyword],
                            'match_mode' => 'contains',
                            'reply' => $reply,
                        ];
                    }
                }
            }
        }

        $rules = [];
        foreach ($decoded as $rule) {
            if (is_array($rule) && isset($rule[0]) && is_array($rule[0])) {
                foreach ($rule as $nestedRule) {
                    if (is_array($nestedRule)) {
                        $rules[] = $nestedRule;
                    }
                }
                continue;
            }
            if (is_array($rule)) {
                $rules[] = $rule;
            }
        }

        $normalizedRules = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $reply = trim((string) ($rule['reply'] ?? ''));
            if ($reply === '') {
                continue;
            }
            $ruleId = trim((string) ($rule['id'] ?? ''));
            if ($ruleId === '') {
                $ruleId = 'rule_' . substr(sha1($reply), 0, 10);
            }
            $keywords = $rule['keywords'] ?? [];
            if (!is_array($keywords)) {
                $keywords = [trim((string) $keywords)];
            }
            $normalizedKeywords = [];
            foreach ($keywords as $keyword) {
                $keyword = trim((string) $keyword);
                if ($keyword !== '') {
                    $normalizedKeywords[] = $keyword;
                }
            }
            if (empty($normalizedKeywords)) {
                continue;
            }
            $matchMode = strtolower(trim((string) ($rule['match_mode'] ?? 'contains')));
            if (!in_array($matchMode, ['contains', 'exact'], true)) {
                $matchMode = 'contains';
            }
            $normalizedRules[] = [
                'id' => $ruleId,
                'keywords' => $normalizedKeywords,
                'match_mode' => $matchMode,
                'reply' => $reply,
            ];
        }
        return $normalizedRules;
    }

    private static function matchKeywordReplyRule(array $rules, string $text): array
    {
        $rawText = trim($text);
        if ($rawText === '') {
            return [];
        }
        $normalizedText = function_exists('mb_strtolower') ? mb_strtolower($rawText, 'UTF-8') : strtolower($rawText);
        foreach ($rules as $rule) {
            $reply = (string) ($rule['reply'] ?? '');
            $ruleId = (string) ($rule['id'] ?? '');
            $mode = (string) ($rule['match_mode'] ?? 'contains');
            $keywords = is_array($rule['keywords'] ?? null) ? $rule['keywords'] : [];
            foreach ($keywords as $keywordRaw) {
                $keyword = trim((string) $keywordRaw);
                if ($keyword === '') {
                    continue;
                }
                $needle = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
                $matched = false;
                if ($mode === 'exact') {
                    $matched = $normalizedText === $needle;
                } else {
                    $matched = function_exists('mb_strpos')
                        ? mb_strpos($normalizedText, $needle, 0, 'UTF-8') !== false
                        : strpos($normalizedText, $needle) !== false;
                }
                if ($matched) {
                    return ['rule_id' => $ruleId, 'reply' => $reply];
                }
            }
        }
        return [];
    }

    private static function isKeywordReplyInCooldown(string $chatId, string $ruleId, int $cooldownSeconds): bool
    {
        try {
            $now = time();
            $lockKey = 'tg_kw_reply:' . $chatId . ':' . substr(sha1($ruleId), 0, 20);
            $row = Capsule::table('mod_cloudflare_job_locks')->where('lock_key', $lockKey)->first();
            if ($row && !empty($row->locked_until)) {
                $lockedUntilTs = strtotime((string) $row->locked_until);
                if ($lockedUntilTs !== false && $lockedUntilTs > $now) {
                    return true;
                }
            }
            $lockedUntil = date('Y-m-d H:i:s', $now + max(1, $cooldownSeconds));
            $payload = [
                'lock_key' => $lockKey,
                'locked_until' => $lockedUntil,
                'meta_json' => json_encode(['type' => 'telegram_keyword_reply'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ];
            Capsule::table('mod_cloudflare_job_locks')->updateOrInsert(['lock_key' => $lockKey], $payload);
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function logWebhookTrace(string $stage, array $update, array $extra): void
    {
        if (!function_exists('cloudflare_subdomain_log')) {
            return;
        }
        $settings = cfmod_get_settings();
        if (!self::shouldLogWebhookTrace($settings, $stage, $update)) {
            return;
        }
        $payload = [
            'stage' => $stage,
            'update_id' => intval($update['update_id'] ?? 0),
            'message_text' => trim((string) (($update['message']['text'] ?? ''))),
            'chat_id' => (string) ($update['message']['chat']['id'] ?? ''),
            'from_id' => intval($update['message']['from']['id'] ?? 0),
        ];
        foreach ($extra as $k => $v) {
            $payload[$k] = $v;
        }
        cloudflare_subdomain_log('telegram_bind_trace', $payload, 0, null);
    }

    private static function shouldLogWebhookTrace(array $moduleSettings, string $stage, array $update): bool
    {
        $enabled = in_array(
            strtolower(trim((string) ($moduleSettings['telegram_bind_trace_enabled'] ?? '0'))),
            ['1', 'on', 'yes', 'true', 'enabled'],
            true
        );
        if (!$enabled) {
            return false;
        }
        if (strpos($stage, 'bind_') === 0) {
            return true;
        }
        $text = trim((string) ($update['message']['text'] ?? ''));
        if ($text !== '' && preg_match('/^\\/start\\s+\\S+/u', $text)) {
            return true;
        }
        return false;
    }

    private static function sendBotChatMessage(string $botToken, string $chatId, string $text, string $returnUrl = '', string $returnButtonText = ''): void
    {
        if (!self::validateBotToken($botToken)) {
            return;
        }
        if (!preg_match('/^-?[0-9]{5,20}$/', trim($chatId))) {
            return;
        }
        $text = trim($text);
        if ($text === '') {
            return;
        }
        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => '1',
            ];
            if ($returnUrl !== '') {
                $buttonText = trim($returnButtonText) !== '' ? trim($returnButtonText) : '↩️ 返回网站';
                $payload['reply_markup'] = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => $buttonText, 'url' => $returnUrl],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            self::telegramApiRequest($botToken, 'sendMessage', $payload);
        } catch (\Throwable $e) {
        }
    }

    private static function buildInviteCodeMessage(array $moduleSettings, string $languageCode, array $vars): string
    {
        $isZh = strpos($languageCode, 'zh') === 0;
        $defaultZh = "🎟️ 你的一次性准入邀请码：{code}\n⏳ 失效时间（服务器时区）：{expires_at}\n请尽快回到网站完成验证。";
        $defaultEn = "🎟️ Your one-time gate invite code: {code}\n⏳ Expires at (server timezone): {expires_at}\nPlease return to the site and complete verification.";
        $tpl = trim((string) ($moduleSettings[$isZh ? 'invite_registration_telegram_code_message_zh' : 'invite_registration_telegram_code_message_en'] ?? ''));
        if ($tpl === '') {
            $tpl = $isZh ? $defaultZh : $defaultEn;
        }
        $replace = [
            '{code}' => (string) ($vars['code'] ?? ''),
            '{expires_at}' => (string) ($vars['expires_at'] ?? ''),
            '{ttl_seconds}' => (string) ($vars['ttl_seconds'] ?? ''),
        ];
        return strtr($tpl, $replace);
    }

    private static function shouldIssueInviteCodeForScene(string $returnUrl): bool
    {
        $scene = self::extractBindSceneFromUrl($returnUrl);
        return $scene === 'invite_gate';
    }

    private static function extractBindSceneFromUrl(string $returnUrl): string
    {
        $url = trim($returnUrl);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $query = (string) ($parts['query'] ?? '');
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        return strtolower(trim((string) ($params['tg_bind_scene'] ?? '')));
    }

    private static function shouldIssueInviteCodeForBinding(array $moduleSettings, array $binding, string $returnUrl): bool
    {
        if (!self::shouldIssueInviteCodeForScene($returnUrl)) {
            return false;
        }
        if (!class_exists('CfInviteRegistrationService')) {
            return false;
        }
        $userId = (int) ($binding['userid'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        try {
            if (!CfInviteRegistrationService::isGateEnabled($moduleSettings)) {
                return false;
            }
            if (!CfInviteRegistrationService::isTelegramOptionEnabled($moduleSettings)) {
                return false;
            }
            if (CfInviteRegistrationService::userHasUnlocked($userId)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private static function botWebhookText(string $languageCode, string $key): string
    {
        $isZh = strpos(strtolower(trim($languageCode)), 'zh') === 0;
        $zh = [
            'missing_bind_token' => '👋 你好！请从网站功能中心点击“打开 Bot 对话并确认绑定”按钮进入，这样我才能识别你的绑定口令。',
            'bind_success' => '✅ 绑定成功！点击下方按钮返回网站继续领取奖励。',
            'bind_failed' => '❌ 绑定失败，请回到网站重新发起绑定。',
            'auth_expired' => '⏰ 绑定口令已过期，请回到网站重新生成后再试。',
            'telegram_used' => '⚠️ 此 Telegram 账号已绑定到其他用户，无法重复绑定。',
            'auth_invalid' => '❌ 绑定口令无效，请从网站按钮重新进入 Bot。',
            'return_button' => '↩️ 返回网站',
        ];
        $en = [
            'missing_bind_token' => '👋 Hi! Please click “Open Telegram Bot Chat and Bind” from the website so I can recognize your bind token.',
            'bind_success' => '✅ Binding succeeded! Tap the button below to return to the website and continue.',
            'bind_failed' => '❌ Binding failed. Please return to the website and start binding again.',
            'auth_expired' => '⏰ Your bind token has expired. Please generate a new one from the website and try again.',
            'telegram_used' => '⚠️ This Telegram account is already bound to another user and cannot be reused.',
            'auth_invalid' => '❌ Invalid bind token. Please reopen the bot from the website button.',
            'return_button' => '↩️ Back to site',
        ];
        $dict = $isZh ? $zh : $en;
        return (string) ($dict[$key] ?? ($zh[$key] ?? $key));
    }

    private static function confirmBindByToken(
        string $token,
        int $telegramUserId,
        string $telegramUsername,
        string $firstName,
        string $lastName,
        array $moduleSettings = [],
        string $botToken = ''
    ): array
    {
        $token = trim($token);
        if ($token === '' || $telegramUserId <= 0) {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        $now = date('Y-m-d H:i:s');
        return Capsule::connection()->transaction(function () use ($token, $telegramUserId, $telegramUsername, $firstName, $lastName, $moduleSettings, $botToken, $now) {
            $session = Capsule::table(self::TABLE_BIND_SESSIONS)
                ->where('bind_token', $token)
                ->lockForUpdate()
                ->first();
            if (!$session) {
                throw new CfTelegramGroupRewardException('auth_invalid');
            }

            $status = strtolower(trim((string) ($session->status ?? 'pending')));
            $expiresAt = trim((string) ($session->expires_at ?? ''));
            if ($status !== 'pending') {
                throw new CfTelegramGroupRewardException('auth_invalid');
            }
            if ($expiresAt !== '' && strtotime($expiresAt) !== false && time() > strtotime($expiresAt)) {
                Capsule::table(self::TABLE_BIND_SESSIONS)
                    ->where('id', intval($session->id ?? 0))
                    ->update(['status' => 'expired', 'updated_at' => $now]);
                throw new CfTelegramGroupRewardException('auth_expired');
            }

            $userId = intval($session->userid ?? 0);
            if ($userId <= 0) {
                throw new CfTelegramGroupRewardException('invalid_user');
            }

            $bindingExisting = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();
            $bindingByTelegram = Capsule::table(self::TABLE_BINDINGS)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first();
            $testMode = self::isInviteTelegramTestMode($moduleSettings);
            if (!$testMode && $bindingByTelegram && intval($bindingByTelegram->userid ?? 0) !== $userId) {
                throw new CfTelegramGroupRewardException('telegram_used');
            }

            $payload = [
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $telegramUsername !== '' ? $telegramUsername : null,
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name' => $lastName !== '' ? $lastName : null,
                'auth_date' => time(),
                'updated_at' => $now,
            ];

            $requireGroup = in_array(
                strtolower(trim((string) ($moduleSettings['invite_registration_telegram_require_group_member'] ?? '0'))),
                ['1', 'on', 'yes', 'true', 'enabled'],
                true
            );
            $chatId = trim((string) ($moduleSettings['telegram_group_chat_id'] ?? ''));
            if ($requireGroup && $chatId !== '' && self::validateBotToken($botToken)) {
                try {
                    $groupVerifyTtl = max(60, min(86400, (int) ($moduleSettings['invite_registration_telegram_group_member_cache_ttl_seconds'] ?? 600)));
                    $membership = self::verifyMembership($botToken, $chatId, $telegramUserId, $groupVerifyTtl);
                    if (!empty($membership['is_member'])) {
                        $payload['is_group_member'] = 1;
                        $payload['group_chat_id'] = $chatId;
                        $payload['group_verified_at'] = $now;
                        $payload['verify_source'] = self::VERIFY_SOURCE_GROUP;
                    } else {
                        $payload['is_group_member'] = 0;
                        $payload['group_chat_id'] = null;
                    }
                } catch (\Throwable $e) {
                    $payload['is_group_member'] = 0;
                    $payload['group_chat_id'] = null;
                }
            }
            if ($bindingExisting) {
                Capsule::table(self::TABLE_BINDINGS)
                    ->where('id', intval($bindingExisting->id ?? 0))
                    ->update($payload);
            } elseif ($testMode && $bindingByTelegram && intval($bindingByTelegram->userid ?? 0) !== $userId) {
                $payload['userid'] = $userId;
                Capsule::table(self::TABLE_BINDINGS)
                    ->where('id', intval($bindingByTelegram->id ?? 0))
                    ->update($payload);
            } else {
                $payload['userid'] = $userId;
                $payload['created_at'] = $now;
                Capsule::table(self::TABLE_BINDINGS)->insert($payload);
            }

            Capsule::table(self::TABLE_BIND_SESSIONS)
                ->where('id', intval($session->id ?? 0))
                ->update([
                    'status' => 'consumed',
                    'telegram_user_id' => $telegramUserId,
                    'telegram_username' => $telegramUsername !== '' ? $telegramUsername : null,
                    'consumed_at' => $now,
                    'updated_at' => $now,
                ]);

            return [
                'userid' => $userId,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $telegramUsername,
                'return_url' => (string) ($session->return_url ?? ''),
            ];
        });
    }

    private static function buildBindReturnUrl(string $returnUrl, bool $ok, string $reason): string
    {
        $returnUrl = self::normalizeReturnUrl($returnUrl);
        if ($returnUrl === '') {
            return '';
        }
        $params = [
            'tg_bind' => '1',
            'tg_bind_ok' => $ok ? '1' : '0',
            'tg_bind_reason' => preg_replace('/[^a-z0-9_\\-]/i', '', strtolower(trim($reason))),
            'tg_bind_ts' => (string) time(),
        ];
        $separator = strpos($returnUrl, '?') === false ? '?' : '&';
        return $returnUrl . $separator . http_build_query($params);
    }

    private static function normalizeReturnUrl(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '') {
            return '';
        }
        if (stripos($returnUrl, 'javascript:') === 0) {
            return '';
        }
        if (!preg_match('~^https?://~i', $returnUrl)) {
            $baseUrl = self::resolveWhmcsSystemUrl();
            if ($baseUrl === '') {
                return '';
            }
            $returnUrl = rtrim($baseUrl, '/') . '/' . ltrim($returnUrl, '/');
        }
        return $returnUrl;
    }

    private static function resolveWhmcsSystemUrl(): string
    {
        try {
            $systemUrl = trim((string) Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')
                ->value('value'));
            if ($systemUrl !== '') {
                return rtrim($systemUrl, '/');
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    public static function getWebhookDebugInfo(array $moduleSettings): array
    {
        $token = self::resolveBotToken($moduleSettings);
        if (!self::validateBotToken($token)) {
            return [
                'ok' => false,
                'error' => 'invalid_bot_token',
            ];
        }
        try {
            $response = self::telegramApiRequest($token, 'getWebhookInfo', []);
            $result = is_array($response['result'] ?? null) ? $response['result'] : [];
            return [
                'ok' => !empty($response['ok']),
                'http_status' => intval($response['http_status'] ?? 0),
                'url' => (string) ($result['url'] ?? ''),
                'has_custom_certificate' => !empty($result['has_custom_certificate']),
                'pending_update_count' => intval($result['pending_update_count'] ?? 0),
                'last_error_date' => intval($result['last_error_date'] ?? 0),
                'last_error_message' => (string) ($result['last_error_message'] ?? ''),
                'max_connections' => intval($result['max_connections'] ?? 0),
                'ip_address' => (string) ($result['ip_address'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function getExpectedWebhookUrl(): string
    {
        $baseUrl = self::resolveWhmcsSystemUrl();
        if ($baseUrl === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/index.php?m=domain_hub&telegram_bind_webhook=1';
    }

    public static function setWebhookToExpected(array $moduleSettings): array
    {
        $token = self::resolveBotToken($moduleSettings);
        if (!self::validateBotToken($token)) {
            return [
                'ok' => false,
                'error' => 'invalid_bot_token',
            ];
        }
        $expectedUrl = self::getExpectedWebhookUrl();
        if ($expectedUrl === '') {
            return [
                'ok' => false,
                'error' => 'system_url_missing',
            ];
        }
        try {
            $response = self::telegramApiRequest($token, 'setWebhook', [
                'url' => $expectedUrl,
                'drop_pending_updates' => '0',
            ]);
            return [
                'ok' => !empty($response['ok']),
                'http_status' => intval($response['http_status'] ?? 0),
                'description' => (string) ($response['description'] ?? ''),
                'expected_url' => $expectedUrl,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'expected_url' => $expectedUrl,
            ];
        }
    }

    public static function isEnabled(array $moduleSettings): bool
    {
        return in_array(($moduleSettings['enable_telegram_group_reward'] ?? '0'), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function resolveRewardAmount(array $moduleSettings): int
    {
        return max(1, min(1000, (int) ($moduleSettings['telegram_group_reward_amount'] ?? 1)));
    }

    public static function resolveGroupLink(array $moduleSettings): string
    {
        return trim((string) ($moduleSettings['telegram_group_link'] ?? ''));
    }

    public static function resolveChatId(array $moduleSettings): string
    {
        return trim((string) ($moduleSettings['telegram_group_chat_id'] ?? ''));
    }

    public static function resolveBotUsername(array $moduleSettings): string
    {
        $username = trim((string) ($moduleSettings['telegram_group_bot_username'] ?? ''));
        if ($username !== '' && strpos($username, '@') === 0) {
            $username = ltrim($username, '@');
        }
        return $username;
    }

    public static function resolveBotToken(array $moduleSettings): string
    {
        $token = trim((string) ($moduleSettings['telegram_group_bot_token'] ?? ''));
        if ($token === '') {
            return '';
        }
        if (strpos($token, 'enc::') === 0) {
            $token = substr($token, strlen('enc::'));
            $token = trim((string) cfmod_decrypt_sensitive($token));
        }
        return $token;
    }

    private static function normalizeTelegramUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return '';
        }
        if (strpos($username, '@') === 0) {
            $username = ltrim($username, '@');
        }
        if (!preg_match('/^[A-Za-z0-9_]{5,64}$/', $username)) {
            return '';
        }
        return strtolower($username);
    }

    private static function resolveGroupHash(string $groupLink, string $chatId): string
    {
        $base = strtolower(trim($chatId));
        if ($base === '') {
            $base = strtolower(trim($groupLink));
        }
        return hash('sha256', $base);
    }

    private static function validateChatId(string $chatId): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }
        return (bool) preg_match('/^-?[0-9]{5,20}$/', $chatId);
    }

    private static function validateBotToken(string $botToken): bool
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            return false;
        }
        return (bool) preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,120}$/', $botToken);
    }

    private static function isInviteTelegramTestMode(array $moduleSettings): bool
    {
        $value = strtolower(trim((string) ($moduleSettings['invite_registration_telegram_test_mode'] ?? '0')));
        return in_array($value, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    private static function normalizeAuthPayload(array $authPayload): array
    {
        $result = [];
        foreach ($authPayload as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $result[$key] = trim((string) $value);
        }
        return $result;
    }

    private static function buildDataCheckString(array $payload): string
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($key === 'hash') {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $pairs[$key] = $key . '=' . $value;
        }
        ksort($pairs, SORT_STRING);
        return implode("\n", array_values($pairs));
    }

    private static function verifyAuthPayload(array $authPayload, string $botToken, int $maxAgeSeconds): array
    {
        $payload = self::normalizeAuthPayload($authPayload);
        $telegramUserId = intval($payload['id'] ?? 0);
        $authDate = intval($payload['auth_date'] ?? 0);
        $hash = strtolower(trim((string) ($payload['hash'] ?? '')));

        if ($telegramUserId <= 0 || $authDate <= 0 || $hash === '') {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        if ($maxAgeSeconds > 0 && (time() - $authDate) > $maxAgeSeconds) {
            throw new CfTelegramGroupRewardException('auth_expired');
        }

        $dataCheckString = self::buildDataCheckString($payload);
        if ($dataCheckString === '') {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        $secretKey = hash('sha256', $botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        if (!hash_equals($expectedHash, $hash)) {
            throw new CfTelegramGroupRewardException('auth_invalid');
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

    private static function telegramApiRequest(string $botToken, string $method, array $params): array
    {
        if (!function_exists('curl_init')) {
            throw new CfTelegramGroupRewardException('verify_failed', 'curl_missing');
        }

        $url = rtrim(self::TELEGRAM_API_BASE, '/') . '/bot' . rawurlencode($botToken) . '/' . $method;
        $curl = curl_init();
        if ($curl === false) {
            throw new CfTelegramGroupRewardException('verify_failed', 'curl_init_failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new CfTelegramGroupRewardException('verify_failed', $error !== '' ? $error : 'curl_exec_failed');
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            throw new CfTelegramGroupRewardException('verify_failed', 'invalid_json');
        }

        $payload['http_status'] = $status;
        return $payload;
    }

    private static function verifyMembership(string $botToken, string $chatId, int $telegramUserId, int $cacheTtlSeconds = 600): array
    {
        $cacheKey = 'cf_tg_group_verify_' . md5($chatId . ':' . $telegramUserId);
        $cacheTtlSeconds = max(60, min(86400, $cacheTtlSeconds));
        if (function_exists('cfmod_cache_get')) {
            $cached = cfmod_cache_get($cacheKey);
            if (is_array($cached) && (($cached['expires_at'] ?? 0) > time())) {
                return $cached['value'] ?? [];
            }
        }
        $response = self::telegramApiRequest($botToken, 'getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $telegramUserId,
        ]);

        $httpStatus = (int) ($response['http_status'] ?? 0);
        $ok = !empty($response['ok']);
        if (!$ok) {
            $errorCode = (int) ($response['error_code'] ?? $httpStatus);
            if ($errorCode === 429) {
                throw new CfTelegramGroupRewardException('verify_rate_limited');
            }
            $description = strtolower(trim((string) ($response['description'] ?? '')));
            if ($description !== '' && (strpos($description, 'user not found') !== false || strpos($description, 'participant') !== false)) {
                throw new CfTelegramGroupRewardException('member_not_found');
            }
            if ($errorCode === 400 || $errorCode === 404) {
                throw new CfTelegramGroupRewardException('not_joined');
            }
            throw new CfTelegramGroupRewardException('verify_failed', $description !== '' ? $description : ('http_' . $httpStatus));
        }

        $member = is_array($response['result'] ?? null) ? $response['result'] : [];
        $status = strtolower(trim((string) ($member['status'] ?? '')));
        if (!in_array($status, ['creator', 'administrator', 'member', 'restricted'], true)) {
            throw new CfTelegramGroupRewardException('not_joined');
        }

        $result = [
            'status' => $status,
            'member' => $member,
        ];
        if (function_exists('cfmod_cache_set')) {
            cfmod_cache_set($cacheKey, ['value' => $result, 'expires_at' => time() + $cacheTtlSeconds], $cacheTtlSeconds);
        }
        return $result;
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            return Capsule::schema()->hasTable($table) && Capsule::schema()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getBindingForUser(int $userId): array
    {
        $result = [
            'bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'first_name' => '',
            'last_name' => '',
            'auth_date' => 0,
            'updated_at' => '',
        ];
        if ($userId <= 0) {
            return $result;
        }

        self::ensureTables();

        try {
            $row = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->first();
            if (!$row) {
                return $result;
            }
            return [
                'bound' => true,
                'telegram_user_id' => (int) ($row->telegram_user_id ?? 0),
                'telegram_username' => (string) ($row->telegram_username ?? ''),
                'first_name' => (string) ($row->first_name ?? ''),
                'last_name' => (string) ($row->last_name ?? ''),
                'auth_date' => (int) ($row->auth_date ?? 0),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];
        } catch (\Throwable $e) {
            return $result;
        }
    }

    public static function getUserClaimState(int $userId, array $moduleSettings): array
    {
        $groupLink = self::resolveGroupLink($moduleSettings);
        $chatId = self::resolveChatId($moduleSettings);
        $state = [
            'enabled' => self::isEnabled($moduleSettings),
            'group_link' => $groupLink,
            'chat_id' => $chatId,
            'reward_amount' => self::resolveRewardAmount($moduleSettings),
            'claimed' => false,
            'telegram_bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'bot_username' => self::resolveBotUsername($moduleSettings),
        ];

        if ($userId <= 0 || !$state['enabled']) {
            return $state;
        }

        self::ensureTables();

        $binding = self::getBindingForUser($userId);
        $state['telegram_bound'] = !empty($binding['bound']);
        $state['telegram_user_id'] = (int) ($binding['telegram_user_id'] ?? 0);
        $state['telegram_username'] = (string) ($binding['telegram_username'] ?? '');

        $groupHash = self::resolveGroupHash($groupLink, $chatId);

        try {
            $query = Capsule::table(self::TABLE_REWARDS)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                $query->where('group_hash', $groupHash);
            } else {
                $query->where('group_link', $groupLink);
            }
            $row = $query->orderBy('id', 'desc')->first();
            $state['claimed'] = !empty($row);
            if ($row && $state['telegram_username'] === '' && self::hasColumn(self::TABLE_REWARDS, 'telegram_username')) {
                $state['telegram_username'] = (string) ($row->telegram_username ?? '');
            }
            if ($row && $state['telegram_user_id'] <= 0 && self::hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                $state['telegram_user_id'] = (int) ($row->telegram_user_id ?? 0);
            }
        } catch (\Throwable $e) {
            $state['claimed'] = false;
        }

        return $state;
    }

    public static function unbindUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new CfTelegramGroupRewardException('invalid_user');
        }

        self::ensureTables();
        $now = date('Y-m-d H:i:s');

        return Capsule::connection()->transaction(function () use ($userId, $now) {
            $binding = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$binding) {
                throw new CfTelegramGroupRewardException('not_bound');
            }

            Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->delete();

            $expirySubscriptionTable = 'mod_cloudflare_expiry_telegram_subscriptions';
            if (Capsule::schema()->hasTable($expirySubscriptionTable)) {
                Capsule::table($expirySubscriptionTable)
                    ->where('userid', $userId)
                    ->update([
                        'enabled' => 0,
                        'telegram_user_id' => null,
                        'telegram_username' => null,
                        'updated_at' => $now,
                    ]);
            }

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_telegram_unbind', [
                    'telegram_user_id' => (int) ($binding->telegram_user_id ?? 0),
                    'telegram_username' => (string) ($binding->telegram_username ?? ''),
                ], $userId, null);
            }

            return [
                'telegram_user_id' => (int) ($binding->telegram_user_id ?? 0),
                'telegram_username' => (string) ($binding->telegram_username ?? ''),
            ];
        });
    }

    public static function claim(int $userId, array $moduleSettings, string $clientIp = '', string $userAgent = '', array $authPayload = []): array
    {
        if ($userId <= 0) {
            throw new CfTelegramGroupRewardException('invalid_user');
        }
        if (!self::isEnabled($moduleSettings)) {
            throw new CfTelegramGroupRewardException('disabled');
        }

        $groupLink = self::resolveGroupLink($moduleSettings);
        $chatId = self::resolveChatId($moduleSettings);
        $botToken = self::resolveBotToken($moduleSettings);
        $rewardAmount = self::resolveRewardAmount($moduleSettings);
        $groupHash = self::resolveGroupHash($groupLink, $chatId);

        if ($groupLink !== '' && preg_match('/^\s*javascript:/i', $groupLink)) {
            throw new CfTelegramGroupRewardException('invalid_group_link');
        }
        if (!self::validateChatId($chatId)) {
            throw new CfTelegramGroupRewardException('invalid_chat_id');
        }
        if (!self::validateBotToken($botToken)) {
            throw new CfTelegramGroupRewardException('invalid_bot_token');
        }

        self::ensureTables();

        $safeIp = trim($clientIp);
        $safeUa = trim($userAgent);
        if ($safeUa !== '') {
            $safeUa = function_exists('mb_substr') ? mb_substr($safeUa, 0, 255, 'UTF-8') : substr($safeUa, 0, 255);
        }
        $now = date('Y-m-d H:i:s');

        $maxAgeSeconds = max(60, min(7 * 86400, (int) ($moduleSettings['telegram_reward_auth_max_age_seconds'] ?? 86400)));

        $authData = null;
        $normalizedAuthPayload = self::normalizeAuthPayload($authPayload);
        $hasAuthPayload = false;
        foreach (['id', 'auth_date', 'hash'] as $requiredKey) {
            if (trim((string) ($normalizedAuthPayload[$requiredKey] ?? '')) !== '') {
                $hasAuthPayload = true;
                break;
            }
        }
        if ($hasAuthPayload) {
            $authData = self::verifyAuthPayload($normalizedAuthPayload, $botToken, $maxAgeSeconds);
        }

        $binding = self::getBindingForUser($userId);
        if ($authData === null) {
            if (empty($binding['bound']) || intval($binding['telegram_user_id'] ?? 0) <= 0) {
                throw new CfTelegramGroupRewardException('auth_required');
            }
            $authData = [
                'telegram_user_id' => (int) ($binding['telegram_user_id'] ?? 0),
                'telegram_username' => self::normalizeTelegramUsername((string) ($binding['telegram_username'] ?? '')),
                'first_name' => (string) ($binding['first_name'] ?? ''),
                'last_name' => (string) ($binding['last_name'] ?? ''),
                'photo_url' => '',
                'auth_date' => (int) ($binding['auth_date'] ?? 0),
            ];
        }

        $telegramUserId = (int) ($authData['telegram_user_id'] ?? 0);
        $telegramUsername = self::normalizeTelegramUsername((string) ($authData['telegram_username'] ?? ''));
        if ($telegramUserId <= 0) {
            throw new CfTelegramGroupRewardException('auth_invalid');
        }

        $groupVerifyTtl = max(60, min(86400, (int) ($moduleSettings['invite_registration_telegram_group_member_cache_ttl_seconds'] ?? 600)));
        $membership = self::verifyMembership($botToken, $chatId, $telegramUserId, $groupVerifyTtl);
        $verifySource = $hasAuthPayload ? self::VERIFY_SOURCE_WIDGET : self::VERIFY_SOURCE_BOT;
        if (!empty($membership['status'])) {
            $verifySource = self::VERIFY_SOURCE_GROUP;
        }

        return Capsule::connection()->transaction(function () use ($userId, $moduleSettings, $groupLink, $groupHash, $telegramUserId, $telegramUsername, $authData, $rewardAmount, $safeIp, $safeUa, $now, $chatId, $verifySource) {
            $existingRewardQuery = Capsule::table(self::TABLE_REWARDS)
                ->where('userid', $userId)
                ->where('status', 'granted');
            if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                $existingRewardQuery->where('group_hash', $groupHash);
            } else {
                $existingRewardQuery->where('group_link', $groupLink);
            }
            $existingReward = $existingRewardQuery->lockForUpdate()->first();
            if ($existingReward) {
                throw new CfTelegramGroupRewardException('already_claimed');
            }

            if (self::hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                $telegramUsedQuery = Capsule::table(self::TABLE_REWARDS)
                    ->where('status', 'granted')
                    ->where('userid', '<>', $userId)
                    ->where('telegram_user_id', $telegramUserId);
                if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                    $telegramUsedQuery->where('group_hash', $groupHash);
                } else {
                    $telegramUsedQuery->where('group_link', $groupLink);
                }
                $telegramUsed = $telegramUsedQuery->lockForUpdate()->first();
                if ($telegramUsed) {
                    throw new CfTelegramGroupRewardException('telegram_used');
                }
            }

            $bindingExisting = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $bindingByTelegram = Capsule::table(self::TABLE_BINDINGS)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first();
            if ($bindingByTelegram && intval($bindingByTelegram->userid ?? 0) !== $userId) {
                throw new CfTelegramGroupRewardException('telegram_used');
            }

            $bindingPayload = [
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $telegramUsername !== '' ? $telegramUsername : null,
                'first_name' => trim((string) ($authData['first_name'] ?? '')) !== '' ? trim((string) ($authData['first_name'] ?? '')) : null,
                'last_name' => trim((string) ($authData['last_name'] ?? '')) !== '' ? trim((string) ($authData['last_name'] ?? '')) : null,
                'photo_url' => trim((string) ($authData['photo_url'] ?? '')) !== '' ? trim((string) ($authData['photo_url'] ?? '')) : null,
                'auth_date' => max(0, intval($authData['auth_date'] ?? 0)),
                'verify_source' => $verifySource,
                'is_group_member' => 1,
                'group_verified_at' => $now,
                'group_chat_id' => $chatId,
                'updated_at' => $now,
            ];
            if ($bindingExisting) {
                Capsule::table(self::TABLE_BINDINGS)
                    ->where('id', intval($bindingExisting->id ?? 0))
                    ->update($bindingPayload);
            } else {
                $bindingPayload['userid'] = $userId;
                $bindingPayload['created_at'] = $now;
                Capsule::table(self::TABLE_BINDINGS)->insert($bindingPayload);
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
                throw new CfTelegramGroupRewardException('quota_unavailable');
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
                'group_link' => $groupLink,
                'reward_amount' => $rewardAmount,
                'before_quota' => $beforeQuota,
                'after_quota' => $afterQuota,
                'status' => 'granted',
                'client_ip' => $safeIp !== '' ? $safeIp : null,
                'user_agent' => $safeUa !== '' ? $safeUa : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (self::hasColumn(self::TABLE_REWARDS, 'group_hash')) {
                $insertData['group_hash'] = $groupHash;
            }
            if (self::hasColumn(self::TABLE_REWARDS, 'telegram_user_id')) {
                $insertData['telegram_user_id'] = $telegramUserId;
            }
            if (self::hasColumn(self::TABLE_REWARDS, 'telegram_username')) {
                $insertData['telegram_username'] = $telegramUsername;
            }
            Capsule::table(self::TABLE_REWARDS)->insert($insertData);

            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_telegram_group_reward', [
                    'group_link' => $groupLink,
                    'telegram_user_id' => $telegramUserId,
                    'telegram_username' => $telegramUsername,
                    'reward_amount' => $rewardAmount,
                    'before_quota' => $beforeQuota,
                    'after_quota' => $afterQuota,
                ], $userId, null);
            }

            return [
                'group_link' => $groupLink,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $telegramUsername,
                'reward_amount' => $rewardAmount,
                'before_quota' => $beforeQuota,
                'after_quota' => $afterQuota,
            ];
        });
    }

    public static function getUserHistory(int $userId, int $page = 1, int $perPage = self::HISTORY_PER_PAGE): array
    {
        self::ensureTables();
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

        $query = Capsule::table(self::TABLE_REWARDS)
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
                'group_link' => (string) ($row->group_link ?? ''),
                'telegram_user_id' => (int) ($row->telegram_user_id ?? 0),
                'telegram_username' => (string) ($row->telegram_username ?? ''),
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
}
