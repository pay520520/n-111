<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfDomainPermanentUpgradeService
{
    private const TABLE_REQUESTS = 'mod_cloudflare_domain_permanent_upgrade_requests';
    private const TABLE_ASSISTS = 'mod_cloudflare_domain_permanent_upgrade_assists';
    private const TABLE_INVOICE_MAP = 'mod_cloudflare_domain_permanent_upgrade_invoice_map';
    private const CODE_LENGTH = 10;

    public static function isEnabled(array $settings): bool
    {
        $raw = $settings['enable_domain_permanent_upgrade'] ?? '1';
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($raw);
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getRequiredAssistCount(array $settings): int
    {
        $raw = intval($settings['domain_permanent_upgrade_assist_required'] ?? 3);
        return max(1, min(100, $raw));
    }

    public static function getHelperAssistLimit(array $settings): int
    {
        $raw = intval($settings['domain_permanent_upgrade_helper_limit'] ?? 0);
        return max(0, min(1000, $raw));
    }

    public static function isRealtimeFeedEnabled(array $settings): bool
    {
        $raw = $settings['domain_permanent_upgrade_enable_realtime_feed'] ?? '1';
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($raw);
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function isPaidEnabled(array $settings): bool
    {
        $raw = $settings['domain_permanent_upgrade_paid_enabled'] ?? '0';
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($raw);
        }
        return in_array(strtolower(trim((string) $raw)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getPaidPrice(array $settings): float
    {
        return round(max(0, (float) ($settings['domain_permanent_upgrade_paid_price'] ?? 0)), 2);
    }

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();

            if (!$schema->hasTable(self::TABLE_REQUESTS)) {
                $schema->create(self::TABLE_REQUESTS, function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned();
                    $table->integer('subdomain_id')->unsigned()->unique();
                    $table->string('assist_code', 20)->unique();
                    $table->integer('target_assists')->unsigned()->default(3);
                    $table->integer('assist_count')->unsigned()->default(0);
                    $table->string('status', 20)->default('pending');
                    $table->dateTime('upgraded_at')->nullable();
                    $table->timestamps();
                    $table->index('userid');
                    $table->index('status');
                    $table->index('created_at');
                });
            }

            if (!$schema->hasTable(self::TABLE_ASSISTS)) {
                $schema->create(self::TABLE_ASSISTS, function ($table) {
                    $table->increments('id');
                    $table->integer('request_id')->unsigned();
                    $table->integer('helper_userid')->unsigned();
                    $table->string('helper_email', 191)->nullable();
                    $table->string('helper_ip', 64)->nullable();
                    $table->string('assist_code', 20);
                    $table->timestamps();
                    $table->unique(['request_id', 'helper_userid'], 'uniq_cf_perm_upgrade_helper_once');
                    $table->index('request_id');
                    $table->index('helper_userid');
                    $table->index('assist_code');
                    $table->index('created_at');
                });
            }
            if (!$schema->hasTable(self::TABLE_INVOICE_MAP)) {
                $schema->create(self::TABLE_INVOICE_MAP, function ($table) {
                    $table->increments('id');
                    $table->integer('request_id')->unsigned();
                    $table->integer('subdomain_id')->unsigned();
                    $table->integer('userid')->unsigned();
                    $table->integer('invoice_id')->unsigned();
                    $table->string('status', 20)->default('pending');
                    $table->dateTime('paid_at')->nullable();
                    $table->timestamps();
                    $table->unique(['request_id', 'status'], 'uniq_cf_perm_upgrade_invoice_req_status');
                    $table->unique(['invoice_id'], 'uniq_cf_perm_upgrade_invoice_id');
                    $table->index(['userid', 'status'], 'idx_cf_perm_upgrade_invoice_user_status');
                });
            }
            if (!$schema->hasColumn(self::TABLE_REQUESTS, 'upgrade_source')) {
                $schema->table(self::TABLE_REQUESTS, function ($table) {
                    $table->string('upgrade_source', 20)->nullable()->after('status');
                });
            }
            if (!$schema->hasColumn(self::TABLE_REQUESTS, 'paid_amount')) {
                $schema->table(self::TABLE_REQUESTS, function ($table) {
                    $table->decimal('paid_amount', 10, 2)->nullable()->after('upgrade_source');
                });
            }

            self::ensureAssistCodeUniqueIndex();
        } catch (\Throwable $e) {
        }
    }

    private static function ensureAssistCodeUniqueIndex(): void
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE_REQUESTS)) {
                return;
            }

            $indexes = Capsule::select('SHOW INDEX FROM `' . self::TABLE_REQUESTS . '`');
            $hasUniqueAssistCode = false;
            foreach ($indexes as $index) {
                $column = strtolower((string) ($index->Column_name ?? $index->column_name ?? ''));
                $nonUnique = (int) ($index->Non_unique ?? $index->non_unique ?? 1);
                if ($column === 'assist_code' && $nonUnique === 0) {
                    $hasUniqueAssistCode = true;
                    break;
                }
            }

            if (!$hasUniqueAssistCode) {
                Capsule::statement('ALTER TABLE `' . self::TABLE_REQUESTS . '` ADD UNIQUE KEY `uniq_cf_perm_upgrade_assist_code` (`assist_code`)');
            }
        } catch (\Throwable $e) {
        }
    }

    public static function getUserState(int $userId, array $settings, int $page = 1, int $perPage = 10): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $requiredAssists = self::getRequiredAssistCount($settings);
        $helperAssistLimit = self::getHelperAssistLimit($settings);
        $realtimeFeedEnabled = self::isRealtimeFeedEnabled($settings);

        $state = [
            'assist_required' => $requiredAssists,
            'helper_assist_limit' => $helperAssistLimit,
            'helper_assist_count' => 0,
            'helper_assist_remaining' => $helperAssistLimit > 0 ? $helperAssistLimit : null,
            'helper_limit_reached' => false,
            'realtime_feed_enabled' => $realtimeFeedEnabled,
            'eligible_domains' => [],
            'requests' => [],
            'invoice_pending_orders' => [],
            'assist_logs' => [],
            'recent_success_feed' => [],
            'pending_count' => 0,
            'upgraded_count' => 0,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ],
        ];

        if ($userId <= 0) {
            return $state;
        }

        try {
            $helperAssistCount = (int) Capsule::table(self::TABLE_ASSISTS)
                ->where('helper_userid', $userId)
                ->count();
            $helperAssistRemaining = $helperAssistLimit > 0 ? max(0, $helperAssistLimit - $helperAssistCount) : null;
            $state['helper_assist_count'] = $helperAssistCount;
            $state['helper_assist_remaining'] = $helperAssistRemaining;
            $state['helper_limit_reached'] = $helperAssistLimit > 0 && $helperAssistCount >= $helperAssistLimit;

            $eligibleRows = Capsule::table('mod_cloudflare_subdomain as s')
                ->leftJoin(self::TABLE_REQUESTS . ' as pending_req', function ($join) {
                    $join->on('pending_req.subdomain_id', '=', 's.id')
                        ->where('pending_req.status', '=', 'pending');
                })
                ->select('s.id', 's.subdomain', 's.status')
                ->where('s.userid', $userId)
                ->where('s.never_expires', 0)
                ->whereIn('s.status', ['active', 'pending'])
                ->whereNull('pending_req.id')
                ->orderBy('s.created_at', 'desc')
                ->get();

            $eligibleDomains = [];
            foreach ($eligibleRows as $eligibleRow) {
                $eligibleDomains[] = [
                    'id' => (int) ($eligibleRow->id ?? 0),
                    'domain' => (string) ($eligibleRow->subdomain ?? ''),
                    'status' => (string) ($eligibleRow->status ?? ''),
                ];
            }
            $state['eligible_domains'] = $eligibleDomains;

            $baseQuery = Capsule::table(self::TABLE_REQUESTS . ' as r')
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->where('r.userid', $userId)
                ->whereNotIn('r.status', ['invoice_pending', 'invoice_cancelled']);

            $invoicePendingRows = Capsule::table(self::TABLE_REQUESTS . ' as r')
                ->leftJoin(self::TABLE_INVOICE_MAP . ' as im', function ($join) {
                    $join->on('im.request_id', '=', 'r.id')
                        ->where('im.status', '=', 'pending');
                })
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->select('r.id', 'r.subdomain_id', 'r.created_at', 's.subdomain as domain_name', 'im.invoice_id')
                ->where('r.userid', $userId)
                ->where('r.status', 'invoice_pending')
                ->orderBy('r.id', 'desc')
                ->get();
            $invoicePendingOrders = [];
            foreach ($invoicePendingRows as $pendingRow) {
                $invoicePendingOrders[] = [
                    'request_id' => (int) ($pendingRow->id ?? 0),
                    'subdomain_id' => (int) ($pendingRow->subdomain_id ?? 0),
                    'domain' => (string) ($pendingRow->domain_name ?? ''),
                    'invoice_id' => (int) ($pendingRow->invoice_id ?? 0),
                    'created_at' => (string) ($pendingRow->created_at ?? ''),
                ];
            }

            $total = (clone $baseQuery)->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $rows = $baseQuery
                ->select(
                    'r.id',
                    'r.userid',
                    'r.subdomain_id',
                    'r.assist_code',
                    'r.target_assists',
                    'r.assist_count',
                    'r.status',
                    'r.upgraded_at',
                    'r.created_at',
                    's.subdomain as domain_name',
                    's.never_expires as domain_never_expires'
                )
                ->orderByRaw("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END")
                ->orderBy('r.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $requestIds = [];
            $requestItems = [];
            $pendingCount = 0;
            $upgradedCount = 0;

            $pendingInvoiceMapByRequest = [];
            if (!empty($rows)) {
                $rowRequestIds = [];
                foreach ($rows as $rowItem) {
                    $rid = (int) ($rowItem->id ?? 0);
                    if ($rid > 0) {
                        $rowRequestIds[] = $rid;
                    }
                }
                if (!empty($rowRequestIds)) {
                    $pendingMaps = Capsule::table(self::TABLE_INVOICE_MAP)
                        ->select('request_id', 'invoice_id')
                        ->whereIn('request_id', $rowRequestIds)
                        ->where('status', 'pending')
                        ->get();
                    foreach ($pendingMaps as $pendingMapRow) {
                        $pendingInvoiceMapByRequest[(int) ($pendingMapRow->request_id ?? 0)] = (int) ($pendingMapRow->invoice_id ?? 0);
                    }
                }
            }

            foreach ($rows as $row) {
                $requestId = (int) ($row->id ?? 0);
                if ($requestId <= 0) {
                    continue;
                }
                $requestIds[] = $requestId;

                $status = strtolower((string) ($row->status ?? 'pending'));
                $domainNeverExpires = intval($row->domain_never_expires ?? 0) === 1;
                if ($status !== 'upgraded' && $domainNeverExpires) {
                    $status = 'upgraded';
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', $requestId)
                        ->update([
                            'status' => 'upgraded',
                            'upgraded_at' => $row->upgraded_at ?: date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }

                if ($status === 'pending') {
                    $pendingCount++;
                }
                if ($status === 'upgraded') {
                    $upgradedCount++;
                }

                $invoiceId = (int) ($pendingInvoiceMapByRequest[$requestId] ?? 0);
                if ($status === 'pending' && $invoiceId > 0) {
                    $status = 'invoice_pending';
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', $requestId)
                        ->update([
                            'status' => 'invoice_pending',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }
                if ($status === 'invoice_pending') {
                    continue;
                }

                $targetAssists = max(1, (int) ($row->target_assists ?? $requiredAssists));
                $assistCount = max(0, (int) ($row->assist_count ?? 0));
                if ($assistCount > $targetAssists) {
                    $assistCount = $targetAssists;
                }

                $requestItems[$requestId] = [
                    'id' => $requestId,
                    'subdomain_id' => (int) ($row->subdomain_id ?? 0),
                    'domain' => (string) ($row->domain_name ?? ''),
                    'assist_code' => strtoupper((string) ($row->assist_code ?? '')),
                    'target_assists' => $targetAssists,
                    'assist_count' => $assistCount,
                    'status' => $status,
                    'created_at' => (string) ($row->created_at ?? ''),
                    'upgraded_at' => (string) ($row->upgraded_at ?? ''),
                    'helpers_preview' => [],
                    'can_copy' => $status === 'pending',
                    'invoice_id' => $invoiceId,
                ];

            }

            if (!empty($requestIds)) {
                $assistRows = Capsule::table(self::TABLE_ASSISTS)
                    ->select('request_id', 'helper_email')
                    ->whereIn('request_id', $requestIds)
                    ->orderBy('id', 'desc')
                    ->get();

                foreach ($assistRows as $assistRow) {
                    $requestId = (int) ($assistRow->request_id ?? 0);
                    if ($requestId <= 0 || !isset($requestItems[$requestId])) {
                        continue;
                    }
                    if (count($requestItems[$requestId]['helpers_preview']) >= 3) {
                        continue;
                    }
                    $requestItems[$requestId]['helpers_preview'][] = self::maskEmail((string) ($assistRow->helper_email ?? ''));
                }
            }

            $state['requests'] = array_values($requestItems);
            $state['invoice_pending_orders'] = $invoicePendingOrders;
            $state['assist_logs'] = self::getAssistLogsForUser($userId, 20);
            $state['recent_success_feed'] = $realtimeFeedEnabled ? self::getRecentUpgradeFeed(12) : [];
            $state['pending_count'] = $pendingCount;
            $state['upgraded_count'] = $upgradedCount;
            $state['pagination'] = [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ];
        } catch (\Throwable $e) {
        }

        return $state;
    }

    public static function createOrGetRequest(int $userId, int $subdomainId, array $settings): array
    {
        self::ensureTables();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if ($subdomainId <= 0) {
            throw new \InvalidArgumentException('invalid_subdomain');
        }
        if (!self::isEnabled($settings)) {
            throw new \InvalidArgumentException('feature_disabled');
        }

        $targetAssists = self::getRequiredAssistCount($settings);

        return Capsule::connection()->transaction(function () use ($userId, $subdomainId, $targetAssists) {
            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', $subdomainId)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();

            if (!$subdomain) {
                throw new \InvalidArgumentException('invalid_subdomain');
            }

            if (intval($subdomain->never_expires ?? 0) === 1) {
                throw new \InvalidArgumentException('already_permanent');
            }

            $status = strtolower((string) ($subdomain->status ?? ''));
            if (!in_array($status, ['active', 'pending'], true)) {
                throw new \InvalidArgumentException('invalid_status');
            }

            $existingRequest = Capsule::table(self::TABLE_REQUESTS)
                ->where('subdomain_id', $subdomainId)
                ->lockForUpdate()
                ->first();

            $now = date('Y-m-d H:i:s');

            if ($existingRequest) {
                $requestStatus = strtolower((string) ($existingRequest->status ?? 'pending'));
                if ($requestStatus === 'upgraded') {
                    throw new \InvalidArgumentException('already_permanent');
                }
                if ($requestStatus === 'invoice_pending') {
                    throw new \InvalidArgumentException('invoice_pending_exists');
                }

                if ($requestStatus !== 'pending') {
                    $newCode = self::generateUniqueAssistCode();
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', (int) ($existingRequest->id ?? 0))
                        ->update([
                            'assist_code' => $newCode,
                            'target_assists' => $targetAssists,
                            'assist_count' => 0,
                            'status' => 'pending',
                            'upgraded_at' => null,
                            'updated_at' => $now,
                        ]);
                    Capsule::table(self::TABLE_ASSISTS)
                        ->where('request_id', (int) ($existingRequest->id ?? 0))
                        ->delete();
                } else {
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', (int) ($existingRequest->id ?? 0))
                        ->update([
                            'target_assists' => $targetAssists,
                            'updated_at' => $now,
                        ]);
                }

                $refreshed = Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($existingRequest->id ?? 0))
                    ->first();

                return [
                    'created' => false,
                    'request_id' => (int) ($refreshed->id ?? 0),
                    'subdomain_id' => $subdomainId,
                    'domain' => (string) ($subdomain->subdomain ?? ''),
                    'assist_code' => strtoupper((string) ($refreshed->assist_code ?? '')),
                    'assist_count' => max(0, (int) ($refreshed->assist_count ?? 0)),
                    'target_assists' => max(1, (int) ($refreshed->target_assists ?? $targetAssists)),
                ];
            }

            $assistCode = self::generateUniqueAssistCode();
            $requestId = Capsule::table(self::TABLE_REQUESTS)->insertGetId([
                'userid' => $userId,
                'subdomain_id' => $subdomainId,
                'assist_code' => $assistCode,
                'target_assists' => $targetAssists,
                'assist_count' => 0,
                'status' => 'pending',
                'upgraded_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'created' => true,
                'request_id' => (int) $requestId,
                'subdomain_id' => $subdomainId,
                'domain' => (string) ($subdomain->subdomain ?? ''),
                'assist_code' => strtoupper($assistCode),
                'assist_count' => 0,
                'target_assists' => $targetAssists,
            ];
        });
    }

    public static function cancelRequest(int $userId, int $requestId): array
    {
        self::ensureTables();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if ($requestId <= 0) {
            throw new \InvalidArgumentException('invalid_request');
        }
        return Capsule::connection()->transaction(function () use ($userId, $requestId) {
            $request = Capsule::table(self::TABLE_REQUESTS)
                ->where('id', $requestId)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();
            if (!$request) {
                throw new \InvalidArgumentException('request_not_found');
            }

            $requestStatus = strtolower((string) ($request->status ?? 'pending'));
            if ($requestStatus !== 'pending') {
                throw new \InvalidArgumentException('request_not_pending');
            }

            $now = date('Y-m-d H:i:s');
            Capsule::table(self::TABLE_REQUESTS)
                ->where('id', $requestId)
                ->update([
                    'status' => 'invoice_cancelled',
                    'updated_at' => $now,
                ]);

            return [
                'request_id' => $requestId,
                'subdomain_id' => (int) ($request->subdomain_id ?? 0),
                'assist_code' => strtoupper((string) ($request->assist_code ?? '')),
            ];
        });
    }

    public static function cancelInvoicePendingOrder(int $userId, int $requestId): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if ($requestId <= 0) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return Capsule::connection()->transaction(function () use ($userId, $requestId) {
            $request = Capsule::table(self::TABLE_REQUESTS)
                ->where('id', $requestId)
                ->where('userid', $userId)
                ->lockForUpdate()
                ->first();
            if (!$request) {
                throw new \InvalidArgumentException('request_not_found');
            }

            $status = strtolower((string) ($request->status ?? 'pending'));
            if ($status !== 'invoice_pending') {
                throw new \InvalidArgumentException('request_not_invoice_pending');
            }

            $pendingMap = Capsule::table(self::TABLE_INVOICE_MAP)
                ->where('request_id', $requestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (!$pendingMap) {
                throw new \InvalidArgumentException('invoice_not_found');
            }

            $invoiceId = (int) ($pendingMap->invoice_id ?? 0);
            if ($invoiceId > 0) {
                Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->where('userid', $userId)
                    ->whereIn('status', ['Unpaid', 'Draft', 'Payment Pending'])
                    ->update(['status' => 'Cancelled']);
            }

            $now = date('Y-m-d H:i:s');
            $hasCancelledMap = Capsule::table(self::TABLE_INVOICE_MAP)
                ->where('request_id', $requestId)
                ->where('status', 'cancelled')
                ->where('id', '!=', (int) ($pendingMap->id ?? 0))
                ->exists();
            if ($hasCancelledMap) {
                Capsule::table(self::TABLE_INVOICE_MAP)
                    ->where('id', (int) ($pendingMap->id ?? 0))
                    ->delete();
            } else {
                Capsule::table(self::TABLE_INVOICE_MAP)
                    ->where('id', (int) ($pendingMap->id ?? 0))
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => $now,
                    ]);
            }
            Capsule::table(self::TABLE_REQUESTS)
                ->where('id', $requestId)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => $now,
                ]);

            return [
                'request_id' => $requestId,
                'invoice_id' => $invoiceId,
                'subdomain_id' => (int) ($request->subdomain_id ?? 0),
            ];
        });
    }

    public static function assistByCode(int $helperUserId, string $assistCode, string $helperEmail, string $helperIp, array $settings): array
    {
        self::ensureTables();

        if ($helperUserId <= 0) {
            throw new \InvalidArgumentException('invalid_user');
        }
        if (!self::isEnabled($settings)) {
            throw new \InvalidArgumentException('feature_disabled');
        }

        $cleanCode = strtoupper(trim($assistCode));
        if ($cleanCode === '') {
            throw new \InvalidArgumentException('invalid_code');
        }

        $normalizedEmail = strtolower(trim($helperEmail));
        $helperAssistLimit = self::getHelperAssistLimit($settings);
        $now = date('Y-m-d H:i:s');

        return Capsule::connection()->transaction(function () use ($helperUserId, $cleanCode, $normalizedEmail, $helperIp, $helperAssistLimit, $now) {
            $request = Capsule::table(self::TABLE_REQUESTS)
                ->where('assist_code', $cleanCode)
                ->lockForUpdate()
                ->first();

            if (!$request) {
                throw new \InvalidArgumentException('invalid_code');
            }

            $requestStatus = strtolower((string) ($request->status ?? 'pending'));
            if ($requestStatus !== 'pending') {
                throw new \InvalidArgumentException($requestStatus === 'upgraded' ? 'already_upgraded' : 'request_closed');
            }

            $ownerUserId = (int) ($request->userid ?? 0);
            if ($ownerUserId <= 0) {
                throw new \InvalidArgumentException('request_invalid');
            }
            if ($ownerUserId === $helperUserId) {
                throw new \InvalidArgumentException('self_assist');
            }

            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                ->where('id', (int) ($request->subdomain_id ?? 0))
                ->lockForUpdate()
                ->first();

            if (!$subdomain || (int) ($subdomain->userid ?? 0) !== $ownerUserId) {
                throw new \InvalidArgumentException('request_invalid');
            }

            if (intval($subdomain->never_expires ?? 0) === 1) {
                Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($request->id ?? 0))
                    ->update([
                        'status' => 'upgraded',
                        'assist_count' => max((int) ($request->assist_count ?? 0), (int) ($request->target_assists ?? 1)),
                        'upgraded_at' => $request->upgraded_at ?: $now,
                        'updated_at' => $now,
                    ]);
                throw new \InvalidArgumentException('already_upgraded');
            }

            $existingAssist = Capsule::table(self::TABLE_ASSISTS)
                ->where('request_id', (int) ($request->id ?? 0))
                ->where('helper_userid', $helperUserId)
                ->first();

            if ($existingAssist) {
                throw new \InvalidArgumentException('already_assisted');
            }

            if ($helperAssistLimit > 0) {
                $helperAssistCount = (int) Capsule::table(self::TABLE_ASSISTS)
                    ->where('helper_userid', $helperUserId)
                    ->count();
                if ($helperAssistCount >= $helperAssistLimit) {
                    throw new \InvalidArgumentException('helper_limit_reached');
                }
            }

            Capsule::table(self::TABLE_ASSISTS)->insert([
                'request_id' => (int) ($request->id ?? 0),
                'helper_userid' => $helperUserId,
                'helper_email' => $normalizedEmail,
                'helper_ip' => $helperIp,
                'assist_code' => $cleanCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $targetAssists = max(1, (int) ($request->target_assists ?? 1));
            $assistCount = max(0, (int) ($request->assist_count ?? 0)) + 1;
            $upgraded = $assistCount >= $targetAssists;

            if ($upgraded) {
                $assistCount = $targetAssists;
                Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($request->id ?? 0))
                    ->update([
                        'assist_count' => $assistCount,
                        'status' => 'upgraded',
                        'upgrade_source' => 'assist',
                        'paid_amount' => null,
                        'upgraded_at' => $now,
                        'updated_at' => $now,
                    ]);
                self::markSubdomainPermanent((int) ($subdomain->id ?? 0), $now);
            } else {
                Capsule::table(self::TABLE_REQUESTS)
                    ->where('id', (int) ($request->id ?? 0))
                    ->update([
                        'assist_count' => $assistCount,
                        'updated_at' => $now,
                    ]);
            }

            return [
                'request_id' => (int) ($request->id ?? 0),
                'owner_userid' => $ownerUserId,
                'domain' => (string) ($subdomain->subdomain ?? ''),
                'assist_count' => $assistCount,
                'target_assists' => $targetAssists,
                'upgraded' => $upgraded,
            ];
        });
    }

    private static function markSubdomainPermanent(int $subdomainId, string $now): void
    {
        Capsule::table('mod_cloudflare_subdomain')
            ->where('id', $subdomainId)
            ->update([
                'never_expires' => 1,
                'expires_at' => null,
                'auto_deleted_at' => null,
                'renewed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private static function buildInvoiceDescription(int $userId, string $domain): string
    {
        $domain = trim($domain);
        $lang = '';
        try {
            $lang = strtolower(trim((string) Capsule::table('tblclients')->where('id', $userId)->value('language')));
        } catch (\Throwable $e) {
            $lang = '';
        }
        $isChinese = in_array($lang, ['chinese', 'zh', 'zh-cn', 'zh_cn', 'zh-hans', 'zh_hans'], true)
            || strpos($lang, 'chinese') !== false
            || strpos($lang, 'zh') === 0;
        $prefix = $isChinese ? '域名升级永久: ' : 'Domain permanent upgrade: ';
        return $prefix . $domain;
    }

    public static function payUpgrade(int $userId, int $subdomainId, array $settings): array
    {
        self::ensureTables();
        if ($userId <= 0 || $subdomainId <= 0) {
            throw new \InvalidArgumentException('invalid_request');
        }
        if (!self::isEnabled($settings) || !self::isPaidEnabled($settings)) {
            throw new \InvalidArgumentException('feature_disabled');
        }
        $price = self::getPaidPrice($settings);
        if ($price <= 0) {
            throw new \InvalidArgumentException('invalid_price');
        }

        $requiredAssists = self::getRequiredAssistCount($settings);

        return Capsule::connection()->transaction(function () use ($userId, $subdomainId, $price, $requiredAssists) {
            $now = date('Y-m-d H:i:s');
            $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', $subdomainId)->where('userid', $userId)->lockForUpdate()->first();
            if (!$sub) { throw new \InvalidArgumentException('invalid_subdomain'); }
            if ((int) ($sub->never_expires ?? 0) === 1) { throw new \InvalidArgumentException('already_permanent'); }
            $subStatus = strtolower((string) ($sub->status ?? ''));
            if (!in_array($subStatus, ['active', 'pending'], true)) { throw new \InvalidArgumentException('invalid_status'); }

            $request = Capsule::table(self::TABLE_REQUESTS)->where('subdomain_id', $subdomainId)->lockForUpdate()->first();
            if (!$request) {
                $requestId = (int) Capsule::table(self::TABLE_REQUESTS)->insertGetId([
                    'userid' => $userId, 'subdomain_id' => $subdomainId, 'assist_code' => self::generateUniqueAssistCode(),
                    'target_assists' => $requiredAssists, 'assist_count' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $request = Capsule::table(self::TABLE_REQUESTS)->where('id', $requestId)->lockForUpdate()->first();
            }
            $requestStatus = strtolower((string) ($request->status ?? 'pending'));
            if ($requestStatus === 'upgraded') {
                throw new \InvalidArgumentException('already_permanent');
            }

            $currentTargetAssists = max(1, (int) ($request->target_assists ?? 1));
            if ($currentTargetAssists < $requiredAssists) {
                $hasAssistLogs = Capsule::table(self::TABLE_ASSISTS)
                    ->where('request_id', (int) ($request->id ?? 0))
                    ->exists();
                if (!$hasAssistLogs) {
                    Capsule::table(self::TABLE_REQUESTS)
                        ->where('id', (int) $request->id)
                        ->update([
                            'target_assists' => $requiredAssists,
                            'updated_at' => $now,
                        ]);
                    $request = Capsule::table(self::TABLE_REQUESTS)->where('id', (int) $request->id)->lockForUpdate()->first();
                }
            }

            $client = Capsule::table('tblclients')->where('id', $userId)->lockForUpdate()->first();
            $credit = round((float) ($client->credit ?? 0), 2);
            if ($credit + 1e-6 >= $price) {
                Capsule::table('tblclients')->where('id', $userId)->update(['credit' => number_format(round($credit - $price, 2), 2, '.', '')]);
                self::markSubdomainPermanent((int) $sub->id, $now);
                Capsule::table(self::TABLE_REQUESTS)->where('id', (int) $request->id)->update([
                    'status' => 'upgraded', 'upgrade_source' => 'balance', 'paid_amount' => number_format($price, 2, '.', ''),
                    'assist_count' => max((int) ($request->assist_count ?? 0), (int) ($request->target_assists ?? 1)), 'upgraded_at' => $now, 'updated_at' => $now,
                ]);
                return ['upgraded' => true, 'payment' => 'balance', 'price' => $price];
            }

            $pendingMap = Capsule::table(self::TABLE_INVOICE_MAP)->where('request_id', (int) $request->id)->where('status', 'pending')->lockForUpdate()->first();
            if ($pendingMap) {
                if ($requestStatus !== 'invoice_pending') {
                    Capsule::table(self::TABLE_REQUESTS)->where('id', (int) $request->id)->update([
                        'status' => 'invoice_pending',
                        'updated_at' => $now,
                    ]);
                }
                return ['upgraded' => false, 'payment' => 'invoice_pending', 'invoice_id' => (int) $pendingMap->invoice_id, 'price' => $price];
            }

            $dueDate = date('Y-m-d', strtotime('+7 days'));
            $invoiceId = (int) Capsule::table('tblinvoices')->insertGetId([
                'userid' => $userId,
                'date' => date('Y-m-d'),
                'duedate' => $dueDate,
                'status' => 'Unpaid',
                'subtotal' => number_format($price, 2, '.', ''),
                'total' => number_format($price, 2, '.', ''),
                'tax' => 0,
                'tax2' => 0,
            ]);
            Capsule::table('tblinvoiceitems')->insert([
                'invoiceid' => $invoiceId, 'userid' => $userId, 'type' => 'Item', 'relid' => (int) $sub->id,
                'description' => self::buildInvoiceDescription($userId, (string) ($sub->subdomain ?? '')), 'amount' => number_format($price, 2, '.', ''),
                'taxed' => 0, 'duedate' => $dueDate, 'paymentmethod' => '',
            ]);
            Capsule::table(self::TABLE_INVOICE_MAP)->insert([
                'request_id' => (int) $request->id, 'subdomain_id' => (int) $sub->id, 'userid' => $userId, 'invoice_id' => $invoiceId,
                'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
            Capsule::table(self::TABLE_REQUESTS)->where('id', (int) $request->id)->update([
                'status' => 'invoice_pending',
                'updated_at' => $now,
            ]);
            return ['upgraded' => false, 'payment' => 'invoice_created', 'invoice_id' => $invoiceId, 'price' => $price];
        });
    }

    public static function settlePaidInvoice(int $invoiceId): bool
    {
        self::ensureTables();
        if ($invoiceId <= 0) {
            return false;
        }

        return (bool) Capsule::connection()->transaction(function () use ($invoiceId) {
            $now = date('Y-m-d H:i:s');
            $map = Capsule::table(self::TABLE_INVOICE_MAP)
                ->where('invoice_id', $invoiceId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (!$map) {
                return false;
            }

            $invoice = Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();
            if (!$invoice) {
                return false;
            }

            $invoiceStatus = strtolower(trim((string) ($invoice->status ?? '')));
            if (!in_array($invoiceStatus, ['paid', 'collections'], true)) {
                return false;
            }

            $request = Capsule::table(self::TABLE_REQUESTS)
                ->where('id', (int) ($map->request_id ?? 0))
                ->lockForUpdate()
                ->first();
            if (!$request) {
                return false;
            }
            if (strtolower((string) ($request->status ?? 'pending')) === 'upgraded') {
                Capsule::table(self::TABLE_INVOICE_MAP)
                    ->where('id', (int) ($map->id ?? 0))
                    ->update(['status' => 'paid', 'paid_at' => $now, 'updated_at' => $now]);
                return true;
            }

            $subdomainId = (int) ($map->subdomain_id ?? $request->subdomain_id ?? 0);
            if ($subdomainId <= 0) {
                return false;
            }

            self::markSubdomainPermanent($subdomainId, $now);
            Capsule::table(self::TABLE_REQUESTS)
                ->where('id', (int) $request->id)
                ->update([
                    'status' => 'upgraded',
                    'upgrade_source' => 'invoice',
                    'paid_amount' => number_format((float) ($invoice->total ?? 0), 2, '.', ''),
                    'assist_count' => max((int) ($request->assist_count ?? 0), (int) ($request->target_assists ?? 1)),
                    'upgraded_at' => $now,
                    'updated_at' => $now,
                ]);
            Capsule::table(self::TABLE_INVOICE_MAP)
                ->where('id', (int) ($map->id ?? 0))
                ->update([
                    'status' => 'paid',
                    'paid_at' => $now,
                    'updated_at' => $now,
                ]);
            return true;
        });
    }

    private static function getRecentUpgradeFeed(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        try {
            $rows = Capsule::table(self::TABLE_REQUESTS . ' as r')
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->leftJoin('tblclients as c', 'r.userid', '=', 'c.id')
                ->select('r.id', 'r.upgraded_at', 'r.updated_at', 'r.created_at', 's.subdomain as domain_name', 'c.email as owner_email')
                ->where('r.status', 'upgraded')
                ->orderByRaw('COALESCE(r.upgraded_at, r.updated_at, r.created_at) DESC')
                ->limit($limit)
                ->get();

            $items = [];
            foreach ($rows as $row) {
                $domain = trim((string) ($row->domain_name ?? ''));
                $email = trim((string) ($row->owner_email ?? ''));
                $upgradedAt = (string) ($row->upgraded_at ?? '');
                if ($upgradedAt === '') {
                    $upgradedAt = (string) ($row->updated_at ?? '');
                }
                if ($upgradedAt === '') {
                    $upgradedAt = (string) ($row->created_at ?? '');
                }

                $items[] = [
                    'id' => (int) ($row->id ?? 0),
                    'email_masked' => self::maskEmail($email),
                    'domain_masked' => self::maskDomainForFeed($domain),
                    'upgraded_at' => $upgradedAt,
                ];
            }

            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function maskDomainForFeed(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || strpos($domain, '.') === false) {
            return $domain !== '' ? $domain : '-';
        }

        $parts = explode('.', $domain);
        $prefix = array_shift($parts);
        if ($prefix === null || $prefix === '') {
            return $domain;
        }

        $prefixLen = strlen($prefix);
        if ($prefixLen <= 1) {
            $maskedPrefix = '*';
        } elseif ($prefixLen <= 3) {
            $maskedPrefix = substr($prefix, 0, 1) . '*';
        } else {
            $maskedPrefix = substr($prefix, 0, 1) . str_repeat('*', min(3, $prefixLen - 2));
        }

        return $maskedPrefix . '.' . implode('.', $parts);
    }

    private static function getAssistLogsForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $logs = [];

        try {
            $receivedRows = Capsule::table(self::TABLE_ASSISTS . ' as a')
                ->join(self::TABLE_REQUESTS . ' as r', 'a.request_id', '=', 'r.id')
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->select('a.id', 'a.helper_userid', 'a.helper_email', 'a.assist_code', 'a.created_at', 's.subdomain as domain_name')
                ->where('r.userid', $userId)
                ->orderBy('a.id', 'desc')
                ->limit($limit)
                ->get();

            foreach ($receivedRows as $row) {
                $helperEmail = trim((string) ($row->helper_email ?? ''));
                $helperUserId = (int) ($row->helper_userid ?? 0);
                $counterpart = $helperEmail !== '' ? self::maskEmail($helperEmail) : ($helperUserId > 0 ? ('UID:' . $helperUserId) : '-');
                $logs[] = [
                    'id' => (int) ($row->id ?? 0),
                    'role' => 'received',
                    'domain' => trim((string) ($row->domain_name ?? '')),
                    'assist_code' => strtoupper((string) ($row->assist_code ?? '')),
                    'counterpart' => $counterpart,
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }

            $assistedRows = Capsule::table(self::TABLE_ASSISTS . ' as a')
                ->join(self::TABLE_REQUESTS . ' as r', 'a.request_id', '=', 'r.id')
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->select('a.id', 'a.assist_code', 'a.created_at', 'r.userid as owner_userid', 's.subdomain as domain_name')
                ->where('a.helper_userid', $userId)
                ->orderBy('a.id', 'desc')
                ->limit($limit)
                ->get();

            $ownerIds = [];
            $assistedRowsNormalized = [];
            foreach ($assistedRows as $row) {
                $ownerUserId = (int) ($row->owner_userid ?? 0);
                if ($ownerUserId > 0) {
                    $ownerIds[$ownerUserId] = $ownerUserId;
                }
                $assistedRowsNormalized[] = [
                    'id' => (int) ($row->id ?? 0),
                    'owner_userid' => $ownerUserId,
                    'assist_code' => strtoupper((string) ($row->assist_code ?? '')),
                    'domain' => (string) ($row->domain_name ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }

            $ownerMap = [];
            if (!empty($ownerIds)) {
                $ownerRows = Capsule::table('tblclients')
                    ->select('id', 'email')
                    ->whereIn('id', array_values($ownerIds))
                    ->get();
                foreach ($ownerRows as $ownerRow) {
                    $ownerId = (int) ($ownerRow->id ?? 0);
                    if ($ownerId <= 0) {
                        continue;
                    }
                    $ownerMap[$ownerId] = self::maskEmail((string) ($ownerRow->email ?? ''));
                }
            }

            foreach ($assistedRowsNormalized as $row) {
                $ownerUserId = (int) ($row['owner_userid'] ?? 0);
                $counterpart = $ownerMap[$ownerUserId] ?? ($ownerUserId > 0 ? ('UID:' . $ownerUserId) : '-');
                $logs[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'role' => 'assisted',
                    'domain' => self::maskDomainForFeed((string) ($row['domain'] ?? '')),
                    'assist_code' => (string) ($row['assist_code'] ?? ''),
                    'counterpart' => $counterpart,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }

            if (!empty($logs)) {
                usort($logs, static function (array $a, array $b): int {
                    $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
                    $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
                    if ($aTime === $bTime) {
                        return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
                    }
                    return $bTime <=> $aTime;
                });

                if (count($logs) > $limit) {
                    $logs = array_slice($logs, 0, $limit);
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $logs;
    }

    public static function fetchAdminAssistLogs(string $search = '', int $page = 1, int $perPage = 20): array
    {
        self::ensureTables();

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $search = trim($search);
        $total = 0;
        $items = [];
        $totalPages = 1;

        try {
            $baseQuery = Capsule::table(self::TABLE_ASSISTS . ' as a')
                ->join(self::TABLE_REQUESTS . ' as r', 'a.request_id', '=', 'r.id')
                ->leftJoin('mod_cloudflare_subdomain as s', 'r.subdomain_id', '=', 's.id')
                ->leftJoin('tblclients as helper', 'a.helper_userid', '=', 'helper.id')
                ->leftJoin('tblclients as owner', 'r.userid', '=', 'owner.id')
                ->select(
                    'a.id',
                    'a.request_id',
                    'a.helper_userid',
                    'a.helper_email',
                    'a.helper_ip',
                    'a.assist_code',
                    'a.created_at',
                    'r.userid as owner_userid',
                    's.subdomain as domain_name',
                    'owner.email as owner_email',
                    'helper.email as helper_client_email'
                );

            if ($search !== '') {
                $searchLike = '%' . $search . '%';
                $baseQuery->where(function ($q) use ($searchLike, $search) {
                    $q->where('a.assist_code', 'like', $searchLike)
                        ->orWhere('a.helper_email', 'like', $searchLike)
                        ->orWhere('a.helper_ip', 'like', $searchLike)
                        ->orWhere('s.subdomain', 'like', $searchLike)
                        ->orWhere('owner.email', 'like', $searchLike)
                        ->orWhere('helper.email', 'like', $searchLike);
                    if (ctype_digit($search)) {
                        $q->orWhere('a.helper_userid', (int) $search)
                            ->orWhere('r.userid', (int) $search);
                    }
                });
            }

            $total = (clone $baseQuery)->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;
            $rows = (clone $baseQuery)
                ->orderBy('a.id', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            foreach ($rows as $row) {
                $items[] = [
                    'id' => (int) ($row->id ?? 0),
                    'request_id' => (int) ($row->request_id ?? 0),
                    'owner_userid' => (int) ($row->owner_userid ?? 0),
                    'owner_email' => (string) ($row->owner_email ?? ''),
                    'helper_userid' => (int) ($row->helper_userid ?? 0),
                    'helper_email' => (string) ($row->helper_email ?? ''),
                    'helper_client_email' => (string) ($row->helper_client_email ?? ''),
                    'helper_ip' => (string) ($row->helper_ip ?? ''),
                    'assist_code' => strtoupper((string) ($row->assist_code ?? '')),
                    'domain' => (string) ($row->domain_name ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $items = [];
            $total = 0;
            $totalPages = 1;
            $page = 1;
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
    }

    private static function generateUniqueAssistCode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($characters) - 1;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $characters[random_int(0, $maxIndex)];
            }
            $exists = Capsule::table(self::TABLE_REQUESTS)
                ->where('assist_code', $code)
                ->exists();
            if (!$exists) {
                return $code;
            }
        }

        throw new \RuntimeException('assist_code_generate_failed');
    }

    private static function maskEmail(string $email): string
    {
        $email = trim($email);
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
        $maskedDomainParts = array_map(static function ($part) {
            $len = strlen($part);
            if ($len <= 2) {
                return substr($part, 0, 1) . '*';
            }

            return substr($part, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($part, -1);
        }, $domainParts);

        return $maskedUser . '@' . implode('.', $maskedDomainParts);
    }
}
