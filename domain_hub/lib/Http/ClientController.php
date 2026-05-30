<?php
// phpcs:ignoreFile

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfClientController
{
    private const SUPPORTED_LANGUAGES = ['english', 'chinese'];

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    public static function encodeLanguageRedirectPayload(array $params): string
    {
        if (($params['action'] ?? '') === 'change_language') {
            unset($params['action']);
        }
        unset($params['lang'], $params['cf_lang'], $params['return']);
        if (empty($params)) {
            return '';
        }
        $query = http_build_query($params);
        if ($query === '') {
            return '';
        }
        $encoded = base64_encode($query);
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    private static function decodeLanguageRedirectPayload(?string $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [];
        }
        $normalized = strtr($payload, '-_', '+/');
        $pad = strlen($normalized) % 4;
        if ($pad > 0) {
            $normalized .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                return [];
            }
        }
        parse_str($decoded, $params);
        return is_array($params) ? $params : [];
    }

    public static function resolveClientEntryScript(): string
    {
        return self::isClientAreaRequestContext() ? 'clientarea.php' : 'index.php';
    }

    public static function buildClientBaseQuery(string $moduleSlug): array
    {
        if (self::isClientAreaRequestContext()) {
            return ['action' => 'addon', 'module' => $moduleSlug];
        }
        return ['m' => $moduleSlug];
    }

    public static function preferredClientEntryScript(): string
    {
        return 'index.php';
    }

    public static function preferredClientBaseQuery(string $moduleSlug): array
    {
        return ['m' => $moduleSlug];
    }

    public static function buildPreferredClientUrl(string $moduleSlug, array $params = []): string
    {
        $query = array_merge(self::preferredClientBaseQuery($moduleSlug), $params);
        return self::preferredClientEntryScript() . '?' . http_build_query($query);
    }

    private static function isClientAreaRequestContext(): bool
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

    private static function paramsIndicateClientArea(array $params): bool
    {
        $action = strtolower($params['action'] ?? '');
        if ($action === 'addon' && isset($params['module'])) {
            return true;
        }
        if (isset($params['module']) && !isset($params['m'])) {
            return true;
        }
        return false;
    }

    private static function ensureLanguageRedirectBaseParams(array $params, string $moduleSlug): array
    {
        if (self::paramsIndicateClientArea($params)) {
            if (($params['action'] ?? '') !== 'addon') {
                $params['action'] = 'addon';
            }
            if (!isset($params['module']) || $params['module'] === '') {
                $params['module'] = $moduleSlug;
            }
            return $params;
        }

        if (!isset($params['m']) || $params['m'] === '') {
            $params['m'] = $moduleSlug;
        }
        return $params;
    }

    private static function resolveRedirectScript(array $params): string
    {
        return self::paramsIndicateClientArea($params) ? 'clientarea.php' : 'index.php';
    }

    public function handle(array $vars = [], bool $isLegacyEntry = false, bool $forceRender = false): void
    {
            if (!$forceRender && !cf_is_module_request()) {
                return;
            }
        
            if (defined('CFMOD_CLIENTAREA_PAGE_RENDERED')) {
                return;
            }
            define('CFMOD_CLIENTAREA_PAGE_RENDERED', true);
        
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $actionParam = (string) ($_GET['action'] ?? '');
            $moduleActionParam = (string) ($_GET['module_action'] ?? ($_GET['cf_action'] ?? ''));
            if (($actionParam === '' || strtolower($actionParam) === 'addon') && $moduleActionParam !== '') {
                $actionParam = $moduleActionParam;
            }
            if ($actionParam === 'change_language') {
                $requestedLang = (string)($_GET['lang'] ?? '');
                $returnPayload = is_string($_GET['return'] ?? null) ? (string)$_GET['return'] : null;
                $returnParams = self::decodeLanguageRedirectPayload($returnPayload);
                if (empty($returnParams)) {
                    $returnParams = $_GET ?? [];
                }
                $this->handleLanguageSwitchRequest($requestedLang, $moduleSlug, $returnParams);
            }
            $requestedLanguage = $_GET['cf_lang'] ?? null;
            if ($requestedLanguage !== null) {
                $returnPayload = is_string($_GET['return'] ?? null) ? (string) $_GET['return'] : null;
                $returnParams = self::decodeLanguageRedirectPayload($returnPayload);
                if (empty($returnParams)) {
                    $returnParams = $_GET ?? [];
                }
                $this->handleLanguageSwitchRequest((string) $requestedLanguage, $moduleSlug, $returnParams);
            }

            $this->handleTelegramBotBindWebhook();
        
            if (cf_is_api_request()) {
                cf_dispatch_api_request();
            }
        
            if (!isset($_SESSION['uid'])) {
                header('Location: login.php');
                exit;
            }
        
            cfmod_ensure_client_csrf_seed();
            $clientCsrfValid = cfmod_validate_client_area_csrf();
            $GLOBALS['cfmod_client_csrf_valid'] = $clientCsrfValid;
            if (!$clientCsrfValid && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $_POST = [];
                $GLOBALS['cfmod_client_csrf_error'] = true;
            }
        
            // 如果执行到这里，说明其他钩子都已经检查过了，没有重定向
            // 现在执行插件自己的验证
        
            $userId = intval($_SESSION['uid']);
        
            // 执行WHMCS标准客户区访问验证
            // 检查用户账户状态
            $client = Capsule::table('tblclients')->where('id', $userId)->first();
            if (!$client) {
                header('Location: login.php');
                exit;
            }
        
            // 检查账户状态 - 若非 Active，允许插件封禁用户进入页面但禁止操作
            if (isset($client->status) && strtolower($client->status) !== 'active') {
                $pluginBanned = Capsule::table('mod_cloudflare_user_bans')
                    ->where('userid', $userId)
                    ->where('status', 'banned')
                    ->exists();
                if (!$pluginBanned) {
                    header('Location: clientarea.php?action=details');
                    exit;
                }
            }
        
            // 检查是否需要邮箱验证
            if (isset($client->email_verified) && $client->email_verified == 0) {
                // 检查系统是否启用了邮箱验证要求
                $emailVerificationEnabled = Capsule::table('tblconfiguration')
                    ->where('setting', 'RequireClientEmailVerification')
                    ->value('value');
                if ($emailVerificationEnabled == 'on') {
                    header('Location: clientarea.php');
                    exit;
                }
            }
        
            // 使用WHMCS的钩子系统检查额外限制
            // 优先执行外部 ClientAreaPage 钩子，兼容返回重定向指令的认证/验证插件
            $pageTitleText = cfmod_trans('cfclient.breadcrumb.client_page', '我的二级域名管理');
            $this->enforceExternalClientAreaHooks($vars, $userId, $pageTitleText);

            // 触发一个自定义钩子点，允许其他插件/钩子阻止访问
            $hookResults = run_hook('ClientAreaPageBeforeAccess', [
                'userid' => $userId,
                'module' => CF_MODULE_NAME,
                'legacy_module' => CF_MODULE_NAME_LEGACY,
                'pagetitle' => $pageTitleText
            ]);
            $this->handleHookRedirectResults($hookResults);
        
            // 检查安全问题（如果系统要求）
            $securityQuestionsEnabled = Capsule::table('tblconfiguration')
                ->where('setting', 'SecurityQuestionsEnabled')
                ->value('value');
            if ($securityQuestionsEnabled == 'on') {
                $hasAnsweredQuestions = Capsule::table('tblclientsecurityquestions')
                    ->where('userid', $userId)
                    ->count();
                $requiredQuestions = Capsule::table('tblconfiguration')
                    ->where('setting', 'SecurityQuestionsRequired')
                    ->value('value');
                if ($requiredQuestions && $hasAnsweredQuestions < intval($requiredQuestions)) {
                    header('Location: clientarea.php?action=security');
                    exit;
                }
            }
        
            // 检查两步验证要求（如果系统强制启用）
            $twoFactorAuthRequired = Capsule::table('tblconfiguration')
                ->where('setting', 'TwoFactorAuthenticationRequired')
                ->value('value');
            if ($twoFactorAuthRequired == 'on') {
                $hasTwoFactor = Capsule::table('tblusers_twofactor')
                    ->where('user_id', $userId)
                    ->where('user_type', 'Client')
                    ->count();
                if (!$hasTwoFactor) {
                    header('Location: clientarea.php?action=security');
                    exit;
                }
            }

            $this->handleInviteRegistrationOauth($userId);
            $this->handleGithubStarRewardOauth($userId);
        
            $actionOverrides = [];
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $preViewModel = $this->buildViewModel($userId, $client, $vars);
                $preGlobals = $preViewModel['globals'] ?? [];
                $actionOverrides = $this->handleAction($userId, $client, $preGlobals);
                $actionRedirect = trim((string) ($actionOverrides['redirect'] ?? $actionOverrides['redirectTo'] ?? $actionOverrides['forceRedirect'] ?? $actionOverrides['location'] ?? ''));
                if ($actionRedirect !== '') {
                    header('Location: ' . $actionRedirect);
                    exit;
                }
                $cfViewModel = $this->buildViewModel($userId, $client, $vars);
                $GLOBALS['cfClientViewModel'] = $this->applyActionOverrides($cfViewModel, $actionOverrides);
            } else {
                $GLOBALS['cfClientViewModel'] = $this->buildViewModel($userId, $client, $vars);
            }

// 设置页面标题和面包屑
            $homeLabel = cfmod_trans('cfclient.breadcrumb.home', '首页');
            $vars['pagetitle'] = $pageTitleText;
            $vars['breadcrumb'] = [
                ['url' => 'index.php', 'label' => $homeLabel],
                ['url' => '#', 'label' => $pageTitleText]
            ];
        
            // 包含客户端模板
        include __DIR__ . '/../../templates/client.tpl';
            exit;
    }

    private function enforceExternalClientAreaHooks(array $vars, int $userId, string $pageTitleText): void
    {
            if (!function_exists('run_hook')) {
                return;
            }

            $payload = is_array($vars) ? $vars : [];
            $payload['userid'] = $userId;
            $payload['module'] = CF_MODULE_NAME;
            $payload['legacy_module'] = CF_MODULE_NAME_LEGACY;
            $payload['pagetitle'] = $pageTitleText;

            $GLOBALS['cfmod_suppress_clientarea_hooks'] = true;
            try {
                $hookResults = run_hook('ClientAreaPage', $payload);
            } catch (\Throwable $e) {
                $hookResults = [];
            }
            unset($GLOBALS['cfmod_suppress_clientarea_hooks']);

            $this->handleHookRedirectResults($hookResults);
    }

    private function handleHookRedirectResults($hookResults): void
    {
            foreach ((array) $hookResults as $result) {
                if (is_array($result)) {
                    $redirect = '';
                    foreach (['redirect', 'redirectTo', 'forceRedirect', 'location'] as $key) {
                        $value = trim((string) ($result[$key] ?? ''));
                        if ($value !== '') {
                            $redirect = $value;
                            break;
                        }
                    }
                    if ($redirect !== '') {
                        header('Location: ' . $redirect);
                        exit;
                    }
                }
                if (is_string($result) && strpos($result, 'Location:') === 0) {
                    header($result);
                    exit;
                }
            }
    }

    private function handleLanguageSwitchRequest(string $languageCode, string $moduleSlug, ?array $returnParams = null): void
    {
            try {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
            } catch (\Throwable $e) {}

            $normalized = function_exists('cfmod_normalize_language_code') ? cfmod_normalize_language_code($languageCode) : strtolower(trim($languageCode));
            if ($normalized === '' || !in_array($normalized, self::SUPPORTED_LANGUAGES, true)) {
                $normalized = 'english';
            }
            $_SESSION['Language'] = $normalized;
            $_SESSION['language'] = $normalized;
            setcookie('WHMCSLanguage', $normalized, time() + 86400 * 365, '/', '', false, true);

            $params = $returnParams ?? ($_GET ?? []);
            if (($params['action'] ?? '') === 'change_language') {
                unset($params['action']);
            }
            unset($params['cf_lang'], $params['lang'], $params['return']);
            $params = self::ensureLanguageRedirectBaseParams($params, $moduleSlug);
            $query = http_build_query($params);
            $redirect = self::resolveRedirectScript($params) . ($query ? ('?' . $query) : '');
            header('Location: ' . $redirect);
            exit;
    }

    

    

    private function handleInviteRegistrationOauth(int $userId): void
    {
            $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if ($requestMethod !== 'GET') {
                return;
            }

            $oauthAction = trim((string) ($_GET['invite_registration_oauth'] ?? ''));
            if (!in_array($oauthAction, ['github_start', 'github_callback'], true)) {
                return;
            }

            $banState = function_exists('cfmod_resolve_user_ban_state')
                ? cfmod_resolve_user_ban_state($userId)
                : ['is_banned' => false, 'reason' => ''];
            if (!empty($banState['is_banned'])) {
                $message = self::actionText('cfclient.banned.operation_blocked', '您的账号已被封禁或停用，当前操作已禁用。');
                $banReason = trim((string) ($banState['reason'] ?? ''));
                if ($banReason !== '') {
                    $message .= ' ' . $banReason;
                }
                $this->flashClientMessage('warning', $message, 'invite_gate');
                $this->redirectToInviteRegistrationBase();
            }

            $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
            if (!is_array($settings)) {
                $settings = [];
            }

            if (!class_exists('CfInviteRegistrationService') || !CfInviteRegistrationService::isGateEnabled($settings) || !CfInviteRegistrationService::isGithubOptionEnabled($settings)) {
                $this->flashClientMessage(
                    'warning',
                    self::actionText('cfclient.invite_registration.github.mode_disabled', '当前准入模式未启用 GitHub 认证。'),
                    'invite_gate'
                );
                $this->redirectToInviteRegistrationBase();
            }

            if (!class_exists('CfInviteRegistrationGithubService')) {
                $this->flashClientMessage(
                    'danger',
                    self::actionText('cfclient.invite_registration.github.service_unavailable', 'GitHub 认证服务不可用，请稍后重试。'),
                    'invite_gate'
                );
                $this->redirectToInviteRegistrationBase();
            }

            $callbackUrl = $this->buildInviteRegistrationOauthUrl('github_callback', true);

            if ($oauthAction === 'github_start') {
                try {
                    $authorizeUrl = CfInviteRegistrationGithubService::createAuthorizationUrl($userId, $settings, $callbackUrl);
                    header('Location: ' . $authorizeUrl);
                    exit;
                } catch (CfInviteRegistrationGithubException $e) {
                    $message = $this->inviteRegistrationGithubErrorMessage($e->getReason(), $e->getContext());
                    $this->flashClientMessage('danger', $message, 'invite_gate');
                    $this->redirectToInviteRegistrationBase();
                } catch (\Throwable $e) {
                    $this->flashClientMessage(
                        'danger',
                        self::actionText('cfclient.invite_registration.github.error.generic', 'GitHub 认证失败，请稍后重试。'),
                        'invite_gate'
                    );
                    $this->redirectToInviteRegistrationBase();
                }
            }

            try {
                CfInviteRegistrationGithubService::handleCallback($userId, $settings, $_GET, $callbackUrl);
                $this->flashClientMessage(
                    'success',
                    self::actionText('cfclient.invite_registration.github.success', 'GitHub 认证成功，准入验证已完成。'),
                    'invite_gate'
                );
            } catch (CfInviteRegistrationGithubException $e) {
                $message = $this->inviteRegistrationGithubErrorMessage($e->getReason(), $e->getContext());
                $this->flashClientMessage('danger', $message, 'invite_gate');
            } catch (\Throwable $e) {
                $this->flashClientMessage(
                    'danger',
                    self::actionText('cfclient.invite_registration.github.error.generic', 'GitHub 认证失败，请稍后重试。'),
                    'invite_gate'
                );
            }

            $this->redirectToInviteRegistrationBase();
    }

    private function handleTelegramBotBindWebhook(): void
    {
            $flag = trim((string) ($_GET['telegram_bind_webhook'] ?? ''));
            if ($flag !== '1') {
                return;
            }

            $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
            if (!is_array($settings)) {
                $settings = [];
            }
            if (!class_exists('CfTelegramGroupRewardService')) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
                exit;
            }

            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && trim((string) ($_GET['debug'] ?? '')) === '1') {
                $token = CfTelegramGroupRewardService::resolveBotToken($settings);
                $expectedWebhookUrl = CfTelegramGroupRewardService::getExpectedWebhookUrl();
                $webhookInfo = CfTelegramGroupRewardService::getWebhookDebugInfo($settings);
                $setWebhookResult = null;
                if (trim((string) ($_GET['set_webhook'] ?? '')) === '1') {
                    $setWebhookResult = CfTelegramGroupRewardService::setWebhookToExpected($settings);
                    $webhookInfo = CfTelegramGroupRewardService::getWebhookDebugInfo($settings);
                }
                $currentWebhookUrl = trim((string) ($webhookInfo['url'] ?? ''));
                $debugPayload = [
                    'ok' => true,
                    'debug' => true,
                    'feature_enabled' => CfTelegramGroupRewardService::isEnabled($settings),
                    'bot_username' => CfTelegramGroupRewardService::resolveBotUsername($settings),
                    'bot_token_valid' => preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,120}$/', $token) === 1,
                    'webhook_expected' => $expectedWebhookUrl !== '' ? $expectedWebhookUrl : 'https://你的WHMCS域名/index.php?m=domain_hub&telegram_bind_webhook=1',
                    'webhook_url_empty' => $currentWebhookUrl === '',
                    'webhook_url_matches_expected' => $expectedWebhookUrl !== '' && $currentWebhookUrl === $expectedWebhookUrl,
                    'webhook_info' => $webhookInfo,
                    'set_webhook_result' => $setWebhookResult,
                ];
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($debugPayload);
                exit;
            }

            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
                exit;
            }

            $raw = file_get_contents('php://input');
            $update = json_decode((string) $raw, true);
            if (!is_array($update)) {
                $update = [];
            }

            try {
                $result = CfTelegramGroupRewardService::handleBotBindWebhook($settings, $update);
                $messageText = trim((string) (($update['message']['text'] ?? '')));
                $isBindTokenStart = (bool) preg_match('/^\/start(?:@\w+)?\s+cfbind_[A-Za-z0-9_-]+$/', $messageText);
                $handled = !empty($result['handled']);
                if (function_exists('cloudflare_subdomain_log') && $handled && $isBindTokenStart) {
                    cloudflare_subdomain_log('telegram_bind_webhook', [
                        'update_id' => intval($update['update_id'] ?? 0),
                        'message_text' => $messageText,
                        'chat_id' => (string) ($update['message']['chat']['id'] ?? ''),
                        'from_id' => intval($update['message']['from']['id'] ?? 0),
                        'result' => $result,
                    ], 0, null);
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($result);
            } catch (CfTelegramGroupRewardException $e) {
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('telegram_bind_webhook_error', [
                        'reason' => $e->getReason(),
                        'message' => $e->getMessage(),
                        'update_id' => intval($update['update_id'] ?? 0),
                        'message_text' => trim((string) (($update['message']['text'] ?? ''))),
                    ], 0, null);
                }
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $e->getReason()]);
            } catch (\Throwable $e) {
                if (function_exists('cloudflare_subdomain_log')) {
                    cloudflare_subdomain_log('telegram_bind_webhook_error', [
                        'reason' => 'internal_error',
                        'message' => $e->getMessage(),
                        'update_id' => intval($update['update_id'] ?? 0),
                    ], 0, null);
                }
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'internal_error']);
            }
            exit;
    }

    private function handleGithubStarRewardOauth(int $userId): void
    {
            $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if ($requestMethod !== 'GET') {
                return;
            }

            $oauthAction = trim((string) ($_GET['github_star_reward_oauth'] ?? ''));
            if (!in_array($oauthAction, ['start', 'callback'], true)) {
                return;
            }

            $settings = function_exists('cf_get_module_settings_cached') ? cf_get_module_settings_cached() : [];
            if (!is_array($settings)) {
                $settings = [];
            }
            if (!class_exists('CfGithubStarRewardService') || !CfGithubStarRewardService::isEnabled($settings)) {
                $this->flashClientMessage('warning', self::actionText('cfclient.feature.github_star.oauth.disabled', '当前未启用 GitHub 点赞奖励功能。'), 'tools');
                $this->redirectToToolsBase();
            }
            if (!CfGithubStarRewardService::isOauthConfigured($settings)) {
                $this->flashClientMessage('warning', self::actionText('cfclient.feature.github_star.oauth.not_configured', '管理员尚未配置 GitHub OAuth，请稍后再试。'), 'tools');
                $this->redirectToToolsBase();
            }

            $callbackUrl = $this->buildGithubStarRewardOauthUrl('callback', true);
            if ($oauthAction === 'start') {
                try {
                    $authorizeUrl = CfGithubStarRewardService::createAuthorizationUrl($userId, $settings, $callbackUrl);
                    header('Location: ' . $authorizeUrl);
                    exit;
                } catch (CfGithubStarRewardException $e) {
                    $this->flashClientMessage('danger', $this->githubStarRewardOauthErrorMessage($e->getReason()), 'tools');
                } catch (\Throwable $e) {
                    $this->flashClientMessage('danger', self::actionText('cfclient.feature.github_star.oauth.error.generic', 'GitHub 授权失败，请稍后再试。'), 'tools');
                }
                $this->redirectToToolsBase();
            }

            try {
                $result = CfGithubStarRewardService::handleCallback($userId, $settings, $_GET, $callbackUrl);
                $login = trim((string) ($result['github_login'] ?? ''));
                $message = $login !== ''
                    ? self::actionText('cfclient.feature.github_star.oauth.success_with_user', 'GitHub 授权成功，已绑定 @%s。', [$login])
                    : self::actionText('cfclient.feature.github_star.oauth.success', 'GitHub 授权成功。');
                $this->flashClientMessage('success', $message, 'tools');
            } catch (CfGithubStarRewardException $e) {
                $this->flashClientMessage('danger', $this->githubStarRewardOauthErrorMessage($e->getReason()), 'tools');
            } catch (\Throwable $e) {
                $this->flashClientMessage('danger', self::actionText('cfclient.feature.github_star.oauth.error.generic', 'GitHub 授权失败，请稍后再试。'), 'tools');
            }

            $this->redirectToToolsBase();
    }

    private function buildGithubStarRewardOauthUrl(string $oauthAction, bool $absolute = false): string
    {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            if ($oauthAction === 'callback') {
                $script = 'index.php';
                $params = [
                    'm' => $moduleSlug,
                    'view' => 'tools',
                    'github_star_reward_oauth' => 'callback',
                ];
            } else {
                $script = self::resolveClientEntryScript();
                $params = self::buildClientBaseQuery($moduleSlug);
                $params['view'] = 'tools';
                $params['github_star_reward_oauth'] = 'start';
            }

            $url = $script . '?' . http_build_query($params);
            if (!$absolute) {
                return $url;
            }
            return $this->toAbsoluteClientUrl($url);
    }

    private function buildInviteRegistrationOauthUrl(string $oauthAction, bool $absolute = false): string
    {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';

            if ($oauthAction === 'github_callback') {
                $script = 'index.php';
                $params = [
                    'm' => $moduleSlug,
                    'invite_registration_oauth' => $oauthAction,
                ];
            } else {
                $script = self::resolveClientEntryScript();
                $params = self::buildClientBaseQuery($moduleSlug);
                $params['invite_registration_oauth'] = $oauthAction;
            }

            $url = $script . '?' . http_build_query($params);
            if (!$absolute) {
                return $url;
            }

            return $this->toAbsoluteClientUrl($url);
    }

    private function toAbsoluteClientUrl(string $url): string
    {
            if ($url === '' || preg_match('~^https?://~i', $url)) {
                return $url;
            }

            $baseUrl = $this->resolveWhmcsSystemUrl();
            if ($baseUrl === '') {
                return $url;
            }

            return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function resolveWhmcsSystemUrl(): string
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

            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($host === '') {
                return '';
            }

            $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
            $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            $scheme = ($https === 'on' || $https === '1' || $forwardedProto === 'https') ? 'https' : 'http';

            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
            $basePath = trim(str_replace('\\', '/', dirname($scriptName)));
            if ($basePath === '.' || $basePath === '/') {
                $basePath = '';
            }

            return $scheme . '://' . $host . $basePath;
    }

    private function redirectToInviteRegistrationBase(): void
    {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $script = self::resolveClientEntryScript();
            $params = self::buildClientBaseQuery($moduleSlug);
            header('Location: ' . $script . '?' . http_build_query($params));
            exit;
    }

    private function redirectToToolsBase(): void
    {
            $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
            $script = self::resolveClientEntryScript();
            $params = self::buildClientBaseQuery($moduleSlug);
            $params['view'] = 'tools';
            header('Location: ' . $script . '?' . http_build_query($params));
            exit;
    }

    private function flashClientMessage(string $type, string $message, string $context = ''): void
    {
            if ($message === '') {
                return;
            }

            if (!isset($_SESSION) || !is_array($_SESSION)) {
                return;
            }

            $_SESSION['cfmod_client_flash'] = [
                'type' => $type,
                'message' => $message,
                'context' => $context,
            ];
    }

    private function inviteRegistrationGithubErrorMessage(string $reason, array $context = []): string
    {
            switch ($reason) {
                case 'oauth_not_configured':
                    return self::actionText('cfclient.invite_registration.github.error.not_configured', '管理员尚未完成 GitHub OAuth 配置，请稍后再试。');
                case 'user_cancelled':
                    return self::actionText('cfclient.invite_registration.github.error.cancelled', '您已取消 GitHub 授权，请重试或选择邀请码验证。');
                case 'invalid_state':
                case 'state_expired':
                    return self::actionText('cfclient.invite_registration.github.error.state_invalid', '安全校验失败，请重新发起 GitHub 认证。');
                case 'missing_code':
                    return self::actionText('cfclient.invite_registration.github.error.missing_code', '未获取到 GitHub 授权码，请重新尝试。');
                case 'account_age_insufficient':
                    $required = max(0, (int) ($context['required_months'] ?? 0));
                    $actual = max(0, (int) ($context['actual_months'] ?? 0));
                    return self::actionText('cfclient.invite_registration.github.error.account_age_insufficient', '账号年限不足：当前仅 %1$s 个月，需至少 %2$s 个月。', [$actual, $required]);
                case 'repo_count_insufficient':
                    $requiredRepos = max(0, (int) ($context['required_repos'] ?? 0));
                    $actualRepos = max(0, (int) ($context['actual_repos'] ?? 0));
                    return self::actionText('cfclient.invite_registration.github.error.repo_count_insufficient', '公开仓库数量不足：当前仅 %1$s 个，需至少 %2$s 个。', [$actualRepos, $requiredRepos]);
                case 'github_already_bound':
                    return self::actionText('cfclient.invite_registration.github.error.already_bound', '该 GitHub 账号已绑定其他平台账户，无法重复激活。');
                case 'oauth_exchange_failed':
                case 'oauth_user_fetch_failed':
                case 'http_request_failed':
                case 'http_unavailable':
                    return self::actionText('cfclient.invite_registration.github.error.oauth_failed', 'GitHub API 调用失败，请稍后再试。');
                case 'invalid_github_id':
                case 'missing_account_created_at':
                case 'invalid_account_created_at':
                    return self::actionText('cfclient.invite_registration.github.error.profile_invalid', '无法读取 GitHub 账户信息，请确认授权权限后重试。');
                case 'invalid_user':
                    return self::actionText('cfclient.invite_registration.github.error.invalid_user', '登录状态已失效，请刷新页面后重试。');
                default:
                    return self::actionText('cfclient.invite_registration.github.error.generic', 'GitHub 认证失败，请稍后重试。');
            }
    }

    private function githubStarRewardOauthErrorMessage(string $reason): string
    {
            switch ($reason) {
                case 'oauth_not_configured':
                    return self::actionText('cfclient.feature.github_star.oauth.not_configured', '管理员尚未配置 GitHub OAuth，请稍后再试。');
                case 'user_cancelled':
                    return self::actionText('cfclient.feature.github_star.oauth.cancelled', '您已取消 GitHub 授权，请重新尝试。');
                case 'invalid_state':
                    return self::actionText('cfclient.feature.github_star.oauth.state_invalid', '安全校验失败，请重新发起 GitHub 授权。');
                case 'missing_code':
                    return self::actionText('cfclient.feature.github_star.oauth.missing_code', '未获取到 GitHub 授权码，请重试。');
                case 'github_already_bound':
                    return self::actionText('cfclient.feature.github_star.oauth.already_bound', '该 GitHub 账号已被其他用户绑定，请使用自己的账号。');
                case 'oauth_exchange_failed':
                case 'oauth_user_fetch_failed':
                case 'http_request_failed':
                case 'http_unavailable':
                    return self::actionText('cfclient.feature.github_star.oauth.api_failed', 'GitHub API 调用失败，请稍后再试。');
                case 'invalid_user':
                    return self::actionText('cfclient.feature.github_star.oauth.invalid_user', '登录状态已失效，请刷新页面后重试。');
                default:
                    return self::actionText('cfclient.feature.github_star.oauth.error.generic', 'GitHub 授权失败，请稍后再试。');
            }
    }

    private function handleAction(int $userId, $client, array $globals): array
    {
                // AJAX处理：API密钥管理
                            $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
                            $moduleAction = (string) ($_GET['module_action'] ?? $_POST['module_action'] ?? ($_GET['cf_action'] ?? ($_POST['cf_action'] ?? '')));
                            $ajaxAction = (string) ($_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '');
                            if (($action === '' || strtolower($action) === 'addon') && $moduleAction !== '') {
                                $action = $moduleAction;
                            }
                            if (($action === '' || strtolower($action) === 'addon') && strpos($ajaxAction, 'ajax_') === 0) {
                                $action = $ajaxAction;
                            }

                            try {
                                self::enforceAjaxRateLimit($action, $userId);
                            } catch (CfRateLimitExceededException $e) {
                                self::respondAjaxRateLimitError($e);
                            }

                            $banState = (isset($globals['banState']) && is_array($globals['banState']))
                                ? $globals['banState']
                                : (function_exists('cfmod_resolve_user_ban_state') ? cfmod_resolve_user_ban_state($userId) : ['is_banned' => false, 'reason' => '']);
                            $isUserBannedOrInactive = !empty($globals['isUserBannedOrInactive'])
                                || !empty($banState['is_banned'])
                                || (isset($client->status) && strtolower((string) $client->status) !== 'active');
                            if ($isUserBannedOrInactive && self::shouldBlockActionWhenBanned((string) $action)) {
                                $banReasonText = trim((string) ($banState['reason'] ?? ''));
                                $message = self::actionText(
                                    'cfclient.banned.operation_blocked',
                                    '您的账号已被封禁或停用，当前操作已禁用。'
                                );
                                if ($banReasonText !== '') {
                                    $message .= ' ' . $banReasonText;
                                }
                                header('Content-Type: application/json; charset=utf-8');
                                echo json_encode([
                                    'success' => false,
                                    'error' => $message,
                                    'reason' => 'user_banned',
                                ], JSON_UNESCAPED_UNICODE);
                                exit;
                            }


                            if ($action === 'ajax_check_subdomain_availability') {
                                header('Content-Type: application/json; charset=utf-8');
                                try {
                                    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (empty($_SESSION['cfmod_csrf']) || !hash_equals($_SESSION['cfmod_csrf'], (string) $csrf)) {
                                        throw new \RuntimeException(self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'));
                                    }
                                    $settingsForAvailability = cf_get_module_settings_cached();
                                    if (!is_array($settingsForAvailability)) {
                                        $settingsForAvailability = [];
                                    }
                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode((string) $rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = $_POST;
                                    }
                                    $availability = self::buildSubdomainAvailabilityResult($userId, $client, $globals, $settingsForAvailability, $payload);
                                    echo json_encode([
                                        'success' => true,
                                        'data' => $availability,
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                } catch (\Throwable $e) {
                                    $message = trim((string) $e->getMessage());
                                    if ($message === '') {
                                        $message = self::actionText('cfclient.register.availability.error', '检测失败，请稍后重试。');
                                    }
                                    echo json_encode([
                                        'success' => false,
                                        'error' => $message,
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }

                            // VPN检测（AJAX）- 用于前端弹窗时预检
                            if ($action === 'ajax_check_vpn') {
                                header('Content-Type: application/json; charset=utf-8');
                                $settings = cf_get_module_settings_cached();
                                
                                // 检查DNS操作VPN检测是否启用
                                if (!class_exists('CfVpnDetectionService') || !CfVpnDetectionService::isDnsCheckEnabled($settings)) {
                                    echo json_encode(['success' => true, 'blocked' => false, 'reason' => 'disabled']);
                                    exit;
                                }
                                
                                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                                $vpnCheckResult = CfVpnDetectionService::shouldBlockDnsOperation($clientIp, $settings);
                                
                                if (!empty($vpnCheckResult['blocked'])) {
                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log('vpn_detection_ajax_check', [
                                            'ip' => $clientIp,
                                            'reason' => $vpnCheckResult['reason'] ?? 'unknown',
                                            'blocked' => true,
                                        ], $userId, null);
                                    }
                                    echo json_encode([
                                        'success' => true,
                                        'blocked' => true,
                                        'reason' => $vpnCheckResult['reason'] ?? 'vpn_proxy',
                                        'message' => self::actionText('dns.vpn_blocked', '检测到您正在使用VPN或代理，请关闭后再进行DNS操作。')
                                    ]);
                                    exit;
                                }
                                
                                echo json_encode(['success' => true, 'blocked' => false]);
                                exit;
                            }

                            // 创建API密钥
                            if ($action === 'ajax_create_api_key') {
                                try {
                                    // CSRF 校验
                                    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (!empty($_SESSION['cfmod_csrf']) && (!hash_equals($_SESSION['cfmod_csrf'], (string)$hdr))) {
                                        echo json_encode(['success'=>false,'error'=>self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。')]);
                                        exit;
                                    }
                                    $rawInput = file_get_contents('php://input');
                                    $data = json_decode($rawInput, true);

                                    $keyName = trim($data['key_name'] ?? '');
                                    $ipWhitelist = trim($data['ip_whitelist'] ?? '');

                                    if (empty($keyName)) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.name_required', '密钥名称不能为空')]);
                                        exit;
                                    }

                                    // 读取设置
                                    $settings = cf_get_module_settings_cached();

                                    $maxKeys = intval($settings['api_keys_per_user'] ?? 3);
                                    $requireQuota = intval($settings['api_require_quota'] ?? 1);

                                    // 检查数量限制
                                    $currentCount = Capsule::table('mod_cloudflare_api_keys')
                                        ->where('userid', $userId)
                                        ->count();

                                    if ($currentCount >= $maxKeys) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.limit_reached', 'API密钥数量已达上限')]);
                                        exit;
                                    }

                                    // 检查配额要求
                                    if ($requireQuota > 0) {
                                        $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                                            ->where('userid', $userId)
                                            ->first();

                                        $totalQuota = intval($quota->max_count ?? 0);
                                        if ($totalQuota < $requireQuota) {
                                            echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.quota_required', '配额不足，需要至少 %s 个配额', [$requireQuota])]);
                                            exit;
                                        }
                                    }

                                    // 生成API密钥
                                    $apiKey = 'cfsd_' . bin2hex(random_bytes(16));
                                    $apiSecret = bin2hex(random_bytes(32));
                                    $hashedSecret = password_hash($apiSecret, PASSWORD_DEFAULT);
                                    $defaultRateLimit = intval($settings['api_rate_limit'] ?? 60);
                                    if ($defaultRateLimit <= 0) {
                                        $defaultRateLimit = 60;
                                    }

                                    Capsule::table('mod_cloudflare_api_keys')->insert([
                                        'userid' => $userId,
                                        'key_name' => $keyName,
                                        'api_key' => $apiKey,
                                        'api_secret' => $hashedSecret,
                                        'status' => 'active',
                                        'ip_whitelist' => $ipWhitelist,
                                        'permissions' => json_encode(['subdomains' => true, 'dns_records' => true]),
                                        'rate_limit' => $defaultRateLimit,
                                        'request_count' => 0,
                                        'created_at' => date('Y-m-d H:i:s'),
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ]);

                                    echo json_encode([
                                        'success' => true,
                                        'api_key' => $apiKey,
                                        'api_secret' => $apiSecret
                                    ]);
                                } catch (\Exception $e) {
                                    $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('cfclient.ajax.api.create_failed', '创建失败，请稍后再试。'));
                                    echo json_encode(['success' => false, 'error' => $errorText]);
                                }
                                exit;
                            }


                            // 修改API密钥名称
                            if ($action === 'ajax_update_api_key_name') {
                                try {
                                    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (!empty($_SESSION['cfmod_csrf']) && (!hash_equals($_SESSION['cfmod_csrf'], (string) $hdr))) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。')]);
                                        exit;
                                    }

                                    $rawInput = file_get_contents('php://input');
                                    $data = json_decode($rawInput, true);
                                    if (!is_array($data)) {
                                        $data = [];
                                    }

                                    $keyId = intval($data['key_id'] ?? 0);
                                    $keyName = trim((string) ($data['key_name'] ?? ''));

                                    if ($keyId <= 0) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.invalid_key_id', '无效的密钥ID')]);
                                        exit;
                                    }
                                    if ($keyName === '') {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.name_required', '密钥名称不能为空')]);
                                        exit;
                                    }

                                    if (function_exists('mb_strlen')) {
                                        if (mb_strlen($keyName, 'UTF-8') > 100) {
                                            echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.name_too_long', '密钥名称不能超过100个字符')]);
                                            exit;
                                        }
                                    } elseif (strlen($keyName) > 100) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.name_too_long', '密钥名称不能超过100个字符')]);
                                        exit;
                                    }

                                    $key = Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->where('userid', $userId)
                                        ->first();

                                    if (!$key) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.not_found', 'API密钥不存在')]);
                                        exit;
                                    }

                                    Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->where('userid', $userId)
                                        ->update([
                                            'key_name' => $keyName,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ]);

                                    echo json_encode([
                                        'success' => true,
                                        'key_id' => $keyId,
                                        'key_name' => $keyName,
                                    ]);
                                } catch (\Throwable $e) {
                                    $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('cfclient.ajax.api.update_name_failed', '修改名称失败，请稍后再试。'));
                                    echo json_encode(['success' => false, 'error' => $errorText]);
                                }
                                exit;
                            }

                            // 重新生成API密钥
                            if ($action === 'ajax_regenerate_api_key') {
                                try {
                                    // CSRF 校验
                                    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (!empty($_SESSION['cfmod_csrf']) && (!hash_equals($_SESSION['cfmod_csrf'], (string)$hdr))) {
                                        echo json_encode(['success'=>false,'error'=>self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。')]);
                                        exit;
                                    }
                                    $rawInput = file_get_contents('php://input');
                                    $data = json_decode($rawInput, true);
                                    $keyId = intval($data['key_id'] ?? 0);

                                    $key = Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->where('userid', $userId)
                                        ->first();

                                    if (!$key) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.not_found', 'API密钥不存在')]);
                                        exit;
                                    }

                                    // 检查API状态
                                    if ($key->status === 'disabled') {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.disabled', '此API密钥已被管理员禁用，无法执行此操作')]);
                                        exit;
                                    }

                                    // 生成新密钥
                                    $newApiSecret = bin2hex(random_bytes(32));
                                    $hashedSecret = password_hash($newApiSecret, PASSWORD_DEFAULT);

                                    Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->update([
                                            'api_secret' => $hashedSecret,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ]);

                                    echo json_encode([
                                        'success' => true,
                                        'api_key' => $key->api_key,
                                        'api_secret' => $newApiSecret
                                    ]);
                                } catch (\Exception $e) {
                                    $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('cfclient.ajax.api.reset_failed', '重置失败，请稍后再试。'));
                                    echo json_encode(['success' => false, 'error' => $errorText]);
                                }
                                exit;
                            }

                            // 删除API密钥
                            if ($action === 'ajax_delete_api_key') {
                                try {
                                    // CSRF 校验
                                    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (!empty($_SESSION['cfmod_csrf']) && (!hash_equals($_SESSION['cfmod_csrf'], (string)$hdr))) {
                                        echo json_encode(['success'=>false,'error'=>self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。')]);
                                        exit;
                                    }
                                    $rawInput = file_get_contents('php://input');
                                    $data = json_decode($rawInput, true);
                                    $keyId = intval($data['key_id'] ?? 0);

                                    $key = Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->where('userid', $userId)
                                        ->first();

                                    if (!$key) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.not_found', 'API密钥不存在')]);
                                        exit;
                                    }

                                    // 检查API状态
                                    if ($key->status === 'disabled') {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.disabled', '此API密钥已被管理员禁用，无法执行此操作')]);
                                        exit;
                                    }

                                    Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->delete();

                                    echo json_encode(['success' => true]);
                                } catch (\Exception $e) {
                                    $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('cfclient.ajax.api.delete_failed', '删除失败，请稍后再试。'));
                                    echo json_encode(['success' => false, 'error' => $errorText]);
                                }
                                exit;
                            }
                            if ($action === 'ajax_disable_api_key' || $action === 'ajax_enable_api_key') {
                                try {
                                    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (!empty($_SESSION['cfmod_csrf']) && (!hash_equals($_SESSION['cfmod_csrf'], (string)$hdr))) {
                                        echo json_encode(['success'=>false,'error'=>self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。')]);
                                        exit;
                                    }
                                    $rawInput = file_get_contents('php://input');
                                    $data = json_decode($rawInput, true);
                                    $keyId = intval($data['key_id'] ?? 0);
                                    if ($keyId <= 0) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.invalid_key_id', '无效的密钥ID')]);
                                        exit;
                                    }
                                    $key = Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->where('userid', $userId)
                                        ->first();
                                    if (!$key) {
                                        echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.api.not_found', 'API密钥不存在')]);
                                        exit;
                                    }
                                    $targetStatus = $action === 'ajax_enable_api_key' ? 'active' : 'disabled';
                                    Capsule::table('mod_cloudflare_api_keys')
                                        ->where('id', $keyId)
                                        ->where('userid', $userId)
                                        ->update([
                                            'status' => $targetStatus,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ]);
                                    echo json_encode(['success' => true, 'status' => $targetStatus]);
                                } catch (\Exception $e) {
                                    $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('cfclient.ajax.api.toggle_failed', '状态切换失败，请稍后再试。'));
                                    echo json_encode(['success' => false, 'error' => $errorText]);
                                }
                                exit;
                            }

                            // 额度兑换 AJAX
                            if (in_array($action, ['ajax_redeem_quota_code', 'ajax_list_quota_redeems'], true)) {
                                header('Content-Type: application/json; charset=utf-8');
                                $settingsForRedeem = cf_get_module_settings_cached();
                                $redeemEnabled = in_array(($settingsForRedeem['enable_quota_redeem'] ?? '0'), ['1','on','yes','true','enabled'], true);
                                if (!$redeemEnabled) {
                                    echo json_encode(['success' => false, 'error' => self::actionText('cfclient.redeem.disabled', '兑换功能未开启')]);
                                    exit;
                                }
                                try {
                                    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (empty($_SESSION['cfmod_csrf']) || !hash_equals($_SESSION['cfmod_csrf'], (string)$csrf)) {
                                        throw new CfQuotaRedeemException('csrf_invalid');
                                    }

                                    $banState = cfmod_resolve_user_ban_state($userId);
                                    if (!empty($banState['is_banned'])) {
                                        $reasonText = trim((string)($banState['reason'] ?? ''));
                                        $message = self::actionText('cfclient.redeem.banned', '您的账号已被限制操作，无法使用兑换功能。');
                                        if ($reasonText !== '') {
                                            $message .= ' ' . $reasonText;
                                        }
                                        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode($rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = [];
                                    }

                                    if ($action === 'ajax_redeem_quota_code') {
                                        $codeInput = (string)($payload['code'] ?? '');
                                        $service = CfQuotaRedeemService::instance();
                                        $result = $service->redeemCode($userId, $codeInput, $_SERVER['REMOTE_ADDR'] ?? '', $settingsForRedeem);
                                        echo json_encode([
                                            'success' => true,
                                            'data' => $result,
                                        ], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    if ($action === 'ajax_list_quota_redeems') {
                                        $page = isset($payload['page']) ? max(1, (int)$payload['page']) : 1;
                                        $service = CfQuotaRedeemService::instance();
                                        $history = $service->getUserHistory($userId, $page);
                                        echo json_encode([
                                            'success' => true,
                                            'data' => $history['items'],
                                            'pagination' => [
                                                'page' => $history['page'],
                                                'total_pages' => $history['totalPages'],
                                                'total' => $history['total'],
                                            ],
                                        ], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }
                                } catch (CfQuotaRedeemException $e) {
                                    $message = $this->translateRedeemError($e->getReason());
                                    echo json_encode([
                                        'success' => false,
                                        'error' => $message,
                                        'reason' => $e->getReason(),
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                } catch (\Throwable $e) {
                                    echo json_encode([
                                        'success' => false,
                                        'error' => self::actionText('cfclient.redeem.error.generic', '兑换失败，请稍后再试。'),
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }

                            // WHOIS 查询与隐私开关 AJAX
                            if (in_array($action, ['ajax_whois_lookup', 'ajax_whois_privacy_update'], true)) {
                                header('Content-Type: application/json; charset=utf-8');
                                $settingsForWhois = cf_get_module_settings_cached();
                                if (!class_exists('CfWhoisService') || !CfWhoisService::isEnabled($settingsForWhois)) {
                                    echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.whois.disabled', 'WHOIS 功能暂未开启')], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                                try {
                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode($rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = [];
                                    }

                                    if ($action === 'ajax_whois_privacy_update') {
                                        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                        if (empty($_SESSION['cfmod_csrf']) || !hash_equals($_SESSION['cfmod_csrf'], (string) $csrf)) {
                                            throw new \RuntimeException(self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'));
                                        }

                                        $banState = cfmod_resolve_user_ban_state($userId);
                                        if (!empty($banState['is_banned'])) {
                                            $reasonText = trim((string) ($banState['reason'] ?? ''));
                                            $message = self::actionText('cfclient.ajax.whois.banned', '您的账号已被限制操作，无法修改 WHOIS 隐私设置。');
                                            if ($reasonText !== '') {
                                                $message .= ' ' . $reasonText;
                                            }
                                            throw new \RuntimeException($message);
                                        }

                                        $privacyEnabled = !empty($payload['privacy_enabled']);
                                        $result = CfWhoisService::setUserPrivacy($userId, $privacyEnabled);
                                        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    $domain = trim((string) ($payload['domain'] ?? ''));
                                    $lookupResult = CfWhoisService::lookupDomain($userId, $domain);
                                    echo json_encode([
                                        'success' => !empty($lookupResult['success']),
                                        'data' => $lookupResult['result'] ?? [],
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                } catch (\Throwable $e) {
                                    $errorText = trim((string) $e->getMessage());
                                    if ($errorText === '') {
                                        $errorText = self::actionText('cfclient.ajax.whois.error', 'WHOIS 操作失败，请稍后重试。');
                                    }
                                    echo json_encode(['success' => false, 'error' => $errorText], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }


                            // Dig DNS 查询 AJAX
                            if ($action === 'ajax_dig_lookup') {
                                header('Content-Type: application/json; charset=utf-8');
                                $settingsForDig = cf_get_module_settings_cached();
                                if (!class_exists('CfDigService') || !CfDigService::isEnabled($settingsForDig)) {
                                    echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.dig.disabled', 'Dig 功能暂未开启')], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                                try {
                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode($rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = [];
                                    }

                                    $domain = trim((string) ($payload['domain'] ?? ''));
                                    $recordType = trim((string) ($payload['record_type'] ?? 'A'));
                                    $lookupResult = CfDigService::lookup($userId, $domain, $recordType, $settingsForDig, [
                                        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                                        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                                    ]);

                                    echo json_encode([
                                        'success' => !empty($lookupResult['success']),
                                        'data' => $lookupResult['result'] ?? [],
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                } catch (\Throwable $e) {
                                    $errorText = trim((string) $e->getMessage());
                                    if ($errorText === '') {
                                        $errorText = self::actionText('cfclient.ajax.dig.error', 'Dig 查询失败，请稍后重试。');
                                    }
                                    echo json_encode(['success' => false, 'error' => $errorText], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }

                            // 帮助中心 AI 搜索 / 问答 AJAX
                            if ($action === 'ajax_help_ai_search') {
                                header('Content-Type: application/json; charset=utf-8');
                                $settingsForHelpAi = cf_get_module_settings_cached();
                                if (!is_array($settingsForHelpAi)) {
                                    $settingsForHelpAi = [];
                                }
                                if (!class_exists('CfAiHelpSearchService') || !CfAiHelpSearchService::isEnabled($settingsForHelpAi)) {
                                    echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.help_ai.disabled', '帮助中心 AI 搜索功能暂未开启')], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }

                                try {
                                    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (empty($_SESSION['cfmod_csrf']) || !hash_equals($_SESSION['cfmod_csrf'], (string) $csrf)) {
                                        throw new \RuntimeException(self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'));
                                    }
                                    try {
                                        $helpAiRateLimit = CfRateLimiter::resolveLimit(CfRateLimiter::SCOPE_HELP_AI, $settingsForHelpAi);
                                        CfRateLimiter::enforce(CfRateLimiter::SCOPE_HELP_AI, $helpAiRateLimit, [
                                            'userid' => $userId,
                                            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                                            'identifier' => 'ajax_help_ai_search',
                                        ]);
                                    } catch (CfRateLimitExceededException $rateEx) {
                                        self::respondAjaxRateLimitError($rateEx);
                                    }

                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode($rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = [];
                                    }

                                    $query = trim((string) ($payload['query'] ?? ''));
                                    $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];

                                    $locale = strtolower(trim((string) ($_SESSION['Language'] ?? ($_SESSION['language'] ?? 'english'))));
                                    $result = CfAiHelpSearchService::ask($userId, $query, $history, $settingsForHelpAi, [
                                        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                                        'site_url' => (string) ((isset($_SERVER['HTTP_HOST']) ? (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : '')),
                                        'support_group_url' => (string) ($settingsForHelpAi['client_support_group_url'] ?? ''),
                                        'locale' => $locale,
                                        'account_snapshot' => self::buildHelpAiAccountSnapshot($userId),
                                    ]);

                                    echo json_encode([
                                        'success' => true,
                                        'data' => $result,
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                } catch (\Throwable $e) {
                                    $errorText = self::actionText('cfclient.ajax.help_ai.error', 'AI 搜索失败，请稍后再试。');
                                    $safeErrors = [
                                        self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'),
                                        self::actionText('cfclient.ajax.help_ai.disabled', '帮助中心 AI 搜索功能暂未开启'),
                                        '请输入问题后再进行 AI 查询。',
                                        'AI 搜索功能暂未开启。',
                                    ];
                                    $rawErrorText = trim((string) $e->getMessage());
                                    if ($rawErrorText !== '' && in_array($rawErrorText, $safeErrors, true)) {
                                        $errorText = $rawErrorText;
                                    }
                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log('help_ai_search_error', [
                                            'error' => $rawErrorText,
                                            'exception' => get_class($e),
                                        ], $userId, null);
                                    }
                                    echo json_encode(['success' => false, 'error' => $errorText], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }

                            // 前台孤儿记录清理候选 AJAX（远程搜索 / 分页）
                            if ($action === 'ajax_search_orphan_cleanup_subdomains') {
                                header('Content-Type: application/json; charset=utf-8');
                                $settingsForOrphanCleanup = cf_get_module_settings_cached();
                                if (!is_array($settingsForOrphanCleanup)) {
                                    $settingsForOrphanCleanup = [];
                                }
                                if (!cfmod_setting_enabled($settingsForOrphanCleanup['client_orphan_dns_cleanup_enabled'] ?? '0')) {
                                    echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.orphan_cleanup.feature_disabled', '域名记录冲突清理功能未开启')], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }

                                try {
                                    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (empty($_SESSION['cfmod_csrf']) || !hash_equals($_SESSION['cfmod_csrf'], (string) $csrf)) {
                                        throw new \RuntimeException(self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'));
                                    }

                                    $banState = cfmod_resolve_user_ban_state($userId);
                                    if (!empty($banState['is_banned'])) {
                                        $reasonText = trim((string) ($banState['reason'] ?? ''));
                                        $message = self::actionText('cfclient.ajax.orphan_cleanup.banned', '您的账号已被限制操作，无法进行域名记录冲突清理。');
                                        if ($reasonText !== '') {
                                            $message .= ' ' . $reasonText;
                                        }
                                        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode($rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = [];
                                    }

                                    $keyword = strtolower(trim((string) ($payload['q'] ?? '')));
                                    $page = max(1, intval($payload['page'] ?? 1));
                                    $perPage = intval($payload['per_page'] ?? 20);
                                    $perPage = max(10, min(100, $perPage));

                                    $baseQuery = Capsule::table('mod_cloudflare_subdomain')
                                        ->where('userid', $userId)
                                        ->where('status', 'active');

                                    if ($keyword !== '') {
                                        $keywordLike = '%' . $keyword . '%';
                                        $baseQuery->where(function ($q) use ($keywordLike) {
                                            $q->whereRaw('LOWER(subdomain) LIKE ?', [$keywordLike])
                                              ->orWhereRaw('LOWER(rootdomain) LIKE ?', [$keywordLike]);
                                        });
                                    }

                                    $total = (clone $baseQuery)->count();
                                    $totalPages = max(1, (int) ceil($total / $perPage));
                                    if ($page > $totalPages) {
                                        $page = $totalPages;
                                    }

                                    $rows = $baseQuery
                                        ->select('id', 'subdomain', 'rootdomain', 'status')
                                        ->orderBy('created_at', 'desc')
                                        ->orderBy('id', 'desc')
                                        ->offset(($page - 1) * $perPage)
                                        ->limit($perPage)
                                        ->get();

                                    $data = [];
                                    foreach ($rows as $row) {
                                        $data[] = [
                                            'id' => intval($row->id ?? 0),
                                            'fullDomain' => (string) ($row->subdomain ?? ''),
                                            'rootdomain' => (string) ($row->rootdomain ?? ''),
                                            'status' => (string) ($row->status ?? ''),
                                        ];
                                    }

                                    echo json_encode([
                                        'success' => true,
                                        'data' => $data,
                                        'pagination' => [
                                            'page' => $page,
                                            'per_page' => $perPage,
                                            'total' => intval($total),
                                            'total_pages' => $totalPages,
                                        ],
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                } catch (\Throwable $e) {
                                    $errorText = self::actionText('cfclient.ajax.orphan_cleanup.search_failed', '域名候选加载失败，请稍后再试。');
                                    $safeErrors = [
                                        self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'),
                                    ];
                                    $rawErrorText = trim((string) $e->getMessage());
                                    if ($rawErrorText !== '' && in_array($rawErrorText, $safeErrors, true)) {
                                        $errorText = $rawErrorText;
                                    }
                                    if (function_exists('cloudflare_subdomain_log')) {
                                        cloudflare_subdomain_log('client_orphan_cleanup_search_error', [
                                            'error' => $rawErrorText,
                                            'exception' => get_class($e),
                                        ], $userId, null);
                                    }
                                    echo json_encode(['success' => false, 'error' => $errorText], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }

                            // 域名转赠 AJAX
                            if (in_array($action, ['ajax_initiate_domain_gift','ajax_accept_domain_gift','ajax_cancel_domain_gift','ajax_list_domain_gifts','ajax_search_domain_gift_subdomains'], true)) {
                                header('Content-Type: application/json; charset=utf-8');
                                $settingsForGift = cf_get_module_settings_cached();
                                if (!cfmod_is_domain_gift_enabled($settingsForGift)) {
                                    echo json_encode(['success' => false, 'error' => self::actionText('cfclient.ajax.gift.feature_disabled', '域名转赠功能未开启')]);
                                    exit;
                                }
                                try {
                                    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                                    if (empty($_SESSION['cfmod_csrf']) || !hash_equals($_SESSION['cfmod_csrf'], (string)$csrf)) {
                                        throw new \RuntimeException(self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'));
                                    }

                                    $banState = cfmod_resolve_user_ban_state($userId);
                                    if (!empty($banState['is_banned'])) {
                                        $reasonText = trim((string)($banState['reason'] ?? ''));
                                        $message = self::actionText('cfclient.ajax.gift.banned', '您的账号已被限制操作，无法进行域名转赠。');
                                        if ($reasonText !== '') {
                                            $message .= ' ' . $reasonText;
                                        }
                                        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    $rawInput = file_get_contents('php://input');
                                    $payload = json_decode($rawInput, true);
                                    if (!is_array($payload)) {
                                        $payload = [];
                                    }
                                    $subdomainId = isset($payload['subdomain_id']) ? (int) $payload['subdomain_id'] : 0;
                                    $now = time();
                                    $nowStr = date('Y-m-d H:i:s', $now);

                                    if ($action === 'ajax_search_domain_gift_subdomains') {
                                        $keyword = strtolower(trim((string) ($payload['q'] ?? '')));
                                        $page = max(1, intval($payload['page'] ?? 1));
                                        $perPage = intval($payload['per_page'] ?? 20);
                                        $perPage = max(10, min(100, $perPage));

                                        $eligibleBefore = date('Y-m-d H:i:s', $now - (7 * 86400));
                                        $baseQuery = Capsule::table('mod_cloudflare_subdomain')
                                            ->where('userid', $userId)
                                            ->where('status', 'active')
                                            ->where(function ($q) {
                                                $q->whereNull('gift_lock_id')
                                                  ->orWhere('gift_lock_id', 0);
                                            })
                                            ->whereNotNull('created_at')
                                            ->where('created_at', '<=', $eligibleBefore);

                                        if ($keyword !== '') {
                                            $keywordLike = '%' . $keyword . '%';
                                            $baseQuery->where(function ($q) use ($keywordLike) {
                                                $q->where('subdomain', 'like', $keywordLike)
                                                  ->orWhereRaw('LOWER(rootdomain) LIKE ?', [$keywordLike]);
                                            });
                                        }

                                        $total = (clone $baseQuery)->count();
                                        $totalPages = max(1, (int) ceil($total / $perPage));
                                        if ($page > $totalPages) {
                                            $page = $totalPages;
                                        }

                                        $rows = $baseQuery
                                            ->select('id', 'subdomain', 'rootdomain', 'gift_lock_id', 'status')
                                            ->orderBy('created_at', 'desc')
                                            ->offset(($page - 1) * $perPage)
                                            ->limit($perPage)
                                            ->get();

                                        $data = [];
                                        foreach ($rows as $row) {
                                            $data[] = [
                                                'id' => intval($row->id ?? 0),
                                                'fullDomain' => (string) ($row->subdomain ?? ''),
                                                'rootdomain' => (string) ($row->rootdomain ?? ''),
                                                'locked' => intval($row->gift_lock_id ?? 0) > 0,
                                                'status' => (string) ($row->status ?? ''),
                                            ];
                                        }

                                        echo json_encode([
                                            'success' => true,
                                            'data' => $data,
                                            'pagination' => [
                                                'page' => $page,
                                                'per_page' => $perPage,
                                                'total' => intval($total),
                                                'total_pages' => $totalPages,
                                            ],
                                        ], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    if ($action === 'ajax_initiate_domain_gift') {

                                        if ($subdomainId <= 0) {
                                            throw new \RuntimeException(self::actionText('cfclient.ajax.gift.select_domain', '请选择要转赠的域名'));
                                        }
                                        $ttlHours = cfmod_get_domain_gift_ttl_hours($settingsForGift);
                                        $expiresAt = date('Y-m-d H:i:s', $now + $ttlHours * 3600);
                                        $result = Capsule::transaction(function () use ($subdomainId, $userId, $expiresAt, $settingsForGift, $nowStr, $now) {
                                            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $subdomainId)
                                                ->where('userid', $userId)
                                                ->lockForUpdate()
                                                ->first();
                                            if (!$subdomain) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.domain_not_found', '未找到该域名或无权操作'));
                                            }

                                            $createdAtTs = $subdomain->created_at ? strtotime((string) $subdomain->created_at) : 0;
                                            $minGiftAgeSeconds = 7 * 86400;
                                            if ($createdAtTs <= 0 || ($now - $createdAtTs) < $minGiftAgeSeconds) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.min_age', '该域名注册未满7天，暂不支持转赠'));
                                            }

                                            if (intval($subdomain->gift_lock_id ?? 0) > 0) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.already_transferring', '该域名正在转赠中，请等待当前转赠完成'));
                                            }
                                            $attempts = 0;
                                            do {
                                                $code = cfmod_generate_domain_gift_code();
                                                $exists = Capsule::table('mod_cloudflare_domain_gifts')->where('code', $code)->exists();
                                                $attempts++;
                                            } while ($exists && $attempts < 5);
                                            if ($exists) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.generate_failed', '生成转赠码失败，请稍后再试'));
                                            }
                                            $giftId = Capsule::table('mod_cloudflare_domain_gifts')->insertGetId([
                                                'code' => $code,
                                                'subdomain_id' => $subdomainId,
                                                'from_userid' => $userId,
                                                'to_userid' => null,
                                                'full_domain' => $subdomain->subdomain,
                                                'status' => 'pending',
                                                'expires_at' => $expiresAt,
                                                'completed_at' => null,
                                                'cancelled_at' => null,
                                                'cancelled_by_admin' => null,
                                                'created_at' => $nowStr,
                                                'updated_at' => $nowStr,
                                            ]);
                                            Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $subdomainId)
                                                ->update([
                                                    'gift_lock_id' => $giftId,
                                                    'updated_at' => $nowStr,
                                                ]);
                                            return [
                                                'gift_id' => $giftId,
                                                'code' => $code,
                                                'expires_at' => $expiresAt,
                                                'full_domain' => $subdomain->subdomain,
                                                'subdomain_id' => $subdomainId,
                                            ];
                                        });
                                        if (function_exists('cloudflare_subdomain_log')) {
                                            cloudflare_subdomain_log('client_gift_initiate', $result, $userId, $result['subdomain_id']);
                                        }
                                        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    if ($action === 'ajax_accept_domain_gift') {
                                        $codeInput = strtoupper(trim((string)($payload['code'] ?? '')));
                                        if ($codeInput === '' || strlen($codeInput) < 6) {
                                            throw new \RuntimeException(self::actionText('cfclient.ajax.gift.code_required', '请输入有效的接收码'));
                                        }
                                        $settings = $settingsForGift;
                                        $acceptResult = Capsule::transaction(function () use ($codeInput, $userId, $nowStr, $settings) {
                                            $gift = Capsule::table('mod_cloudflare_domain_gifts')
                                                ->where('code', $codeInput)
                                                ->lockForUpdate()
                                                ->first();
                                            if (!$gift) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.code_not_found', '接收码不存在'));
                                            }
                                            if ($gift->status !== 'pending') {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.code_used', '该接收码已被使用或失效'));
                                            }
                                            if (strtotime($gift->expires_at) !== false && strtotime($gift->expires_at) < time()) {
                                                Capsule::table('mod_cloudflare_domain_gifts')
                                                    ->where('id', $gift->id)
                                                    ->update([
                                                        'status' => 'expired',
                                                        'cancelled_at' => $nowStr,
                                                        'updated_at' => $nowStr,
                                                    ]);
                                                Capsule::table('mod_cloudflare_subdomain')
                                                    ->where('id', $gift->subdomain_id)
                                                    ->where('gift_lock_id', $gift->id)
                                                    ->update(['gift_lock_id' => null, 'updated_at' => $nowStr]);
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.code_expired', '接收码已过期'));
                                            }
                                            if (intval($gift->from_userid) === $userId) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.self_redeem', '不能领取自己发起的转赠'));
                                            }
                                            $subdomain = Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $gift->subdomain_id)
                                                ->lockForUpdate()
                                                ->first();
                                            if (!$subdomain || intval($subdomain->gift_lock_id ?? 0) !== intval($gift->id)) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.domain_invalid', '当前域名状态异常，请稍后再试'));
                                            }

                                            $rootForGift = (string)($subdomain->rootdomain ?? '');
                                            if ($rootForGift !== '' && function_exists('cfmod_check_rootdomain_user_limit')) {
                                                $limitCheck = cfmod_check_rootdomain_user_limit($userId, $rootForGift, 1);
                                                if (!$limitCheck['allowed']) {
                                                    $limitMessage = cfmod_format_rootdomain_limit_message($rootForGift, $limitCheck['limit']);
                                                    if ($limitMessage === '') {
                                                        $limitValueText = max(1, intval($limitCheck['limit'] ?? 0));
                                                        $limitMessage = self::actionText('cfclient.actions.register.root_user_limit', '%1$s 每个账号最多注册 %2$s 个，您已达到上限', [$rootForGift, $limitValueText]);
                                                    }
                                                    throw new \RuntimeException($limitMessage);
                                                }
                                            }

                                            $quotaTable = 'mod_cloudflare_subdomain_quotas';
                                            $donorQuota = Capsule::table($quotaTable)
                                                ->where('userid', $gift->from_userid)
                                                ->lockForUpdate()
                                                ->first();
                                            $recipientQuota = Capsule::table($quotaTable)
                                                ->where('userid', $userId)
                                                ->lockForUpdate()
                                                ->first();

                                            $baseMax = max(0, intval($settings['max_subdomain_per_user'] ?? 0));
                                            $inviteLimit = intval($settings['invite_bonus_limit_global'] ?? 5);
                                            if ($inviteLimit <= 0) {
                                                $inviteLimit = 5;
                                            }
                                            $isRecipientPrivileged = function_exists('cf_is_user_privileged') && cf_is_user_privileged($userId);
                                            $privilegedLimit = function_exists('cf_get_privileged_limit') ? cf_get_privileged_limit() : 0;

                                            if (!$recipientQuota) {
                                                Capsule::table($quotaTable)->insert([
                                                    'userid' => $userId,
                                                    'used_count' => 0,
                                                    'max_count' => $isRecipientPrivileged ? $privilegedLimit : $baseMax,
                                                    'invite_bonus_count' => 0,
                                                    'invite_bonus_limit' => $inviteLimit,
                                                    'created_at' => $nowStr,
                                                    'updated_at' => $nowStr,
                                                ]);
                                                $recipientQuota = Capsule::table($quotaTable)
                                                    ->where('userid', $userId)
                                                    ->lockForUpdate()
                                                    ->first();
                                            }

                                            if ($isRecipientPrivileged) {
                                                if (intval($recipientQuota->max_count ?? 0) !== $privilegedLimit && $privilegedLimit > 0) {
                                                    Capsule::table($quotaTable)
                                                        ->where('userid', $userId)
                                                        ->update(['max_count' => $privilegedLimit, 'updated_at' => $nowStr]);
                                                    $recipientQuota->max_count = $privilegedLimit;
                                                }
                                            } else {
                                                $bonusCount = max(0, intval($recipientQuota->invite_bonus_count ?? 0));
                                                $currentMax = intval($recipientQuota->max_count ?? 0);
                                                $currentBase = max(0, $currentMax - $bonusCount);
                                                if ($baseMax > 0 && $currentBase < $baseMax) {
                                                    $newMax = $baseMax + $bonusCount;
                                                    Capsule::table($quotaTable)
                                                        ->where('userid', $userId)
                                                        ->update(['max_count' => $newMax, 'updated_at' => $nowStr]);
                                                    $recipientQuota->max_count = $newMax;
                                                }
                                            }

                                            $recipientMax = intval($recipientQuota->max_count ?? 0);
                                            $recipientUsed = intval($recipientQuota->used_count ?? 0);
                                            if (!$isRecipientPrivileged && $recipientMax > 0 && $recipientUsed >= $recipientMax) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.quota_insufficient', '您的配额不足，请先提升配额后再领取'));
                                            }

                                            if (!$donorQuota) {
                                                Capsule::table($quotaTable)->insert([
                                                    'userid' => $gift->from_userid,
                                                    'used_count' => 0,
                                                    'max_count' => max($baseMax, 0),
                                                    'invite_bonus_count' => 0,
                                                    'invite_bonus_limit' => $inviteLimit,
                                                    'created_at' => $nowStr,
                                                    'updated_at' => $nowStr,
                                                ]);
                                                $donorQuota = Capsule::table($quotaTable)
                                                    ->where('userid', $gift->from_userid)
                                                    ->lockForUpdate()
                                                    ->first();
                                            }

                                            Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $subdomain->id)
                                                ->update([
                                                    'userid' => $userId,
                                                    'gift_lock_id' => null,
                                                    'updated_at' => $nowStr,
                                                ]);

                                            Capsule::table($quotaTable)
                                                ->where('userid', $gift->from_userid)
                                                ->update([
                                                    'used_count' => max(0, intval($donorQuota->used_count ?? 0) - 1),
                                                    'updated_at' => $nowStr,
                                                ]);

                                            Capsule::table($quotaTable)
                                                ->where('userid', $userId)
                                                ->update([
                                                    'used_count' => $recipientUsed + 1,
                                                    'updated_at' => $nowStr,
                                                ]);

                                            Capsule::table('mod_cloudflare_domain_gifts')
                                                ->where('id', $gift->id)
                                                ->update([
                                                    'status' => 'accepted',
                                                    'to_userid' => $userId,
                                                    'completed_at' => $nowStr,
                                                    'updated_at' => $nowStr,
                                                ]);

                                            return [
                                                'full_domain' => $subdomain->subdomain,
                                                'gift_id' => $gift->id,
                                                'from_userid' => $gift->from_userid,
                                                'subdomain_id' => $subdomain->id,
                                            ];
                                        });
                                        if (function_exists('cloudflare_subdomain_log')) {
                                            cloudflare_subdomain_log('client_gift_accept', $acceptResult, $userId, $acceptResult['subdomain_id']);
                                        }
                                        echo json_encode(['success' => true, 'data' => $acceptResult], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    if ($action === 'ajax_cancel_domain_gift') {
                                        $giftId = intval($payload['gift_id'] ?? 0);
                                        if ($giftId <= 0) {
                                            throw new \RuntimeException(self::actionText('cfclient.ajax.gift.missing_record', '缺少转赠记录'));
                                        }
                                        $cancelResult = Capsule::transaction(function () use ($giftId, $userId, $nowStr) {
                                            $gift = Capsule::table('mod_cloudflare_domain_gifts')
                                                ->where('id', $giftId)
                                                ->lockForUpdate()
                                                ->first();
                                            if (!$gift || intval($gift->from_userid) !== $userId) {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.record_forbidden', '转赠记录不存在或无权操作'));
                                            }
                                            if ($gift->status !== 'pending') {
                                                throw new \RuntimeException(self::actionText('cfclient.ajax.gift.cancel_restriction', '仅可取消进行中的转赠'));
                                            }
                                            Capsule::table('mod_cloudflare_domain_gifts')
                                                ->where('id', $giftId)
                                                ->update([
                                                    'status' => 'cancelled',
                                                    'cancelled_at' => $nowStr,
                                                    'updated_at' => $nowStr,
                                                ]);
                                            Capsule::table('mod_cloudflare_subdomain')
                                                ->where('id', $gift->subdomain_id)
                                                ->where('gift_lock_id', $gift->id)
                                                ->update([
                                                    'gift_lock_id' => null,
                                                    'updated_at' => $nowStr,
                                                ]);
                                            return [
                                                'gift_id' => $giftId,
                                                'full_domain' => $gift->full_domain,
                                                'subdomain_id' => $gift->subdomain_id,
                                            ];
                                        });
                                        if (function_exists('cloudflare_subdomain_log')) {
                                            cloudflare_subdomain_log('client_gift_cancel', $cancelResult, $userId, $cancelResult['subdomain_id']);
                                        }
                                        echo json_encode(['success' => true, 'data' => $cancelResult], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }

                                    if ($action === 'ajax_list_domain_gifts') {
                                        $page = max(1, intval($payload['page'] ?? 1));
                                        $perPage = 5;
                                        $baseQuery = Capsule::table('mod_cloudflare_domain_gifts')
                                            ->where(function ($q) use ($userId) {
                                                $q->where('from_userid', $userId)
                                                  ->orWhere('to_userid', $userId);
                                            });
                                        $total = (clone $baseQuery)->count();
                                        $totalPages = max(1, (int)ceil($total / $perPage));
                                        if ($page > $totalPages) {
                                            $page = $totalPages;
                                        }
                                        $records = $baseQuery
                                            ->orderBy('id', 'desc')
                                            ->offset(($page - 1) * $perPage)
                                            ->limit($perPage)
                                            ->get();
                                        $items = [];
                                        foreach ($records as $record) {
                                            $role = ((int)$record->from_userid === $userId) ? 'sent' : 'received';
                                            $items[] = [
                                                'id' => (int)$record->id,
                                                'code' => $record->code,
                                                'full_domain' => $record->full_domain,
                                                'status' => $record->status,
                                                'expires_at' => $record->expires_at,
                                                'created_at' => $record->created_at,
                                                'completed_at' => $record->completed_at,
                                                'cancelled_at' => $record->cancelled_at,
                                                'to_userid' => $record->to_userid ? (int)$record->to_userid : null,
                                                'role' => $role,
                                            ];
                                        }
                                        echo json_encode([
                                            'success' => true,
                                            'data' => $items,
                                            'pagination' => [
                                                'page' => $page,
                                                'per_page' => $perPage,
                                                'total' => $total,
                                                'total_pages' => $totalPages,
                                            ]
                                        ], JSON_UNESCAPED_UNICODE);
                                        exit;
                                    }
                                } catch (\Throwable $e) {
                                    $errorText = trim((string) $e->getMessage());
                                    if ($errorText === '') {
                                        $errorText = self::actionText('cfclient.ajax.gift.process_failed', '处理失败，请稍后再试。');
                                    }
                                    echo json_encode(['success' => false, 'error' => $errorText], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }

                            // 轻量接口：实时排行榜（当前周期 Top N）
                            if ($action === 'realtime_top') {
                                try {
                                    // 读取模块设置
                                    $settings = cf_get_module_settings_cached();
                                    $periodDays = max(1, intval($settings['invite_leaderboard_period_days'] ?? 7));
                                    $cycleStart = trim($settings['invite_cycle_start'] ?? '');
                                    $limit = max(1, min(50, intval($_GET['limit'] ?? 5)));

                                    // 实时榜单API：一直返回实时数据，不检查周期结束

                                    // 计算实时窗口：包含今天
                                    $todayYmd = date('Y-m-d');
                                    if ($cycleStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cycleStart)) {
                                        $startTs = strtotime($cycleStart);
                                        $todayTs = strtotime($todayYmd);
                                        if ($todayTs >= $startTs) {
                                            $k = (int) floor((($todayTs - $startTs) / 86400) / $periodDays);
                                            $periodStart = date('Y-m-d', strtotime('+' . ($k * $periodDays) . ' days', $startTs));
                                            $periodEnd = date('Y-m-d', strtotime('+' . (($k * $periodDays) + $periodDays - 1) . ' days', $startTs));
                                            // 如果当前周期还没开始，则使用今天作为开始
                                            if ($todayTs < strtotime($periodStart)) {
                                                $periodStart = $todayYmd;
                                                $periodEnd = $todayYmd;
                                            }
                                        } else { 
                                            $periodStart = $todayYmd; 
                                            $periodEnd = $todayYmd; 
                                        }
                                    } else {
                                        $periodEnd = $todayYmd;
                                        $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . ($periodDays - 1) . ' days'));
                                    }
                                    // 查询实时 Top N
                                    $rt = Capsule::table('mod_cloudflare_invitation_claims as ic')
                                        ->select('ic.inviter_userid', Capsule::raw('COUNT(*) as cnt'))
                                        ->whereBetween('ic.created_at', [$periodStart.' 00:00:00', $periodEnd.' 23:59:59'])
                                        ->groupBy('ic.inviter_userid')
                                        ->orderBy('cnt','desc')
                                        ->limit($limit)
                                        ->get();
                                    $rows = [];
                                    $rankRows = [];
                                    $userIds = [];
                                    foreach ($rt as $row) {
                                        $uid = intval($row->inviter_userid ?? 0);
                                        if ($uid <= 0) {
                                            continue;
                                        }
                                        $rankRows[] = [
                                            'uid' => $uid,
                                            'count' => intval($row->cnt ?? 0),
                                        ];
                                        $userIds[$uid] = $uid;
                                    }

                                    $emailMap = [];
                                    $codeMap = [];
                                    $userIds = array_values($userIds);
                                    if (!empty($userIds)) {
                                        if (function_exists('cfmod_client_fetch_invite_user_meta')) {
                                            [$emailMap, $codeMap] = cfmod_client_fetch_invite_user_meta($userIds);
                                        } else {
                                            try {
                                                $users = Capsule::table('tblclients')
                                                    ->select('id', 'email')
                                                    ->whereIn('id', $userIds)
                                                    ->get();
                                                foreach ($users as $userRow) {
                                                    $emailMap[(int) ($userRow->id ?? 0)] = $userRow->email ?? '';
                                                }
                                            } catch (\Throwable $e) {
                                            }
                                            try {
                                                $codes = Capsule::table('mod_cloudflare_invitation_codes')
                                                    ->select('userid', 'code')
                                                    ->whereIn('userid', $userIds)
                                                    ->get();
                                                foreach ($codes as $codeRow) {
                                                    $codeMap[(int) ($codeRow->userid ?? 0)] = $codeRow->code ?? '';
                                                }
                                            } catch (\Throwable $e) {
                                            }
                                        }
                                    }

                                    foreach ($rankRows as $i => $rankRow) {
                                        $uid = (int) ($rankRow['uid'] ?? 0);
                                        $email = (string) ($emailMap[$uid] ?? '');
                                        if (function_exists('cfmod_client_mask_leaderboard_email')) {
                                            $emailMasked = cfmod_client_mask_leaderboard_email($email);
                                        } else {
                                            $emailMasked = '***';
                                        }
                                        $codeMasked = cfmod_mask_invite_code((string) ($codeMap[$uid] ?? ''));
                                        $rows[] = [
                                            'rank' => $i + 1,
                                            'uid' => $uid,
                                            'emailMasked' => $emailMasked,
                                            'codeMasked' => $codeMasked,
                                            'count' => (int) ($rankRow['count'] ?? 0),
                                        ];
                                    }

                                    header('Content-Type: application/json; charset=utf-8');
                                    echo json_encode([
                                        'ok' => true,
                                        'periodStart' => $periodStart,
                                        'periodEnd' => $periodEnd,
                                        'rows' => $rows,
                                        'serverTime' => date('Y-m-d H:i:s')
                                    ], JSON_UNESCAPED_UNICODE);
                                } catch (\Throwable $e) {
                                    header('Content-Type: application/json; charset=utf-8');
                                    $errorText = cfmod_format_provider_error($e->getMessage(), self::actionText('cfclient.ajax.realtime.fetch_failed', '数据获取失败，请稍后再试。'));
                                    echo json_encode(['ok'=>false,'error'=>$errorText]);
                                }
                                exit;
                            }



        return $this->handleClientFormAction($userId, $globals);
    }

    private function handleClientFormAction(int $userId, array $globals): array
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($requestMethod !== 'POST') {
            return [];
        }

        $csrfValid = $GLOBALS['cfmod_client_csrf_valid'] ?? true;
        if (!$csrfValid) {
            return [
                'msg' => self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。'),
                'msg_type' => 'danger',
            ];
        }

        if (!isset($_POST['action'])) {
            return [];
        }

        if (empty($globals['tables_exist'])) {
            return [];
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === '' || in_array($action, ['__csrf_failed__', '__maintenance__', '__banned__'], true)) {
            return [];
        }

        if (!empty($globals['isUserBannedOrInactive'])) {
            $banReasonText = trim((string) ($globals['banReasonText'] ?? ''));
            $messageTemplate = cfmod_trans('cfclient.account_banned', '您的账号已被封禁或停用，操作已被禁止。%s');
            $message = sprintf($messageTemplate, $banReasonText !== '' ? (' ' . $banReasonText) : '');
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_banned_attempt', ['original_action' => $action], $userId, null);
            }
            return [
                'msg' => $message,
                'msg_type' => 'danger',
            ];
        }

        if (!empty($globals['maintenanceMode'])) {
            if (function_exists('cloudflare_subdomain_log')) {
                cloudflare_subdomain_log('client_maintenance_attempt', ['original_action' => $action], $userId, null);
            }
            return [
                'msg' => cfmod_trans('cfclient.maintenance_notice', '系统维护中，当前暂不可操作，请稍后再试。'),
                'msg_type' => 'warning',
            ];
        }

        return CfClientActionService::process($globals);
    }

    private function translateRedeemError(string $reason): string
    {
        switch ($reason) {
            case 'csrf_invalid':
                return self::actionText('cfclient.csrf_failed', '安全校验失败：请刷新页面后重试。');
            case 'invalid_user':
            case 'empty_code':
            case 'code_not_found':
                return self::actionText('cfclient.redeem.error.invalid_code', '兑换码不存在或已失效');
            case 'code_inactive':
                return self::actionText('cfclient.redeem.error.inactive', '该兑换码已被停用');
            case 'code_not_started':
                return self::actionText('cfclient.redeem.error.not_started', '兑换尚未开始');
            case 'code_expired':
                return self::actionText('cfclient.redeem.error.expired', '兑换码已过期');
            case 'code_exhausted':
                return self::actionText('cfclient.redeem.error.exhausted', '兑换次数已用完');
            case 'per_user_limit':
                return self::actionText('cfclient.redeem.error.per_user_limit', '您已使用过该兑换码');
            case 'same_type_limit':
                return self::actionText('cfclient.redeem.error.same_type_limit', '您已兑换过同类型限制兑换码，无法重复兑换');
            case 'grant_invalid':
                return self::actionText('cfclient.redeem.error.invalid_grant', '兑换码配置异常，请联系客服');
            case 'quota_unavailable':
                return self::actionText('cfclient.redeem.error.quota_unavailable', '当前无法更新您的额度，请稍后重试');
            default:
                return self::actionText('cfclient.redeem.error.generic', '兑换失败，请稍后再试。');
        }
    }

    private function applyActionOverrides(array $viewModel, array $overrides): array
    {
        if (empty($overrides)) {
            return $viewModel;
        }

        if (!isset($viewModel['globals']) || !is_array($viewModel['globals'])) {
            $viewModel['globals'] = [];
        }

        foreach ($overrides as $key => $value) {
            $viewModel['globals'][$key] = $value;
        }

        if (isset($viewModel['meta']['template_variables']) && is_array($viewModel['meta']['template_variables'])) {
            $viewModel['meta']['template_variables'] = array_values(array_unique(array_merge($viewModel['meta']['template_variables'], array_keys($overrides))));
        }

        return $viewModel;
    }

    private function buildViewModel(int $userId, $client, array $vars): array
    {
        if (class_exists('CfClientViewModelBuilder')) {
            return CfClientViewModelBuilder::build($userId);
        }

        return ['globals' => []];
    }

    private function resolveMaintenanceState(array $settings): array
    {
        $enabled = (int)($settings['client_maintenance_mode'] ?? $settings['maintenance_mode'] ?? 0) === 1;
        return [
            'enabled' => $enabled,
            'message' => trim((string)($settings['client_maintenance_message'] ?? '')),
        ];
    }

    private function resolveBanState(int $userId): array
    {
        if (function_exists('cfmod_resolve_user_ban_state')) {
            $state = cfmod_resolve_user_ban_state($userId);
            return [
                'isBanned' => !empty($state['is_banned']),
                'reason' => trim((string)($state['reason'] ?? '')),
            ];
        }

        $record = Capsule::table('mod_cloudflare_user_bans')
            ->where('userid', $userId)
            ->where('status', 'banned')
            ->first();

        return [
            'isBanned' => (bool) $record,
            'reason' => $record ? trim((string)($record->reason ?? '')) : '',
        ];
    }

    private function resolveQuotaState(int $userId, array $settings): array
    {
        $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
            ->where('userid', $userId)
            ->first();

        $baseMax = max(0, (int)($settings['max_subdomain_per_user'] ?? 0));

        return [
            'max' => (int)($quota->max_count ?? $baseMax),
            'used' => (int)($quota->used_count ?? 0),
            'invite_bonus_limit' => (int)($quota->invite_bonus_limit ?? ($settings['invite_bonus_limit_global'] ?? 0)),
            'invite_bonus_count' => (int)($quota->invite_bonus_count ?? 0),
        ];
    }

    private function resolveRootOptions(): array
    {
        if (function_exists('cfmod_get_known_rootdomains')) {
            $domains = cfmod_get_known_rootdomains();
            if (is_array($domains)) {
                return array_values(array_map(static function ($domain) {
                    return ['domain' => $domain];
                }, $domains));
            }
        }

        $allowSuspended = false;
        try {
            $sessionUserId = intval($_SESSION['uid'] ?? 0);
            if ($sessionUserId > 0 && function_exists('cf_is_user_privileged') && cf_is_user_privileged($sessionUserId)) {
                $allowSuspended = function_exists('cf_is_privileged_feature_enabled')
                    && cf_is_privileged_feature_enabled('allow_register_suspended_root');
            }
        } catch (\Throwable $e) {
            $allowSuspended = false;
        }

        try {
            $query = Capsule::table('mod_cloudflare_rootdomains')
                ->select('domain', 'status')
                ->orderBy('domain');
            if ($allowSuspended) {
                $query->whereIn('status', ['active', 'suspended']);
            } else {
                $query->where('status', 'active');
            }
            $rows = $query->get();
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'domain' => $row->domain ?? '',
                'status' => $row->status ?? 'active',
            ];
        }, $rows ? $rows->all() : []);
    }

    private function buildRegisterFormState(array $settings): array
    {
        $limits = function_exists('cf_get_prefix_length_limits') ? cf_get_prefix_length_limits($settings) : ['min' => 3, 'max' => 32];
        return [
            'length' => $limits,
            'term_years' => (int)($settings['domain_registration_term_years'] ?? 1),
        ];
    }

    private function buildSubdomainView(int $userId, array $settings): array
    {
        $searchTerm = trim((string)($_GET['search'] ?? ''));
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $pageSize = (int)($settings['client_subdomain_page_size'] ?? 20);
        $pageSize = max(5, min(100, $pageSize));

        [$records, $total, $totalPages, $currentPage] = CfSubdomainService::instance()
            ->loadSubdomainsPaginated($userId, $page, $pageSize, $searchTerm);

        $normalized = array_map([$this, 'normalizeSubdomainRecord'], $records);

        return [
            'items' => $normalized,
            'pagination' => [
                'page' => $currentPage,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
            'search' => $searchTerm,
        ];
    }

    private function normalizeSubdomainRecord($record): array
    {
        if ($record instanceof \stdClass) {
            $record = (array) $record;
        }

        if (!is_array($record)) {
            return [];
        }

        $record['created_at'] = isset($record['created_at']) ? (string) $record['created_at'] : '';
        $record['updated_at'] = isset($record['updated_at']) ? (string) $record['updated_at'] : '';

        return $record;
    }


    private static function buildSubdomainAvailabilityResult(int $userId, $client, array $globals, array $settings, array $payload): array
    {
        $prefixRaw = trim((string) ($payload['subdomain'] ?? ($payload['prefix'] ?? '')));
        $rootRaw = trim((string) ($payload['rootdomain'] ?? ''));
        $inviteCode = strtoupper(trim((string) ($payload['rootdomain_invite_code'] ?? ($payload['invite_code'] ?? ''))));
        $rootdomain = strtolower(rtrim($rootRaw, '.'));
        $prefix = strtolower($prefixRaw);
        $fullsub = ($prefix !== '' && $rootdomain !== '') ? ($prefix . '.' . $rootdomain) : '';
        $cacheKey = sha1(implode('|', [$userId, $prefix, $rootdomain, $inviteCode]));

        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (!empty($_SESSION['cfmod_register_availability_cache'][$cacheKey])) {
                $cached = $_SESSION['cfmod_register_availability_cache'][$cacheKey];
                if (is_array($cached) && intval($cached['expires_at'] ?? 0) >= time() && is_array($cached['data'] ?? null)) {
                    return $cached['data'];
                }
            }
        } catch (\Throwable $ignored) {
        }

        $make = static function (bool $available, string $level, string $reason, string $message, array $extra = []) use ($prefix, $rootdomain, $fullsub, $cacheKey): array {
            $result = array_merge([
                'available' => $available,
                'level' => $level,
                'reason' => $reason,
                'message' => $message,
                'prefix' => $prefix,
                'rootdomain' => $rootdomain,
                'full_domain' => $fullsub,
                'cache_ttl' => 30,
            ], $extra);
            try {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
                if (!isset($_SESSION['cfmod_register_availability_cache']) || !is_array($_SESSION['cfmod_register_availability_cache'])) {
                    $_SESSION['cfmod_register_availability_cache'] = [];
                }
                if (count($_SESSION['cfmod_register_availability_cache']) > 80) {
                    $_SESSION['cfmod_register_availability_cache'] = array_slice($_SESSION['cfmod_register_availability_cache'], -40, null, true);
                }
                $_SESSION['cfmod_register_availability_cache'][$cacheKey] = [
                    'expires_at' => time() + 30,
                    'data' => $result,
                ];
            } catch (\Throwable $ignored) {
            }
            return $result;
        };

        if (!empty($globals['maintenanceMode']) || self::settingEnabled($settings['maintenance_mode'] ?? '0')) {
            return $make(false, 'warning', 'maintenance', self::actionText('cfclient.actions.register.availability.maintenance', '系统维护中，暂时无法注册新域名。'));
        }
        if (!empty($globals['pauseFreeRegistration']) || self::settingEnabled($settings['pause_free_registration'] ?? '0')) {
            return $make(false, 'warning', 'registration_paused', self::actionText('cfclient.actions.register.availability.paused', '当前已暂停免费域名注册，请稍后再试。'));
        }
        if ($userId <= 0) {
            return $make(false, 'warning', 'login_required', self::actionText('cfclient.actions.register.availability.login_required', '请先登录后再检测注册状态。'));
        }
        $banState = (isset($globals['banState']) && is_array($globals['banState']))
            ? $globals['banState']
            : (function_exists('cfmod_resolve_user_ban_state') ? cfmod_resolve_user_ban_state($userId) : ['is_banned' => false, 'reason' => '']);
        if (!empty($globals['isUserBannedOrInactive']) || !empty($banState['is_banned']) || (isset($client->status) && strtolower((string) $client->status) !== 'active')) {
            return $make(false, 'danger', 'user_banned', self::actionText('cfclient.actions.register.availability.banned', '您的账号已被封禁或停用，禁止注册新域名。'));
        }
        if (class_exists('CfInviteRegistrationService') && CfInviteRegistrationService::isGateEnabled($settings)) {
            try {
                if (!CfInviteRegistrationService::userHasUnlocked($userId)) {
                    return $make(false, 'warning', 'invite_gate_locked', self::actionText('cfclient.actions.invite_registration.gate_locked', '首次使用前请先完成准入验证。'));
                }
            } catch (\Throwable $ignored) {
                return $make(false, 'warning', 'invite_gate_locked', self::actionText('cfclient.actions.invite_registration.gate_locked', '首次使用前请先完成准入验证。'));
            }
        }
        if ($prefix === '' || $rootdomain === '') {
            return $make(false, 'muted', 'missing_fields', self::actionText('cfclient.actions.register.availability.missing_fields', '请选择根域名并输入域名前缀。'));
        }

        $prefixLimits = function_exists('cf_get_prefix_length_limits') ? cf_get_prefix_length_limits($settings) : [
            'min' => intval($globals['subPrefixMinLength'] ?? 3),
            'max' => intval($globals['subPrefixMaxLength'] ?? 32),
        ];
        $minLen = max(1, intval($prefixLimits['min'] ?? 3));
        $maxLen = max($minLen, intval($prefixLimits['max'] ?? 32));
        $prefixLen = strlen($prefixRaw);
        if ($prefixLen < $minLen || $prefixLen > $maxLen) {
            return $make(false, 'danger', 'invalid_length', self::actionText('cfclient.actions.register.length_error', '子域名前缀长度必须在%1$s-%2$s个字符之间', [$minLen, $maxLen]));
        }
        if (!preg_match('/^[a-zA-Z0-9\-]+$/', $prefixRaw)) {
            return $make(false, 'danger', 'invalid_chars', self::actionText('cfclient.actions.register.invalid_chars', '子域名前缀只能包含字母、数字和连字符'));
        }
        $hasInvalidEdge = function_exists('cfmod_has_invalid_edge_character')
            ? cfmod_has_invalid_edge_character($prefixRaw)
            : (substr($prefixRaw, 0, 1) === '-' || substr($prefixRaw, -1) === '-' || substr($prefixRaw, 0, 1) === '.' || substr($prefixRaw, -1) === '.');
        if ($hasInvalidEdge) {
            return $make(false, 'danger', 'invalid_edge', self::actionText('cfclient.actions.register.edge_error', "子域名前缀不能以 '.' 或 '-' 开头或结尾"));
        }
        $forbidden = array_map('strtolower', array_map('trim', (array) ($globals['forbidden'] ?? explode(',', (string) ($settings['forbidden_prefix'] ?? 'www,mail,ftp,admin,root,gov,pay,bank')))));
        if (in_array($prefix, $forbidden, true)) {
            return $make(false, 'danger', 'forbidden_prefix', self::actionText('cfclient.actions.register.forbidden_prefix', "该前缀 '%s' 禁止使用", [$prefix]));
        }

        $allowedRoots = array_map(static function ($item) { return strtolower(rtrim(trim((string) $item), '.')); }, (array) ($globals['roots'] ?? []));
        if (!in_array($rootdomain, $allowedRoots, true)) {
            return $make(false, 'danger', 'root_not_allowed', self::actionText('cfclient.actions.register.root_not_allowed', '根域名未被允许注册'));
        }
        $rootMaintenanceMap = is_array($globals['rootMaintenanceMap'] ?? null) ? $globals['rootMaintenanceMap'] : [];
        if (!empty($rootMaintenanceMap[$rootdomain])) {
            return $make(false, 'warning', 'root_maintenance', self::actionText('cfclient.actions.dns.rootdomain_maintenance', '该根域名（%s）正在维护中，暂时无法进行DNS操作，请稍后再试。', [$rootdomain]));
        }

        $quota = $globals['quota'] ?? null;
        if (is_object($quota) && intval($quota->max_count ?? 0) > 0 && intval($quota->used_count ?? 0) >= intval($quota->max_count ?? 0)) {
            return $make(false, 'warning', 'quota_exhausted', self::actionText('cfclient.actions.register.limit_reached', '已达到最大注册数量限制 (%s)', [intval($quota->max_count ?? 0)]));
        }
        if (function_exists('cfmod_check_rootdomain_user_limit')) {
            try {
                $limitCheck = cfmod_check_rootdomain_user_limit($userId, $rootdomain, 1);
                if (empty($limitCheck['allowed'])) {
                    $limitMessage = function_exists('cfmod_format_rootdomain_limit_message')
                        ? cfmod_format_rootdomain_limit_message($rootdomain, $limitCheck['limit'] ?? 0)
                        : '';
                    if ($limitMessage === '') {
                        $limitValueText = max(1, intval($limitCheck['limit'] ?? 0));
                        $limitMessage = self::actionText('cfclient.actions.register.root_user_limit', '%1$s 每个账号最多注册 %2$s 个，您已达到上限', [$rootdomain, $limitValueText]);
                    }
                    return $make(false, 'warning', 'root_user_limit', $limitMessage);
                }
            } catch (\Throwable $ignored) {
            }
        }

        $inviteRequiredMap = is_array($globals['rootInviteRequiredMap'] ?? null) ? $globals['rootInviteRequiredMap'] : [];
        $inviteRequired = !empty($inviteRequiredMap[$rootdomain]);
        if ($inviteRequired && $inviteCode === '') {
            return $make(false, 'warning', 'invite_required', self::actionText('cfclient.actions.rootdomain_invite.code_required', '该根域名需要邀请码才能注册，请输入邀请码。'), ['requires_invite' => true]);
        }
        if ($inviteRequired) {
            $inviteCheck = self::validateRootdomainInviteCodeForAvailability($userId, $rootdomain, $inviteCode);
            if (empty($inviteCheck['valid'])) {
                return $make(false, 'danger', (string) ($inviteCheck['reason'] ?? 'invite_invalid'), (string) ($inviteCheck['message'] ?? self::actionText('cfclient.actions.rootdomain_invite.invalid_code', '邀请码无效，请检查后重试。')), ['requires_invite' => true]);
            }
        }

        try {
            if (Capsule::schema()->hasTable('mod_cloudflare_forbidden_domains')) {
                $isForbidden = Capsule::table('mod_cloudflare_forbidden_domains')
                    ->where('domain', $fullsub)
                    ->exists();
                if ($isForbidden) {
                    return $make(false, 'danger', 'forbidden_domain', self::actionText('cfclient.actions.register.forbidden_domain', '该域名已被禁止注册'));
                }
            }
        } catch (\Throwable $ignored) {
        }
        try {
            $exists = Capsule::table('mod_cloudflare_subdomain')
                ->where('subdomain', $fullsub)
                ->exists();
            if ($exists) {
                return $make(false, 'danger', 'duplicate', self::actionText('cfclient.actions.register.duplicate', "域名 '%s' 已被注册,请更换后重试.", [$fullsub]));
            }
        } catch (\Throwable $ignored) {
            return $make(false, 'warning', 'check_failed', self::actionText('cfclient.register.availability.error', '检测失败，请稍后重试。'));
        }

        return $make(true, 'success', 'available', self::actionText('cfclient.actions.register.availability.available', '子域名可注册'));
    }

    private static function validateRootdomainInviteCodeForAvailability(int $userId, string $rootdomain, string $inviteCode): array
    {
        $inviteCode = strtoupper(trim($inviteCode));
        if ($inviteCode === '') {
            return ['valid' => false, 'reason' => 'invite_required', 'message' => self::actionText('cfclient.actions.rootdomain_invite.code_required', '该根域名需要邀请码才能注册，请输入邀请码。')];
        }
        if (!preg_match('/^[A-Z0-9]{10}$/', $inviteCode)) {
            return ['valid' => false, 'reason' => 'invite_invalid_format', 'message' => self::actionText('cfclient.actions.rootdomain_invite.invalid_code', '邀请码无效，请检查后重试。')];
        }
        try {
            if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomain_invite_codes')) {
                return ['valid' => false, 'reason' => 'invite_unavailable', 'message' => self::actionText('cfclient.actions.rootdomain_invite.error_unavailable', '邀请服务暂不可用，请稍后再试。')];
            }
            $codeRow = Capsule::table('mod_cloudflare_rootdomain_invite_codes')
                ->where('invite_code', $inviteCode)
                ->where('rootdomain', $rootdomain)
                ->first();
            if (!$codeRow) {
                return ['valid' => false, 'reason' => 'invalid_code', 'message' => self::actionText('cfclient.actions.rootdomain_invite.invalid_code', '邀请码无效，请检查后重试。')];
            }
            $inviterId = (int) ($codeRow->userid ?? 0);
            if ($inviterId === $userId) {
                return ['valid' => false, 'reason' => 'self_code', 'message' => self::actionText('cfclient.actions.rootdomain_invite.self_code', '不能使用自己的邀请码。')];
            }
            $inviterStatus = Capsule::table('tblclients')->where('id', $inviterId)->value('status');
            if ($inviterStatus !== null && strtolower((string) $inviterStatus) !== 'active') {
                return ['valid' => false, 'reason' => 'inviter_banned', 'message' => self::actionText('cfclient.actions.rootdomain_invite.inviter_banned', '邀请人账户状态异常，无法使用该邀请码。')];
            }
            if (function_exists('cfmod_resolve_user_ban_state')) {
                $banState = cfmod_resolve_user_ban_state($inviterId);
                if (!empty($banState['is_banned'])) {
                    return ['valid' => false, 'reason' => 'inviter_banned', 'message' => self::actionText('cfclient.actions.rootdomain_invite.inviter_banned', '邀请人账户状态异常，无法使用该邀请码。')];
                }
            }
            if (class_exists('CfRootdomainInviteService') && !CfRootdomainInviteService::checkInviterLimit($inviterId, $rootdomain)) {
                return ['valid' => false, 'reason' => 'inviter_limit_reached', 'message' => self::actionText('cfclient.actions.rootdomain_invite.inviter_limit_reached', '该邀请码已达使用上限。')];
            }
            return ['valid' => true, 'reason' => 'ok'];
        } catch (\Throwable $e) {
            return ['valid' => false, 'reason' => 'invite_check_failed', 'message' => self::actionText('cfclient.actions.rootdomain_invite.error', '邀请码验证失败：%s', [$e->getMessage()])];
        }
    }

    private static function settingEnabled($value): bool
    {
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($value);
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    private function buildScriptPayload(array $settings): array
    {
        return [
            'csrfToken' => $_SESSION['cfmod_csrf'] ?? '',
            'ajaxEndpoints' => $this->buildAjaxEndpoints(),
            'featureFlags' => [
                'domainGift' => !empty($settings['domain_gift_enabled']),
            ],
        ];
    }

    private function buildAjaxEndpoints(): array
    {
        $moduleSlug = defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub';
        $baseParams = self::preferredClientBaseQuery($moduleSlug);

        $buildUrl = static function (string $action) use ($baseParams): string {
            $params = $baseParams;
            $params['module_action'] = $action;
            return self::preferredClientEntryScript() . '?' . http_build_query($params);
        };

        return [
            'createApiKey' => $buildUrl('ajax_create_api_key'),
            'updateApiKeyName' => $buildUrl('ajax_update_api_key_name'),
            'regenerateApiKey' => $buildUrl('ajax_regenerate_api_key'),
            'deleteApiKey' => $buildUrl('ajax_delete_api_key'),
            'disableApiKey' => $buildUrl('ajax_disable_api_key'),
            'enableApiKey' => $buildUrl('ajax_enable_api_key'),
            'helpAiSearch' => $buildUrl('ajax_help_ai_search'),
            'checkSubdomainAvailability' => $buildUrl('ajax_check_subdomain_availability'),
            'domainGift' => [
                'initiate' => $buildUrl('ajax_initiate_domain_gift'),
                'accept' => $buildUrl('ajax_accept_domain_gift'),
                'cancel' => $buildUrl('ajax_cancel_domain_gift'),
                'list' => $buildUrl('ajax_list_domain_gifts'),
                'search' => $buildUrl('ajax_search_domain_gift_subdomains'),
            ],
            'leaderboard' => $buildUrl('realtime_top'),
        ];
    }

    private static function enforceAjaxRateLimit(string $action, int $userId): void
    {
        $scope = self::resolveAjaxRateLimitScope($action);
        if ($scope === null) {
            return;
        }
        $settings = cf_get_module_settings_cached();
        if (!is_array($settings)) {
            $settings = [];
        }
        $limit = CfRateLimiter::resolveLimit($scope, $settings);
        CfRateLimiter::enforce($scope, $limit, [
            'userid' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'identifier' => $action,
        ]);
    }

    private static function resolveAjaxRateLimitScope(string $action): ?string
    {
        if ($action === '') {
            return null;
        }
        if (in_array($action, ['ajax_create_api_key', 'ajax_update_api_key_name', 'ajax_regenerate_api_key', 'ajax_delete_api_key', 'ajax_disable_api_key', 'ajax_enable_api_key'], true)) {
            return CfRateLimiter::SCOPE_API_KEY;
        }
        if (in_array($action, ['ajax_redeem_quota_code', 'ajax_initiate_domain_gift', 'ajax_accept_domain_gift', 'ajax_cancel_domain_gift', 'ajax_search_domain_gift_subdomains'], true)) {
            return CfRateLimiter::SCOPE_QUOTA_GIFT;
        }
        if ($action === 'ajax_help_ai_search') {
            return CfRateLimiter::SCOPE_HELP_AI;
        }
        if (in_array($action, ['ajax_list_quota_redeems', 'ajax_list_domain_gifts'], true)) {
            return null;
        }
        if (strpos($action, 'ajax_') === 0) {
            return CfRateLimiter::SCOPE_AJAX_SENSITIVE;
        }
        return null;
    }

    private static function respondAjaxRateLimitError(CfRateLimitExceededException $e): void
    {
        $minutes = CfRateLimiter::formatRetryMinutes($e->getRetryAfterSeconds());
        $template = cfmod_trans('cfclient.rate_limit.hit', '操作频率过高，请 %s 分钟后再试。');
        try {
            $message = sprintf($template, $minutes);
        } catch (\Throwable $ex) {
            $message = '操作频率过高，请稍后再试。';
        }
        echo json_encode([
            'success' => false,
            'error' => $message,
            'retry_after_minutes' => $minutes,
        ]);
        exit;
    }

    private static function buildHelpAiAccountSnapshot(int $userId): array
    {
        $timezone = date_default_timezone_get();
        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'timezone' => $timezone,
            'snapshot_time' => $now,
            'quota' => [
                'found' => false,
                'used_count' => 0,
                'max_count' => 0,
                'remaining_count' => 0,
            ],
            'expiring_domains' => [],
        ];
        if ($userId <= 0) {
            return $snapshot;
        }
        try {
            $quota = Capsule::table('mod_cloudflare_subdomain_quotas')
                ->select('used_count', 'max_count')
                ->where('userid', $userId)
                ->first();
            if ($quota) {
                $used = max(0, (int) ($quota->used_count ?? 0));
                $max = max(0, (int) ($quota->max_count ?? 0));
                $snapshot['quota'] = [
                    'found' => true,
                    'used_count' => $used,
                    'max_count' => $max,
                    'remaining_count' => max(0, $max - $used),
                ];
            }
        } catch (\Throwable $ignored) {
        }
        try {
            $rows = Capsule::table('mod_cloudflare_subdomain')
                ->select('subdomain', 'rootdomain', 'expires_at')
                ->where('userid', $userId)
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '>=', $now)
                ->orderBy('expires_at', 'asc')
                ->limit(8)
                ->get();
            foreach ($rows as $row) {
                $sub = trim((string) ($row->subdomain ?? ''));
                $root = trim((string) ($row->rootdomain ?? ''));
                $fqdn = self::normalizeHelpAiSnapshotFqdn($sub, $root);
                if ($fqdn === '') {
                    continue;
                }
                $snapshot['expiring_domains'][] = [
                    'fqdn' => $fqdn,
                    'expires_at' => (string) ($row->expires_at ?? ''),
                ];
            }
        } catch (\Throwable $ignored) {
        }
        return $snapshot;
    }

    private static function normalizeHelpAiSnapshotFqdn(string $subdomain, string $rootdomain): string
    {
        $subdomain = rtrim(trim($subdomain), '.');
        $rootdomain = rtrim(trim($rootdomain), '.');
        if ($subdomain === '') {
            return $rootdomain;
        }
        if ($rootdomain === '') {
            return $subdomain;
        }

        $subLower = strtolower($subdomain);
        $rootLower = strtolower($rootdomain);
        if ($subLower === $rootLower || substr($subLower, -strlen('.' . $rootLower)) === '.' . $rootLower) {
            return $subdomain;
        }

        return $subdomain . '.' . $rootdomain;
    }

    private static function shouldBlockActionWhenBanned(string $action): bool
    {
        if ($action === '') {
            return false;
        }
        return strpos($action, 'ajax_') === 0;
    }

    private static function actionText(string $key, string $default, array $params = []): string
    {
        $text = cfmod_trans($key, $default);
        if (!empty($params)) {
            try {
                $text = vsprintf($text, $params);
            } catch (\Throwable $e) {
            }
        }
        return $text;
    }

}
