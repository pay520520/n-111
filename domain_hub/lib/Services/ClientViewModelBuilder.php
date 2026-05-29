<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../AtomicOperations.php';
require_once __DIR__ . '/DnsUnlockService.php';
require_once __DIR__ . '/InviteRegistrationService.php';

class CfClientViewModelBuilder
{
    public static function build(int $userId): array
    {
        $globals = [];

        if (function_exists('cfmod_load_language')) {
            cfmod_load_language();
        }

        $languageMeta = function_exists('cfmod_resolve_language_preference') ? cfmod_resolve_language_preference() : ['normalized' => 'english'];
        $currentLanguage = $languageMeta['normalized'] ?? 'english';
        $globals['currentLanguage'] = $currentLanguage;
        $globals['availableLanguages'] = self::buildClientLanguageOptions($currentLanguage);

        $noscriptText = cfmod_trans('cfmod.client.enable_js_notice', '为确保账户安全，请启用浏览器的 JavaScript 后重试。');
        $globals['cfmodClientNoscriptNotice'] = '<noscript><div class="alert alert-danger m-3">' . htmlspecialchars($noscriptText) . '</div></noscript>';
        $globals['msg'] = '';
        $globals['msg_type'] = '';
        $globals['registerError'] = '';
        $globals['inviteRegistrationGateFlash'] = null;

        $clientFlash = self::consumeClientFlash();
        if ($clientFlash !== null) {
            $globals['msg'] = $clientFlash['message'];
            $globals['msg_type'] = $clientFlash['type'];
            if (($clientFlash['context'] ?? '') === 'invite_gate') {
                $globals['inviteRegistrationGateFlash'] = $clientFlash;
            }
        }

        $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
        $legacyModuleSlug = defined('CF_MODULE_NAME_LEGACY') ? CF_MODULE_NAME_LEGACY : 'cloudflare_subdomain';
        $globals['moduleSlug'] = $moduleSlug;
        $globals['legacyModuleSlug'] = $legacyModuleSlug;
        $globals['moduleSlugList'] = array_values(array_unique([$moduleSlug, $legacyModuleSlug]));

        $globals['tables_exist'] = self::checkCoreTablesExist();
        if (!$globals['tables_exist']) {
            $globals['module_settings'] = [];
            return [
                'globals' => $globals,
                'meta' => ['template_variables' => array_keys($globals)],
            ];
        }

        $moduleSettings = self::loadModuleSettings($moduleSlug, $legacyModuleSlug);
        $globals['module_settings'] = $moduleSettings;

        $globals['domainGiftEnabled'] = cfmod_is_domain_gift_enabled($moduleSettings);
        $globals['domainGiftTtlHours'] = cfmod_get_domain_gift_ttl_hours($moduleSettings);
        $globals['quotaRedeemEnabled'] = in_array(($moduleSettings['enable_quota_redeem'] ?? '0'), ['1','on','yes','true','enabled'], true);

        $domainPermanentUpgradeEnabled = class_exists('CfDomainPermanentUpgradeService')
            && CfDomainPermanentUpgradeService::isEnabled($moduleSettings);
        $domainPermanentUpgradeAssistRequired = class_exists('CfDomainPermanentUpgradeService')
            ? CfDomainPermanentUpgradeService::getRequiredAssistCount($moduleSettings)
            : max(1, min(100, intval($moduleSettings['domain_permanent_upgrade_assist_required'] ?? 3)));
        $domainPermanentUpgradeAssistLimit = class_exists('CfDomainPermanentUpgradeService')
            ? CfDomainPermanentUpgradeService::getHelperAssistLimit($moduleSettings)
            : max(0, min(1000, intval($moduleSettings['domain_permanent_upgrade_helper_limit'] ?? 0)));
        $domainPermanentUpgradePaidEnabled = class_exists('CfDomainPermanentUpgradeService')
            && CfDomainPermanentUpgradeService::isPaidEnabled($moduleSettings);
        $domainPermanentUpgradePaidPrice = class_exists('CfDomainPermanentUpgradeService')
            ? CfDomainPermanentUpgradeService::getPaidPrice($moduleSettings)
            : round(max(0, (float) ($moduleSettings['domain_permanent_upgrade_paid_price'] ?? 0)), 2);
        [$permUpgradeCurrencyPrefix, $permUpgradeCurrencySuffix] = self::resolveClientCurrencyDecorators($userId);
        $domainPermanentUpgradeState = [
            'assist_required' => $domainPermanentUpgradeAssistRequired,
            'helper_assist_limit' => $domainPermanentUpgradeAssistLimit,
            'eligible_domains' => [],
            'requests' => [],
            'assist_logs' => [],
            'recent_success_feed' => [],
            'pending_count' => 0,
            'upgraded_count' => 0,
            'pagination' => [
                'page' => 1,
                'perPage' => 5,
                'total' => 0,
                'totalPages' => 1,
            ],
        ];
        if ($domainPermanentUpgradeEnabled && class_exists('CfDomainPermanentUpgradeService')) {
            $permUpgradePage = isset($_GET['perm_upgrade_page']) ? max(1, (int) $_GET['perm_upgrade_page']) : 1;
            try {
                $domainPermanentUpgradeState = CfDomainPermanentUpgradeService::getUserState($userId, $moduleSettings, $permUpgradePage, 5);
            } catch (\Throwable $e) {
            }
        }
        $globals['domainPermanentUpgradeEnabled'] = $domainPermanentUpgradeEnabled;
        $globals['domainPermanentUpgradeAssistRequired'] = $domainPermanentUpgradeAssistRequired;
        $globals['domainPermanentUpgradeAssistLimit'] = $domainPermanentUpgradeAssistLimit;
        $globals['domainPermanentUpgradePaidEnabled'] = $domainPermanentUpgradePaidEnabled;
        $globals['domainPermanentUpgradePaidPrice'] = $domainPermanentUpgradePaidPrice;
        $globals['domainPermanentUpgradeCurrencyPrefix'] = $permUpgradeCurrencyPrefix;
        $globals['domainPermanentUpgradeCurrencySuffix'] = $permUpgradeCurrencySuffix;
        $globals['domainPermanentUpgradeState'] = $domainPermanentUpgradeState;
        $domainPermanentIncentiveEnabled = class_exists('CfDomainPermanentIncentiveService')
            && CfDomainPermanentIncentiveService::isEnabled($moduleSettings)
            && CfDomainPermanentIncentiveService::isInCampaignWindow($moduleSettings)
            && CfDomainPermanentIncentiveService::isGrayHit($userId, $moduleSettings);
        $domainPermanentIncentiveState = ['eligible_domains' => [], 'logs' => []];
        if ($domainPermanentIncentiveEnabled && class_exists('CfDomainPermanentIncentiveService')) {
            try {
                $permIncentiveLogsPage = isset($_GET['perm_incentive_logs_page']) ? max(1, (int) $_GET['perm_incentive_logs_page']) : 1;
                $domainPermanentIncentiveState = CfDomainPermanentIncentiveService::getUserState($userId, $moduleSettings, $permIncentiveLogsPage, 5);
            } catch (\Throwable $e) {
            }
        }
        $globals['domainPermanentIncentiveEnabled'] = $domainPermanentIncentiveEnabled;
        $globals['domainPermanentIncentiveState'] = $domainPermanentIncentiveState;
        $globals['domainPermanentIncentiveConditionMode'] = class_exists('CfDomainPermanentIncentiveService')
            ? CfDomainPermanentIncentiveService::getConditionMode($moduleSettings)
            : 'any';
        $globals['domainPermanentIncentiveEndAt'] = trim((string) ($moduleSettings['domain_permanent_incentive_end_at'] ?? ''));
        $quotaTickerEnabledRaw = $moduleSettings['client_quota_ticker_enabled'] ?? '0';
        $quotaTickerEnabled = in_array(strtolower(trim((string) $quotaTickerEnabledRaw)), ['1', 'on', 'yes', 'true', 'enabled'], true);
        $quotaTickerRawText = trim((string) ($moduleSettings['client_quota_ticker_text'] ?? ''));
        $quotaTickerText = $quotaTickerRawText;
        if ($quotaTickerRawText !== '' && function_exists('cfmod_pick_bilingual_text')) {
            $quotaTickerText = (string) cfmod_pick_bilingual_text($quotaTickerRawText, $currentLanguage);
        }
        $quotaTickerSpeed = max(12, min(80, intval($moduleSettings['client_quota_ticker_speed'] ?? 28)));
        $globals['quotaTickerEnabled'] = $quotaTickerEnabled && $quotaTickerText !== '';
        $globals['quotaTickerText'] = $quotaTickerText;
        $globals['quotaTickerSpeed'] = $quotaTickerSpeed;

        $prefixLimits = function_exists('cf_get_prefix_length_limits') ? cf_get_prefix_length_limits($moduleSettings) : ['min' => 3, 'max' => 32];
        $globals['prefixLengthLimits'] = $prefixLimits;
        $globals['subPrefixMinLength'] = $prefixLimits['min'];
        $globals['subPrefixMaxLength'] = $prefixLimits['max'];
        $globals['subPrefixPatternHtml'] = '[a-zA-Z0-9\-]{' . $prefixLimits['min'] . ',' . $prefixLimits['max'] . '}';

        $globals['forbidden'] = array_map('trim', explode(',', $moduleSettings['forbidden_prefix'] ?? 'www,mail,ftp,admin,root,gov,pay,bank'));
        $globals['disableDnsWrite'] = in_array(($moduleSettings['disable_dns_write'] ?? '0'), ['1','on','yes','true','enabled'], true);
        $globals['pauseFreeRegistration'] = in_array(($moduleSettings['pause_free_registration'] ?? '0'), ['1','on','yes','true','enabled'], true);
        $globals['disableNsManagement'] = in_array(($moduleSettings['disable_ns_management'] ?? '0'), ['1','on','yes','true','enabled'], true);
        $globals['hideInviteFeature'] = in_array(($moduleSettings['hide_invite_feature'] ?? '0'), ['1','on','yes','true','enabled'], true);
        $globals['nsMaxPerDomain'] = max(1, intval($moduleSettings['ns_max_per_domain'] ?? 8));
        $globals['redeemTicketUrl'] = trim($moduleSettings['redeem_ticket_url'] ?? '') ?: 'submitticket.php';
        $globals['clientSupportTicketUrl'] = trim((string) ($moduleSettings['client_support_ticket_url'] ?? '')) ?: 'submitticket.php';
        $globals['clientSupportGroupUrl'] = trim((string) ($moduleSettings['client_support_group_url'] ?? '')) ?: 'https://t.me/+l9I5TNRDLP5lZDBh';
        $activityPage = isset($_GET['activity_page']) ? max(1, (int) $_GET['activity_page']) : 1;
        $globals['clientActivityLogs'] = self::loadClientActivityLogs($userId, $activityPage, 10, 7, $currentLanguage);

        $helpAiEnabled = class_exists('CfAiHelpSearchService')
            ? CfAiHelpSearchService::isEnabled($moduleSettings)
            : (function_exists('cfmod_setting_enabled')
                ? cfmod_setting_enabled($moduleSettings['enable_help_ai_search'] ?? '0')
                : in_array(strtolower(trim((string) ($moduleSettings['enable_help_ai_search'] ?? '0'))), ['1', 'on', 'yes', 'true', 'enabled'], true));
        $helpAiAssistantName = trim((string) ($moduleSettings['help_ai_assistant_name'] ?? 'AI 助手'));
        if (function_exists('cfmod_pick_bilingual_text')) {
            $helpAiAssistantName = trim((string) cfmod_pick_bilingual_text($helpAiAssistantName, (string) $currentLanguage));
        }
        if ($helpAiAssistantName === '') {
            $helpAiAssistantName = strtolower((string) $currentLanguage) === 'chinese' ? 'AI 助手' : 'AI Assistant';
        }
        $globals['helpAiSearchEnabled'] = $helpAiEnabled;
        $globals['helpAiAssistantName'] = $helpAiAssistantName;
        $globals['helpAiMaxInputChars'] = max(200, min(2000, intval($moduleSettings['help_ai_max_input_chars'] ?? 600)));
        $globals['helpAiFabEnabled'] = class_exists('CfAiHelpSearchService')
            ? CfAiHelpSearchService::isFabEnabled($moduleSettings)
            : cfmod_setting_enabled($moduleSettings['help_ai_fab_enabled'] ?? '1');

        $globals['maintenanceMode'] = in_array(($moduleSettings['maintenance_mode'] ?? '0'), ['1','on','yes','true','enabled'], true);
        $globals['maintenanceMessage'] = trim($moduleSettings['maintenance_message'] ?? '');

        $dnsUnlockFeatureEnabled = cfmod_setting_enabled($moduleSettings['enable_dns_unlock'] ?? '0');
        $globals['dnsUnlockFeatureEnabled'] = $dnsUnlockFeatureEnabled;
        $dnsUnlockPurchaseEnabledSetting = cfmod_setting_enabled($moduleSettings['dns_unlock_purchase_enabled'] ?? '0');
        $dnsUnlockShareEnabledSetting = cfmod_setting_enabled($moduleSettings['dns_unlock_share_enabled'] ?? '1');
        $dnsUnlockPurchasePriceSetting = round(max(0, (float)($moduleSettings['dns_unlock_purchase_price'] ?? 0)), 2);
        $globals['dnsUnlockPurchaseEnabled'] = $dnsUnlockFeatureEnabled && $dnsUnlockPurchaseEnabledSetting;
        $globals['dnsUnlockShareAllowed'] = $dnsUnlockFeatureEnabled && $dnsUnlockShareEnabledSetting;
        $globals['dnsUnlockPurchasePrice'] = $dnsUnlockPurchasePriceSetting;

        $globals['clientAnnounceEnabled'] = in_array(($moduleSettings['admin_announce_enabled'] ?? '0'), ['1','on','yes','true','enabled'], true);
        $rawAnnounceTitle = trim((string) ($moduleSettings['admin_announce_title'] ?? ''));
        if (function_exists('cfmod_pick_bilingual_text')) {
            $rawAnnounceTitle = cfmod_pick_bilingual_text($rawAnnounceTitle, $currentLanguage);
        }
        $globals['clientAnnounceTitle'] = trim($rawAnnounceTitle);
        $rawAnnounceHtml = (string) ($moduleSettings['admin_announce_html'] ?? '');
        $decodedAnnounceHtml = html_entity_decode($rawAnnounceHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('cfmod_pick_bilingual_text')) {
            $decodedAnnounceHtml = cfmod_pick_bilingual_text($decodedAnnounceHtml, $currentLanguage);
        }
        $trimmedAnnounceHtml = trim($decodedAnnounceHtml);
        if ($trimmedAnnounceHtml !== '' && strip_tags($trimmedAnnounceHtml) === $trimmedAnnounceHtml) {
            $trimmedAnnounceHtml = nl2br($trimmedAnnounceHtml);
        }
        $globals['clientAnnounceHtml'] = $trimmedAnnounceHtml;
        $globals['clientAnnounceCookieKey'] = 'cfmod_client_announce_' . substr(md5(($globals['clientAnnounceTitle'] ?: '') . '|' . ($globals['clientAnnounceHtml'] ?: '')), 0, 8);
        $clientDeleteMode = function_exists('cfmod_client_delete_effective_mode')
            ? cfmod_client_delete_effective_mode($moduleSettings, $userId)
            : strtolower(trim((string) ($moduleSettings['client_domain_delete_mode'] ?? 'disabled')));
        $globals['clientDeleteMode'] = $clientDeleteMode;
        $globals['clientDeleteEnabled'] = $clientDeleteMode !== 'disabled';
        $globals['renewButtonDisplayMode'] = self::resolveRenewButtonDisplayMode($userId);

        // VPN检测配置
        $vpnDetectionEnabled = cfmod_setting_enabled($moduleSettings['enable_vpn_detection'] ?? '0');
        $vpnDetectionDnsEnabled = $vpnDetectionEnabled && cfmod_setting_enabled($moduleSettings['vpn_detection_dns_enabled'] ?? '0');
        $globals['vpnDetectionEnabled'] = $vpnDetectionEnabled;
        $globals['vpnDetectionDnsEnabled'] = $vpnDetectionDnsEnabled;

        $globals['max'] = intval($moduleSettings['max_subdomain_per_user'] ?? 5);
        if ($globals['max'] < 0) {
            $globals['max'] = 5;
        }
        $globals['inviteLimitGlobal'] = intval($moduleSettings['invite_bonus_limit_global'] ?? 5);
        if ($globals['inviteLimitGlobal'] <= 0) {
            $globals['inviteLimitGlobal'] = 5;
        }

        $globals['enableInviteLeaderboard'] = ((($moduleSettings['enable_invite_leaderboard'] ?? 'on') === 'on') || (($moduleSettings['enable_invite_leaderboard'] ?? '1') == '1')) && !in_array(($moduleSettings['hide_invite_feature'] ?? '0'), ['1','on'], true);
        $globals['inviteLeaderboardTop'] = max(1, intval($moduleSettings['invite_leaderboard_top'] ?? 5));
        $globals['inviteLeaderboardDays'] = max(1, intval($moduleSettings['invite_leaderboard_period_days'] ?? 7));
        $globals['inviteCycleStart'] = trim($moduleSettings['invite_cycle_start'] ?? '');
        $globals['inviteRewardInstructions'] = trim($moduleSettings['invite_reward_instructions'] ?? '');
        $globals['hideCurrentWeekLeaderboard'] = ($moduleSettings['hide_current_week_leaderboard'] ?? '') === '1';
        $globals['inviteLeaderboardData'] = self::buildInviteLeaderboardData(
            $userId,
            $globals['inviteCycleStart'],
            $globals['inviteLeaderboardDays'],
            $globals['hideCurrentWeekLeaderboard']
        );

        $globals['redemptionModeSetting'] = strtolower(trim($moduleSettings['domain_redemption_mode'] ?? 'manual'));
        if (!in_array($globals['redemptionModeSetting'], ['manual', 'auto_charge'], true)) {
            $globals['redemptionModeSetting'] = 'manual';
        }
        $globals['redemptionFeeSetting'] = round(max(0, (float)($moduleSettings['domain_redemption_fee_amount'] ?? 0)), 2);

        if ($dnsUnlockFeatureEnabled) {
            $unlockPage = isset($_GET['unlock_page']) ? max(1, (int) $_GET['unlock_page']) : 1;
            $dnsUnlockState = self::loadDnsUnlockState($userId, $unlockPage);
            $globals['dnsUnlock'] = $dnsUnlockState;
            $globals['dnsUnlockRequired'] = !$dnsUnlockState['unlocked'];
        } else {
            $globals['dnsUnlock'] = [
                'code' => '',
                'unlocked' => true,
                'logs' => [],
                'pagination' => [
                    'page' => 1,
                    'perPage' => 10,
                    'total' => 0,
                    'totalPages' => 1,
                ],
            ];
            $globals['dnsUnlockRequired'] = false;
        }

        // 邀请注册功能状态
        $inviteGateMode = class_exists('CfInviteRegistrationService')
            ? CfInviteRegistrationService::resolveGateMode($moduleSettings)
            : 'disabled';
        $inviteRegistrationEnabled = $inviteGateMode !== 'disabled';
        $inviteCenterVisibleWhenGateDisabled = in_array(
            strtolower(trim((string) ($moduleSettings['invite_registration_center_visible_when_gate_disabled'] ?? '0'))),
            ['1', 'on', 'yes', 'true', 'enabled'],
            true
        );
        $inviteOptionEnabled = class_exists('CfInviteRegistrationService')
            ? CfInviteRegistrationService::isInviteOptionEnabled($moduleSettings)
            : false;
        $githubOptionEnabled = class_exists('CfInviteRegistrationService')
            ? CfInviteRegistrationService::isGithubOptionEnabled($moduleSettings)
            : false;
        $telegramOptionEnabled = class_exists('CfInviteRegistrationService')
            ? CfInviteRegistrationService::isTelegramOptionEnabled($moduleSettings)
            : false;

        $globals['inviteRegistrationGateMode'] = $inviteGateMode;
        $globals['inviteRegistrationEnabled'] = $inviteRegistrationEnabled;
        $globals['inviteRegistrationInviteEnabled'] = $inviteOptionEnabled;
        $globals['inviteRegistrationCenterVisibleWhenGateDisabled'] = $inviteCenterVisibleWhenGateDisabled;
        $globals['inviteRegistrationCenterVisible'] = $inviteRegistrationEnabled || $inviteCenterVisibleWhenGateDisabled;
        $globals['inviteRegistrationGenerationLockedByGateDisabled'] = !$inviteRegistrationEnabled;
        $globals['inviteRegistrationGithubEnabled'] = $githubOptionEnabled;
        $globals['inviteRegistrationTelegramEnabled'] = $telegramOptionEnabled;

        $isUserPrivileged = $userId > 0 && function_exists('cf_is_user_privileged') && cf_is_user_privileged($userId);
        $globals['isUserPrivileged'] = $isUserPrivileged;

        $privilegedAllowDeleteWithDnsHistory = $isUserPrivileged
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('allow_delete_with_dns_history', $moduleSettings);
        $globals['privilegedAllowDeleteWithDnsHistory'] = $privilegedAllowDeleteWithDnsHistory;

        $inviteRegistrationMaxPerUser = max(0, intval($moduleSettings['invite_registration_max_per_user'] ?? 0));
        if ($userId > 0 && class_exists('CfInviteRegistrationService')) {
            try {
                $inviteRegistrationMaxPerUser = max(0, intval(CfInviteRegistrationService::getInviterMaxInviteLimit($userId)));
            } catch (\Throwable $ignored) {
            }
        }
        $privilegedUnlimitedInvite = $isUserPrivileged
            && function_exists('cf_is_privileged_feature_enabled')
            && cf_is_privileged_feature_enabled('unlimited_invite_generation', $moduleSettings);
        if ($privilegedUnlimitedInvite) {
            $inviteRegistrationMaxPerUser = defined('CF_PRIVILEGED_MAX_SUBDOMAIN')
                ? CF_PRIVILEGED_MAX_SUBDOMAIN
                : 99999999999;
        }
        $globals['inviteRegistrationMaxPerUser'] = $inviteRegistrationMaxPerUser;
        $globals['inviteRegistrationRemainingQuota'] = $inviteRegistrationMaxPerUser > 0 ? $inviteRegistrationMaxPerUser : PHP_INT_MAX;
        $globals['inviteRegistrationBatchMax'] = class_exists('CfInviteRegistrationService')
            ? CfInviteRegistrationService::getGenerateBatchMax()
            : 50;
        $globals['inviteRegistrationCanCustomCode'] = class_exists('CfInviteRegistrationService')
            ? CfInviteRegistrationService::canUserUseCustomInviteCode($userId)
            : false;

        $globals['inviteRegistrationGithubAuthUrl'] = '';
        $globals['inviteRegistrationGithubConfigured'] = false;
        $globals['inviteRegistrationGithubMinMonths'] = 0;
        $globals['inviteRegistrationGithubMinRepos'] = 0;
        $globals['inviteRegistrationGithubBinding'] = [
            'bound' => false,
            'github_id' => 0,
            'github_login' => '',
            'github_name' => '',
            'github_created_at' => null,
        ];
        $globals['inviteRegistrationTelegramConfigured'] = false;
        $globals['inviteRegistrationTelegramBotUsername'] = '';
        $globals['inviteRegistrationTelegramAuthMaxAge'] = max(60, min(604800, (int) ($moduleSettings['invite_registration_telegram_auth_max_age_seconds'] ?? ($moduleSettings['telegram_reward_auth_max_age_seconds'] ?? 86400))));
        $globals['inviteRegistrationTelegramWidgetEnabled'] = in_array(
            strtolower(trim((string) ($moduleSettings['invite_registration_telegram_widget_enabled'] ?? '0'))),
            ['1', 'on', 'yes', 'true', 'enabled'],
            true
        );
        $globals['inviteRegistrationTelegramBotBindUrl'] = '';
        $globals['inviteRegistrationTelegramBotBindExpiresAt'] = '';

        if ($globals['inviteRegistrationCenterVisible']) {
            $inviteRegPage = isset($_GET['invite_reg_page']) ? max(1, (int) $_GET['invite_reg_page']) : 1;
            $inviteCodePage = isset($_GET['invite_code_page']) ? max(1, (int) $_GET['invite_code_page']) : 1;
            $inviteRegState = self::loadInviteRegistrationState($userId, $inviteRegPage, $inviteCodePage);
            $globals['inviteRegistration'] = $inviteRegState;
            $globals['inviteRegistrationRequired'] = $inviteRegistrationEnabled ? !$inviteRegState['unlocked'] : false;
            $globals['inviteRegistrationAgeInsufficient'] = !empty($inviteRegState['age_insufficient']);
            $globals['inviteRegistrationInviterMinMonths'] = max(0, intval($inviteRegState['required_months'] ?? 0));
            $globals['inviteRegistrationInviterCurrentMonths'] = max(0, intval($inviteRegState['current_months'] ?? 0));
            if (class_exists('CfInviteRegistrationService') && $userId > 0) {
                try {
                    $globals['inviteRegistrationRemainingQuota'] = CfInviteRegistrationService::getInviterRemainingQuota($userId);
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            if ($githubOptionEnabled && class_exists('CfInviteRegistrationGithubService')) {
                $globals['inviteRegistrationGithubConfigured'] = CfInviteRegistrationGithubService::isOauthConfigured($moduleSettings);
                $globals['inviteRegistrationGithubMinMonths'] = CfInviteRegistrationGithubService::getMinAccountAgeMonths($moduleSettings);
                $globals['inviteRegistrationGithubMinRepos'] = CfInviteRegistrationGithubService::getMinPublicRepoCount($moduleSettings);
                $globals['inviteRegistrationGithubBinding'] = CfInviteRegistrationGithubService::getBindingForUser($userId);

                $githubEntryScript = class_exists('CfClientController')
                    ? CfClientController::resolveClientEntryScript()
                    : 'index.php';
                $githubBaseQuery = class_exists('CfClientController')
                    ? CfClientController::buildClientBaseQuery($moduleSlug)
                    : ['m' => $moduleSlug];
                if (!is_array($githubBaseQuery) || empty($githubBaseQuery)) {
                    $githubBaseQuery = ['m' => $moduleSlug];
                }
                $githubBaseQuery['invite_registration_oauth'] = 'github_start';
                $globals['inviteRegistrationGithubAuthUrl'] = $githubEntryScript . '?' . http_build_query($githubBaseQuery);
            }

            if (class_exists('CfInviteRegistrationService')) {
                $globals['inviteRegistrationTelegramConfigured'] = CfInviteRegistrationService::isTelegramSilentReady($moduleSettings);
                $globals['inviteRegistrationTelegramBotUsername'] = CfInviteRegistrationService::resolveTelegramBotUsername($moduleSettings);
                if ($telegramOptionEnabled && class_exists('CfTelegramGroupRewardService')) {
                    try {
                        $bindReturnUrl = class_exists('CfClientController')
                            ? CfClientController::buildPreferredClientUrl('domain_hub', ['view' => 'tools', 'tg_bind_scene' => 'invite_gate'])
                            : 'index.php?m=domain_hub&view=tools&tg_bind_scene=invite_gate';
                        $bindSession = CfTelegramGroupRewardService::createBotBindSession($userId, $moduleSettings, 900, $bindReturnUrl);
                        $globals['inviteRegistrationTelegramBotBindUrl'] = (string) ($bindSession['bind_url'] ?? '');
                        $globals['inviteRegistrationTelegramBotBindExpiresAt'] = (string) ($bindSession['expires_at'] ?? '');
                    } catch (\Throwable $e) {
                        $globals['inviteRegistrationTelegramBotBindUrl'] = '';
                        $globals['inviteRegistrationTelegramBotBindExpiresAt'] = '';
                    }
                }
            }
        } else {
            $globals['inviteRegistration'] = [
                'code' => '',
                'unlocked' => true,
                'logs' => [],
                'pagination' => [
                    'page' => 1,
                    'perPage' => 10,
                    'total' => 0,
                    'totalPages' => 1,
                ],
                'age_insufficient' => false,
                'required_months' => 0,
                'current_months' => 0,
            ];
            $globals['inviteRegistrationRequired'] = false;
            $globals['inviteRegistrationAgeInsufficient'] = false;
            $globals['inviteRegistrationInviterMinMonths'] = 0;
            $globals['inviteRegistrationInviterCurrentMonths'] = 0;
        }

        $globals['inviteRegistrationQuotaExhausted'] = false;
        if ($inviteRegistrationEnabled && $inviteOptionEnabled && $userId > 0 && $globals['inviteRegistrationMaxPerUser'] > 0 && class_exists('CfInviteRegistrationService')) {
            try {
                $globals['inviteRegistrationQuotaExhausted'] = !CfInviteRegistrationService::checkInviterLimit($userId);
            } catch (\Throwable $ignored) {
                $globals['inviteRegistrationQuotaExhausted'] = false;
            }
        }
        $globals['inviteRegistrationPendingCode'] = '';
        if ($userId > 0) {
            $pendingCode = strtoupper(trim((string) ($_REQUEST['invite_code'] ?? ($_SESSION['cfmod_invite_registration_pending_code'] ?? ($_COOKIE['cfmod_invite_registration_pending_code'] ?? '')))));
            if ($pendingCode !== '' && preg_match('/^[A-Z0-9]{6,20}$/', $pendingCode)) {
                $globals['inviteRegistrationPendingCode'] = $pendingCode;
                $_SESSION['cfmod_invite_registration_pending_code'] = $pendingCode;
            }
        }

        $globals['roots'] = self::loadRootDomains($userId, $moduleSettings);
        $globals['rootLimitMap'] = self::loadRootLimitMap();
        $globals['rootMaintenanceMap'] = self::loadRootMaintenanceMap();
        $globals['rootInviteRequiredMap'] = self::loadRootInviteRequiredMap();
        $globals['rootdomainInviteCodes'] = self::loadUserRootdomainInviteCodes($userId);
        $globals['rootdomainInviteMaxPerUser'] = max(0, intval($moduleSettings['rootdomain_invite_max_per_user'] ?? 0));

        $globals['userid'] = $userId;
        $globals['myInviteCode'] = self::ensureInviteCode($userId);

        $banState = function_exists('cfmod_resolve_user_ban_state') ? cfmod_resolve_user_ban_state($userId) : ['is_banned' => false, 'reason' => ''];
        $globals['banState'] = $banState;
        $globals['isUserBannedOrInactive'] = !empty($banState['is_banned']);
        $globals['banReasonText'] = $banState['reason'] !== '' ? htmlspecialchars($banState['reason']) : '';

        $quota = self::loadOrCreateQuota($userId, $globals['max'], $globals['inviteLimitGlobal']);
        $globals['quota'] = $quota;
        $globals['domainGiftSubdomains'] = [];

        $githubRewardState = [
            'enabled' => false,
            'repo_url' => '',
            'reward_amount' => 1,
            'claimed' => false,
            'github_username' => '',
        ];
        $githubRewardOauthBinding = [
            'bound' => false,
            'github_id' => 0,
            'github_login' => '',
        ];
        $githubRewardHistory = [
            'items' => [],
            'page' => 1,
            'perPage' => 10,
            'total' => 0,
            'totalPages' => 1,
        ];
        if (class_exists('CfGithubStarRewardService')) {
            try {
                $githubRewardState = CfGithubStarRewardService::getUserClaimState($userId, $moduleSettings);
                if (!empty($githubRewardState['enabled'])) {
                    $githubRewardPage = isset($_GET['github_reward_page']) ? max(1, (int) $_GET['github_reward_page']) : 1;
                    $githubRewardHistory = CfGithubStarRewardService::getUserHistory($userId, $githubRewardPage, 10);
                    if (CfGithubStarRewardService::isOauthConfigured($moduleSettings)) {
                        $githubRewardOauthBinding = CfGithubStarRewardService::getOauthBindingForUser($userId);
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $globals['githubStarRewardEnabled'] = !empty($githubRewardState['enabled']);
        $globals['githubStarRewardRepoUrl'] = (string) ($githubRewardState['repo_url'] ?? '');
        $globals['githubStarRewardAmount'] = max(1, (int) ($githubRewardState['reward_amount'] ?? 1));
        $globals['githubStarRewardAlreadyClaimed'] = !empty($githubRewardState['claimed']);
        $globals['githubStarRewardGithubUsername'] = (string) ($githubRewardState['github_username'] ?? '');
        $globals['githubStarRewardOauthBinding'] = $githubRewardOauthBinding;
        $globals['githubStarRewardOauthConfigured'] = class_exists('CfGithubStarRewardService')
            && CfGithubStarRewardService::isOauthConfigured($moduleSettings);
        $globals['githubStarRewardOauthUrl'] = '';
        if ($globals['githubStarRewardOauthConfigured']) {
            $oauthEntryScript = class_exists('CfClientController')
                ? CfClientController::resolveClientEntryScript()
                : 'index.php';
            $oauthBaseQuery = class_exists('CfClientController')
                ? CfClientController::buildClientBaseQuery($moduleSlug)
                : ['m' => $moduleSlug];
            if (!is_array($oauthBaseQuery) || empty($oauthBaseQuery)) {
                $oauthBaseQuery = ['m' => $moduleSlug];
            }
            $oauthBaseQuery['view'] = 'tools';
            $oauthBaseQuery['github_star_reward_oauth'] = 'start';
            $globals['githubStarRewardOauthUrl'] = $oauthEntryScript . '?' . http_build_query($oauthBaseQuery);
        }
        $globals['githubStarRewardHistory'] = $githubRewardHistory;

        $telegramRewardState = [
            'enabled' => false,
            'group_link' => '',
            'chat_id' => '',
            'reward_amount' => 1,
            'claimed' => false,
            'telegram_bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'bot_username' => '',
        ];
        $telegramRewardHistory = [
            'items' => [],
            'page' => 1,
            'perPage' => 10,
            'total' => 0,
            'totalPages' => 1,
        ];
        if (class_exists('CfTelegramGroupRewardService')) {
            try {
                $telegramRewardState = CfTelegramGroupRewardService::getUserClaimState($userId, $moduleSettings);
                if (!empty($telegramRewardState['enabled'])) {
                    $telegramRewardPage = isset($_GET['telegram_reward_page']) ? max(1, (int) $_GET['telegram_reward_page']) : 1;
                    $telegramRewardHistory = CfTelegramGroupRewardService::getUserHistory($userId, $telegramRewardPage, 10);
                }
            } catch (\Throwable $e) {
            }
        }
        $globals['telegramGroupRewardEnabled'] = !empty($telegramRewardState['enabled']);
        $globals['telegramGroupRewardGroupLink'] = (string) ($telegramRewardState['group_link'] ?? '');
        $globals['telegramGroupRewardChatId'] = (string) ($telegramRewardState['chat_id'] ?? '');
        $globals['telegramGroupRewardAmount'] = max(1, (int) ($telegramRewardState['reward_amount'] ?? 1));
        $globals['telegramGroupRewardAlreadyClaimed'] = !empty($telegramRewardState['claimed']);
        $globals['telegramGroupRewardTelegramBound'] = !empty($telegramRewardState['telegram_bound']);
        $globals['telegramGroupRewardTelegramUserId'] = (int) ($telegramRewardState['telegram_user_id'] ?? 0);
        $globals['telegramGroupRewardTelegramUsername'] = (string) ($telegramRewardState['telegram_username'] ?? '');
        $globals['telegramGroupRewardBotUsername'] = (string) ($telegramRewardState['bot_username'] ?? '');
        $globals['telegramGroupRewardBotBindUrl'] = '';
        $globals['telegramGroupRewardBotBindExpiresAt'] = '';
        if (
            !empty($globals['telegramGroupRewardEnabled'])
            && empty($globals['telegramGroupRewardAlreadyClaimed'])
            && empty($globals['telegramGroupRewardTelegramBound'])
            && class_exists('CfTelegramGroupRewardService')
        ) {
            try {
                $bindReturnUrl = class_exists('CfClientController')
                    ? CfClientController::buildPreferredClientUrl('domain_hub', ['view' => 'tools', 'tg_bind_scene' => 'group_reward'])
                    : 'index.php?m=domain_hub&view=tools&tg_bind_scene=group_reward';
                $bindSession = CfTelegramGroupRewardService::createBotBindSession($userId, $moduleSettings, 900, $bindReturnUrl);
                $globals['telegramGroupRewardBotBindUrl'] = (string) ($bindSession['bind_url'] ?? '');
                $globals['telegramGroupRewardBotBindExpiresAt'] = (string) ($bindSession['expires_at'] ?? '');
            } catch (\Throwable $e) {
                $globals['telegramGroupRewardBotBindUrl'] = '';
                $globals['telegramGroupRewardBotBindExpiresAt'] = '';
            }
        }
        $globals['telegramGroupRewardHistory'] = $telegramRewardHistory;

        $expiryTelegramState = [
            'feature_enabled' => false,
            'configured' => false,
            'bot_username' => '',
            'days' => [],
            'days_csv' => '',
            'subscribed' => false,
            'telegram_bound' => false,
            'telegram_user_id' => 0,
            'telegram_username' => '',
            'updated_at' => '',
        ];
        if ($userId > 0 && class_exists('CfTelegramExpiryReminderService')) {
            try {
                $expiryTelegramState = CfTelegramExpiryReminderService::getUserState($userId, $moduleSettings);
            } catch (\Throwable $e) {
            }
        }
        $globals['expiryTelegramReminderFeatureEnabled'] = !empty($expiryTelegramState['feature_enabled']);
        $globals['expiryTelegramReminderConfigured'] = !empty($expiryTelegramState['configured']);
        $globals['expiryTelegramReminderBotUsername'] = (string) ($expiryTelegramState['bot_username'] ?? '');
        $globals['expiryTelegramReminderDays'] = is_array($expiryTelegramState['days'] ?? null) ? $expiryTelegramState['days'] : [];
        $globals['expiryTelegramReminderDaysCsv'] = (string) ($expiryTelegramState['days_csv'] ?? '');
        $globals['expiryTelegramReminderSubscribed'] = !empty($expiryTelegramState['subscribed']);
        $globals['expiryTelegramReminderTelegramBound'] = !empty($expiryTelegramState['telegram_bound']);
        $globals['expiryTelegramReminderTelegramUserId'] = (int) ($expiryTelegramState['telegram_user_id'] ?? 0);
        $globals['expiryTelegramReminderTelegramUsername'] = (string) ($expiryTelegramState['telegram_username'] ?? '');
        $globals['expiryTelegramReminderUpdatedAt'] = (string) ($expiryTelegramState['updated_at'] ?? '');

        $sslRequestEnabled = false;
        $sslRequestDomains = [];
        $sslCertificates = [
            'items' => [],
            'page' => 1,
            'perPage' => 10,
            'total' => 0,
            'totalPages' => 1,
        ];
        if (class_exists('CfSslCertificateService')) {
            try {
                $sslRequestEnabled = CfSslCertificateService::isEnabled($moduleSettings);
                if ($sslRequestEnabled) {
                    $sslPage = isset($_GET['ssl_page']) ? max(1, (int) $_GET['ssl_page']) : 1;
                    $sslRequestDomains = CfSslCertificateService::getUserDomainOptions($userId);
                    $sslCertificates = CfSslCertificateService::getUserCertificates($userId, $sslPage, 10);
                }
            } catch (\Throwable $e) {
                $sslRequestEnabled = false;
                $sslRequestDomains = [];
                $sslCertificates = [
                    'items' => [],
                    'page' => 1,
                    'perPage' => 10,
                    'total' => 0,
                    'totalPages' => 1,
                ];
            }
        }
        $globals['sslRequestEnabled'] = $sslRequestEnabled;
        $globals['sslRequestDomains'] = $sslRequestDomains;
        $globals['sslCertificates'] = $sslCertificates;

        $whoisFeatureEnabled = false;
        $whoisPrivacyEnabled = true;
        $whoisManagedDomainCount = 0;
        if (class_exists('CfWhoisService')) {
            try {
                $whoisFeatureEnabled = CfWhoisService::isEnabled($moduleSettings);
                if ($whoisFeatureEnabled) {
                    $whoisSettings = CfWhoisService::getUserWhoisSettings($userId);
                    $whoisPrivacyEnabled = !empty($whoisSettings['privacy_enabled']);
                    $whoisManagedDomainCount = max(0, (int) ($whoisSettings['managed_domains'] ?? 0));
                }
            } catch (\Throwable $e) {
                $whoisFeatureEnabled = false;
                $whoisPrivacyEnabled = true;
                $whoisManagedDomainCount = 0;
            }
        }
        $globals['whoisFeatureEnabled'] = $whoisFeatureEnabled;
        $globals['whoisPrivacyEnabled'] = $whoisPrivacyEnabled;
        $globals['whoisManagedDomainCount'] = $whoisManagedDomainCount;

        $digFeatureEnabled = false;
        $digSupportedTypes = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SRV'];
        if (class_exists('CfDigService')) {
            try {
                $digFeatureEnabled = CfDigService::isEnabled($moduleSettings);
                $types = CfDigService::getSupportedTypes();
                if (is_array($types) && !empty($types)) {
                    $digSupportedTypes = array_values(array_unique(array_map(static function ($value) {
                        return strtoupper(trim((string) $value));
                    }, $types)));
                    $digSupportedTypes = array_values(array_filter($digSupportedTypes, static function ($value) {
                        return $value !== '';
                    }));
                }
            } catch (\Throwable $e) {
                $digFeatureEnabled = false;
            }
        }
        $globals['digFeatureEnabled'] = $digFeatureEnabled;
        $globals['digSupportedTypes'] = $digSupportedTypes;
        $globals['featureCardsRegistry'] = self::buildFeatureCardsRegistry([
            'quotaRedeemEnabled' => !empty($globals['quotaRedeemEnabled']),
            'dnsUnlockFeatureEnabled' => !empty($globals['dnsUnlockFeatureEnabled']),
            'inviteRegistrationCenterVisible' => !empty($globals['inviteRegistrationCenterVisible']),
            'domainPermanentUpgradeEnabled' => !empty($globals['domainPermanentUpgradeEnabled']),
            'domainPermanentIncentiveEnabled' => !empty($globals['domainPermanentIncentiveEnabled']),
            'hasRootdomainInvite' => !empty($globals['hasRootdomainInvite']),
            'orphanCleanupEnabled' => in_array(strtolower(trim((string) ($moduleSettings['client_orphan_dns_cleanup_enabled'] ?? '0'))), ['1','on','yes','true','enabled'], true),
            'sslRequestEnabled' => !empty($sslRequestEnabled),
            'githubStarRewardEnabled' => !empty($globals['githubStarRewardEnabled']),
            'telegramGroupRewardEnabled' => !empty($globals['telegramGroupRewardEnabled']),
            'expiryTelegramReminderFeatureEnabled' => !empty($globals['expiryTelegramReminderFeatureEnabled']),
            'rootVerifyEnabled' => in_array(strtolower(trim((string) ($moduleSettings['enable_rootdomain_verify'] ?? '0'))), ['1','on','yes','true','enabled'], true),
            'digFeatureEnabled' => !empty($digFeatureEnabled),
        ]);

        $domainSearch = self::resolveDomainSearch($moduleSlug, $moduleSettings);
        $globals = array_merge($globals, $domainSearch);

        [$existing, $existingTotal, $domainTotalPages, $domainPage] = \CfSubdomainService::instance()->loadSubdomainsPaginated(
            $userId,
            $domainSearch['domainPage'],
            $domainSearch['domainPageSize'],
            $domainSearch['domainSearchTerm']
        );
        $globals['existing'] = $existing;
        $globals['existing_total'] = $existingTotal;
        $globals['domainTotalPages'] = $domainTotalPages;
        $globals['domainPage'] = $domainPage;
        // Orphan cleanup candidates are loaded on demand via AJAX to avoid injecting
        // every active domain into the initial client-area page for large accounts.
        $globals['orphanCleanupDomains'] = [];

        if ($globals['domainGiftEnabled']) {
            // 转赠域名候选改为服务端分页搜索，前端首屏不再注入全量域名列表
            $globals['domainGiftSubdomains'] = [];
        }

        $dnsFilter = self::resolveDnsFilter();
        $globals = array_merge($globals, $dnsFilter);

        $dnsDataset = [];
        if (function_exists('cfmod_fetch_dns_records_for_subdomains')) {
            $dnsDataset = cfmod_fetch_dns_records_for_subdomains(
                is_array($existing) ? $existing : [],
                $dnsFilter['filter_type'],
                $dnsFilter['filter_name'],
                [
                    'page_size' => $dnsFilter['dnsPageSize'],
                    'dns_page' => $dnsFilter['dnsPage'],
                    'dns_page_for' => $dnsFilter['dnsPageFor'],
                ]
            );
        }
        $globals['recordsBySubId'] = $dnsDataset['records'] ?? [];
        $globals['filteredBySubId'] = $globals['recordsBySubId'];
        $globals['nsBySubId'] = $dnsDataset['ns'] ?? [];
        $globals['dnsTotalsBySubId'] = $dnsDataset['totals'] ?? [];
        $globals['subdomainRootMap'] = [];
        $globals['subdomainProviderTypeMap'] = [];
        if (is_array($existing)) {
            $providerAccountIds = [];
            foreach ($existing as $row) {
                $sid = intval($row->id ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $globals['subdomainRootMap'][$sid] = strtolower(trim((string) ($row->rootdomain ?? '')));
                $providerAccountId = intval($row->provider_account_id ?? 0);
                if ($providerAccountId > 0) {
                    $providerAccountIds[$providerAccountId] = true;
                }
            }
            if (!empty($providerAccountIds)) {
                try {
                    $accountRows = Capsule::table('mod_cloudflare_provider_accounts')
                        ->select('id', 'provider_type')
                        ->whereIn('id', array_keys($providerAccountIds))
                        ->get();
                    $providerTypeByAccountId = [];
                    foreach ($accountRows as $accountRow) {
                        $aid = intval($accountRow->id ?? 0);
                        if ($aid <= 0) {
                            continue;
                        }
                        $providerTypeByAccountId[$aid] = strtolower(trim((string) ($accountRow->provider_type ?? '')));
                    }
                    foreach ($existing as $row) {
                        $sid = intval($row->id ?? 0);
                        if ($sid <= 0) {
                            continue;
                        }
                        $providerAccountId = intval($row->provider_account_id ?? 0);
                        $providerType = $providerTypeByAccountId[$providerAccountId] ?? '';
                        if ($providerType === '') {
                            $providerType = 'alidns';
                        }
                        $globals['subdomainProviderTypeMap'][$sid] = $providerType;
                    }
                } catch (\Throwable $ignored) {
                }
            }
        }
        $globals['rootNsDisabledMap'] = self::loadRootNsDisabledMap();
        $globals['deleteProgressBySubId'] = self::loadDeleteProgressBySubId($existing);

        return [
            'globals' => $globals,
            'meta' => ['template_variables' => array_keys($globals)],
        ];
    }

    private static function ensureUserPreferencesTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_user_preferences')) {
                Capsule::schema()->create('mod_cloudflare_user_preferences', function ($table) {
                    $table->increments('id');
                    $table->integer('userid')->unsigned()->unique();
                    $table->string('renew_button_display_mode', 32)->default('window_only');
                    $table->timestamps();
                    $table->index(['renew_button_display_mode']);
                });
            } elseif (!Capsule::schema()->hasColumn('mod_cloudflare_user_preferences', 'renew_button_display_mode')) {
                Capsule::schema()->table('mod_cloudflare_user_preferences', function ($table) {
                    $table->string('renew_button_display_mode', 32)->default('window_only');
                });
            }
        } catch (\Throwable $e) {
        }
    }

    private static function resolveRenewButtonDisplayMode(int $userId): string
    {
        self::ensureUserPreferencesTable();
        if ($userId <= 0) {
            return 'window_only';
        }
        try {
            $raw = strtolower(trim((string) Capsule::table('mod_cloudflare_user_preferences')
                ->where('userid', $userId)
                ->value('renew_button_display_mode')));
            if (!in_array($raw, ['always', 'window_only'], true)) {
                return 'window_only';
            }
            return $raw;
        } catch (\Throwable $e) {
            return 'window_only';
        }
    }

    private static function buildFeatureCardsRegistry(array $flags): array
    {
        return [
            ['key' => 'quota_redeem', 'visible' => !empty($flags['quotaRedeemEnabled']), 'render_partial' => 'feature_quota_redeem', 'default_order' => 10],
            ['key' => 'dns_unlock', 'visible' => !empty($flags['dnsUnlockFeatureEnabled']), 'render_partial' => 'feature_dns_unlock', 'default_order' => 20],
            ['key' => 'invite_registration', 'visible' => !empty($flags['inviteRegistrationCenterVisible']), 'render_partial' => 'feature_invite_registration', 'default_order' => 30],
            ['key' => 'permanent_upgrade', 'visible' => !empty($flags['domainPermanentUpgradeEnabled']), 'render_partial' => 'feature_permanent_upgrade', 'default_order' => 40],
            ['key' => 'permanent_incentive', 'visible' => !empty($flags['domainPermanentIncentiveEnabled']), 'render_partial' => 'feature_permanent_incentive', 'default_order' => 50],
            ['key' => 'root_invite', 'visible' => !empty($flags['hasRootdomainInvite']), 'render_partial' => 'feature_root_invite', 'default_order' => 60],
            ['key' => 'orphan_cleanup', 'visible' => !empty($flags['orphanCleanupEnabled']), 'render_partial' => 'feature_orphan_cleanup', 'default_order' => 70],
            ['key' => 'ssl_request', 'visible' => !empty($flags['sslRequestEnabled']), 'render_partial' => 'feature_ssl_request', 'default_order' => 80],
            ['key' => 'github_star_reward', 'visible' => !empty($flags['githubStarRewardEnabled']), 'render_partial' => 'feature_github_star_reward', 'default_order' => 90],
            ['key' => 'telegram_group_reward', 'visible' => !empty($flags['telegramGroupRewardEnabled']), 'render_partial' => 'feature_telegram_group_reward', 'default_order' => 100],
            ['key' => 'expiry_telegram_reminder', 'visible' => !empty($flags['expiryTelegramReminderFeatureEnabled']), 'render_partial' => 'feature_expiry_telegram_reminder', 'default_order' => 110],
            ['key' => 'root_verify', 'visible' => !empty($flags['rootVerifyEnabled']), 'render_partial' => 'feature_root_verify', 'default_order' => 120],
            ['key' => 'dig_tools', 'visible' => !empty($flags['digFeatureEnabled']), 'render_partial' => 'feature_dig_tools', 'default_order' => 130],
        ];
    }

    private static function loadRootNsDisabledMap(): array
    {
        $map = [];
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomains')) {
                return $map;
            }
            if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'disable_ns_management')) {
                return $map;
            }
            $rows = Capsule::table('mod_cloudflare_rootdomains')
                ->select('domain', 'disable_ns_management')
                ->get();
            foreach ($rows as $row) {
                $root = strtolower(trim((string) ($row->domain ?? '')));
                if ($root === '') {
                    continue;
                }
                $map[$root] = intval($row->disable_ns_management ?? 0) === 1;
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $map;
    }

    private static function buildInviteLeaderboardData(int $userId, string $inviteCycleStart, int $inviteLeaderboardDays, bool $hideCurrentWeekLeaderboard): array
    {
        $data = [
            'realtimeHiddenTop10' => [],
            'recentSnapshots' => [],
            'periodStart' => '',
            'periodEnd' => '',
            'winners' => [],
            'existingReward' => null,
            'realtimeTop10' => [],
            'realtimeTop20' => [],
        ];
        $inviteLeaderboardDays = max(1, $inviteLeaderboardDays);
        $todayYmd = date('Y-m-d');

        try {
            [$hiddenStart, $hiddenEnd] = self::resolveRealtimeWindow($inviteCycleStart, $inviteLeaderboardDays, $todayYmd);
            $data['realtimeHiddenTop10'] = self::fetchInvitationLeaderboardRows($hiddenStart, $hiddenEnd, 10, $userId);
            $data['recentSnapshots'] = Capsule::table('mod_cloudflare_invite_leaderboard')
                ->orderBy('period_start', 'desc')
                ->limit(3)
                ->get()
                ->all();

            [$periodStart, $periodEnd] = self::resolveLastPeriodWindow($inviteCycleStart, $inviteLeaderboardDays);
            $data['periodStart'] = $periodStart;
            $data['periodEnd'] = $periodEnd;
            $data['winners'] = self::fetchInviteWinners($periodStart, $periodEnd, 5, $userId);
            $reward = Capsule::table('mod_cloudflare_invite_rewards')
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->where('inviter_userid', $userId)
                ->first();
            $data['existingReward'] = $reward ? (array) $reward : null;

            [$rtStart2, $rtEnd2] = self::resolveRealtimeWindow($inviteCycleStart, $inviteLeaderboardDays, $todayYmd);
            $data['realtimeTop10'] = self::fetchInvitationLeaderboardRows($rtStart2, $rtEnd2, 10, $userId);

            [$rtStart3, $rtEnd3] = self::resolveRealtimeWindow($inviteCycleStart, $inviteLeaderboardDays, $todayYmd);
            $data['realtimeTop20'] = self::fetchInvitationLeaderboardRows($rtStart3, $rtEnd3, 20, $userId);
        } catch (\Throwable $e) {
            return $data;
        }

        return $data;
    }

    private static function resolveRealtimeWindow(string $inviteCycleStart, int $days, string $todayYmd): array
    {
        if ($inviteCycleStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inviteCycleStart)) {
            $startTs = strtotime($inviteCycleStart);
            $todayTs = strtotime($todayYmd);
            if ($todayTs >= $startTs) {
                $k = (int) floor((($todayTs - $startTs) / 86400) / $days);
                $start = date('Y-m-d', strtotime('+' . ($k * $days) . ' days', $startTs));
            } else {
                $start = $todayYmd;
            }
            return [$start, $todayYmd];
        }
        $end = $todayYmd;
        $start = date('Y-m-d', strtotime($end . ' -' . ($days - 1) . ' days'));
        return [$start, $end];
    }

    private static function resolveLastPeriodWindow(string $inviteCycleStart, int $days): array
    {
        if ($inviteCycleStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inviteCycleStart)) {
            $startTs = strtotime($inviteCycleStart);
            $todayTs = strtotime(date('Y-m-d'));
            if ($todayTs >= $startTs) {
                $k = (int) floor((($todayTs - $startTs) / 86400) / $days);
                return [
                    date('Y-m-d', strtotime('+' . (($k - 1) * $days) . ' days', $startTs)),
                    date('Y-m-d', strtotime('+' . (($k * $days) - 1) . ' days', $startTs)),
                ];
            }
            return [date('Y-m-d', strtotime('yesterday')), date('Y-m-d', strtotime('yesterday'))];
        }
        $periodEnd = date('Y-m-d', strtotime('yesterday'));
        return [$periodEnd ? date('Y-m-d', strtotime($periodEnd . ' -' . ($days - 1) . ' days')) : date('Y-m-d'), $periodEnd];
    }

    private static function fetchInvitationLeaderboardRows(string $startDate, string $endDate, int $limit, int $currentUserId): array
    {
        $rows = Capsule::table('mod_cloudflare_invitation_claims as ic')
            ->select('ic.inviter_userid', Capsule::raw('COUNT(*) as cnt'))
            ->whereBetween('ic.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->groupBy('ic.inviter_userid')
            ->orderBy('cnt', 'desc')
            ->limit(max(1, $limit))
            ->get();
        return self::decorateLeaderboardRows($rows, $currentUserId);
    }

    private static function fetchInviteWinners(string $periodStart, string $periodEnd, int $limit, int $currentUserId): array
    {
        $rows = Capsule::table('mod_cloudflare_invite_rewards as r')
            ->select('r.inviter_userid', 'r.rank', 'r.count', 'r.code')
            ->where('r.period_start', $periodStart)
            ->where('r.period_end', $periodEnd)
            ->orderBy('r.rank', 'asc')
            ->limit(max(1, $limit))
            ->get();
        return self::decorateLeaderboardRows($rows, $currentUserId, true);
    }

    private static function decorateLeaderboardRows($rows, int $currentUserId, bool $preferRewardCode = false): array
    {
        $userIds = [];
        foreach ($rows as $row) {
            $uid = intval($row->inviter_userid ?? 0);
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }
        [$emailMap, $codeMap] = function_exists('cfmod_client_fetch_invite_user_meta')
            ? cfmod_client_fetch_invite_user_meta($userIds)
            : [[], []];
        $out = [];
        $rank = 1;
        foreach ($rows as $row) {
            $uid = intval($row->inviter_userid ?? 0);
            $rewardCode = $preferRewardCode ? trim((string) ($row->code ?? '')) : '';
            $sourceCode = $rewardCode !== '' ? $rewardCode : (string) ($codeMap[$uid] ?? '');
            $out[] = [
                'rank' => intval($row->rank ?? $rank),
                'inviter_userid' => $uid,
                'count' => intval($row->count ?? $row->cnt ?? 0),
                'emailMasked' => function_exists('cfmod_client_mask_leaderboard_email') ? cfmod_client_mask_leaderboard_email((string) ($emailMap[$uid] ?? '')) : '',
                'codeMasked' => function_exists('cfmod_mask_invite_code') ? cfmod_mask_invite_code($sourceCode) : $sourceCode,
                'isCurrentUser' => $uid === $currentUserId,
            ];
            $rank++;
        }
        return $out;
    }

    private static function checkCoreTablesExist(): bool
    {
        try {
            $schema = Capsule::schema();
            return $schema->hasTable('mod_cloudflare_subdomain')
                && $schema->hasTable('mod_cloudflare_subdomain_quotas')
                && $schema->hasTable('mod_cloudflare_rootdomains')
                && $schema->hasTable('mod_cloudflare_dns_records');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function loadDeleteProgressBySubId(array $existingSubdomains): array
    {
        $result = [];
        $subdomainIds = [];
        foreach ($existingSubdomains as $row) {
            $sid = intval($row->id ?? 0);
            if ($sid > 0) {
                $subdomainIds[] = $sid;
            }
        }
        $subdomainIds = array_values(array_unique($subdomainIds));
        if (empty($subdomainIds)) {
            return $result;
        }

        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_jobs')) {
                return $result;
            }

            $jobs = Capsule::table('mod_cloudflare_jobs')
                ->select('id', 'subdomain_id', 'type', 'status', 'created_at', 'started_at')
                ->whereIn('subdomain_id', $subdomainIds)
                ->whereIn('type', ['cleanup_expired_subdomain_remote', 'cleanup_expired_subdomains'])
                ->orderBy('id', 'desc')
                ->get();

            $latestBySubId = [];
            foreach ($jobs as $job) {
                $sid = intval($job->subdomain_id ?? 0);
                if ($sid <= 0 || isset($latestBySubId[$sid])) {
                    continue;
                }
                $latestBySubId[$sid] = $job;
            }

            $runningCount = (int) Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_expired_subdomain_remote')
                ->where('status', 'running')
                ->count();
            $pendingCount = (int) Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_expired_subdomain_remote')
                ->where('status', 'pending')
                ->count();
            $queueDepth = max(0, $runningCount + $pendingCount);

            $avgDuration = (float) Capsule::table('mod_cloudflare_jobs')
                ->where('type', 'cleanup_expired_subdomain_remote')
                ->where('status', 'done')
                ->whereNotNull('duration_seconds')
                ->avg('duration_seconds');
            if ($avgDuration <= 0) {
                $avgDuration = 90.0;
            }
            $etaSeconds = (int) round(max(30.0, $avgDuration * max(1, $queueDepth)));

            $etaMinutes = max(1, (int) ceil($etaSeconds / 60));
            $etaQueuedTemplate = function_exists('cfmod_trans')
                ? cfmod_trans('cfclient.subdomains.delete.progress.eta.queued', 'Estimated about %s minutes')
                : 'Estimated about %s minutes';
            $etaQueuedText = sprintf($etaQueuedTemplate, $etaMinutes);
            $etaRunningText = function_exists('cfmod_trans')
                ? cfmod_trans('cfclient.subdomains.delete.progress.eta.running', 'In progress')
                : 'In progress';
            $etaDoneText = function_exists('cfmod_trans')
                ? cfmod_trans('cfclient.subdomains.delete.progress.eta.done', 'Cleanup completed')
                : 'Cleanup completed';

            foreach ($existingSubdomains as $subdomain) {
                $sid = intval($subdomain->id ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $statusLower = strtolower((string) ($subdomain->status ?? ''));
                if (!in_array($statusLower, ['pending_delete', 'pending_remove', 'deleted'], true)) {
                    continue;
                }
                if ($statusLower === 'deleted') {
                    $result[$sid] = ['stage' => 'local_done', 'percent' => 100, 'eta_text' => ''];
                    continue;
                }

                $job = $latestBySubId[$sid] ?? null;
                if (!$job) {
                    $result[$sid] = [
                        'stage' => 'queued',
                        'percent' => 33,
                        'eta_text' => $etaQueuedText,
                    ];
                    continue;
                }

                $jobStatus = strtolower((string) ($job->status ?? ''));
                if ($jobStatus === 'running') {
                    $result[$sid] = ['stage' => 'remote_deleting', 'percent' => 66, 'eta_text' => $etaRunningText];
                } elseif ($jobStatus === 'done') {
                    $result[$sid] = ['stage' => 'local_done', 'percent' => 100, 'eta_text' => $etaDoneText];
                } else {
                    $result[$sid] = [
                        'stage' => 'queued',
                        'percent' => 33,
                        'eta_text' => $etaQueuedText,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $result;
    }

    private static function loadModuleSettings(string $moduleSlug, string $legacyModuleSlug): array
    {
        try {
            $configs = Capsule::table('tbladdonmodules')->where('module', $moduleSlug)->get();
            if (count($configs) === 0 && $legacyModuleSlug !== $moduleSlug) {
                $configs = Capsule::table('tbladdonmodules')->where('module', $legacyModuleSlug)->get();
            }
            $settings = [];
            foreach ($configs as $config) {
                $settings[$config->setting] = $config->value;
            }
            if (!empty($settings)) {
                return $settings;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'cloudflare_api_key' => '',
            'cloudflare_email' => '',
            'max_subdomain_per_user' => 5,
            'root_domains' => '',
            'forbidden_prefix' => 'www,mail,ftp,admin,root,gov,pay,bank',
            'default_ip' => '192.0.2.1',
            'client_support_ticket_url' => 'submitticket.php',
            'client_support_group_url' => 'https://t.me/+l9I5TNRDLP5lZDBh',
            'invite_registration_gate_mode' => 'disabled',
            'invite_registration_github_client_id' => '',
            'invite_registration_github_client_secret' => '',
            'invite_registration_github_min_months' => '0',
            'invite_registration_github_min_repos' => '0',
            'invite_registration_telegram_bot_username' => '',
            'invite_registration_telegram_bot_token' => '',
            'invite_registration_telegram_auth_max_age_seconds' => '86400',
            'invite_registration_inviter_min_months' => '0',
            'enable_domain_permanent_upgrade' => '1',
            'domain_permanent_upgrade_assist_required' => '3',
            'domain_permanent_upgrade_helper_limit' => '0',
            'domain_permanent_upgrade_enable_realtime_feed' => '1',
            'domain_permanent_upgrade_paid_enabled' => '0',
            'domain_permanent_upgrade_paid_price' => '0',
            'client_quota_ticker_enabled' => '0',
            'client_quota_ticker_text' => '',
            'client_quota_ticker_speed' => '28',
            'enable_github_star_reward' => '0',
            'github_star_repo_url' => '',
            'github_star_reward_amount' => '1',
            'github_star_oauth_client_id' => '',
            'github_star_oauth_client_secret' => '',
            'enable_telegram_group_reward' => '0',
            'telegram_group_link' => '',
            'telegram_group_chat_id' => '',
            'telegram_group_bot_username' => '',
            'telegram_group_bot_token' => '',
            'telegram_group_reward_amount' => '1',
            'telegram_reward_auth_max_age_seconds' => '86400',
            'renewal_notice_telegram_enabled' => '0',
            'renewal_notice_telegram_bot_username' => '',
            'renewal_notice_telegram_bot_token' => '',
            'renewal_notice_telegram_template' => "【域名到期提醒】
域名：{\$fqdn}
到期时间：{\$expiry_datetime}
剩余天数：{\$days_left} 天
请及时续期，避免域名失效。",
            'renewal_notice_telegram_template_zh' => "【域名到期提醒】
域名：{\$fqdn}
到期时间：{\$expiry_datetime}
剩余天数：{\$days_left} 天
请及时续期，避免域名失效。",
            'renewal_notice_telegram_template_en' => "[Domain Expiry Reminder]
Domain: {\$fqdn}
Expiry Time: {\$expiry_datetime}
Days Left: {\$days_left}
Please renew in time to avoid domain suspension.",
            'renewal_notice_telegram_days' => '30,10',
            'renewal_notice_telegram_auth_max_age_seconds' => '86400',
            'enable_ssl_request' => '1',
            'letsencrypt_email' => '',
            'ssl_acme_client' => 'auto',
            'letsencrypt_directory_url' => '',
            'letsencrypt_dns_wait_seconds' => '25',
            'letsencrypt_storage_path' => '',
            'enable_whois_center' => '1',
            'whois_default_nameservers' => '',
            'whois_anonymous_email' => '',
            'partner_plan_admin_email' => '',
            'sponsor_title' => '赞助 DNSHE',
            'sponsor_description' => 'DNSHE 的成长离不开社区的支持。你的每一份赞助都将用于支付服务器与根域名的续费开支。',
            'sponsor_methods' => '',
            'sponsor_acknowledgements' => '',
        ];
    }

    private static function loadDnsUnlockState(int $userId, int $page): array
    {
        $default = [
            'code' => '',
            'unlocked' => false,
            'last_used_code' => '',
            'last_used_owner_userid' => 0,
            'last_used_at' => null,
            'logs' => [],
            'pagination' => [
                'page' => max(1, $page),
                'perPage' => 5,
                'total' => 0,
                'totalPages' => 1,
            ],
        ];
        if ($userId <= 0 || !class_exists('CfDnsUnlockService')) {
            return $default;
        }
        try {
            $profile = CfDnsUnlockService::ensureProfile($userId);
            $logsData = CfDnsUnlockService::fetchLogs($userId, $page, 10);
            $lastUsedInfo = CfDnsUnlockService::getLastUsedUnlockInfo($userId);
            return [
                'code' => $profile['unlock_code'],
                'unlocked' => !empty($profile['unlocked_at']),
                'last_used_code' => $lastUsedInfo['code'] ?? '',
                'last_used_owner_userid' => $lastUsedInfo['owner_userid'] ?? 0,
                'last_used_at' => $lastUsedInfo['used_at'] ?? null,
                'logs' => $logsData['items'] ?? [],
                'pagination' => $logsData['pagination'] ?? $default['pagination'],
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private static function loadInviteRegistrationState(int $userId, int $page, int $codePage = 1): array
    {
        $default = [
            'code' => '',
            'unlocked' => false,
            'logs' => [],
            'pagination' => [
                'page' => max(1, $page),
                'perPage' => 5,
                'total' => 0,
                'totalPages' => 1,
            ],
            'age_insufficient' => false,
            'required_months' => 0,
            'current_months' => 0,
            'unused_codes' => [],
            'unused_pagination' => ['page' => 1, 'perPage' => 5, 'total' => 0, 'totalPages' => 1],
        ];
        if ($userId <= 0 || !class_exists('CfInviteRegistrationService')) {
            return $default;
        }
        try {
            $logsData = CfInviteRegistrationService::fetchUserLogs($userId, $page, 5);
            $eligibility = CfInviteRegistrationService::getInviterCodeEligibility($userId);
            $requiredMonths = max(0, intval($eligibility['required_months'] ?? 0));
            $currentMonths = max(0, intval($eligibility['current_months'] ?? 0));
            $ageInsufficient = !empty($requiredMonths) && empty($eligibility['eligible']);
            $unlocked = CfInviteRegistrationService::userHasUnlocked($userId);
            $unusedCodes = CfInviteRegistrationService::fetchUnusedCodes($userId, $codePage, 5);

            if ($ageInsufficient) {
                return [
                    'code' => '',
                    'unlocked' => $unlocked,
                    'logs' => $logsData['items'] ?? [],
                    'pagination' => $logsData['pagination'] ?? $default['pagination'],
                    'age_insufficient' => true,
                    'required_months' => $requiredMonths,
                    'current_months' => $currentMonths,
                    'unused_codes' => $unusedCodes['items'] ?? [],
                    'unused_pagination' => $unusedCodes['pagination'] ?? $default['unused_pagination'],
                ];
            }

            $profile = CfInviteRegistrationService::ensureProfile($userId);
            return [
                'code' => $profile['invite_code'],
                'unlocked' => !empty($profile['unlocked_at']),
                'logs' => $logsData['items'] ?? [],
                'pagination' => $logsData['pagination'] ?? $default['pagination'],
                'age_insufficient' => false,
                'required_months' => $requiredMonths,
                'current_months' => $currentMonths,
                'unused_codes' => $unusedCodes['items'] ?? [],
                'unused_pagination' => $unusedCodes['pagination'] ?? $default['unused_pagination'],
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private static function loadRootDomains(int $userId, array $moduleSettings): array
    {
        $roots = [];
        $allowSuspended = false;
        if ($userId > 0 && function_exists('cf_is_user_privileged') && cf_is_user_privileged($userId)) {
            $allowSuspended = function_exists('cf_is_privileged_feature_enabled')
                && cf_is_privileged_feature_enabled('allow_register_suspended_root', $moduleSettings);
        }

        try {
            $query = Capsule::table('mod_cloudflare_rootdomains')
                ->orderBy('display_order', 'asc')
                ->orderBy('id', 'asc');

            if ($allowSuspended) {
                $query->whereIn('status', ['active', 'suspended']);
            } else {
                $query->where('status', 'active');
            }

            $rows = $query->get();
            foreach ($rows as $row) {
                $domain = trim((string)($row->domain ?? ''));
                if ($domain !== '') {
                    $grayEnabled = (int)($row->gray_enabled ?? 0);
                    $grayRatio = (int)($row->gray_ratio ?? 100);
                    if (function_exists('cfmod_rootdomain_gray_hit') && !cfmod_rootdomain_gray_hit($userId, $domain, $grayEnabled, $grayRatio)) {
                        continue;
                    }
                    $roots[] = $domain;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return array_values(array_unique(array_filter($roots, static function ($d) {
            return $d !== '';
        })));
    }

    private static function loadRootLimitMap(): array
    {
        if (!function_exists('cfmod_get_rootdomain_limits_map')) {
            return [];
        }
        try {
            return array_change_key_case(cfmod_get_rootdomain_limits_map(), CASE_LOWER);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function loadRootMaintenanceMap(): array
    {
        $map = [];
        try {
            $rows = Capsule::table('mod_cloudflare_rootdomains')
                ->select('domain', 'maintenance')
                ->get();
            foreach ($rows as $row) {
                $domain = strtolower(trim((string)($row->domain ?? '')));
                if ($domain !== '') {
                    $map[$domain] = intval($row->maintenance ?? 0) === 1;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $map;
    }

    private static function loadRootInviteRequiredMap(): array
    {
        $map = [];
        try {
            $rows = Capsule::table('mod_cloudflare_rootdomains')
                ->select('domain', 'require_invite_code')
                ->get();
            foreach ($rows as $row) {
                $domain = strtolower(trim((string)($row->domain ?? '')));
                if ($domain !== '') {
                    $map[$domain] = intval($row->require_invite_code ?? 0) === 1;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $map;
    }

    private static function loadUserRootdomainInviteCodes(int $userId): array
    {
        $codes = [];
        try {
            if (class_exists('CfRootdomainInviteService')) {
                $codes = CfRootdomainInviteService::getUserAllInviteCodes($userId);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $codes;
    }

    private static function ensureInviteCode(int $userId): string
    {
        try {
            $row = Capsule::table('mod_cloudflare_invitation_codes')->where('userid', $userId)->first();
            if ($row) {
                return (string)($row->code ?? '');
            }
            $attempts = 0;
            do {
                $code = self::generateRandomPrefix() . strtoupper(bin2hex(random_bytes(4)));
                $exists = Capsule::table('mod_cloudflare_invitation_codes')->where('code', $code)->exists();
                $attempts++;
            } while ($exists && $attempts < 5);
            if ($exists) {
                $code = self::generateRandomPrefix() . strtoupper(bin2hex(random_bytes(3)));
            }
            Capsule::table('mod_cloudflare_invitation_codes')->insert([
                'userid' => $userId,
                'code' => $code,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return $code;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function generateRandomPrefix(): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return $letters[random_int(0, 25)] . $letters[random_int(0, 25)];
    }

    private static function loadOrCreateQuota(int $userId, int $max, int $inviteLimitGlobal)
    {
        try {
            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->first();
            if (!$quota) {
                Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                    'userid' => $userId,
                    'used_count' => 0,
                    'max_count' => $max,
                    'invite_bonus_count' => 0,
                    'invite_bonus_limit' => $inviteLimitGlobal,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $quota = Capsule::table('mod_cloudflare_subdomain_quotas')->where('userid', $userId)->first();
            }

            if ($quota && $userId > 0) {
                $isPrivileged = function_exists('cf_is_user_privileged') && cf_is_user_privileged($userId);

                if (!$isPrivileged) {
                    if (function_exists('cf_sync_user_base_quota_if_needed') && $max > 0) {
                        $quota = cf_sync_user_base_quota_if_needed($userId, $max, $quota);
                    }
                    if (function_exists('cf_sync_user_invite_limit_if_needed') && $inviteLimitGlobal > 0) {
                        $quota = cf_sync_user_invite_limit_if_needed($userId, $inviteLimitGlobal, $quota);
                    }
                } else {
                    if (function_exists('cf_ensure_privileged_quota')) {
                        $quota = cf_ensure_privileged_quota($userId, $quota, $inviteLimitGlobal);
                    }
                }
            }

            return $quota;
        } catch (\Throwable $e) {
            return (object) [
                'used_count' => 0,
                'max_count' => $max,
                'invite_bonus_count' => 0,
                'invite_bonus_limit' => $inviteLimitGlobal,
            ];
        }
    }

    private static function consumeClientFlash(): ?array
    {
        if (!isset($_SESSION['cfmod_client_flash'])) {
            return null;
        }

        $flash = $_SESSION['cfmod_client_flash'];
        unset($_SESSION['cfmod_client_flash']);

        if (!is_array($flash)) {
            return null;
        }

        $message = trim((string) ($flash['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        $type = strtolower(trim((string) ($flash['type'] ?? 'info')));
        if (!in_array($type, ['success', 'info', 'warning', 'danger'], true)) {
            $type = 'info';
        }

        return [
            'type' => $type,
            'message' => $message,
            'context' => trim((string) ($flash['context'] ?? '')),
        ];
    }

    private static function resolveDomainSearch(string $moduleSlug, array $moduleSettings): array
    {
        $domainSearchTerm = '';
        $domainSearchTermRaw = trim($_GET['domain_search'] ?? '');
        if ($domainSearchTermRaw !== '') {
            $domainSearchTerm = function_exists('mb_substr')
                ? trim(mb_substr($domainSearchTermRaw, 0, 100, 'UTF-8'))
                : trim(substr($domainSearchTermRaw, 0, 100));
        }
        $domainSearchClearParams = $_GET;
        unset($domainSearchClearParams['domain_search'], $domainSearchClearParams['p'], $domainSearchClearParams['page']);
        $entryBaseParams = self::entryBaseQuery($moduleSlug);
        if (isset($entryBaseParams['action'])) {
            unset($domainSearchClearParams['m']);
        }
        if (isset($entryBaseParams['m'])) {
            unset($domainSearchClearParams['action'], $domainSearchClearParams['module']);
        }
        foreach ($entryBaseParams as $key => $value) {
            $domainSearchClearParams[$key] = $value;
        }
        $domainSearchClearQueryString = http_build_query($domainSearchClearParams);
        if ($domainSearchClearQueryString === '') {
            $domainSearchClearQueryString = http_build_query($entryBaseParams);
        }
        $domainPageSizeSetting = intval($moduleSettings['client_page_size'] ?? 20);
        $domainPageSize = max(1, min(20, $domainPageSizeSetting));
        $domainPage = isset($_GET['p']) ? intval($_GET['p']) : 1;
        if ($domainPage < 1) {
            $domainPage = 1;
        }

        return [
            'domainSearchTerm' => $domainSearchTerm,
            'domainSearchClearUrl' => '?' . $domainSearchClearQueryString,
            'domainPageSize' => $domainPageSize,
            'domainPage' => $domainPage,
        ];
    }

    private static function resolveDnsFilter(): array
    {
        $filter_type = trim($_POST['filter_type'] ?? ($_GET['filter_type'] ?? ''));
        $filter_name = trim($_POST['filter_name'] ?? ($_GET['filter_name'] ?? ''));
        $dnsPage = max(1, intval($_GET['dns_page'] ?? 1));
        $dnsPageFor = intval($_GET['dns_for'] ?? 0);
        $dnsPageSize = 20;

        return [
            'filter_type' => $filter_type,
            'filter_name' => $filter_name,
            'dnsPage' => $dnsPage,
            'dnsPageFor' => $dnsPageFor,
            'dnsPageSize' => $dnsPageSize,
        ];
    }

    private static function loadClientActivityLogs(int $userId, int $page = 1, int $perPage = 10, int $windowDays = 7, string $language = 'chinese'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $windowDays = max(1, min(30, $windowDays));
        $cutoff = date('Y-m-d H:i:s', time() - ($windowDays * 86400));

        $allowedActions = [
            'client_register_subdomain',
            'client_create_dns',
            'client_update_dns',
            'client_delete_dns_record',
            'admin_delete_dns_record',
            'system_delete_dns_record',
            'client_replace_ns_group',
            'client_request_delete',
            'client_renew_subdomain',
            'client_invite_quota_reward_inviter',
            'client_invite_quota_reward_invitee',
        ];
        try {
            $baseQuery = Capsule::table('mod_cloudflare_logs')
                ->where('userid', $userId)
                ->whereIn('action', $allowedActions)
                ->where('created_at', '>=', $cutoff);

            $total = (int) $baseQuery->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;

            $rows = Capsule::table('mod_cloudflare_logs')
                ->where('userid', $userId)
                ->whereIn('action', $allowedActions)
                ->where('created_at', '>=', $cutoff)
                ->orderBy('id', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $counterpartyMap = self::buildActivityCounterpartyEmailMap($rows);
            $items = [];
            foreach ($rows as $row) {
                $action = trim((string) ($row->action ?? ''));
                $details = (string) ($row->details ?? '');
                $decoded = json_decode($details, true);
                $decodedDetails = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                $actionLabel = self::resolveClientLogActionLabel($action, $language);
                $detailsText = self::resolveClientLogDetailText($action, $decodedDetails, $details, $language, $counterpartyMap);

                $items[] = [
                    'created_at' => (string) ($row->created_at ?? ''),
                    'action' => $action,
                    'action_label' => $actionLabel,
                    'details' => $detailsText,
                    'ip' => trim((string) ($row->ip ?? '')) !== '' ? (string) $row->ip : '-',
                ];
            }

            return [
                'windowDays' => $windowDays,
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
                'windowDays' => $windowDays,
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

    private static function resolveClientLogActionLabel(string $action, string $language = 'chinese'): string
    {
        $isEn = strtolower($language) === 'english';
        $map = [
            'client_register_subdomain' => $isEn ? 'Register Domain' : '注册域名',
            'client_renew_subdomain' => $isEn ? 'Renew Domain' : '续期域名',
            'client_create_dns' => $isEn ? 'Create DNS Record' : '新增解析',
            'client_update_dns' => $isEn ? 'Update DNS Record' : '修改解析',
            'client_delete_dns_record' => $isEn ? 'Delete DNS Record' : '删除解析',
            'admin_delete_dns_record' => $isEn ? 'System Delete DNS Record' : '系统删除解析',
            'system_delete_dns_record' => $isEn ? 'System Delete DNS Record' : '系统删除解析',
            'client_replace_ns_group' => $isEn ? 'Update DNS Servers' : '修改 DNS 服务器',
            'client_request_delete' => $isEn ? 'Request Domain Deletion' : '申请删除域名',
            'client_invite_quota_reward_inviter' => $isEn ? 'Invite Quota Bonus (Inviter)' : '邀请奖励额度（邀请人）',
            'client_invite_quota_reward_invitee' => $isEn ? 'Invite Quota Bonus (Invitee)' : '邀请奖励额度（被邀请人）',
            'client_toggle_cdn' => $isEn ? 'Toggle CDN Proxy' : '切换 CDN 代理',
            'client_toggle_record_cdn' => $isEn ? 'Toggle Record Proxy' : '切换记录代理',
        ];
        return $map[$action] ?? ($action !== '' ? $action : 'log');
    }

    private static function resolveClientLogDetailText(string $action, array $details, string $raw, string $language = 'chinese', array $counterpartyMap = []): string
    {
        $isEn = strtolower($language) === 'english';
        $text = '';
        $subdomain = trim((string) ($details['subdomain'] ?? ''));
        switch ($action) {
            case 'client_register_subdomain':
            case 'client_renew_subdomain':
            case 'client_request_delete':
                $text = $subdomain !== '' ? $subdomain : (trim((string) ($details['root'] ?? '')) ?: $raw);
                break;
            case 'client_create_dns':
            case 'client_update_dns':
            case 'client_delete_dns_record':
            case 'admin_delete_dns_record':
            case 'system_delete_dns_record':
                $type = strtoupper(trim((string) ($details['type'] ?? '')));
                $name = trim((string) ($details['name'] ?? '@'));
                $content = trim((string) ($details['content'] ?? ''));
                $text = trim(($subdomain !== '' ? $subdomain . ' ' : '') . $name . ' ' . $type . ' -> ' . $content);
                break;
            case 'client_invite_quota_reward_inviter':
            case 'client_invite_quota_reward_invitee':
                $delta = (int) ($details['delta'] ?? 1);
                $peer = (int) ($details['counterparty_userid'] ?? 0);
                $peerEmailMasked = $peer > 0 ? (string) ($counterpartyMap[$peer] ?? ('#' . $peer)) : '';
                if ($isEn) {
                    $text = 'Quota +' . max(0, $delta);
                    if ($peerEmailMasked !== '') {
                        $text .= ', related user ' . $peerEmailMasked;
                    }
                } else {
                    $text = '注册额度 +' . max(0, $delta);
                    if ($peerEmailMasked !== '') {
                        $text .= '，关联用户' . $peerEmailMasked;
                    }
                }
                break;
            case 'client_replace_ns_group':
                $newNs = $details['new_ns'] ?? $details['ns'] ?? [];
                $domainLabel = $subdomain !== '' ? $subdomain : trim((string) ($details['domain'] ?? ''));
                $nsText = '';
                if (is_array($newNs) && !empty($newNs)) {
                    $nsText = implode(', ', array_values(array_filter(array_map('strval', $newNs), static function ($v) {
                        return trim($v) !== '';
                    })));
                } elseif (isset($details['default_ns']) && (int) $details['default_ns'] === 1) {
                    $nsText = $isEn ? 'Switched to system default DNS servers' : '切换为系统默认 DNS 服务器';
                }
                if ($domainLabel === '') {
                    $domainLabel = $isEn ? 'Unknown domain' : '未知域名';
                }
                if ($nsText !== '') {
                    $text = $isEn
                        ? ('Domain: ' . $domainLabel . ' DNS server addresses updated (' . $nsText . ')')
                        : ('域名:' . $domainLabel . ' DNS 服务器地址已更新（' . $nsText . '）');
                } else {
                    $text = $isEn
                        ? ('Domain: ' . $domainLabel . ' DNS server addresses updated')
                        : ('域名:' . $domainLabel . ' DNS 服务器地址已更新');
                }
                break;
            default:
                $text = $raw;
        }
        $text = trim($text);
        if ($text === '') {
            $text = $raw;
        }
        if ($text === '') {
            $text = '-';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > 200) {
                $text = mb_substr($text, 0, 200, 'UTF-8') . '...';
            }
        } elseif (strlen($text) > 200) {
            $text = substr($text, 0, 200) . '...';
        }
        return $text;
    }



    private static function buildActivityCounterpartyEmailMap($rows): array
    {
        $userIds = [];
        foreach ($rows as $row) {
            $action = trim((string) ($row->action ?? ''));
            if (!in_array($action, ['client_invite_quota_reward_inviter', 'client_invite_quota_reward_invitee'], true)) {
                continue;
            }
            $details = (string) ($row->details ?? '');
            $decoded = json_decode($details, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }
            $uid = (int) ($decoded['counterparty_userid'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
        }
        if (empty($userIds)) {
            return [];
        }

        try {
            $rows = Capsule::table('tblclients')
                ->whereIn('id', array_values($userIds))
                ->select('id', 'email')
                ->get();
            $map = [];
            foreach ($rows as $row) {
                $uid = (int) ($row->id ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $map[$uid] = self::maskActivityEmail((string) ($row->email ?? ''));
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function maskActivityEmail(string $email): string
    {
        $email = trim(strtolower($email));
        if ($email === '' || strpos($email, '@') === false) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return '***';
        }
        $prefix = substr($local, 0, 2);
        if ($prefix === '') {
            $prefix = '*';
        }
        return $prefix . '***@' . $domain;
    }

    private static function buildClientLanguageOptions(string $currentLanguage): array
    {
        $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
        $supported = self::resolveSupportedLanguages();
        $options = [];

        foreach ($supported as $code) {
            $options[] = [
                'code' => $code,
                'label' => self::resolveLanguageLabel($code),
            ];
        }

        foreach ($options as &$option) {
            $option['active'] = ($option['code'] === $currentLanguage);
            $option['url'] = self::buildLanguageSwitchUrl($moduleSlug, $option['code']);
        }
        unset($option);

        return $options;
    }

    private static function resolveClientCurrencyDecorators(int $userId): array
    {
        try {
            $currencyId = 0;
            if ($userId > 0) {
                $currencyId = (int) Capsule::table('tblclients')->where('id', $userId)->value('currency');
            }
            $currencyQuery = Capsule::table('tblcurrencies');
            if ($currencyId > 0) {
                $currencyQuery->where('id', $currencyId);
            } else {
                $currencyQuery->where('default', 1);
            }
            $currencyRow = $currencyQuery->first();
            if (!$currencyRow && $currencyId > 0) {
                $currencyRow = Capsule::table('tblcurrencies')->where('default', 1)->first();
            }
            $prefix = trim((string) ($currencyRow->prefix ?? ''));
            $suffix = trim((string) ($currencyRow->suffix ?? ''));
            return [$prefix, $suffix];
        } catch (\Throwable $e) {
            return ['', ''];
        }
    }

    private static function buildLanguageSwitchUrl(string $moduleSlug, string $code): string
    {
        $currentParams = $_GET ?? [];
        if (($currentParams['action'] ?? '') === 'change_language') {
            unset($currentParams['action']);
        }
        unset($currentParams['cf_lang'], $currentParams['lang'], $currentParams['return']);
        $currentParams = self::ensureLanguageBaseParams($currentParams, $moduleSlug);
        $returnToken = self::encodeLanguageRedirectParams($currentParams);

        $params = self::entryBaseQuery($moduleSlug);
        $params['cf_lang'] = $code;
        if ($returnToken !== '') {
            $params['return'] = $returnToken;
        }

        $script = 'index.php';
        if (class_exists('CfClientController') && method_exists('CfClientController', 'preferredClientEntryScript')) {
            $script = CfClientController::preferredClientEntryScript();
        }

        return $script . '?' . http_build_query($params);
    }

    private static function encodeLanguageRedirectParams(array $params): string
    {
        if (class_exists('CfClientController') && method_exists('CfClientController', 'encodeLanguageRedirectPayload')) {
            return CfClientController::encodeLanguageRedirectPayload($params);
        }
        return self::fallbackEncodeLanguageParams($params);
    }

    private static function fallbackEncodeLanguageParams(array $params): string
    {
        $query = http_build_query($params);
        if ($query === '') {
            return '';
        }
        return base64_encode($query);
    }

    private static function resolveSupportedLanguages(): array
    {
        if (class_exists('CfClientController') && method_exists('CfClientController', 'getSupportedLanguages')) {
            return CfClientController::getSupportedLanguages();
        }
        return ['english', 'chinese'];
    }

    private static function resolveLanguageLabel(string $code): string
    {
        $map = [
            'english' => ['key' => 'cfclient.language.english', 'default' => 'English'],
            'chinese' => ['key' => 'cfclient.language.chinese', 'default' => '简体中文'],
        ];

        if (isset($map[$code])) {
            return cfmod_trans($map[$code]['key'], $map[$code]['default']);
        }

        return ucfirst($code);
    }

    private static function entryBaseQuery(string $moduleSlug): array
    {
        if (class_exists('CfClientController') && method_exists('CfClientController', 'preferredClientBaseQuery')) {
            return CfClientController::preferredClientBaseQuery($moduleSlug);
        }
        return ['m' => $moduleSlug];
    }

    private static function ensureLanguageBaseParams(array $params, string $moduleSlug): array
    {
        $baseParams = self::entryBaseQuery($moduleSlug);
        foreach ($baseParams as $key => $value) {
            if (!isset($params[$key]) || $params[$key] === '') {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    private static function detectClientAreaRequest(): bool
    {
        $script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === 'clientarea.php') {
            return true;
        }
        $action = strtolower($_REQUEST['action'] ?? '');
        if ($action === 'addon' && isset($_REQUEST['module'])) {
            return true;
        }
        $rp = $_REQUEST['rp'] ?? '';
        if (is_string($rp) && stripos($rp, 'clientarea.php') !== false) {
            return true;
        }
        return false;
    }
}
