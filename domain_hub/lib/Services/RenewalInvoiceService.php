<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfRenewalInvoiceService
{
    private const TABLE = 'mod_cloudflare_renewal_invoice_map';

    public static function ensureTables(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, function ($table) {
                    $table->increments('id');
                    $table->integer('subdomain_id');
                    $table->integer('userid');
                    $table->integer('invoice_id');
                    $table->integer('term_years')->default(1);
                    $table->decimal('redemption_fee', 10, 2)->default('0.00');
                    $table->string('status', 20)->default('pending');
                    $table->dateTime('created_at');
                    $table->dateTime('updated_at');
                    $table->unique('invoice_id', 'uniq_cf_renew_invoice');
                    $table->index(['subdomain_id', 'status'], 'idx_cf_renew_sub_status');
                    $table->index(['userid', 'status'], 'idx_cf_renew_user_status');
                });
            }
        } catch (\Throwable $e) {
        }
    }

    public static function registerPendingInvoice(int $subdomainId, int $userId, int $invoiceId, int $termYears, float $fee): void
    {
        self::ensureTables();
        if ($subdomainId <= 0 || $userId <= 0 || $invoiceId <= 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE)->updateOrInsert(
            ['invoice_id' => $invoiceId],
            [
                'subdomain_id' => $subdomainId,
                'userid' => $userId,
                'term_years' => max(1, $termYears),
                'redemption_fee' => number_format(max(0, $fee), 2, '.', ''),
                'status' => 'pending',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public static function settlePaidInvoice(int $invoiceId): bool
    {
        self::ensureTables();
        if ($invoiceId <= 0) {
            return false;
        }
        return (bool) Capsule::transaction(function () use ($invoiceId) {
            $now = date('Y-m-d H:i:s');
            $map = Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->where('status', 'pending')->lockForUpdate()->first();
            if (!$map) {
                return false;
            }
            $sub = Capsule::table('mod_cloudflare_subdomain')->where('id', (int) $map->subdomain_id)->where('userid', (int) $map->userid)->lockForUpdate()->first();
            if (!$sub) {
                Capsule::table(self::TABLE)->where('id', (int) $map->id)->update(['status' => 'failed', 'updated_at' => $now]);
                return false;
            }
            if (intval($sub->never_expires ?? 0) === 1 || !in_array(strtolower((string) ($sub->status ?? '')), ['active', 'pending'], true)) {
                Capsule::table(self::TABLE)->where('id', (int) $map->id)->update(['status' => 'failed', 'updated_at' => $now]);
                return false;
            }
            $baseTs = strtotime((string) ($sub->expires_at ?? '')) ?: time();
            $baseTs = max($baseTs, time());
            $newExpiryTs = strtotime('+' . max(1, intval($map->term_years ?? 1)) . ' years', $baseTs);
            if ($newExpiryTs === false) {
                Capsule::table(self::TABLE)->where('id', (int) $map->id)->update(['status' => 'failed', 'updated_at' => $now]);
                return false;
            }
            Capsule::table('mod_cloudflare_subdomain')->where('id', (int) $sub->id)->update([
                'expires_at' => date('Y-m-d H:i:s', $newExpiryTs),
                'renewed_at' => $now,
                'never_expires' => 0,
                'updated_at' => $now,
            ]);
            Capsule::table(self::TABLE)->where('id', (int) $map->id)->update(['status' => 'settled', 'updated_at' => $now]);
            return true;
        });
    }
}

