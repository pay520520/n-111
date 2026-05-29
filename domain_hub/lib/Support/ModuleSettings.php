<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfModuleSettings
{
    public const DEFAULT_MODULE = 'domain_hub';
    public const DEFAULT_LEGACY_MODULE = 'cloudflare_subdomain';

    /**
     * Ensure constants exist and migrate legacy settings one time per request.
     */
    public static function bootstrap(): void
    {
        self::ensureConstants();
        self::ensureMigrated();
        self::ensureInviteGateTransitionState();
    }

    public static function ensureConstants(): void
    {
        if (!defined('CF_MODULE_NAME')) {
            define('CF_MODULE_NAME', self::DEFAULT_MODULE);
        }
        if (!defined('CF_MODULE_NAME_LEGACY')) {
            define('CF_MODULE_NAME_LEGACY', self::DEFAULT_LEGACY_MODULE);
        }
    }

    public static function moduleName(): string
    {
        return defined('CF_MODULE_NAME') ? CF_MODULE_NAME : self::DEFAULT_MODULE;
    }

    public static function legacyModuleName(): string
    {
        return defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : self::DEFAULT_LEGACY_MODULE;
    }

    public static function ensureMigrated(): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }

        $migrated = true;
        try {
            $currentModule = self::moduleName();
            $legacyModule = self::legacyModuleName();

            $newCount = Capsule::table('tbladdonmodules')->where('module', $currentModule)->count();
            if ($newCount === 0 && $legacyModule !== $currentModule) {
                $legacyRows = Capsule::table('tbladdonmodules')->where('module', $legacyModule)->get();
                foreach ($legacyRows as $row) {
                    Capsule::table('tbladdonmodules')->updateOrInsert(
                        ['module' => $currentModule, 'setting' => $row->setting],
                        ['value' => $row->value]
                    );
                }
            }
        } catch (\Throwable $e) {
            // Best effort migration. Swallow exceptions to avoid breaking activation.
        }
    }

    private static function ensureInviteGateTransitionState(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $module = self::moduleName();
            $legacyModule = self::legacyModuleName();
            $modules = [$module];
            if ($legacyModule !== '' && $legacyModule !== $module) {
                $modules[] = $legacyModule;
            }

            $rows = Capsule::table('tbladdonmodules')->whereIn('module', $modules)->get();
            if (!$rows || count($rows) === 0) {
                return;
            }

            $settings = [];
            foreach ($rows as $row) {
                $key = (string) $row->setting;
                $rowModule = (string) ($row->module ?? '');
                if (!array_key_exists($key, $settings) || $rowModule === $module) {
                    $settings[$key] = (string) $row->value;
                }
            }

            $allowedModes = ['disabled', 'invite_only', 'github_only', 'telegram_only', 'invite_or_github', 'invite_or_telegram', 'github_or_telegram', 'invite_or_github_or_telegram'];
            $currentMode = strtolower(trim((string) ($settings['invite_registration_gate_mode'] ?? '')));
            if (!in_array($currentMode, $allowedModes, true)) {
                $legacyEnabledRaw = strtolower(trim((string) ($settings['enable_invite_registration_gate'] ?? '0')));
                $legacyEnabled = in_array($legacyEnabledRaw, ['1', 'on', 'yes', 'true', 'enabled'], true);
                $currentMode = $legacyEnabled ? 'invite_only' : 'disabled';
            }

            $lastMode = strtolower(trim((string) ($settings['invite_registration_gate_last_mode'] ?? '')));
            if (!in_array($lastMode, $allowedModes, true)) {
                $lastMode = 'disabled';
            }

            if ($lastMode === $currentMode) {
                return;
            }

            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $module, 'setting' => 'invite_registration_gate_last_mode'],
                ['value' => $currentMode]
            );

            if ($lastMode === 'disabled' && $currentMode !== 'disabled') {
                $now = date('Y-m-d H:i:s');
                $cutoffUserId = 0;
                try {
                    $cutoffUserId = max(0, intval(Capsule::table('tblclients')->max('id') ?? 0));
                } catch (\Throwable $ignored) {
                    $cutoffUserId = 0;
                }
                Capsule::table('tbladdonmodules')->updateOrInsert(
                    ['module' => $module, 'setting' => 'invite_registration_gate_enabled_at'],
                    ['value' => $now]
                );
                Capsule::table('tbladdonmodules')->updateOrInsert(
                    ['module' => $module, 'setting' => 'invite_registration_gate_cutoff_userid'],
                    ['value' => (string) $cutoffUserId]
                );

                $exists = Capsule::table('mod_cloudflare_jobs')
                    ->where('type', 'unlock_invite_registration_all')
                    ->whereIn('status', ['pending', 'running'])
                    ->exists();
                if (!$exists) {
                    $payload = [
                        'cursor_id' => 0,
                        'batch_size' => 500,
                        'admin_id' => 0,
                        'requested_at' => date('c'),
                    ];
                    Capsule::table('mod_cloudflare_jobs')->insert([
                        'type' => 'unlock_invite_registration_all',
                        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'priority' => 6,
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_run_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('invite_gate_transition_sync_error', [
                    'error' => substr((string) $e->getMessage(), 0, 300),
                ]);
            }
        }
    }
}
