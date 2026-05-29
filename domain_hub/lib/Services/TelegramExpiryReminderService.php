<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfTelegramExpiryReminderException extends \RuntimeException
{
    private string $reason;
    private int $retryAfter;

    public function __construct(string $reason, string $message = '', int $retryAfter = 0, ?\Throwable $previous = null)
    {
        $this->reason = $reason;
        $this->retryAfter = max(0, $retryAfter);
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}

class CfTelegramExpiryReminderService
{
    public const TABLE_SUBSCRIPTIONS = 'mod_cloudflare_expiry_telegram_subscriptions';
    public const TABLE_NOTICES = 'mod_cloudflare_expiry_telegram_notices';
    public const TABLE_BINDINGS = 'mod_cloudflare_telegram_reward_bindings';

    private const TELEGRAM_API_BASE = 'https://api.telegram.org';

    public static function isEnabled(array $settings): bool
    {
        $value = strtolower((string) ($settings['renewal_notice_telegram_enabled'] ?? '0'));
        return in_array($value, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function resolveBotUsername(array $settings): string
    {
        $username = trim((string) ($settings['renewal_notice_telegram_bot_username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($settings['telegram_group_bot_username'] ?? ''));
        }
        if ($username !== '' && strpos($username, '@') === 0) {
            $username = ltrim($username, '@');
        }
        return $username;
    }

    public static function resolveBotToken(array $settings): string
    {
        $token = trim((string) ($settings['renewal_notice_telegram_bot_token'] ?? ''));
        if ($token === '') {
            $token = trim((string) ($settings['telegram_group_bot_token'] ?? ''));
        }
        if ($token === '') {
            return '';
        }
        if (strpos($token, 'enc::') === 0) {
            $token = substr($token, strlen('enc::'));
            $token = trim((string) cfmod_decrypt_sensitive($token));
        }
        return $token;
    }

    public static function validateBotToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        return (bool) preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,120}$/', $token);
    }

    public static function isConfigured(array $settings): bool
    {
        $username = self::resolveBotUsername($settings);
        $token = self::resolveBotToken($settings);
        return $username !== '' && self::validateBotToken($token);
    }

    public static function resolveAuthMaxAge(array $settings): int
    {
        $value = (int) ($settings['renewal_notice_telegram_auth_max_age_seconds']
            ?? ($settings['telegram_reward_auth_max_age_seconds'] ?? 86400));
        return max(60, min(604800, $value));
    }

    public static function parseConfiguredDays(array $settings, array $override = []): array
    {
        $candidates = [];
        if (!empty($override)) {
            $candidates = $override;
        } else {
            $raw = trim((string) ($settings['renewal_notice_telegram_days'] ?? ''));
            if ($raw !== '') {
                $candidates = preg_split('/[\s,，]+/u', $raw) ?: [];
            }
        }

        $days = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $day = (int) $candidate;
            if ($day <= 0 || isset($seen[$day])) {
                continue;
            }
            $seen[$day] = true;
            $days[] = $day;
            if (count($days) >= 2) {
                break;
            }
        }

        return $days;
    }

    public static function formatDaysCsv(array $days): string
    {
        $normalized = [];
        $seen = [];
        foreach ($days as $day) {
            $value = (int) $day;
            if ($value <= 0 || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $normalized[] = $value;
        }

        return implode(',', $normalized);
    }

    public static function reminderKey(int $days): string
    {
        return 'tg_day' . max(1, $days);
    }

    public static function ensureTables(): void
    {
        self::ensureBindingTable();

        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE_SUBSCRIPTIONS)) {
            $schema->create(self::TABLE_SUBSCRIPTIONS, function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned()->unique();
                $table->bigInteger('telegram_user_id')->unsigned()->nullable();
                $table->string('telegram_username', 64)->nullable();
                $table->boolean('enabled')->default(0);
                $table->dateTime('last_notified_at')->nullable();
                $table->timestamps();
                $table->index(['enabled', 'userid'], 'idx_cf_expiry_tg_enabled_user');
                $table->index(['telegram_user_id'], 'idx_cf_expiry_tg_userid');
            });
        }

        if (!$schema->hasTable(self::TABLE_NOTICES)) {
            $schema->create(self::TABLE_NOTICES, function ($table) {
                $table->increments('id');
                $table->integer('subdomain_id')->unsigned()->index();
                $table->integer('userid')->unsigned()->index();
                $table->bigInteger('telegram_user_id')->unsigned()->nullable();
                $table->string('reminder_key', 50)->index();
                $table->dateTime('expires_at_snapshot')->nullable()->index();
                $table->dateTime('sent_at');
                $table->timestamps();
                $table->unique(['subdomain_id', 'reminder_key', 'expires_at_snapshot'], 'uniq_cf_expiry_tg_sent');
            });
        }
    }

    private static function ensureBindingTable(): void
    {
        if (class_exists('CfTelegramGroupRewardService')) {
            try {
                CfTelegramGroupRewardService::ensureTables();
                return;
            } catch (\Throwable $e) {
            }
        }

        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE_BINDINGS)) {
            return;
        }

        $schema->create(self::TABLE_BINDINGS, function ($table) {
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

    public static function getUserState(int $userId, array $settings): array
    {
        $state = [
            'feature_enabled' => self::isEnabled($settings),
            'configured' => self::isConfigured($settings),
            'bot_username' => self::resolveBotUsername($settings),
            'days' => self::parseConfiguredDays($settings),
            'days_csv' => self::formatDaysCsv(self::parseConfiguredDays($settings)),
            'subscribed' => false,
            'telegram_bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'updated_at' => '',
        ];

        if ($userId <= 0) {
            return $state;
        }

        self::ensureTables();

        try {
            $binding = Capsule::table(self::TABLE_BINDINGS)->where('userid', $userId)->first();
            if ($binding) {
                $state['telegram_bound'] = (int) ($binding->telegram_user_id ?? 0) > 0;
                $state['telegram_user_id'] = (int) ($binding->telegram_user_id ?? 0);
                $state['telegram_username'] = (string) ($binding->telegram_username ?? '');
            }
        } catch (\Throwable $e) {
        }

        try {
            $subscription = Capsule::table(self::TABLE_SUBSCRIPTIONS)->where('userid', $userId)->first();
            if ($subscription) {
                $state['subscribed'] = (int) ($subscription->enabled ?? 0) === 1;
                if ((int) ($subscription->telegram_user_id ?? 0) > 0) {
                    $state['telegram_user_id'] = (int) ($subscription->telegram_user_id ?? 0);
                    $state['telegram_bound'] = true;
                }
                if (trim((string) ($subscription->telegram_username ?? '')) !== '') {
                    $state['telegram_username'] = trim((string) ($subscription->telegram_username ?? ''));
                }
                $state['updated_at'] = (string) ($subscription->updated_at ?? '');
            }
        } catch (\Throwable $e) {
        }

        return $state;
    }

    public static function updateUserSubscription(int $userId, array $settings, bool $enable, array $authPayload = []): array
    {
        if ($userId <= 0) {
            throw new CfTelegramExpiryReminderException('invalid_user');
        }

        if (!self::isEnabled($settings)) {
            throw new CfTelegramExpiryReminderException('feature_disabled');
        }

        self::ensureTables();

        $now = date('Y-m-d H:i:s');
        $authData = null;

        if ($enable) {
            $botToken = self::resolveBotToken($settings);
            if (!self::validateBotToken($botToken)) {
                throw new CfTelegramExpiryReminderException('invalid_bot_token');
            }

            $normalizedPayload = self::normalizeAuthPayload($authPayload);
            $hasAuth = trim((string) ($normalizedPayload['id'] ?? '')) !== ''
                || trim((string) ($normalizedPayload['hash'] ?? '')) !== ''
                || trim((string) ($normalizedPayload['auth_date'] ?? '')) !== '';

            if ($hasAuth) {
                $authData = self::verifyAuthPayload($normalizedPayload, $botToken, self::resolveAuthMaxAge($settings));
            }
        }

        Capsule::connection()->transaction(function () use ($userId, $enable, $authData, $now) {
            $binding = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if ($authData !== null) {
                $telegramUserId = (int) ($authData['telegram_user_id'] ?? 0);
                if ($telegramUserId <= 0) {
                    throw new CfTelegramExpiryReminderException('auth_invalid');
                }

                $bindingByTelegram = Capsule::table(self::TABLE_BINDINGS)
                    ->where('telegram_user_id', $telegramUserId)
                    ->lockForUpdate()
                    ->first();
                if ($bindingByTelegram && (int) ($bindingByTelegram->userid ?? 0) !== $userId) {
                    throw new CfTelegramExpiryReminderException('telegram_used');
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

                if ($binding) {
                    Capsule::table(self::TABLE_BINDINGS)
                        ->where('id', (int) ($binding->id ?? 0))
                        ->update($bindingPayload);
                } else {
                    $bindingPayload['userid'] = $userId;
                    $bindingPayload['created_at'] = $now;
                    Capsule::table(self::TABLE_BINDINGS)->insert($bindingPayload);
                }
            } elseif ($enable) {
                if (!$binding || (int) ($binding->telegram_user_id ?? 0) <= 0) {
                    throw new CfTelegramExpiryReminderException('auth_required');
                }
            }

            $finalBinding = Capsule::table(self::TABLE_BINDINGS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $subscription = Capsule::table(self::TABLE_SUBSCRIPTIONS)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            $telegramUserId = (int) ($finalBinding->telegram_user_id ?? 0);
            $telegramUsername = trim((string) ($finalBinding->telegram_username ?? ''));

            $payload = [
                'enabled' => $enable ? 1 : 0,
                'telegram_user_id' => $telegramUserId > 0 ? $telegramUserId : null,
                'telegram_username' => $telegramUsername !== '' ? $telegramUsername : null,
                'updated_at' => $now,
            ];

            if ($subscription) {
                Capsule::table(self::TABLE_SUBSCRIPTIONS)
                    ->where('id', (int) ($subscription->id ?? 0))
                    ->update($payload);
            } else {
                $payload['userid'] = $userId;
                $payload['created_at'] = $now;
                Capsule::table(self::TABLE_SUBSCRIPTIONS)->insert($payload);
            }
        });

        return self::getUserState($userId, $settings);
    }

    public static function fetchPendingRecords(int $days, int $batchLimit): array
    {
        self::ensureTables();

        $batchLimit = max(10, min(1000, $batchLimit));
        $days = max(1, $days);
        $reminderKey = self::reminderKey($days);

        $targetTs = strtotime('+' . $days . ' days');
        $start = date('Y-m-d 00:00:00', $targetTs);
        $end = date('Y-m-d 23:59:59', $targetTs);

        $rows = Capsule::table('mod_cloudflare_subdomain as s')
            ->join(self::TABLE_SUBSCRIPTIONS . ' as t', 't.userid', '=', 's.userid')
            ->leftJoin('tblclients as c', 'c.id', '=', 's.userid')
            ->select(
                's.*',
                't.telegram_user_id as reminder_telegram_user_id',
                't.telegram_username as reminder_telegram_username',
                'c.language as client_language'
            )
            ->where('t.enabled', 1)
            ->whereNotNull('t.telegram_user_id')
            ->where('t.telegram_user_id', '>', 0)
            ->whereNotNull('s.expires_at')
            ->where('s.never_expires', 0)
            ->whereNotIn('s.status', ['deleted', 'Deleted'])
            ->whereBetween('s.expires_at', [$start, $end])
            ->whereNotExists(function ($sub) use ($reminderKey) {
                $sub->select(Capsule::raw('1'))
                    ->from(self::TABLE_NOTICES . ' as n')
                    ->whereColumn('n.subdomain_id', 's.id')
                    ->where('n.reminder_key', $reminderKey)
                    ->whereColumn('n.expires_at_snapshot', 's.expires_at');
            })
            ->orderBy('s.expires_at', 'asc')
            ->orderBy('s.id', 'asc')
            ->limit($batchLimit + 1)
            ->get();

        if ($rows instanceof \Illuminate\Support\Collection) {
            return $rows->all();
        }
        return is_array($rows) ? $rows : [];
    }

    public static function markReminderSent($record, int $days): void
    {
        self::ensureTables();

        $row = is_array($record) ? (object) $record : $record;
        $subdomainId = (int) ($row->id ?? 0);
        $userId = (int) ($row->userid ?? 0);
        if ($subdomainId <= 0 || $userId <= 0) {
            return;
        }

        $telegramUserId = (int) ($row->reminder_telegram_user_id ?? 0);
        $expiresSnapshot = $row->expires_at ?? null;
        $now = date('Y-m-d H:i:s');

        Capsule::table(self::TABLE_NOTICES)->updateOrInsert(
            [
                'subdomain_id' => $subdomainId,
                'reminder_key' => self::reminderKey($days),
                'expires_at_snapshot' => $expiresSnapshot,
            ],
            [
                'userid' => $userId,
                'telegram_user_id' => $telegramUserId > 0 ? $telegramUserId : null,
                'sent_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        if ($telegramUserId > 0) {
            Capsule::table(self::TABLE_SUBSCRIPTIONS)
                ->where('userid', $userId)
                ->update([
                    'last_notified_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    public static function sendReminderMessage($record, int $days, array $settings): array
    {
        $row = is_array($record) ? (object) $record : $record;
        if (!$row) {
            return ['success' => false, 'message' => 'record_missing'];
        }

        $botToken = self::resolveBotToken($settings);
        if (!self::validateBotToken($botToken)) {
            return ['success' => false, 'message' => 'invalid_bot_token'];
        }

        $telegramUserId = (int) ($row->reminder_telegram_user_id ?? 0);
        if ($telegramUserId <= 0) {
            $subscription = Capsule::table(self::TABLE_SUBSCRIPTIONS)
                ->where('userid', (int) ($row->userid ?? 0))
                ->where('enabled', 1)
                ->first();
            $telegramUserId = (int) ($subscription->telegram_user_id ?? 0);
        }

        if ($telegramUserId <= 0) {
            return ['success' => false, 'message' => 'telegram_not_bound'];
        }

        $message = self::buildReminderText($row, $days, $settings);
        $sendResult = self::telegramApiRequest($botToken, 'sendMessage', [
            'chat_id' => (string) $telegramUserId,
            'text' => $message,
            'disable_web_page_preview' => '1',
        ]);

        if (!($sendResult['ok'] ?? false)) {
            $errorCode = (int) ($sendResult['error_code'] ?? ($sendResult['http_status'] ?? 0));
            $description = trim((string) ($sendResult['description'] ?? 'send_failed'));
            if ($errorCode === 429) {
                $retryAfter = (int) (($sendResult['parameters']['retry_after'] ?? 0));
                throw new CfTelegramExpiryReminderException('rate_limited', $description !== '' ? $description : 'rate_limited', $retryAfter);
            }
            return [
                'success' => false,
                'message' => $description !== '' ? $description : 'send_failed',
                'error_code' => $errorCode,
            ];
        }

        return ['success' => true, 'message' => 'sent'];
    }
    public static function defaultTemplate(): string
    {
        return "【域名到期提醒】
域名：{\$fqdn}
到期时间：{\$expiry_datetime}
剩余天数：{\$days_left} 天
请及时续期，避免域名失效。";
    }

    public static function defaultEnglishTemplate(): string
    {
        return "[Domain Expiry Reminder]
Domain: {\$fqdn}
Expiry Time: {\$expiry_datetime}
Days Left: {\$days_left}
Please renew in time to avoid domain suspension.";
    }

    private static function normalizeTemplateLineBreaks(string $template): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", $template);
        return str_replace(['\\r\\n', '\\n', '\\r'], "\n", $template);
    }

    private static function normalizeClientLanguage(?string $language): string
    {
        if (function_exists('cfmod_normalize_language_code')) {
            $normalized = trim((string) cfmod_normalize_language_code($language));
            if ($normalized === 'chinese' || $normalized === 'english') {
                return $normalized;
            }
        }

        $value = strtolower(trim((string) $language));
        if ($value === '') {
            return 'english';
        }

        if ($value === 'cn' || strpos($value, 'zh') === 0 || strpos($value, 'chinese') === 0) {
            return 'chinese';
        }

        if (strpos($value, 'en') === 0 || strpos($value, 'english') === 0) {
            return 'english';
        }

        return 'english';
    }

    private static function resolveRecordLanguage($row): string
    {
        $item = is_array($row) ? (object) $row : $row;
        if (!$item) {
            return 'english';
        }

        $language = trim((string) ($item->client_language ?? ($item->language ?? '')));
        if ($language === '') {
            $userId = (int) ($item->userid ?? 0);
            if ($userId > 0) {
                try {
                    $client = Capsule::table('tblclients')->select('language')->where('id', $userId)->first();
                    $language = trim((string) ($client->language ?? ''));
                } catch (\Throwable $e) {
                    $language = '';
                }
            }
        }

        return self::normalizeClientLanguage($language);
    }

    private static function resolveTemplateByLanguage(array $settings, string $language): string
    {
        $legacyTemplate = trim((string) ($settings['renewal_notice_telegram_template'] ?? ''));
        $zhTemplate = trim((string) ($settings['renewal_notice_telegram_template_zh'] ?? ''));
        $enTemplate = trim((string) ($settings['renewal_notice_telegram_template_en'] ?? ''));

        $defaultTemplate = $legacyTemplate !== ''
            ? $legacyTemplate
            : ($zhTemplate !== '' ? $zhTemplate : self::defaultTemplate());

        if ($language === 'chinese') {
            $template = $zhTemplate !== '' ? $zhTemplate : $defaultTemplate;
            return $template !== '' ? $template : self::defaultTemplate();
        }

        if ($language === 'english') {
            $template = $enTemplate !== '' ? $enTemplate : $defaultTemplate;
            return $template !== '' ? $template : self::defaultEnglishTemplate();
        }

        return $defaultTemplate !== '' ? $defaultTemplate : self::defaultTemplate();
    }

    private static function buildReminderText($row, int $days, array $settings = []): string
    {
        $item = is_array($row) ? (object) $row : $row;
        $subdomain = trim((string) ($item->subdomain ?? ''));
        $rootdomain = trim((string) ($item->rootdomain ?? ''));
        $fqdn = $subdomain;
        if ($fqdn !== '' && $rootdomain !== '' && stripos($fqdn, $rootdomain) === false) {
            $fqdn = rtrim($subdomain, '.') . '.' . ltrim($rootdomain, '.');
        }

        $expiryRaw = trim((string) ($item->expires_at ?? ''));
        $expiryDate = '';
        $expiryDateTime = '';
        if ($expiryRaw !== '') {
            $expiryTs = strtotime($expiryRaw);
            if ($expiryTs !== false) {
                $expiryDate = date('Y-m-d', $expiryTs);
                $expiryDateTime = date('Y-m-d H:i:s', $expiryTs);
            }
        }

        $daysLeft = max(1, $days);
        $vars = [
            '{$domain}' => $subdomain !== '' ? $subdomain : '-',
            '{$rootdomain}' => $rootdomain !== '' ? $rootdomain : '-',
            '{$fqdn}' => $fqdn !== '' ? $fqdn : '-',
            '{$expiry_date}' => $expiryDate !== '' ? $expiryDate : '-',
            '{$expiry_datetime}' => $expiryDateTime !== '' ? $expiryDateTime : '-',
            '{$days_left}' => (string) $daysLeft,
            '{$reminder_days}' => (string) $daysLeft,
        ];

        $language = self::resolveRecordLanguage($item);
        $template = self::resolveTemplateByLanguage($settings, $language);
        $template = self::normalizeTemplateLineBreaks($template);

        $message = trim(strtr($template, $vars));
        if ($message === '') {
            $fallbackTemplate = $language === 'chinese' ? self::defaultTemplate() : self::defaultEnglishTemplate();
            $message = trim(strtr($fallbackTemplate, $vars));
        }

        $message = self::normalizeTemplateLineBreaks($message);
        if ($message === '') {
            $message = $language === 'chinese' ? '域名到期提醒' : 'Domain expiry reminder';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($message, 'UTF-8') > 3900) {
                $message = mb_substr($message, 0, 3900, 'UTF-8');
            }
        } elseif (strlen($message) > 3900) {
            $message = substr($message, 0, 3900);
        }

        return $message;
    }

    private static function normalizeAuthPayload(array $authPayload): array
    {
        $result = [];
        foreach ($authPayload as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }
            $result[$key] = trim((string) $value);
        }
        return $result;
    }

    private static function verifyAuthPayload(array $authPayload, string $botToken, int $maxAgeSeconds): array
    {
        $payload = self::normalizeAuthPayload($authPayload);
        $telegramUserId = (int) ($payload['id'] ?? 0);
        $authDate = (int) ($payload['auth_date'] ?? 0);
        $hash = strtolower(trim((string) ($payload['hash'] ?? '')));

        if ($telegramUserId <= 0 || $authDate <= 0 || $hash === '') {
            throw new CfTelegramExpiryReminderException('auth_invalid');
        }

        if ($maxAgeSeconds > 0 && (time() - $authDate) > $maxAgeSeconds) {
            throw new CfTelegramExpiryReminderException('auth_expired');
        }

        $dataCheckString = self::buildDataCheckString($payload);
        if ($dataCheckString === '') {
            throw new CfTelegramExpiryReminderException('auth_invalid');
        }

        $secretKey = hash('sha256', $botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        if (!hash_equals($expectedHash, $hash)) {
            throw new CfTelegramExpiryReminderException('auth_invalid');
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

    private static function buildDataCheckString(array $payload): string
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

    private static function telegramApiRequest(string $botToken, string $method, array $params): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'description' => 'curl_missing', 'error_code' => 0, 'http_status' => 0];
        }

        $url = rtrim(self::TELEGRAM_API_BASE, '/') . '/bot' . rawurlencode($botToken) . '/' . $method;
        $curl = curl_init();
        if ($curl === false) {
            return ['ok' => false, 'description' => 'curl_init_failed', 'error_code' => 0, 'http_status' => 0];
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            return ['ok' => false, 'description' => ($error !== '' ? $error : 'curl_exec_failed'), 'error_code' => 0, 'http_status' => $status];
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'description' => 'invalid_json', 'error_code' => 0, 'http_status' => $status];
        }

        $payload['http_status'] = $status;
        return $payload;
    }
}
