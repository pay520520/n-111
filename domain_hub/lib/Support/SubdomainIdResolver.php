<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

final class CfSubdomainIdResolver
{
    private static ?bool $hasPublicIdColumn = null;
    /** @var array<int,int> */
    private static array $publicToInternalCache = [];

    public static function hasPublicIdColumn(): bool
    {
        if (self::$hasPublicIdColumn !== null) {
            return self::$hasPublicIdColumn;
        }

        try {
            self::$hasPublicIdColumn = Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'public_id');
        } catch (\Throwable $e) {
            self::$hasPublicIdColumn = false;
        }

        return self::$hasPublicIdColumn;
    }

    public static function resolveToInternal($rawId): int
    {
        $id = (int) $rawId;
        if ($id <= 0) {
            return 0;
        }
        if (!self::hasPublicIdColumn()) {
            return $id;
        }

        if (isset(self::$publicToInternalCache[$id])) {
            return self::$publicToInternalCache[$id];
        }

        try {
            $internal = (int) Capsule::table('mod_cloudflare_subdomain')->where('public_id', $id)->value('id');
        } catch (\Throwable $e) {
            return $id;
        }

        if ($internal > 0) {
            self::$publicToInternalCache[$id] = $internal;
            return $internal;
        }

        return $id;
    }
}
