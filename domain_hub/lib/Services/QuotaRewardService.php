<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfQuotaRewardService
{
    private const TABLE_QUOTAS = 'mod_cloudflare_subdomain_quotas';
    private const TABLE_REWARD_LOGS = 'mod_cloudflare_invite_reward_logs';

    public static function defaultChangeSet(): array
    {
        return [
            'before_max_count' => 0,
            'after_max_count' => 0,
            'before_bonus_count' => 0,
            'after_bonus_count' => 0,
            'applied_to_max_count' => 0,
        ];
    }

    public static function ensureRewardLogColumns(): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE_REWARD_LOGS)) {
            return;
        }
        if (!Capsule::schema()->hasColumn(self::TABLE_REWARD_LOGS, 'before_max_count')) {
            Capsule::schema()->table(self::TABLE_REWARD_LOGS, function ($table) {
                $table->integer('before_max_count')->default(0)->after('cap_snapshot');
            });
        }
        if (!Capsule::schema()->hasColumn(self::TABLE_REWARD_LOGS, 'after_max_count')) {
            Capsule::schema()->table(self::TABLE_REWARD_LOGS, function ($table) {
                $table->integer('after_max_count')->default(0)->after('before_max_count');
            });
        }
        if (!Capsule::schema()->hasColumn(self::TABLE_REWARD_LOGS, 'before_bonus_count')) {
            Capsule::schema()->table(self::TABLE_REWARD_LOGS, function ($table) {
                $table->integer('before_bonus_count')->default(0)->after('after_max_count');
            });
        }
        if (!Capsule::schema()->hasColumn(self::TABLE_REWARD_LOGS, 'after_bonus_count')) {
            Capsule::schema()->table(self::TABLE_REWARD_LOGS, function ($table) {
                $table->integer('after_bonus_count')->default(0)->after('before_bonus_count');
            });
        }
        if (!Capsule::schema()->hasColumn(self::TABLE_REWARD_LOGS, 'applied_to_max_count')) {
            Capsule::schema()->table(self::TABLE_REWARD_LOGS, function ($table) {
                $table->tinyInteger('applied_to_max_count')->default(0)->after('after_bonus_count');
                $table->index(['inviter_userid', 'applied_to_max_count'], 'idx_inviter_applied_to_max');
            });
        }
    }

    public static function ensureQuotaRow(int $userId, int $defaultMax, int $defaultInviteLimit, string $now): object
    {
        $quota = Capsule::table(self::TABLE_QUOTAS)->where('userid', $userId)->lockForUpdate()->first();
        if ($quota) {
            return $quota;
        }

        Capsule::table(self::TABLE_QUOTAS)->insert([
            'userid' => $userId,
            'used_count' => 0,
            'max_count' => max(0, $defaultMax),
            'invite_bonus_count' => 0,
            'invite_bonus_limit' => max(0, $defaultInviteLimit),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (object) [
            'userid' => $userId,
            'used_count' => 0,
            'max_count' => max(0, $defaultMax),
            'invite_bonus_count' => 0,
            'invite_bonus_limit' => max(0, $defaultInviteLimit),
        ];
    }

    public static function grantSingleReward(int $userId, string $now): array
    {
        if ($userId <= 0) {
            return self::defaultChangeSet();
        }
        $quota = Capsule::table(self::TABLE_QUOTAS)->where('userid', $userId)->lockForUpdate()->first();
        if (!$quota) {
            Capsule::table(self::TABLE_QUOTAS)->insert([
                'userid' => $userId,
                'used_count' => 0,
                'max_count' => 1,
                'invite_bonus_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return [
                'before_max_count' => 0,
                'after_max_count' => 1,
                'before_bonus_count' => 0,
                'after_bonus_count' => 1,
                'applied_to_max_count' => 1,
            ];
        }

        $beforeMax = max(0, (int) ($quota->max_count ?? 0));
        $beforeBonus = max(0, (int) ($quota->invite_bonus_count ?? 0));
        $afterMax = $beforeMax + 1;
        $afterBonus = $beforeBonus + 1;
        Capsule::table(self::TABLE_QUOTAS)->where('userid', $userId)->update([
            'max_count' => $afterMax,
            'invite_bonus_count' => $afterBonus,
            'updated_at' => $now,
        ]);

        return [
            'before_max_count' => $beforeMax,
            'after_max_count' => $afterMax,
            'before_bonus_count' => $beforeBonus,
            'after_bonus_count' => $afterBonus,
            'applied_to_max_count' => 1,
        ];
    }
}
