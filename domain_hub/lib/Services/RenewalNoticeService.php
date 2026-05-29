<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfRenewalNoticeService
{
    public const TABLE = 'mod_cloudflare_expiry_notices';

    public static function ensureTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($table) {
            /** @var \Illuminate\Database\Schema\Blueprint $table */
            $table->increments('id');
            $table->integer('subdomain_id')->unsigned()->index();
            $table->string('reminder_key', 50)->index();
            $table->dateTime('expires_at_snapshot')->nullable()->index();
            $table->dateTime('sent_at');
            $table->timestamps();
            $table->unique(['subdomain_id', 'reminder_key', 'expires_at_snapshot'], 'uniq_subdomain_reminder_expiry');
        });
    }

    public static function isEnabled(array $settings): bool
    {
        $value = strtolower((string) ($settings['renewal_notice_enabled'] ?? ''));
        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }

    public static function parseConfiguredDays(array $settings, array $override = []): array
    {
        $candidates = [];
        if (!empty($override)) {
            $candidates = $override;
        } else {
            $candidates[] = intval($settings['renewal_notice_days_primary'] ?? 0);
            $candidates[] = intval($settings['renewal_notice_days_secondary'] ?? 0);
        }
        $filtered = [];
        foreach ($candidates as $day) {
            $day = intval($day);
            if ($day > 0) {
                $filtered[] = $day;
            }
        }
        $filtered = array_values(array_unique($filtered));
        sort($filtered, SORT_NUMERIC);
        return $filtered;
    }

    public static function reminderKey(int $days): string
    {
        return 'day' . $days;
    }

    public static function markReminderSent(int $subdomainId, string $reminderKey, ?string $expiresAtSnapshot): void
    {
        self::ensureTable();
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE)->updateOrInsert(
            [
                'subdomain_id' => $subdomainId,
                'reminder_key' => $reminderKey,
                'expires_at_snapshot' => $expiresAtSnapshot,
            ],
            [
                'sent_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public static function sendReminderEmail($record, string $templateName, int $days, ?string $overrideEmail = null): array
    {
        if (!function_exists('localAPI')) {
            return ['success' => false, 'message' => 'localAPI_unavailable'];
        }
        if (!$record) {
            return ['success' => false, 'message' => 'record_missing'];
        }
        $subdomain = is_array($record) ? (object) $record : $record;
        $userId = intval($subdomain->userid ?? 0);
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'userid_missing'];
        }
        $expiry = $subdomain->expires_at ?? null;
        $expiryTs = $expiry ? strtotime((string) $expiry) : null;
        $domainLabel = (string) ($subdomain->subdomain ?? '');
        $rootdomain = (string) ($subdomain->rootdomain ?? '');
        $fqdn = $domainLabel;
        if ($fqdn !== '' && $rootdomain !== '' && stripos($fqdn, $rootdomain) === false) {
            $fqdn = rtrim($domainLabel, '.') . '.' . ltrim($rootdomain, '.');
        }
        $customVars = [
            'domain' => $domainLabel,
            'rootdomain' => $rootdomain,
            'fqdn' => $fqdn,
            'expiry_date' => $expiryTs ? date('Y-m-d', $expiryTs) : '',
            'expiry_datetime' => $expiryTs ? date('Y-m-d H:i:s', $expiryTs) : '',
            'days_left' => $days,
            'reminder_days' => $days,
        ];
        $params = [
            'messagename' => $templateName,
            'id' => $userId,
            'customvars' => base64_encode(serialize($customVars)),
        ];
        if ($overrideEmail && filter_var($overrideEmail, FILTER_VALIDATE_EMAIL)) {
            $params['email'] = $overrideEmail;
        }
        try {
            $response = localAPI('SendEmail', $params);
            if (!is_array($response) || ($response['result'] ?? '') !== 'success') {
                $message = is_array($response) ? ($response['message'] ?? 'send_failed') : 'send_failed';
                return ['success' => false, 'message' => $message];
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => true, 'message' => 'sent'];
    }
}
