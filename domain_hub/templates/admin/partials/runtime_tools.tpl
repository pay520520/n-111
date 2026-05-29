<?php
$cfAdminViewModel = $cfAdminViewModel ?? [];
$LANG = $LANG ?? [];
$lang = $LANG['domainHub'] ?? [];
$runtimeView = $runtimeView ?? ($cfAdminViewModel['runtime'] ?? []);
$module_settings = $module_settings ?? [];

$title = $lang['runtime_card_title'] ?? '运行控制';
$pauseLabel = $lang['runtime_pause_free'] ?? '暂停免费域名注册';
$nsLabel = $lang['runtime_disable_ns'] ?? '禁止设置 DNS 服务器（NS 管理）';
$maintenanceLabel = $lang['runtime_maintenance'] ?? '页面维护模式（禁止前台操作）';
$dnsWriteLabel = $lang['runtime_disable_dns_write'] ?? '禁止新增/修改 DNS 解析（仅允许删除）';
$dnsConflictAutoRepairLabel = $lang['runtime_dns_conflict_auto_repair'] ?? '启用 DNS 冲突自动修复（Enable DNS conflict auto-repair）';
$dnsConflictAutoRepairHint = $lang['runtime_dns_conflict_auto_repair_hint'] ?? '开启后：新增 DNS 遇到 exists/conflict 时自动执行 Upsert 自愈并回写本地映射。';
$inviteFeatureLabel = $lang['runtime_hide_invite'] ?? '隐藏“邀请好友解锁注册额度”';
$clientDeleteLabel = $lang['runtime_client_delete'] ?? '启用前台自助删除域名';
$permUpgradeRealtimeFeedLabel = $lang['runtime_perm_upgrade_realtime_feed'] ?? '启用前台“永久升级实时动态”';
$permUpgradeRealtimeFeedHint = $lang['runtime_perm_upgrade_realtime_feed_hint'] ?? '关闭后前台永久升级弹窗将隐藏“实时升级动态”滚动信息。';
$privilegedAllowSuspendedRootLabel = $lang['runtime_privileged_allow_suspended_root'] ?? '特权用户可注册已停止根域';
$privilegedUnlimitedInviteLabel = $lang['runtime_privileged_unlimited_invite'] ?? '特权用户邀请码不受邀请次数限制';
$privilegedForceNeverExpireLabel = $lang['runtime_privileged_force_never_expire'] ?? '特权用户域名默认永久有效';
$privilegedDeleteHistoryLabel = $lang['runtime_privileged_delete_with_dns_history'] ?? '特权用户可删除有解析历史域名';
$syncInviteLabel = $lang['runtime_sync_invite'] ?? '当全局上限变大时同步提升未定制用户上限';
$clientPageSizeLabel = $lang['runtime_client_page_size'] ?? '前端每页子域名数量';
$cleanupIntervalLabel = $lang['runtime_cleanup_interval'] ?? '过期域名清理间隔（小时）';
$cleanupIntervalHint = $lang['runtime_cleanup_interval_hint'] ?? '最小1小时，最大168小时，设置越小清理越频繁。';
$maintenanceMsgLabel = $lang['runtime_maintenance_message'] ?? '维护说明（展示给前台用户）';
$saveLabel = $lang['runtime_save_button'] ?? '保存设置';
$infoNote = $lang['runtime_quick_toggle_hint'] ?? '快速停止/开启根域名的注册已移动至本卡片下方的独立区域，避免与“保存设置”表单冲突。';
$quickToggleLabel = $lang['runtime_quick_toggle_label'] ?? '在此快速停止/开启某个根域名的注册';
$quickLimitLabel = $lang['runtime_quick_limit_label'] ?? '快速设置单个用户注册上限（0 = 不限制）';
$applyLabel = $lang['runtime_apply_button'] ?? '应用';
$perUserLabel = $lang['runtime_per_user_label'] ?? '个/用户';
$saveLimitLabel = $lang['runtime_save_limit_button'] ?? '保存';
$cleanupTitle = $lang['runtime_cleanup_title'] ?? '根域名本地清理（不影响云端）';
$cleanupIntro = $lang['runtime_cleanup_intro'] ?? '该操作会删除指定根域名下的所有本地子域名及关联数据，并自动归还用户配额，不会调用阿里云 API，云端解析保持不变。';
$cleanupSelectLabel = $lang['runtime_cleanup_select'] ?? '选择已有根域名';
$cleanupTarget = $lang['runtime_cleanup_target'] ?? '目标根域名';
$cleanupConfirm = $lang['runtime_cleanup_confirm'] ?? '确认根域名';
$cleanupBatch = $lang['runtime_cleanup_batch'] ?? '每批处理数量';
$cleanupHint = $lang['runtime_cleanup_hint'] ?? '本地清理不会删除阿里云中的任何记录，如需同步清理云端请在任务完成后手动处理。';
$cleanupButton = $lang['runtime_cleanup_button'] ?? '开始本地清理';
$runNowLabel = $lang['runtime_cleanup_run_now'] ?? '提交后立即尝试执行一次队列';
$selectPlaceholder = $lang['runtime_select_placeholder'] ?? '请选择一个根域名';
$maintenanceTextareaPlaceholder = $lang['runtime_maintenance_placeholder'] ?? '例如：系统维护中，预计北京时间 02:00-03:00 完成。感谢理解。';

$rootRows = $runtimeView['rootdomains'] ?? [];
$cfmodQuickRootOptions = [];
foreach ($rootRows as $r) {
    $optionLabel = htmlspecialchars($r->domain ?? '') . ' (' . htmlspecialchars($r->status ?? '') . ')';
    $cfmodQuickRootOptions[] = '<option value="id-' . intval($r->id ?? 0) . '">' . $optionLabel . '</option>';
}
$cfmodQuickRootOptionsHtml = implode("\n", $cfmodQuickRootOptions);

$maintenanceMessage = $module_settings['maintenance_message'] ?? '';
$clientPageSizeSetting = $module_settings['client_page_size'] ?? 20;
$cleanupIntervalSetting = intval($module_settings['domain_cleanup_interval_minutes'] ?? 1440);
if ($cleanupIntervalSetting < 5) { $cleanupIntervalSetting = 1440; }
if ($cleanupIntervalSetting > 9999) { $cleanupIntervalSetting = 9999; }
$rateLimitSettings = [
    'rate_limit_register_per_hour' => intval($module_settings['rate_limit_register_per_hour'] ?? 30),
    'rate_limit_dns_per_hour' => intval($module_settings['rate_limit_dns_per_hour'] ?? 120),
    'rate_limit_api_key_per_hour' => intval($module_settings['rate_limit_api_key_per_hour'] ?? 20),
    'rate_limit_quota_gift_per_hour' => intval($module_settings['rate_limit_quota_gift_per_hour'] ?? 20),
    'rate_limit_ajax_per_hour' => intval($module_settings['rate_limit_ajax_per_hour'] ?? 60),
    'rate_limit_dns_unlock_per_hour' => intval($module_settings['rate_limit_dns_unlock_per_hour'] ?? 10),
    'rate_limit_perm_incentive_per_hour' => intval($module_settings['rate_limit_perm_incentive_per_hour'] ?? 10),
];
$riskBatchLabel = $lang['runtime_risk_batch_label'] ?? '风险扫描批量大小';
$riskBatchHint = $lang['runtime_risk_batch_hint'] ?? '每次风险扫描处理的子域数量（10-1000）';
$riskBatchValue = intval($module_settings['risk_scan_batch_size'] ?? 50);

$dnsUnlockLabel = $lang['dns_unlock_label'] ?? '启用 DNS 解锁';
$dnsUnlockHint = $lang['dns_unlock_hint'] ?? '用户需要输入解锁码后才允许设置 NS 服务器。';
$dnsUnlockEnabled = in_array($module_settings['enable_dns_unlock'] ?? '0', ['1','on'], true);
$dnsUnlockShareLabel = $lang['dns_unlock_share_label'] ?? '允许分享解锁码';
$dnsUnlockShareHint = $lang['dns_unlock_share_hint'] ?? '关闭后仅保留付费解锁入口，用户界面将隐藏解锁码输入区域。';
$dnsUnlockShareEnabledSetting = in_array($module_settings['dns_unlock_share_enabled'] ?? '1', ['1','on'], true);
$dnsUnlockPurchaseLabel = $lang['dns_unlock_purchase_label'] ?? '启用余额付费解锁';
$dnsUnlockPurchaseHint = $lang['dns_unlock_purchase_hint'] ?? '允许用户通过账户余额购买解锁权限';
$dnsUnlockPurchasePriceLabel = $lang['dns_unlock_purchase_price_label'] ?? '解锁费用（账户币种）';
$dnsUnlockPurchaseEnabledSetting = in_array($module_settings['dns_unlock_purchase_enabled'] ?? '0', ['1','on'], true);
$dnsUnlockPurchasePriceSetting = (float) ($module_settings['dns_unlock_purchase_price'] ?? 0);
$supportTicketLabel = $lang['runtime_support_ticket_label'] ?? '前台工单链接';
$supportTicketHint = $lang['runtime_support_ticket_hint'] ?? '显示在左侧菜单“提交工单”入口，默认 submitticket.php';
$supportGroupLabel = $lang['runtime_support_group_label'] ?? '前台交流群链接';
$supportGroupHint = $lang['runtime_support_group_hint'] ?? '显示在左侧菜单“交流群组”入口，支持 TG/Discord 等链接';
$supportTicketUrlSetting = trim((string) ($module_settings['client_support_ticket_url'] ?? '')) ?: 'submitticket.php';
$supportGroupUrlSetting = trim((string) ($module_settings['client_support_group_url'] ?? '')) ?: 'https://t.me/+l9I5TNRDLP5lZDBh';
$helpAiEnableLabel = $lang['runtime_help_ai_enable'] ?? '启用帮助中心 AI 搜索/问答';
$helpAiEnableHint = $lang['runtime_help_ai_enable_hint'] ?? '开启后，前台帮助中心将出现 AI 搜索/问答按钮。';
$helpAiFabEnableLabel = $lang['runtime_help_ai_fab_enable'] ?? '首页显示 AI 悬浮按钮';
$helpAiFabEnableHint = $lang['runtime_help_ai_fab_enable_hint'] ?? '控制首页（域名管理）右下角机器人悬浮入口是否显示。';
$helpAiProviderLabel = $lang['runtime_help_ai_provider'] ?? 'AI 提供商';
$helpAiProviderHint = $lang['runtime_help_ai_provider_hint'] ?? '当前支持 Alibaba Cloud Qwen（OpenAI Compatible）';
$helpAiAssistantNameLabel = $lang['runtime_help_ai_assistant_name'] ?? 'AI 助手名称';
$helpAiAssistantNameHint = $lang['runtime_help_ai_assistant_name_hint'] ?? '显示在帮助中心对话弹窗中的名称。支持中英双语格式：中文名|English Name';
$helpAiSystemPromptLabel = $lang['runtime_help_ai_system_prompt'] ?? 'AI 系统提示词';
$helpAiSystemPromptHint = $lang['runtime_help_ai_system_prompt_hint'] ?? '建议限制回答范围为本插件的域名、DNS、API 与帮助中心相关内容。';
$helpAiMaxInputLabel = $lang['runtime_help_ai_max_input'] ?? '单次提问最大长度';
$helpAiMaxInputHint = $lang['runtime_help_ai_max_input_hint'] ?? '限制 200-2000 字符，防止过长请求。';
$helpAiQwenKeyLabel = 'Qwen API Key';
$helpAiQwenModelLabel = 'Qwen 主模型';
$helpAiQwenModelHint = '建议使用可在当前账号/区域访问的模型名称，例如 qwen3.6-flash。';
$helpAiQwenFallbackModelLabel = 'Qwen 备用模型';
$helpAiQwenFallbackModelHint = '主模型失败时自动回退，建议使用低成本模型如 qwen3.5-flash。';
$helpAiEnabledSetting = in_array($module_settings['enable_help_ai_search'] ?? '0', ['1','on','yes','true'], true);
$helpAiFabEnabledSetting = !array_key_exists('help_ai_fab_enabled', $module_settings)
    ? true
    : in_array($module_settings['help_ai_fab_enabled'] ?? '1', ['1','on','yes','true'], true);
$helpAiProviderSetting = 'qwen';
$helpAiAssistantNameSetting = trim((string) ($module_settings['help_ai_assistant_name'] ?? 'AI 助手'));
if ($helpAiAssistantNameSetting === '') {
    $helpAiAssistantNameSetting = 'AI 助手';
}
$helpAiSystemPromptSetting = trim((string) ($module_settings['help_ai_system_prompt'] ?? ''));
$helpAiKbSourceSetting = strtolower(trim((string) ($module_settings['help_ai_kb_source'] ?? 'mixed')));
if (!in_array($helpAiKbSourceSetting, ['db','static','mixed'], true)) { $helpAiKbSourceSetting = 'mixed'; }
$helpAiIncludeModuleHelpSetting = !array_key_exists('help_ai_include_module_help', $module_settings)
    ? true
    : in_array($module_settings['help_ai_include_module_help'] ?? '1', ['1','on','yes','true','enabled'], true);
$helpAiKbRefreshSetting = max(1, min(1440, intval($module_settings['help_ai_kb_refresh_minutes'] ?? 30)));
$helpAiMaxInputSetting = max(200, min(2000, intval($module_settings['help_ai_max_input_chars'] ?? 600)));
$helpAiQwenModelSetting = trim((string) ($module_settings['help_ai_qwen_model'] ?? 'qwen3.6-flash'));
if ($helpAiQwenModelSetting === '') {
    $helpAiQwenModelSetting = 'qwen3.6-flash';
}
$helpAiQwenFallbackModelSetting = trim((string) ($module_settings['help_ai_qwen_fallback_model'] ?? 'qwen3.5-flash'));
if ($helpAiQwenFallbackModelSetting === '') {
    $helpAiQwenFallbackModelSetting = 'qwen3.5-flash';
}
$helpAiQwenKeyStored = trim((string) ($module_settings['help_ai_qwen_api_key'] ?? ''));
$helpAiQwenKeyConfigured = $helpAiQwenKeyStored !== '';
if ($helpAiQwenKeyConfigured && strpos($helpAiQwenKeyStored, 'enc::') === 0 && function_exists('cfmod_decrypt_sensitive')) {
    $helpAiQwenKeyConfigured = trim((string) cfmod_decrypt_sensitive(substr($helpAiQwenKeyStored, strlen('enc::')))) !== '';
}
$helpAiQwenKeyPlaceholder = $helpAiQwenKeyConfigured ? '留空表示保持当前 Key 不变' : 'sk-...';
$jobWarnSecondsSetting = max(5, min(3600, intval($module_settings['job_warn_seconds'] ?? 45)));
$jobFailRetryBackoffSetting = trim((string) ($module_settings['job_fail_retry_backoff'] ?? '1,2,4,8,16'));
if ($jobFailRetryBackoffSetting === '') { $jobFailRetryBackoffSetting = '1,2,4,8,16'; }
$maxJobsPerMinuteSetting = max(0, min(10000, intval($module_settings['max_jobs_per_minute'] ?? 0)));
$githubStarEnableLabel = $lang['runtime_github_star_enable'] ?? '启用 GitHub 点赞奖励';
$githubStarEnableHint = $lang['runtime_github_star_enable_hint'] ?? '用户完成 GitHub 点赞后可领取额外注册额度。';
$githubStarRepoLabel = $lang['runtime_github_star_repo'] ?? 'GitHub 仓库地址';
$githubStarRepoHint = $lang['runtime_github_star_repo_hint'] ?? '例如：https://github.com/owner/repo';
$githubStarRewardLabel = $lang['runtime_github_star_reward'] ?? '点赞奖励额度';
$githubStarRewardHint = $lang['runtime_github_star_reward_hint'] ?? '每位用户每个仓库仅可领取一次。';
$githubStarEnabledSetting = in_array($module_settings['enable_github_star_reward'] ?? '0', ['1','on','yes','true'], true);
$githubStarRepoSetting = trim((string) ($module_settings['github_star_repo_url'] ?? ''));
$githubStarRewardSetting = max(1, intval($module_settings['github_star_reward_amount'] ?? 1));
$telegramGroupEnableLabel = $lang['runtime_telegram_group_enable'] ?? '启用 Telegram 社群奖励';
$telegramGroupEnableHint = $lang['runtime_telegram_group_enable_hint'] ?? '用户加入指定社群并通过 Telegram 身份验证后可领取额度。';
$telegramGroupLinkLabel = $lang['runtime_telegram_group_link'] ?? 'Telegram 群组链接';
$telegramGroupLinkHint = $lang['runtime_telegram_group_link_hint'] ?? '例如：https://t.me/your_group';
$telegramGroupChatIdLabel = $lang['runtime_telegram_group_chat_id'] ?? 'Telegram 群组 Chat ID';
$telegramGroupChatIdHint = $lang['runtime_telegram_group_chat_id_hint'] ?? '例如：-1001234567890（必须填写，供 Bot API 校验成员）';
$telegramGroupBotUsernameLabel = $lang['runtime_telegram_group_bot_username'] ?? 'Telegram 机器人用户名';
$telegramGroupBotUsernameHint = $lang['runtime_telegram_group_bot_username_hint'] ?? '用于前台 Telegram 登录控件，填写 @botname 或 botname。';
$telegramGroupBotTokenLabel = $lang['runtime_telegram_group_bot_token'] ?? 'Telegram Bot Token';
$telegramGroupBotTokenHint = $lang['runtime_telegram_group_bot_token_hint'] ?? '用于调用 Telegram Bot API 校验成员身份。';
$telegramGroupRewardLabel = $lang['runtime_telegram_group_reward'] ?? '社群奖励额度';
$telegramGroupRewardHint = $lang['runtime_telegram_group_reward_hint'] ?? '每位 Telegram 账号仅可领取一次。';
$telegramAuthMaxAgeLabel = $lang['runtime_telegram_auth_max_age'] ?? '授权有效期（秒）';
$telegramAuthMaxAgeHint = $lang['runtime_telegram_auth_max_age_hint'] ?? '限制 Telegram 登录回传数据的最大有效期（60-604800）。';
$telegramGroupEnabledSetting = in_array($module_settings['enable_telegram_group_reward'] ?? '0', ['1','on','yes','true'], true);
$telegramGroupLinkSetting = trim((string) ($module_settings['telegram_group_link'] ?? ''));
$telegramGroupChatIdSetting = trim((string) ($module_settings['telegram_group_chat_id'] ?? ''));
$telegramGroupBotUsernameSetting = trim((string) ($module_settings['telegram_group_bot_username'] ?? ''));
$telegramGroupBotTokenStored = trim((string) ($module_settings['telegram_group_bot_token'] ?? ''));
$telegramGroupBotTokenConfigured = $telegramGroupBotTokenStored !== '';
if ($telegramGroupBotTokenConfigured && strpos($telegramGroupBotTokenStored, 'enc::') === 0 && function_exists('cfmod_decrypt_sensitive')) {
    $telegramGroupBotTokenConfigured = trim((string) cfmod_decrypt_sensitive(substr($telegramGroupBotTokenStored, strlen('enc::')))) !== '';
}
$telegramGroupBotTokenPlaceholder = $telegramGroupBotTokenConfigured ? '留空表示保持当前 Token 不变' : '123456:ABCDEF...';
$telegramGroupRewardSetting = max(1, intval($module_settings['telegram_group_reward_amount'] ?? 1));
$telegramAuthMaxAgeSetting = max(60, min(604800, intval($module_settings['telegram_reward_auth_max_age_seconds'] ?? 86400)));

$inviteGateModeLabel = $lang['runtime_invite_gate_mode'] ?? '新用户准入模式';
$inviteGateModeHint = $lang['runtime_invite_gate_mode_hint'] ?? '支持邀请码 / GitHub / Telegram 组合；Telegram 授权需先在 BotFather 配置域名白名单。';
$inviteTelegramBotUsernameLabel = $lang['runtime_invite_telegram_bot_username'] ?? '准入 Telegram Bot 用户名';
$inviteTelegramBotUsernameHint = $lang['runtime_invite_telegram_bot_username_hint'] ?? '留空时回退使用社群奖励 Bot 用户名。';
$inviteTelegramBotTokenLabel = $lang['runtime_invite_telegram_bot_token'] ?? '准入 Telegram Bot Token';
$inviteTelegramBotTokenHint = $lang['runtime_invite_telegram_bot_token_hint'] ?? '留空时回退使用社群奖励 Bot Token；用于静默验证 Telegram 授权回传。';
$inviteTelegramAuthAgeLabel = $lang['runtime_invite_telegram_auth_age'] ?? '准入授权有效期（秒）';
$inviteTelegramAuthAgeHint = $lang['runtime_invite_telegram_auth_age_hint'] ?? '限制 Telegram 静默授权数据时效（60-604800）。';
$inviteGateModeSetting = trim((string) ($module_settings['invite_registration_gate_mode'] ?? 'disabled'));
$inviteGateModeOptions = [
    'disabled' => '关闭准入验证',
    'invite_only' => '仅邀请码',
    'github_only' => '仅 GitHub',
    'telegram_only' => '仅 Telegram（静默授权）',
    'invite_or_github' => '邀请码 / GitHub 二选一',
    'invite_or_telegram' => '邀请码 / Telegram 二选一',
    'github_or_telegram' => 'GitHub / Telegram 二选一',
    'invite_or_github_or_telegram' => '邀请码 / GitHub / Telegram 三选一',
];
if (!array_key_exists($inviteGateModeSetting, $inviteGateModeOptions)) {
    $inviteGateModeSetting = 'disabled';
}
$inviteTelegramBotUsernameSetting = trim((string) ($module_settings['invite_registration_telegram_bot_username'] ?? ''));
$inviteTelegramBotTokenStored = trim((string) ($module_settings['invite_registration_telegram_bot_token'] ?? ''));
$inviteTelegramBotTokenConfigured = $inviteTelegramBotTokenStored !== '';
if ($inviteTelegramBotTokenConfigured && strpos($inviteTelegramBotTokenStored, 'enc::') === 0 && function_exists('cfmod_decrypt_sensitive')) {
    $inviteTelegramBotTokenConfigured = trim((string) cfmod_decrypt_sensitive(substr($inviteTelegramBotTokenStored, strlen('enc::')))) !== '';
}
$inviteTelegramBotTokenPlaceholder = $inviteTelegramBotTokenConfigured ? '留空表示保持当前 Token 不变（未填写则回退社群奖励 Token）' : '123456:ABCDEF...（留空回退社群奖励 Token）';
$inviteTelegramAuthAgeSetting = max(60, min(604800, intval($module_settings['invite_registration_telegram_auth_max_age_seconds'] ?? ($module_settings['telegram_reward_auth_max_age_seconds'] ?? 86400))));

$orphanTitle = $lang['runtime_orphan_title'] ?? '孤儿记录扫描与清理';
$orphanIntro = $lang['runtime_orphan_intro'] ?? '扫描本地存在但云端已删除的解析记录，可选择只统计或直接删除。';
$orphanRootLabel = $lang['runtime_orphan_root'] ?? '筛选根域名（可选）';
$orphanLimitLabel = $lang['runtime_orphan_limit'] ?? '扫描子域数量';
$orphanModeLabel = $lang['runtime_orphan_mode'] ?? '执行模式';
$orphanModeDry = $lang['runtime_orphan_mode_dry'] ?? '干跑（仅统计，不删除）';
$orphanModeDelete = $lang['runtime_orphan_mode_delete'] ?? '删除孤儿记录';
$orphanHint = $lang['runtime_orphan_hint'] ?? '提示：操作会实时调用 DNS API，请根据供应商限额合理设置数量。';
$orphanButton = $lang['runtime_orphan_button'] ?? '开始扫描';
$orphanCursorLabel = $lang['runtime_orphan_cursor_label'] ?? '游标策略';
$orphanCursorResume = $lang['runtime_orphan_cursor_resume'] ?? '从上次游标继续';
$orphanCursorReset = $lang['runtime_orphan_cursor_reset'] ?? '从头开始';
$orphanCursorCurrentFmt = $lang['runtime_orphan_cursor_current'] ?? '当前默认游标：%s';
$orphanCursorListFmt = $lang['runtime_orphan_cursor_list'] ?? '各根域游标：%s';

$renewalCardTitle = $lang['renewal_notice_title'] ?? '域名到期提醒';
$renewalEnableLabel = $lang['renewal_notice_enable'] ?? '启用到期提醒邮件';
$renewalTemplateLabel = $lang['renewal_notice_template_label'] ?? '邮件模板名称';
$renewalDaysPrimaryLabel = $lang['renewal_notice_days_primary_label'] ?? '首次提醒（天）';
$renewalDaysSecondaryLabel = $lang['renewal_notice_days_secondary_label'] ?? '二次提醒（天）';
$renewalDaysHint = $lang['renewal_notice_days_hint'] ?? '输入正整数天数，留空或 0 表示关闭';
$renewalTemplateHint = $lang['renewal_notice_template_hint'] ?? '模板可使用 {$domain}、{$rootdomain}、{$fqdn}、{$expiry_date}、{$days_left}';
$renewalSaveLabel = $lang['renewal_notice_save_button'] ?? '保存到期提醒设置';
$renewalTestTitle = $lang['renewal_notice_test_title'] ?? '邮件测试发送';
$renewalTestDomainLabel = $lang['renewal_notice_test_domain_label'] ?? '子域名';
$renewalTestIdLabel = $lang['renewal_notice_test_id_label'] ?? '子域名ID';
$renewalTestDaysLabel = $lang['renewal_notice_test_days_label'] ?? '提醒天数';
$renewalTestEmailLabel = $lang['renewal_notice_test_email_label'] ?? '覆盖收件邮箱（可选）';
$renewalTestButton = $lang['renewal_notice_test_button'] ?? '发送测试邮件';
$renewalVariablesHint = $lang['renewal_notice_variables_hint'] ?? '{$domain} / {$rootdomain} / {$fqdn} / {$expiry_date} / {$days_left}';
$renewalTelegramTitle = $lang['renewal_notice_telegram_title'] ?? 'Telegram 到期提醒';
$renewalTelegramEnableLabel = $lang['renewal_notice_telegram_enable'] ?? '启用 Telegram 到期提醒';
$renewalTelegramBotUserLabel = $lang['renewal_notice_telegram_bot_user'] ?? 'Bot 用户名';
$renewalTelegramBotTokenLabel = $lang['renewal_notice_telegram_bot_token'] ?? 'Bot Token';
$renewalTelegramDaysLabel = $lang['renewal_notice_telegram_days'] ?? 'Telegram 提醒天数';
$renewalTelegramAuthAgeLabel = $lang['renewal_notice_telegram_auth_age'] ?? '授权有效期（秒）';
$renewalTelegramTemplateZhLabel = $lang['renewal_notice_telegram_template_zh_label'] ?? 'Telegram 中文模板';
$renewalTelegramTemplateEnLabel = $lang['renewal_notice_telegram_template_en_label'] ?? 'Telegram 英文模板';
$renewalTelegramTemplateHint = $lang['renewal_notice_telegram_template_hint'] ?? '支持变量 {$domain}、{$rootdomain}、{$fqdn}、{$expiry_date}、{$expiry_datetime}、{$days_left}、{$reminder_days}，可换行。';
$renewalTelegramHint = $lang['renewal_notice_telegram_hint'] ?? '支持 1~2 个提醒天数（逗号分隔），如 30,10；填 0 表示关闭该档位。';
$renewalTelegramTestTitle = $lang['renewal_notice_telegram_test_title'] ?? 'Telegram 测试发送';
$renewalTelegramTestDomainLabel = $lang['renewal_notice_telegram_test_domain_label'] ?? '子域名';
$renewalTelegramTestIdLabel = $lang['renewal_notice_telegram_test_id_label'] ?? '子域名 ID';
$renewalTelegramTestDaysLabel = $lang['renewal_notice_telegram_test_days_label'] ?? '提醒天数';
$renewalTelegramTestUserIdLabel = $lang['renewal_notice_telegram_test_user_id_label'] ?? '覆盖 Telegram 用户ID（可选）';
$renewalTelegramTestButton = $lang['renewal_notice_telegram_test_button'] ?? '发送测试 Telegram 消息';

$renewalEnabledSetting = in_array($module_settings['renewal_notice_enabled'] ?? '0', ['1','on','yes','true'], true);
$renewalTemplateName = $module_settings['renewal_notice_template'] ?? '';
$renewalDay1Setting = $module_settings['renewal_notice_days_primary'] ?? '';
$renewalDay2Setting = $module_settings['renewal_notice_days_secondary'] ?? '';
$renewalTelegramEnabledSetting = in_array($module_settings['renewal_notice_telegram_enabled'] ?? '0', ['1','on','yes','true'], true);
$renewalTelegramBotUsername = ltrim((string) ($module_settings['renewal_notice_telegram_bot_username'] ?? ''), '@');
$renewalTelegramTemplateLegacySetting = (string) ($module_settings['renewal_notice_telegram_template'] ?? '');
$renewalTelegramTemplateZhSetting = (string) ($module_settings['renewal_notice_telegram_template_zh'] ?? $renewalTelegramTemplateLegacySetting);
if ($renewalTelegramTemplateZhSetting === '' && class_exists('CfTelegramExpiryReminderService')) {
    $renewalTelegramTemplateZhSetting = CfTelegramExpiryReminderService::defaultTemplate();
}
$renewalTelegramTemplateEnSetting = (string) ($module_settings['renewal_notice_telegram_template_en'] ?? '');
if ($renewalTelegramTemplateEnSetting === '' && class_exists('CfTelegramExpiryReminderService') && method_exists('CfTelegramExpiryReminderService', 'defaultEnglishTemplate')) {
    $renewalTelegramTemplateEnSetting = CfTelegramExpiryReminderService::defaultEnglishTemplate();
}
$renewalTelegramDaysSetting = (string) ($module_settings['renewal_notice_telegram_days'] ?? '30,10');
$renewalTelegramAuthAgeSetting = (string) ($module_settings['renewal_notice_telegram_auth_max_age_seconds'] ?? '86400');
$renewalTelegramTokenRaw = trim((string) ($module_settings['renewal_notice_telegram_bot_token'] ?? ''));
$renewalTelegramTokenConfigured = $renewalTelegramTokenRaw !== '';

$orphanSummary = $runtimeView['orphanCursors'] ?? ['default' => 0, 'list' => []];
$orphanCursorDefaultValue = intval($orphanSummary['default'] ?? 0);
$orphanCursorList = $orphanSummary['list'] ?? [];
$orphanCursorListText = '';
if (!empty($orphanCursorList)) {
    $pairs = [];
    foreach (array_slice($orphanCursorList, 0, 5) as $entry) {
        $pairs[] = ($entry['rootdomain'] ?? '') . ':' . ($entry['cursor'] ?? 0);
    }
    if (count($orphanCursorList) > 5) {
        $pairs[] = '...';
    }
    $orphanCursorListText = sprintf($orphanCursorListFmt, implode('，', $pairs));
}

$orphanRootOptions = [];
foreach ($rootRows as $rootRow) {
    $domainValue = strtolower(trim((string)($rootRow->domain ?? '')));
    if ($domainValue === '') { continue; }
    $orphanRootOptions[] = '<option value="' . htmlspecialchars($domainValue) . '">' . htmlspecialchars($rootRow->domain ?? '') . '</option>';
}
$cfmodOrphanRootOptionsHtml = implode("\n", $orphanRootOptions);
?>

<div class="card mb-4" id="runtime-control">
  <div class="card-body">
    <h5 class="card-title mb-3"><i class="fas fa-toggle-on"></i> <?php echo htmlspecialchars($title); ?></h5>
    <form method="post" class="row g-3 align-items-center">
      <input type="hidden" name="action" value="save_runtime_switches">
      <div class="col-12 col-md-8 col-lg-6">
        <label class="form-label" for="runtime_preset_profile">运行环境模板（可一键套用）</label>
        <div class="input-group">
          <select class="form-select" id="runtime_preset_profile" name="runtime_preset_profile">
            <option value="">不套用模板（仅保存当前表单）</option>
            <option value="dev">开发/测试环境（低并发，高日志）</option>
            <option value="small">小站生产（稳妥）</option>
            <option value="medium">中站生产（推荐）</option>
            <option value="large">大站生产（高吞吐）</option>
          </select>
          <span class="input-group-text">模板</span>
        </div>
        <small class="text-muted">选择模板后会一键覆盖队列并发、清理批次、限速与日志保留等关键配置。</small>
      </div>
      <div class="col-12 col-md-4 col-lg-3">
        <div class="form-check form-switch mt-md-4 pt-md-2">
          <input class="form-check-input" type="checkbox" id="runtime_preset_apply" name="runtime_preset_apply" value="1">
          <label class="form-check-label" for="runtime_preset_apply">应用模板覆盖关键配置</label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="pause_free_registration" name="pause_free_registration" value="1" <?php echo (isset($module_settings['pause_free_registration']) && in_array($module_settings['pause_free_registration'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="pause_free_registration"><?php echo htmlspecialchars($pauseLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="disable_ns_management" name="disable_ns_management" value="1" <?php echo (isset($module_settings['disable_ns_management']) && in_array($module_settings['disable_ns_management'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="disable_ns_management"><?php echo htmlspecialchars($nsLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?php echo (isset($module_settings['maintenance_mode']) && in_array($module_settings['maintenance_mode'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="maintenance_mode"><?php echo htmlspecialchars($maintenanceLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="disable_dns_write" name="disable_dns_write" value="1" <?php echo (isset($module_settings['disable_dns_write']) && in_array($module_settings['disable_dns_write'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="disable_dns_write"><?php echo htmlspecialchars($dnsWriteLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="dns_conflict_auto_repair_enabled" name="dns_conflict_auto_repair_enabled" value="1" <?php echo (!isset($module_settings['dns_conflict_auto_repair_enabled']) || in_array($module_settings['dns_conflict_auto_repair_enabled'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="dns_conflict_auto_repair_enabled"><?php echo htmlspecialchars($dnsConflictAutoRepairLabel); ?></label>
          <div class="small text-muted"><?php echo htmlspecialchars($dnsConflictAutoRepairHint); ?></div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="dns_repair_post_update_verify_enabled" name="dns_repair_post_update_verify_enabled" value="1" <?php echo (!isset($module_settings['dns_repair_post_update_verify_enabled']) || in_array($module_settings['dns_repair_post_update_verify_enabled'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="dns_repair_post_update_verify_enabled">DNS 自愈写后回读校验</label>
          <div class="small text-muted">开启后，Upsert 自愈 update 成功后会回读校验云端记录，确认一致才回填本地。</div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="hide_invite_feature" name="hide_invite_feature" value="1" <?php echo (isset($module_settings['hide_invite_feature']) && in_array($module_settings['hide_invite_feature'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="hide_invite_feature"><?php echo htmlspecialchars($inviteFeatureLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <?php
          $clientDeleteModeSetting = strtolower(trim((string)($module_settings['client_domain_delete_mode'] ?? '')));
          if (!in_array($clientDeleteModeSetting, ['disabled','allow_all','never_had_dns_only','no_current_dns_only'], true)) {
              $clientDeleteModeSetting = (isset($module_settings['enable_client_domain_delete']) && in_array($module_settings['enable_client_domain_delete'], ['1','on'], true))
                  ? 'never_had_dns_only'
                  : 'disabled';
          }
        ?>
        <label class="form-label" for="client_domain_delete_mode"><?php echo htmlspecialchars($clientDeleteLabel); ?></label>
        <select class="form-select" id="client_domain_delete_mode" name="client_domain_delete_mode">
          <option value="disabled" <?php echo $clientDeleteModeSetting === 'disabled' ? 'selected' : ''; ?>>关闭前台自助删除</option>
          <option value="allow_all" <?php echo $clientDeleteModeSetting === 'allow_all' ? 'selected' : ''; ?>>允许全部自助删除</option>
          <option value="never_had_dns_only" <?php echo $clientDeleteModeSetting === 'never_had_dns_only' ? 'selected' : ''; ?>>仅允许从未有过解析记录</option>
          <option value="no_current_dns_only" <?php echo $clientDeleteModeSetting === 'no_current_dns_only' ? 'selected' : ''; ?>>允许当前无解析记录（历史不限）</option>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="client_domain_delete_gray_enabled" name="client_domain_delete_gray_enabled" value="1" <?php echo (isset($module_settings['client_domain_delete_gray_enabled']) && in_array($module_settings['client_domain_delete_gray_enabled'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="client_domain_delete_gray_enabled">自助删除策略灰度开关</label>
        </div>
        <div class="small text-muted">默认“从未有解析可删”时，灰度命中用户可升级为“当前无解析可删（历史不限）”。</div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="client_domain_delete_gray_ratio">自助删除灰度比例（0-100）</label>
        <input type="number" class="form-control" id="client_domain_delete_gray_ratio" name="client_domain_delete_gray_ratio" min="0" max="100" value="<?php echo intval($module_settings['client_domain_delete_gray_ratio'] ?? 0); ?>">
      </div>
      <div class="col-12">
        <hr>
        <h6 class="text-muted mb-2"><i class="fas fa-th-large"></i> 功能中心卡片布局</h6>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="client_feature_cards_order">卡片顺序（feature key）</label>
        <input type="text" class="form-control" id="client_feature_cards_order" name="client_feature_cards_order" value="<?php echo htmlspecialchars((string)($module_settings['client_feature_cards_order'] ?? '')); ?>" placeholder="quota_redeem,dns_unlock,invite_registration,permanent_upgrade,permanent_incentive,root_invite,orphan_cleanup,ssl_request,github_star_reward,telegram_group_reward,expiry_telegram_reminder,root_verify,dig_tools">
        <small class="text-muted">逗号分隔。未配置的新卡会自动追加到末尾，便于后续扩展。</small>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="client_feature_cards_hidden">隐藏卡片（feature key）</label>
        <input type="text" class="form-control" id="client_feature_cards_hidden" name="client_feature_cards_hidden" value="<?php echo htmlspecialchars((string)($module_settings['client_feature_cards_hidden'] ?? '')); ?>" placeholder="dig_tools,root_verify">
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="client_feature_cards_new_badge">NEW/HOT 标记</label>
        <input type="text" class="form-control" id="client_feature_cards_new_badge" name="client_feature_cards_new_badge" value="<?php echo htmlspecialchars((string)($module_settings['client_feature_cards_new_badge'] ?? '')); ?>" placeholder='{"ssl_request":"NEW","permanent_incentive":"HOT"}'>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="domain_permanent_upgrade_enable_realtime_feed" name="domain_permanent_upgrade_enable_realtime_feed" value="1" <?php echo (!isset($module_settings['domain_permanent_upgrade_enable_realtime_feed']) || in_array($module_settings['domain_permanent_upgrade_enable_realtime_feed'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="domain_permanent_upgrade_enable_realtime_feed"><?php echo htmlspecialchars($permUpgradeRealtimeFeedLabel); ?></label>
          <div class="small text-muted"><?php echo htmlspecialchars($permUpgradeRealtimeFeedHint); ?></div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="privileged_allow_register_suspended_root" name="privileged_allow_register_suspended_root" value="1" <?php echo (isset($module_settings['privileged_allow_register_suspended_root']) && in_array($module_settings['privileged_allow_register_suspended_root'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="privileged_allow_register_suspended_root"><?php echo htmlspecialchars($privilegedAllowSuspendedRootLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="privileged_unlimited_invite_generation" name="privileged_unlimited_invite_generation" value="1" <?php echo (!isset($module_settings['privileged_unlimited_invite_generation']) || in_array($module_settings['privileged_unlimited_invite_generation'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="privileged_unlimited_invite_generation"><?php echo htmlspecialchars($privilegedUnlimitedInviteLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="privileged_force_never_expire" name="privileged_force_never_expire" value="1" <?php echo (!isset($module_settings['privileged_force_never_expire']) || in_array($module_settings['privileged_force_never_expire'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="privileged_force_never_expire"><?php echo htmlspecialchars($privilegedForceNeverExpireLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="privileged_allow_delete_with_dns_history" name="privileged_allow_delete_with_dns_history" value="1" <?php echo (isset($module_settings['privileged_allow_delete_with_dns_history']) && in_array($module_settings['privileged_allow_delete_with_dns_history'], ['1','on','yes','true'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="privileged_allow_delete_with_dns_history"><?php echo htmlspecialchars($privilegedDeleteHistoryLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="sync_invite_limit_up_only" name="sync_invite_limit_up_only" value="1" <?php echo (isset($module_settings['sync_invite_limit_up_only']) && in_array($module_settings['sync_invite_limit_up_only'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="sync_invite_limit_up_only"><?php echo htmlspecialchars($syncInviteLabel); ?></label>
        </div>
      </div>
      <div class="col-12">
        <hr class="my-2">
        <h6 class="mb-2"><i class="fas fa-vials"></i> 灰度管理中心（白名单强制放行）</h6>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="gray_whitelist_userids">白名单用户ID（逗号或换行分隔）</label>
        <textarea class="form-control" id="gray_whitelist_userids" name="gray_whitelist_userids" rows="4" placeholder="例如: 1,2,3"><?php echo htmlspecialchars((string)($module_settings['gray_whitelist_userids'] ?? '')); ?></textarea>
        <div class="form-text">命中后可强制放行所有灰度功能（根域名灰度、永久激励灰度等）。</div>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="gray_whitelist_emails">白名单邮箱（逗号或换行分隔）</label>
        <textarea class="form-control" id="gray_whitelist_emails" name="gray_whitelist_emails" rows="4" placeholder="例如: user@example.com"><?php echo htmlspecialchars((string)($module_settings['gray_whitelist_emails'] ?? '')); ?></textarea>
        <div class="form-text">邮箱匹配不区分大小写；建议填写主账号邮箱。</div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="gray_paid_priority_enabled" name="gray_paid_priority_enabled" value="1" <?php echo (isset($module_settings['gray_paid_priority_enabled']) && in_array($module_settings['gray_paid_priority_enabled'], ['1','on','yes','true','enabled'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="gray_paid_priority_enabled">充值用户优先灰度命中</label>
        </div>
        <div class="small text-muted">开启后，近时间窗内有已支付账单的用户可直接命中灰度（白名单仍然最高优先级）。</div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="gray_paid_priority_window_days">充值优先有效期（天）</label>
        <input type="number" class="form-control" id="gray_paid_priority_window_days" name="gray_paid_priority_window_days" min="1" max="3650" value="<?php echo intval($module_settings['gray_paid_priority_window_days'] ?? 90); ?>">
        <div class="form-text">统计最近 N 天内 Paid / Collections 且金额大于 0 的账单。</div>
      </div>
      <div class="col-12 col-md-4">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="client_orphan_dns_cleanup_enabled" name="client_orphan_dns_cleanup_enabled" value="1" <?php echo (isset($module_settings['client_orphan_dns_cleanup_enabled']) && in_array($module_settings['client_orphan_dns_cleanup_enabled'], ['1','on'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="client_orphan_dns_cleanup_enabled">启用前台“域名记录冲突清理”</label>
          <div class="small text-muted">开启后，用户可在功能中心发起“删除所选域名云端全部 DNS 记录”操作。</div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <?php $orphanCleanupMode = strtolower(trim((string)($module_settings['client_orphan_dns_cleanup_mode'] ?? 'queue'))); ?>
        <?php if (!in_array($orphanCleanupMode, ['queue', 'sync'], true)) { $orphanCleanupMode = 'queue'; } ?>
        <label class="form-label" for="client_orphan_dns_cleanup_mode">前台冲突清理执行模式</label>
        <select class="form-select" id="client_orphan_dns_cleanup_mode" name="client_orphan_dns_cleanup_mode">
          <option value="queue" <?php echo $orphanCleanupMode === 'queue' ? 'selected' : ''; ?>>异步队列（推荐）</option>
          <option value="sync" <?php echo $orphanCleanupMode === 'sync' ? 'selected' : ''; ?>>同步执行（小记录量）</option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="client_page_size"><?php echo htmlspecialchars($clientPageSizeLabel); ?></label>
        <div class="input-group">
          <input type="number" class="form-control" id="client_page_size" name="client_page_size" min="1" max="20" value="<?php echo htmlspecialchars($clientPageSizeSetting); ?>">
          <span class="input-group-text">条/页</span>
        </div>
        <small class="text-muted">可设置 1-20 条，超过部分自动限制为 20</small>
      </div>
      <div class="col-12">
        <hr>
        <h6 class="text-muted mb-2"><i class="fas fa-tachometer-alt"></i> 请求级限速（单个用户 / 每小时，0 表示不限制）</h6>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_register_per_hour">域名注册</label>
        <input type="number" class="form-control" id="rate_limit_register_per_hour" name="rate_limit_register_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_register_per_hour']); ?>">
        <small class="text-muted">限制注册/赠送入口</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_dns_per_hour">DNS 写操作</label>
        <input type="number" class="form-control" id="rate_limit_dns_per_hour" name="rate_limit_dns_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_dns_per_hour']); ?>">
        <small class="text-muted">新增/修改/删除解析</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_api_key_per_hour">API 密钥操作</label>
        <input type="number" class="form-control" id="rate_limit_api_key_per_hour" name="rate_limit_api_key_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_api_key_per_hour']); ?>">
        <small class="text-muted">创建/重置/删除</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_quota_gift_per_hour">兑换 &amp; 转赠</label>
        <input type="number" class="form-control" id="rate_limit_quota_gift_per_hour" name="rate_limit_quota_gift_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_quota_gift_per_hour']); ?>">
        <small class="text-muted">配额兑换 / 域名礼物</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_ajax_per_hour">敏感 AJAX</label>
        <input type="number" class="form-control" id="rate_limit_ajax_per_hour" name="rate_limit_ajax_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_ajax_per_hour']); ?>">
        <small class="text-muted">客户端异步写操作</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_dns_unlock_per_hour">DNS 解锁</label>
        <input type="number" class="form-control" id="rate_limit_dns_unlock_per_hour" name="rate_limit_dns_unlock_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_dns_unlock_per_hour']); ?>">
        <small class="text-muted">限制解锁码 / 余额解锁请求</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="rate_limit_perm_incentive_per_hour">永久激励提交</label>
        <input type="number" class="form-control" id="rate_limit_perm_incentive_per_hour" name="rate_limit_perm_incentive_per_hour" min="0" value="<?php echo htmlspecialchars($rateLimitSettings['rate_limit_perm_incentive_per_hour']); ?>">
        <small class="text-muted">限制“提交检测升级”请求频率</small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="risk_scan_batch_size"><?php echo htmlspecialchars($riskBatchLabel); ?></label>
        <div class="input-group">
          <input type="number" class="form-control" id="risk_scan_batch_size" name="risk_scan_batch_size" min="10" max="1000" value="<?php echo htmlspecialchars($riskBatchValue); ?>">
          <span class="input-group-text">子域/批</span>
        </div>
        <small class="text-muted"><?php echo htmlspecialchars($riskBatchHint); ?></small>
      </div>
      <div class="col-12 col-md-4 col-xl-3">
        <label class="form-label" for="domain_cleanup_interval_minutes"><?php echo htmlspecialchars($cleanupIntervalLabel); ?></label>
        <div class="input-group">
          <input type="number" class="form-control" id="domain_cleanup_interval_minutes" name="domain_cleanup_interval_minutes" min="5" max="9999" value="<?php echo htmlspecialchars($cleanupIntervalSetting); ?>">
          <span class="input-group-text">min</span>
        </div>
        <small class="text-muted"><?php echo htmlspecialchars($cleanupIntervalHint); ?></small>
      </div>
      <div class="col-12">
        <div class="alert alert-primary mb-2">
          <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($infoNote); ?>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label" for="maintenance_message"><?php echo htmlspecialchars($maintenanceMsgLabel); ?></label>
        <textarea class="form-control" id="maintenance_message" name="maintenance_message" rows="3" placeholder="<?php echo htmlspecialchars($maintenanceTextareaPlaceholder); ?>"><?php echo htmlspecialchars($maintenanceMessage); ?></textarea>
        <small class="text-muted">当开启维护模式时，将在用户界面显示此说明。</small>
      </div>
      <div class="col-12">
        <hr>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="enable_dns_unlock" name="enable_dns_unlock" value="1" <?php echo $dnsUnlockEnabled ? 'checked' : ''; ?>>
          <label class="form-check-label" for="enable_dns_unlock"><?php echo htmlspecialchars($dnsUnlockLabel); ?></label>
        </div>
        <small class="text-muted d-block mb-2"><?php echo htmlspecialchars($dnsUnlockHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="dns_unlock_share_enabled" name="dns_unlock_share_enabled" value="1" <?php echo !empty($dnsUnlockShareEnabledSetting) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="dns_unlock_share_enabled"><?php echo htmlspecialchars($dnsUnlockShareLabel); ?></label>
        </div>
        <small class="text-muted d-block mb-2"><?php echo htmlspecialchars($dnsUnlockShareHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="dns_unlock_purchase_enabled" name="dns_unlock_purchase_enabled" value="1" <?php echo !empty($dnsUnlockPurchaseEnabledSetting) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="dns_unlock_purchase_enabled"><?php echo htmlspecialchars($dnsUnlockPurchaseLabel); ?></label>
        </div>
        <small class="text-muted d-block mb-2"><?php echo htmlspecialchars($dnsUnlockPurchaseHint); ?></small>
      </div>
      <div class="col-12 col-lg-4 col-xl-3">
        <label class="form-label" for="dns_unlock_purchase_price"><?php echo htmlspecialchars($dnsUnlockPurchasePriceLabel); ?></label>
        <div class="input-group">
          <input type="number" class="form-control" id="dns_unlock_purchase_price" name="dns_unlock_purchase_price" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format($dnsUnlockPurchasePriceSetting, 2, '.', '')); ?>">
        </div>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="client_support_ticket_url"><?php echo htmlspecialchars($supportTicketLabel); ?></label>
        <input type="text" class="form-control" id="client_support_ticket_url" name="client_support_ticket_url" value="<?php echo htmlspecialchars($supportTicketUrlSetting); ?>" placeholder="submitticket.php">
        <small class="text-muted"><?php echo htmlspecialchars($supportTicketHint); ?></small>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="client_support_group_url"><?php echo htmlspecialchars($supportGroupLabel); ?></label>
        <input type="text" class="form-control" id="client_support_group_url" name="client_support_group_url" value="<?php echo htmlspecialchars($supportGroupUrlSetting); ?>" placeholder="https://t.me/your-group">
        <small class="text-muted"><?php echo htmlspecialchars($supportGroupHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mt-2">
          <input class="form-check-input" type="checkbox" id="enable_github_star_reward" name="enable_github_star_reward" value="1" <?php echo $githubStarEnabledSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="enable_github_star_reward"><?php echo htmlspecialchars($githubStarEnableLabel); ?></label>
        </div>
        <small class="text-muted d-block"><?php echo htmlspecialchars($githubStarEnableHint); ?></small>
      </div>
      <div class="col-12 col-lg-5">
        <label class="form-label" for="github_star_repo_url"><?php echo htmlspecialchars($githubStarRepoLabel); ?></label>
        <input type="text" class="form-control" id="github_star_repo_url" name="github_star_repo_url" value="<?php echo htmlspecialchars($githubStarRepoSetting); ?>" placeholder="https://github.com/owner/repo">
        <small class="text-muted"><?php echo htmlspecialchars($githubStarRepoHint); ?></small>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="github_star_reward_amount"><?php echo htmlspecialchars($githubStarRewardLabel); ?></label>
        <input type="number" class="form-control" id="github_star_reward_amount" name="github_star_reward_amount" min="1" max="1000" value="<?php echo htmlspecialchars($githubStarRewardSetting); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($githubStarRewardHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mt-2">
          <input class="form-check-input" type="checkbox" id="enable_telegram_group_reward" name="enable_telegram_group_reward" value="1" <?php echo $telegramGroupEnabledSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="enable_telegram_group_reward"><?php echo htmlspecialchars($telegramGroupEnableLabel); ?></label>
        </div>
        <small class="text-muted d-block"><?php echo htmlspecialchars($telegramGroupEnableHint); ?></small>
      </div>
      <div class="col-12 col-lg-8">
        <label class="form-label" for="telegram_group_link"><?php echo htmlspecialchars($telegramGroupLinkLabel); ?></label>
        <input type="text" class="form-control" id="telegram_group_link" name="telegram_group_link" value="<?php echo htmlspecialchars($telegramGroupLinkSetting); ?>" placeholder="https://t.me/your_group">
        <small class="text-muted"><?php echo htmlspecialchars($telegramGroupLinkHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="telegram_group_chat_id"><?php echo htmlspecialchars($telegramGroupChatIdLabel); ?></label>
        <input type="text" class="form-control" id="telegram_group_chat_id" name="telegram_group_chat_id" value="<?php echo htmlspecialchars($telegramGroupChatIdSetting); ?>" placeholder="-1001234567890">
        <small class="text-muted"><?php echo htmlspecialchars($telegramGroupChatIdHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="telegram_group_bot_username"><?php echo htmlspecialchars($telegramGroupBotUsernameLabel); ?></label>
        <input type="text" class="form-control" id="telegram_group_bot_username" name="telegram_group_bot_username" value="<?php echo htmlspecialchars($telegramGroupBotUsernameSetting); ?>" placeholder="my_reward_bot">
        <small class="text-muted"><?php echo htmlspecialchars($telegramGroupBotUsernameHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="telegram_group_bot_token"><?php echo htmlspecialchars($telegramGroupBotTokenLabel); ?></label>
        <input type="password" class="form-control" id="telegram_group_bot_token" name="telegram_group_bot_token" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars($telegramGroupBotTokenPlaceholder); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($telegramGroupBotTokenHint); ?><?php if ($telegramGroupBotTokenConfigured): ?>（已检测到历史 Token，留空将保持不变）<?php endif; ?></small>
      </div>
      <div class="col-12 col-lg-2">
        <label class="form-label" for="telegram_group_reward_amount"><?php echo htmlspecialchars($telegramGroupRewardLabel); ?></label>
        <input type="number" class="form-control" id="telegram_group_reward_amount" name="telegram_group_reward_amount" min="1" max="1000" value="<?php echo htmlspecialchars($telegramGroupRewardSetting); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($telegramGroupRewardHint); ?></small>
      </div>
      <div class="col-12 col-lg-2">
        <label class="form-label" for="telegram_reward_auth_max_age_seconds"><?php echo htmlspecialchars($telegramAuthMaxAgeLabel); ?></label>
        <input type="number" class="form-control" id="telegram_reward_auth_max_age_seconds" name="telegram_reward_auth_max_age_seconds" min="60" max="604800" step="60" value="<?php echo htmlspecialchars($telegramAuthMaxAgeSetting); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($telegramAuthMaxAgeHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="invite_registration_gate_mode"><?php echo htmlspecialchars($inviteGateModeLabel); ?></label>
        <select class="form-select" id="invite_registration_gate_mode" name="invite_registration_gate_mode">
          <?php foreach ($inviteGateModeOptions as $modeValue => $modeLabel): ?>
            <option value="<?php echo htmlspecialchars($modeValue, ENT_QUOTES); ?>" <?php echo $inviteGateModeSetting === $modeValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($modeLabel); ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted"><?php echo htmlspecialchars($inviteGateModeHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="invite_registration_telegram_bot_username"><?php echo htmlspecialchars($inviteTelegramBotUsernameLabel); ?></label>
        <input type="text" class="form-control" id="invite_registration_telegram_bot_username" name="invite_registration_telegram_bot_username" value="<?php echo htmlspecialchars($inviteTelegramBotUsernameSetting); ?>" placeholder="my_invite_bot">
        <small class="text-muted"><?php echo htmlspecialchars($inviteTelegramBotUsernameHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="invite_registration_telegram_bot_token"><?php echo htmlspecialchars($inviteTelegramBotTokenLabel); ?></label>
        <input type="password" class="form-control" id="invite_registration_telegram_bot_token" name="invite_registration_telegram_bot_token" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars($inviteTelegramBotTokenPlaceholder); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($inviteTelegramBotTokenHint); ?><?php if ($inviteTelegramBotTokenConfigured): ?>（已检测到历史 Token，留空将保持不变）<?php endif; ?></small>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="invite_registration_telegram_auth_max_age_seconds"><?php echo htmlspecialchars($inviteTelegramAuthAgeLabel); ?></label>
        <input type="number" class="form-control" id="invite_registration_telegram_auth_max_age_seconds" name="invite_registration_telegram_auth_max_age_seconds" min="60" max="604800" step="60" value="<?php echo htmlspecialchars($inviteTelegramAuthAgeSetting); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($inviteTelegramAuthAgeHint); ?></small>
      </div>
      <div class="col-12 col-lg-3">
        <div class="form-check form-switch mt-2">
          <input class="form-check-input" type="checkbox" id="invite_registration_balance_unlock_gray_enabled" name="invite_registration_balance_unlock_gray_enabled" value="1" <?php echo (isset($module_settings['invite_registration_balance_unlock_gray_enabled']) && in_array($module_settings['invite_registration_balance_unlock_gray_enabled'], ['1','on','yes','true','enabled'], true)) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="invite_registration_balance_unlock_gray_enabled">余额解锁灰度开关</label>
        </div>
        <small class="text-muted">开启后，仅命中灰度的用户可使用“邀请准入余额解锁”（白名单/充值优先可放行）。</small>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="invite_registration_balance_unlock_gray_ratio">余额解锁灰度比例（0-100）</label>
        <input type="number" class="form-control" id="invite_registration_balance_unlock_gray_ratio" name="invite_registration_balance_unlock_gray_ratio" min="0" max="100" value="<?php echo intval($module_settings['invite_registration_balance_unlock_gray_ratio'] ?? 100); ?>">
        <small class="text-muted">仅在灰度开关开启时生效；100=全量开放，0=不开放。</small>
      </div>

      <div class="col-12">
        <hr>
        <h6 class="text-muted mb-2"><i class="fas fa-robot"></i> 帮助中心 AI 搜索 / 问答</h6>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mt-2">
          <input class="form-check-input" type="checkbox" id="enable_help_ai_search" name="enable_help_ai_search" value="1" <?php echo $helpAiEnabledSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="enable_help_ai_search"><?php echo htmlspecialchars($helpAiEnableLabel); ?></label>
        </div>
        <small class="text-muted d-block"><?php echo htmlspecialchars($helpAiEnableHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <div class="form-check form-switch mt-2">
          <input class="form-check-input" type="checkbox" id="help_ai_fab_enabled" name="help_ai_fab_enabled" value="1" <?php echo $helpAiFabEnabledSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="help_ai_fab_enabled"><?php echo htmlspecialchars($helpAiFabEnableLabel); ?></label>
        </div>
        <small class="text-muted d-block"><?php echo htmlspecialchars($helpAiFabEnableHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="help_ai_provider"><?php echo htmlspecialchars($helpAiProviderLabel); ?></label>
        <select class="form-select" id="help_ai_provider" name="help_ai_provider">
          <option value="qwen" selected>Alibaba Cloud Qwen API</option>
        </select>
        <small class="text-muted"><?php echo htmlspecialchars($helpAiProviderHint); ?></small>
      </div>

      <div class="col-12 col-lg-4">
        <label class="form-label" for="help_ai_kb_source">帮助中心 AI 知识库来源</label>
        <select class="form-select" id="help_ai_kb_source" name="help_ai_kb_source">
          <option value="mixed" <?php echo $helpAiKbSourceSetting === 'mixed' ? 'selected' : ''; ?>>混合（推荐）</option>
          <option value="db" <?php echo $helpAiKbSourceSetting === 'db' ? 'selected' : ''; ?>>数据库（Help/Knowledge Base）</option>
          <option value="static" <?php echo $helpAiKbSourceSetting === 'static' ? 'selected' : ''; ?>>内置静态</option>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <div class="form-check mt-4 pt-2">
          <input class="form-check-input" type="checkbox" id="help_ai_include_module_help" name="help_ai_include_module_help" value="1" <?php echo $helpAiIncludeModuleHelpSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="help_ai_include_module_help">纳入 /view=help 问答</label>
        </div>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="help_ai_kb_refresh_minutes">知识库刷新间隔（分钟）</label>
        <input type="number" class="form-control" id="help_ai_kb_refresh_minutes" name="help_ai_kb_refresh_minutes" min="1" max="1440" value="<?php echo htmlspecialchars($helpAiKbRefreshSetting); ?>">
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="help_ai_assistant_name"><?php echo htmlspecialchars($helpAiAssistantNameLabel); ?></label>
        <input type="text" class="form-control" id="help_ai_assistant_name" name="help_ai_assistant_name" maxlength="60" value="<?php echo htmlspecialchars($helpAiAssistantNameSetting); ?>" placeholder="AI 助手">
        <small class="text-muted"><?php echo htmlspecialchars($helpAiAssistantNameHint); ?></small>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label" for="help_ai_max_input_chars"><?php echo htmlspecialchars($helpAiMaxInputLabel); ?></label>
        <input type="number" class="form-control" id="help_ai_max_input_chars" name="help_ai_max_input_chars" min="200" max="2000" value="<?php echo htmlspecialchars($helpAiMaxInputSetting); ?>">
        <small class="text-muted"><?php echo htmlspecialchars($helpAiMaxInputHint); ?></small>
      </div>
      <div class="col-12 col-lg-9">
        <label class="form-label" for="help_ai_system_prompt"><?php echo htmlspecialchars($helpAiSystemPromptLabel); ?></label>
        <textarea class="form-control" id="help_ai_system_prompt" name="help_ai_system_prompt" rows="3" placeholder="你是 domain_hub 帮助中心助手，仅回答插件相关问题。若超出范围请引导用户提交工单。"><?php echo htmlspecialchars($helpAiSystemPromptSetting); ?></textarea>
        <small class="text-muted"><?php echo htmlspecialchars($helpAiSystemPromptHint); ?></small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="help_ai_qwen_api_key">Qwen API Key</label>
        <input type="password" class="form-control" id="help_ai_qwen_api_key" name="help_ai_qwen_api_key" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars($helpAiQwenKeyPlaceholder); ?>">
        <small class="text-muted"><?php if ($helpAiQwenKeyConfigured): ?>已检测到历史 Key，留空将保持不变。<?php else: ?>用于 Alibaba Cloud Qwen API 鉴权。<?php endif; ?></small>
      </div>
      <div class="col-12 col-lg-8">
        <label class="form-label" for="help_ai_qwen_model">Qwen 主模型</label>
        <input type="text" class="form-control" id="help_ai_qwen_model" name="help_ai_qwen_model" value="<?php echo htmlspecialchars($helpAiQwenModelSetting); ?>" placeholder="qwen3.6-flash">
        <small class="text-muted">候选：Qwen3.7-Max / Qwen3.6-Plus / Qwen3.6-Flash / Qwen3.5-Flash（可编辑）</small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="help_ai_qwen_fallback_model">Qwen 备用模型</label>
        <input type="text" class="form-control" id="help_ai_qwen_fallback_model" name="help_ai_qwen_fallback_model" value="<?php echo htmlspecialchars($helpAiQwenFallbackModelSetting); ?>" placeholder="qwen3.5-flash">
        <small class="text-muted">主模型调用失败时自动回退该模型。</small>
      </div>
      <div class="col-12 col-lg-8">
        <label class="form-label" for="help_ai_provider">AI 提供商</label>
        <input type="text" class="form-control" value="qwen" disabled>
        <small class="text-muted">当前仅支持 Qwen。</small>
      </div>
      <div class="col-12">
        <hr>
        <h6 class="text-muted mb-2"><i class="fas fa-wave-square"></i> 队列可观测性与限流</h6>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="job_warn_seconds">慢任务告警阈值（秒）</label>
        <input type="number" class="form-control" id="job_warn_seconds" name="job_warn_seconds" min="5" max="3600" value="<?php echo htmlspecialchars($jobWarnSecondsSetting); ?>">
        <small class="text-muted">超过该耗时将记为慢任务并写入统计。</small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="job_fail_retry_backoff">失败重试退避（分钟）</label>
        <input type="text" class="form-control" id="job_fail_retry_backoff" name="job_fail_retry_backoff" value="<?php echo htmlspecialchars($jobFailRetryBackoffSetting); ?>" placeholder="1,2,4,8,16">
        <small class="text-muted">逗号分隔，按尝试次数映射退避分钟数。</small>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label" for="max_jobs_per_minute">每分钟最大处理任务数</label>
        <input type="number" class="form-control" id="max_jobs_per_minute" name="max_jobs_per_minute" min="0" max="10000" value="<?php echo htmlspecialchars($maxJobsPerMinuteSetting); ?>">
        <small class="text-muted">0 表示不限制。</small>
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-primary mt-2"><?php echo htmlspecialchars($saveLabel); ?></button>
      </div>
    </form>
    <hr>
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label"><?php echo htmlspecialchars($quickToggleLabel); ?></label>
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="action" value="set_rootdomain_status">
          <select name="rootdomain_id" class="form-select">
            <?php echo $cfmodQuickRootOptionsHtml; ?>
          </select>
          <select name="new_status" class="form-select">
            <option value="active">开启注册</option>
            <option value="suspended">停止注册</option>
          </select>
          <button type="submit" class="btn btn-outline-primary"><?php echo htmlspecialchars($applyLabel); ?></button>
        </form>
      </div>
      <div class="col-12 col-md-5 col-xl-6">
        <label class="form-label"><?php echo htmlspecialchars($quickLimitLabel); ?></label>
        <form method="post" class="d-flex flex-wrap gap-2">
          <input type="hidden" name="action" value="set_rootdomain_limit">
          <select name="rootdomain_id" class="form-select">
            <?php echo $cfmodQuickRootOptionsHtml; ?>
          </select>
          <div class="input-group" style="max-width: 220px;">
            <input type="number" name="per_user_limit" class="form-control" min="0" value="0">
            <span class="input-group-text"><?php echo htmlspecialchars($perUserLabel); ?></span>
          </div>
          <button type="submit" class="btn btn-outline-secondary"><?php echo htmlspecialchars($saveLimitLabel); ?></button>
        </form>
        <small class="text-muted d-block mt-1">设置为 0 表示不限；仅影响新增注册，已拥有的域名不会被回收。</small>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4 border-warning">
  <div class="card-body">
    <h5 class="card-title mb-3 text-warning"><i class="fas fa-eraser"></i> <?php echo htmlspecialchars($cleanupTitle); ?></h5>
    <div class="alert alert-warning small mb-3">
      <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($cleanupIntro); ?>
    </div>
    <form method="post" class="row g-3" onsubmit="return confirm('确认仅删除本地数据并归还额度？该操作不可撤销。');">
      <input type="hidden" name="action" value="purge_rootdomain_local">
      <div class="col-12 col-md-4">
        <label class="form-label" for="cf-purge-root-select"><?php echo htmlspecialchars($cleanupSelectLabel); ?></label>
        <select class="form-select" id="cf-purge-root-select">
          <option value=""><?php echo htmlspecialchars($selectPlaceholder); ?></option>
          <?php
            $purgeOptions = [];
            foreach ($rootRows as $rootRow) {
                $value = strtolower($rootRow->domain ?? '');
                if ($value === '') { continue; }
                $purgeOptions[] = '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($rootRow->domain ?? '') . ' (' . htmlspecialchars($rootRow->status ?? '') . ')</option>';
            }
            echo implode("\n", $purgeOptions);
          ?>
        </select>
        <small class="text-muted">选择后将自动填入下方输入框。</small>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="cf-purge-root-input"><?php echo htmlspecialchars($cleanupTarget); ?></label>
        <input type="text" class="form-control" name="target_rootdomain" id="cf-purge-root-input" placeholder="例如 a.com" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label"><?php echo htmlspecialchars($cleanupConfirm); ?></label>
        <input type="text" class="form-control" name="confirm_rootdomain" placeholder="再次输入以确认" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label"><?php echo htmlspecialchars($cleanupBatch); ?></label>
        <input type="number" class="form-control" name="batch_size" min="20" max="5000" value="200">
        <small class="text-muted">建议 20-500，特殊场景可临时提升（最高 5,000）。值越大单次处理的记录越多。</small>
      </div>
      <div class="col-12 col-md-4 d-flex align-items-center">
        <div class="form-check form-switch mt-4">
          <input class="form-check-input" type="checkbox" name="run_now" value="1" id="cf-purge-run-now" checked>
          <label class="form-check-label" for="cf-purge-run-now"><?php echo htmlspecialchars($runNowLabel); ?></label>
        </div>
      </div>
      <div class="col-12">
        <small class="text-muted"><?php echo htmlspecialchars($cleanupHint); ?></small>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i> <?php echo htmlspecialchars($cleanupButton); ?></button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-4" id="renewal-notice">
  <div class="card-body">
    <h5 class="card-title mb-3"><i class="fas fa-envelope-open-text"></i> <?php echo htmlspecialchars($renewalCardTitle); ?></h5>
    <form method="post" class="row g-3 align-items-end mb-3">
      <input type="hidden" name="action" value="save_renewal_notice_settings">
      <div class="col-12 col-md-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="renewal_notice_enabled" name="renewal_notice_enabled" value="1" <?php echo $renewalEnabledSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="renewal_notice_enabled"><?php echo htmlspecialchars($renewalEnableLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="renewal_notice_template"><?php echo htmlspecialchars($renewalTemplateLabel); ?></label>
        <input type="text" class="form-control" id="renewal_notice_template" name="renewal_notice_template" value="<?php echo htmlspecialchars($renewalTemplateName); ?>" placeholder="Domain Expiry Reminder">
        <small class="text-muted"><?php echo htmlspecialchars($renewalTemplateHint); ?></small>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="renewal_notice_days_primary"><?php echo htmlspecialchars($renewalDaysPrimaryLabel); ?></label>
        <input type="number" class="form-control" id="renewal_notice_days_primary" name="renewal_notice_days_primary" min="0" value="<?php echo htmlspecialchars($renewalDay1Setting); ?>" placeholder="180">
        <small class="text-muted d-block"><?php echo htmlspecialchars($renewalDaysHint); ?></small>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="renewal_notice_days_secondary"><?php echo htmlspecialchars($renewalDaysSecondaryLabel); ?></label>
        <input type="number" class="form-control" id="renewal_notice_days_secondary" name="renewal_notice_days_secondary" min="0" value="<?php echo htmlspecialchars($renewalDay2Setting); ?>" placeholder="7">
      </div>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12">
        <h6 class="mb-1"><?php echo htmlspecialchars($renewalTelegramTitle); ?></h6>
        <small class="text-muted"><?php echo htmlspecialchars($renewalTelegramHint); ?></small>
      </div>
      <div class="col-12 col-md-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="renewal_notice_telegram_enabled" name="renewal_notice_telegram_enabled" value="1" <?php echo $renewalTelegramEnabledSetting ? 'checked' : ''; ?>>
          <label class="form-check-label" for="renewal_notice_telegram_enabled"><?php echo htmlspecialchars($renewalTelegramEnableLabel); ?></label>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="renewal_notice_telegram_bot_username"><?php echo htmlspecialchars($renewalTelegramBotUserLabel); ?></label>
        <input type="text" class="form-control" id="renewal_notice_telegram_bot_username" name="renewal_notice_telegram_bot_username" value="<?php echo htmlspecialchars($renewalTelegramBotUsername); ?>" placeholder="your_bot">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="renewal_notice_telegram_bot_token"><?php echo htmlspecialchars($renewalTelegramBotTokenLabel); ?></label>
        <input type="password" class="form-control" id="renewal_notice_telegram_bot_token" name="renewal_notice_telegram_bot_token" value="" placeholder="<?php echo htmlspecialchars($renewalTelegramTokenConfigured ? '已配置，留空表示不变' : '123456789:AA...'); ?>" autocomplete="new-password">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="renewal_notice_telegram_days"><?php echo htmlspecialchars($renewalTelegramDaysLabel); ?></label>
        <input type="text" class="form-control" id="renewal_notice_telegram_days" name="renewal_notice_telegram_days" value="<?php echo htmlspecialchars($renewalTelegramDaysSetting); ?>" placeholder="30,10">
      </div>
      <div class="col-12 col-md-1">
        <label class="form-label" for="renewal_notice_telegram_auth_max_age_seconds"><?php echo htmlspecialchars($renewalTelegramAuthAgeLabel); ?></label>
        <input type="number" class="form-control" id="renewal_notice_telegram_auth_max_age_seconds" name="renewal_notice_telegram_auth_max_age_seconds" min="60" value="<?php echo htmlspecialchars($renewalTelegramAuthAgeSetting); ?>" placeholder="86400">
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="renewal_notice_telegram_template_zh"><?php echo htmlspecialchars($renewalTelegramTemplateZhLabel); ?></label>
        <textarea class="form-control" id="renewal_notice_telegram_template_zh" name="renewal_notice_telegram_template_zh" rows="4" placeholder="【域名到期提醒】\n域名：{$fqdn}\n到期时间：{$expiry_datetime}\n剩余天数：{$days_left} 天\n请及时续期，避免域名失效。"><?php echo htmlspecialchars($renewalTelegramTemplateZhSetting); ?></textarea>
      </div>
      <div class="col-12 col-lg-6">
        <label class="form-label" for="renewal_notice_telegram_template_en"><?php echo htmlspecialchars($renewalTelegramTemplateEnLabel); ?></label>
        <textarea class="form-control" id="renewal_notice_telegram_template_en" name="renewal_notice_telegram_template_en" rows="4" placeholder="[Domain Expiry Reminder]\nDomain: {$fqdn}\nExpiry Time: {$expiry_datetime}\nDays Left: {$days_left}\nPlease renew in time to avoid domain suspension."><?php echo htmlspecialchars($renewalTelegramTemplateEnSetting); ?></textarea>
      </div>
      <div class="col-12">
        <small class="text-muted d-block"><?php echo htmlspecialchars($renewalTelegramTemplateHint); ?></small>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars($renewalSaveLabel); ?></button>
      </div>
    </form>
    <p class="text-muted small mb-3"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($renewalVariablesHint); ?></p>

    <hr>
    <h6 class="mb-3"><?php echo htmlspecialchars($renewalTestTitle); ?></h6>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="admin_test_renewal_notice">
      <div class="col-12 col-md-4">
        <label class="form-label" for="test_subdomain"><?php echo htmlspecialchars($renewalTestDomainLabel); ?></label>
        <input type="text" class="form-control" id="test_subdomain" name="test_subdomain" placeholder="foo.example.com">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="test_subdomain_id"><?php echo htmlspecialchars($renewalTestIdLabel); ?></label>
        <input type="number" class="form-control" id="test_subdomain_id" name="test_subdomain_id" min="0" placeholder="ID">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="test_notice_days"><?php echo htmlspecialchars($renewalTestDaysLabel); ?></label>
        <input type="number" class="form-control" id="test_notice_days" name="test_notice_days" min="1" placeholder="<?php echo htmlspecialchars($renewalDay1Setting ?: '180'); ?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="test_override_email"><?php echo htmlspecialchars($renewalTestEmailLabel); ?></label>
        <input type="email" class="form-control" id="test_override_email" name="test_override_email" placeholder="admin@example.com">
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button type="submit" class="btn btn-outline-primary"><?php echo htmlspecialchars($renewalTestButton); ?></button>
      </div>
    </form>

    <hr>
    <h6 class="mb-3"><?php echo htmlspecialchars($renewalTelegramTestTitle); ?></h6>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="admin_test_renewal_notice_telegram">
      <div class="col-12 col-md-4">
        <label class="form-label" for="test_telegram_subdomain"><?php echo htmlspecialchars($renewalTelegramTestDomainLabel); ?></label>
        <input type="text" class="form-control" id="test_telegram_subdomain" name="test_telegram_subdomain" placeholder="foo.example.com">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="test_telegram_subdomain_id"><?php echo htmlspecialchars($renewalTelegramTestIdLabel); ?></label>
        <input type="number" class="form-control" id="test_telegram_subdomain_id" name="test_telegram_subdomain_id" min="0" placeholder="ID">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label" for="test_telegram_notice_days"><?php echo htmlspecialchars($renewalTelegramTestDaysLabel); ?></label>
        <input type="number" class="form-control" id="test_telegram_notice_days" name="test_telegram_notice_days" min="1" placeholder="30">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="test_override_telegram_user_id"><?php echo htmlspecialchars($renewalTelegramTestUserIdLabel); ?></label>
        <input type="number" class="form-control" id="test_override_telegram_user_id" name="test_override_telegram_user_id" min="0" placeholder="123456789">
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button type="submit" class="btn btn-outline-info"><?php echo htmlspecialchars($renewalTelegramTestButton); ?></button>
      </div>
    </form>
  </div>
</div>


<div class="card mb-4 border-info">
  <div class="card-body">
    <h5 class="card-title mb-3 text-info"><i class="fas fa-search"></i> <?php echo htmlspecialchars($orphanTitle); ?></h5>
    <div class="alert alert-info small mb-3">
      <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($orphanIntro); ?>
    </div>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="scan_orphan_records">
      <div class="col-12 col-md-4">
        <label class="form-label" for="cf-orphan-root-select"><?php echo htmlspecialchars($orphanRootLabel); ?></label>
        <select class="form-select" id="cf-orphan-root-select" name="orphan_rootdomain">
          <option value=""><?php echo htmlspecialchars($selectPlaceholder); ?></option>
          <?php echo $cfmodOrphanRootOptionsHtml; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="cf-orphan-limit"><?php echo htmlspecialchars($orphanLimitLabel); ?></label>
        <input type="number" class="form-control" id="cf-orphan-limit" name="orphan_subdomain_limit" min="10" max="5000" value="100">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="cf-orphan-cursor-mode"><?php echo htmlspecialchars($orphanCursorLabel); ?></label>
        <select class="form-select" id="cf-orphan-cursor-mode" name="orphan_cursor_mode">
          <option value="resume"><?php echo htmlspecialchars($orphanCursorResume); ?></option>
          <option value="reset"><?php echo htmlspecialchars($orphanCursorReset); ?></option>
        </select>
        <small class="text-muted d-block mt-1">
          <?php echo htmlspecialchars(sprintf($orphanCursorCurrentFmt, (int) $orphanCursorDefaultValue)); ?>
          <?php if ($orphanCursorListText !== '') { echo '<br>' . htmlspecialchars($orphanCursorListText); } ?>
        </small>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="cf-orphan-mode"><?php echo htmlspecialchars($orphanModeLabel); ?></label>
        <select class="form-select" id="cf-orphan-mode" name="orphan_mode">
          <option value="dry"><?php echo htmlspecialchars($orphanModeDry); ?></option>
          <option value="delete"><?php echo htmlspecialchars($orphanModeDelete); ?></option>
        </select>
      </div>
      <div class="col-12">
        <small class="text-muted"><?php echo htmlspecialchars($orphanHint); ?></small>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-info text-white"><i class="fas fa-broom me-1"></i> <?php echo htmlspecialchars($orphanButton); ?></button>
      </div>
    </form>
  </div>
</div>
