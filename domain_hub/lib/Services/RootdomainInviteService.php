<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

/**
 * 根域名邀请注册服务
 * 用户注册指定根域名的二级域名时需要使用邀请码
 */
class CfRootdomainInviteService
{
    private const CODE_LENGTH = 10;
    private const TABLE_CODES = 'mod_cloudflare_rootdomain_invite_codes';
    private const TABLE_LOGS = 'mod_cloudflare_rootdomain_invite_logs';

    /**
     * 确保表存在并且字段完整
     */
    public static function ensureTables(): void
    {
        try {
            // 根域名邀请码表
            if (!Capsule::schema()->hasTable(self::TABLE_CODES)) {
                Capsule::schema()->create(self::TABLE_CODES, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->string('rootdomain', 255);
                    $table->string('invite_code', 10)->unique();
                    $table->integer('code_generate_count')->unsigned()->default(1);
                    $table->timestamps();
                    $table->unique(['userid', 'rootdomain']);
                    $table->index('rootdomain');
                    $table->index('userid');
                    $table->index('invite_code');
                });
            }

            // 根域名邀请注册日志表
            if (!Capsule::schema()->hasTable(self::TABLE_LOGS)) {
                Capsule::schema()->create(self::TABLE_LOGS, function ($table) {
                    $table->increments('id');
                    $table->string('rootdomain', 255);
                    $table->string('invite_code', 10);
                    $table->integer('inviter_userid')->unsigned();
                    $table->integer('invitee_userid')->unsigned()->nullable();
                    $table->string('invitee_email', 191)->nullable();
                    $table->string('subdomain', 255)->nullable();
                    $table->string('invitee_ip', 64)->nullable();
                    $table->timestamps();
                    $table->index('rootdomain');
                    $table->index('invite_code');
                    $table->index('inviter_userid');
                    $table->index('invitee_userid');
                    $table->index('invitee_email');
                    $table->index('created_at');
                });
            } else {
                // 表存在但可能缺少 subdomain 字段（旧版本升级）
                if (!Capsule::schema()->hasColumn(self::TABLE_LOGS, 'subdomain')) {
                    Capsule::schema()->table(self::TABLE_LOGS, function ($table) {
                        $table->string('subdomain', 255)->nullable()->after('invitee_email');
                    });
                }
            }
        } catch (\Throwable $e) {
            // ignore schema creation errors
        }
    }

    /**
     * 获取或创建用户在指定根域名的邀请码
     */
    public static function getOrCreateInviteCode(int $userId, string $rootdomain): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID');
        }

        $rootdomain = strtolower(trim($rootdomain));
        if ($rootdomain === '') {
            throw new \InvalidArgumentException('Invalid rootdomain');
        }

        self::ensureTables();

        $row = Capsule::table(self::TABLE_CODES)
            ->where('userid', $userId)
            ->where('rootdomain', $rootdomain)
            ->first();

        if (!$row) {
            $code = self::generateUniqueCode();
            $now = date('Y-m-d H:i:s');
            
            $id = Capsule::table(self::TABLE_CODES)->insertGetId([
                'userid' => $userId,
                'rootdomain' => $rootdomain,
                'invite_code' => $code,
                'code_generate_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = Capsule::table(self::TABLE_CODES)->where('id', $id)->first();
        }

        return self::normalizeRow($row);
    }

    /**
     * 验证并使用邀请码注册域名
     */
    public static function validateAndUseInviteCode(
        int $inviteeUserId,
        string $rootdomain,
        string $inputCode,
        string $subdomain,
        string $inviteeEmail,
        string $ipAddress = ''
    ): void {
        self::ensureTables();

        $rootdomain = strtolower(trim($rootdomain));
        $cleanCode = strtoupper(trim($inputCode));

        if ($cleanCode === '') {
            throw new \InvalidArgumentException('invalid_code');
        }

        // 查找邀请码
        $codeRow = Capsule::table(self::TABLE_CODES)
            ->where('invite_code', $cleanCode)
            ->where('rootdomain', $rootdomain)
            ->first();

        if (!$codeRow) {
            throw new \InvalidArgumentException('invalid_code');
        }

        $inviterId = (int) ($codeRow->userid ?? 0);

        // 不能使用自己的邀请码
        if ($inviterId === $inviteeUserId) {
            throw new \InvalidArgumentException('self_code');
        }

        // 检查邀请人状态
        if (!self::inviterCanShare($inviterId)) {
            throw new \InvalidArgumentException('inviter_banned');
        }

        // 检查邀请人是否达到邀请上限
        if (!self::checkInviterLimit($inviterId, $rootdomain)) {
            throw new \InvalidArgumentException('inviter_limit_reached');
        }

        // 记录日志
        Capsule::table(self::TABLE_LOGS)->insert([
            'rootdomain' => $rootdomain,
            'invite_code' => $cleanCode,
            'inviter_userid' => $inviterId,
            'invitee_userid' => $inviteeUserId,
            'invitee_email' => strtolower(trim($inviteeEmail ?? '')),
            'subdomain' => strtolower(trim($subdomain)),
            'invitee_ip' => $ipAddress,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 轮换邀请码
        self::rotateInviteCode((int) ($codeRow->id ?? 0));
    }

    /**
     * 获取用户在指定根域名下已邀请的数量
     */
    public static function getUserInviteCount(int $userId, string $rootdomain): int
    {
        self::ensureTables();

        $rootdomain = strtolower(trim($rootdomain));

        try {
            return Capsule::table(self::TABLE_LOGS)
                ->where('inviter_userid', $userId)
                ->where('rootdomain', $rootdomain)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 检查邀请人是否达到邀请上限
     */
    public static function checkInviterLimit(int $inviterId, string $rootdomain): bool
    {
        $maxLimit = self::getMaxInvitesPerUser();

        $isPrivileged = function_exists('cf_is_user_privileged') && cf_is_user_privileged($inviterId);
        $privilegedUnlimitedInvite = $isPrivileged
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('unlimited_invite_generation');
        if ($privilegedUnlimitedInvite) {
            return true;
        }

        // 0 表示不限制
        if ($maxLimit <= 0) {
            return true;
        }

        $currentCount = self::getUserInviteCount($inviterId, $rootdomain);
        return $currentCount < $maxLimit;
    }

    /**
     * 获取每个用户最多可邀请的数量（配置）
     */
    public static function getMaxInvitesPerUser(): int
    {
        try {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $value = Capsule::table('tbladdonmodules')
                ->where('module', $moduleSlug)
                ->where('setting', 'rootdomain_invite_max_per_user')
                ->value('value');
            return max(0, intval($value ?? 0));
        } catch (\Throwable $e) {
            return 0; // 0 = 不限制
        }
    }

    /**
     * 获取后台管理日志
     */
    public static function fetchAdminLogs(string $searchTerm, string $searchType, int $page, int $perPage = 20): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $searchTerm = trim($searchTerm);
        $searchType = trim($searchType);

        try {
            $query = Capsule::table(self::TABLE_LOGS . ' as l')
                ->leftJoin('tblclients as inviter', 'l.inviter_userid', '=', 'inviter.id')
                ->leftJoin('tblclients as invitee', 'l.invitee_userid', '=', 'invitee.id')
                ->select(
                    'l.*',
                    'inviter.email as inviter_email',
                    'invitee.email as invitee_account_email'
                );

            if ($searchTerm !== '') {
                if ($searchType === 'email') {
                    $like = '%' . $searchTerm . '%';
                    $query->where(function ($q) use ($like) {
                        $q->where('l.invitee_email', 'like', $like)
                            ->orWhere('inviter.email', 'like', $like)
                            ->orWhere('invitee.email', 'like', $like);
                    });
                } elseif ($searchType === 'rootdomain') {
                    $query->where('l.rootdomain', 'like', '%' . $searchTerm . '%');
                } elseif ($searchType === 'code') {
                    $query->where('l.invite_code', 'like', strtoupper($searchTerm) . '%');
                } else {
                    // 默认搜索所有
                    $like = '%' . $searchTerm . '%';
                    $query->where(function ($q) use ($like, $searchTerm) {
                        $q->where('l.rootdomain', 'like', $like)
                            ->orWhere('l.invite_code', 'like', strtoupper($searchTerm) . '%')
                            ->orWhere('l.invitee_email', 'like', $like)
                            ->orWhere('inviter.email', 'like', $like)
                            ->orWhere('invitee.email', 'like', $like);
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
                    'rootdomain' => (string) ($row->rootdomain ?? ''),
                    'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
                    'inviter_userid' => (int) ($row->inviter_userid ?? 0),
                    'inviter_email' => (string) ($row->inviter_email ?? ''),
                    'invitee_userid' => (int) ($row->invitee_userid ?? 0),
                    'invitee_email' => (string) ($row->invitee_email ?? ($row->invitee_account_email ?? '')),
                    'subdomain' => (string) ($row->subdomain ?? ''),
                    'invitee_ip' => (string) ($row->invitee_ip ?? ''),
                    'created_at' => $row->created_at ?? '',
                ];
            }

            return [
                'items' => $items,
                'search' => $searchTerm,
                'searchType' => $searchType,
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
                'search' => $searchTerm,
                'searchType' => $searchType,
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
     * 获取用户的邀请历史（特定根域名）
     */
    public static function fetchUserInviteLogs(int $userId, string $rootdomain, int $page = 1, int $perPage = 10): array
    {
        self::ensureTables();

        $rootdomain = strtolower(trim($rootdomain));
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        try {
            $query = Capsule::table(self::TABLE_LOGS . ' as l')
                ->leftJoin('tblclients as c', 'l.invitee_userid', '=', 'c.id')
                ->select('l.*', 'c.email as joined_email')
                ->where('l.inviter_userid', $userId)
                ->where('l.rootdomain', $rootdomain);

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
                    'subdomain' => (string) ($log->subdomain ?? ''),
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
     * 获取用户所有根域名的邀请码
     */
    public static function getUserAllInviteCodes(int $userId): array
    {
        self::ensureTables();

        try {
            $rows = Capsule::table(self::TABLE_CODES)
                ->where('userid', $userId)
                ->orderBy('rootdomain', 'asc')
                ->get();

            $result = [];
            foreach ($rows as $row) {
                $rootdomain = strtolower((string) ($row->rootdomain ?? ''));
                $result[$rootdomain] = [
                    'id' => (int) ($row->id ?? 0),
                    'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
                    'code_generate_count' => (int) ($row->code_generate_count ?? 0),
                    'created_at' => $row->created_at ?? null,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 检查根域名是否需要邀请码
     */
    public static function isInviteRequired(string $rootdomain): bool
    {
        $rootdomain = strtolower(trim($rootdomain));
        if ($rootdomain === '') {
            return false;
        }

        try {
            $row = Capsule::table('mod_cloudflare_rootdomains')
                ->where('domain', $rootdomain)
                ->first();

            if (!$row) {
                return false;
            }

            return !empty($row->require_invite_code);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 轮换邀请码
     */
    private static function rotateInviteCode(int $codeId): ?string
    {
        if ($codeId <= 0) {
            return null;
        }

        try {
            $newCode = self::generateUniqueCode();
            Capsule::table(self::TABLE_CODES)
                ->where('id', $codeId)
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
     * 生成唯一邀请码（10位）
     */
    private static function generateUniqueCode(): string
    {
        do {
            // 使用字母和数字，排除容易混淆的字符
            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $maxIndex = strlen($characters) - 1;
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $characters[random_int(0, $maxIndex)];
            }
            $exists = Capsule::table(self::TABLE_CODES)->where('invite_code', $code)->exists();
        } while ($exists);
        return $code;
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
            // ignore status lookup errors
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

    /**
     * 标准化数据库行
     */
    private static function normalizeRow($row): array
    {
        if (!$row) {
            return [
                'id' => 0,
                'userid' => 0,
                'rootdomain' => '',
                'invite_code' => '',
                'code_generate_count' => 0,
            ];
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'userid' => (int) ($row->userid ?? 0),
            'rootdomain' => strtolower((string) ($row->rootdomain ?? '')),
            'invite_code' => strtoupper((string) ($row->invite_code ?? '')),
            'code_generate_count' => (int) ($row->code_generate_count ?? 0),
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }
}
