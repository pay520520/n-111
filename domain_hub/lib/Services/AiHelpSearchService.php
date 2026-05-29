<?php

declare(strict_types=1);

class CfAiHelpSearchService
{
    private const PROVIDER_QWEN = 'qwen';

    public static function isEnabled(array $settings): bool
    {
        if (!array_key_exists('enable_help_ai_search', $settings)) {
            return false;
        }

        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($settings['enable_help_ai_search']);
        }

        $raw = strtolower(trim((string) $settings['enable_help_ai_search']));
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function isFabEnabled(array $settings): bool
    {
        if (!array_key_exists('help_ai_fab_enabled', $settings)) {
            return true;
        }
        if (function_exists('cfmod_setting_enabled')) {
            return cfmod_setting_enabled($settings['help_ai_fab_enabled']);
        }
        $raw = strtolower(trim((string) $settings['help_ai_fab_enabled']));
        return in_array($raw, ['1', 'on', 'yes', 'true', 'enabled'], true);
    }

    public static function getAssistantName(array $settings, string $fallback = 'AI 助手'): string
    {
        $name = trim((string) ($settings['help_ai_assistant_name'] ?? ''));
        if (function_exists('cfmod_pick_bilingual_text')) {
            $locale = (string) ($settings['help_ai_locale'] ?? ($_SESSION['Language'] ?? ($_SESSION['language'] ?? 'english')));
            $name = trim((string) cfmod_pick_bilingual_text($name, $locale));
        }
        if ($name === '') {
            return $fallback;
        }
        return $name;
    }

    public static function ask(int $requestUserId, string $query, array $history = [], array $settings = [], array $context = []): array
    {
        if (empty($settings) && function_exists('cf_get_module_settings_cached')) {
            $settings = cf_get_module_settings_cached();
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        if (!self::isEnabled($settings)) {
            throw new \RuntimeException('AI 搜索功能暂未开启。');
        }

        $maxInputChars = self::resolveMaxInputChars($settings);
        $question = trim($query);
        if ($question === '') {
            throw new \RuntimeException('请输入问题后再进行 AI 查询。');
        }
        $question = self::truncateText($question, $maxInputChars);
        $sensitiveScan = self::sanitizeSensitiveInput($question);
        $question = $sensitiveScan['sanitized'];

        $conversation = self::normalizeHistory($history);
        $conversation[] = [
            'role' => 'user',
            'content' => $question,
        ];
        $accountSnapshot = is_array($context['account_snapshot'] ?? null) ? $context['account_snapshot'] : [];
        $dnsHealthAnswer = self::buildDnsHealthAnswer($question, $requestUserId, $settings, $accountSnapshot);
        if ($dnsHealthAnswer !== null) {
            return self::buildDeterministicAccountResponse($dnsHealthAnswer, $settings, $context, $question, $sensitiveScan);
        }
        $apiKeyAnswer = self::buildApiKeySummaryAnswer($question, $requestUserId, $settings, $accountSnapshot);
        if ($apiKeyAnswer !== null) {
            return self::buildDeterministicAccountResponse($apiKeyAnswer, $settings, $context, $question, $sensitiveScan);
        }
        $delegatedMeaningAnswer = self::buildDelegatedMeaningAnswer($question, $settings);
        if ($delegatedMeaningAnswer !== null) {
            return self::buildDeterministicAccountResponse($delegatedMeaningAnswer, $settings, $context, $question, $sensitiveScan);
        }
        $quotaIncreaseAnswer = self::buildQuotaIncreaseAnswer($question, $settings);
        if ($quotaIncreaseAnswer !== null) {
            return self::buildDeterministicAccountResponse($quotaIncreaseAnswer, $settings, $context, $question, $sensitiveScan);
        }
        $accountAnswer = self::buildAccountLinkedAnswer($question, $accountSnapshot, $settings);
        if ($accountAnswer !== null) {
            return self::buildDeterministicAccountResponse($accountAnswer, $settings, $context, $question, $sensitiveScan);
        }

        $provider = self::resolveProvider($settings);
        if (!empty($context['locale']) && empty($settings['help_ai_locale'])) {
            $settings['help_ai_locale'] = (string) $context['locale'];
        }
        $route = self::classifyQuestion($question);
        $retrieval = self::retrieveFaqSnippets($question, $route, $settings);
        $systemPrompt = self::resolveSystemPrompt($settings, $route, $retrieval, $question);
        $systemPrompt .= "\n\n" . self::responseLanguageInstruction($question, $settings);

        $diagnosticPack = '';
        if ($route === 'dns' && self::extractDomainFromQuestion($question) !== '') {
            $conversation[] = [
                'role' => 'user',
                'content' => self::buildDnsDiagnosticTemplate(
                    self::extractDomainFromQuestion($question),
                    self::isChineseLanguage($settings) && !self::looksLikeEnglish($question)
                ),
            ];
            $diagnosticPack = self::buildDnsDiagnosticCommands(self::extractDomainFromQuestion($question));
        }

        $result = self::requestQwenWithFallback($settings, $systemPrompt, $conversation, $context);

        $answer = trim((string) ($result['answer'] ?? ''));
        if ($answer === '') {
            throw new \RuntimeException('AI 暂时没有返回可用内容，请稍后重试。');
        }

        $confidence = self::estimateConfidence($question, $answer, $route, $retrieval);
        $escalation = self::buildEscalationAdvice($confidence, $context, $settings, $question);
        if (!empty($escalation['should_escalate'])) {
            $answer .= "\n\n" . $escalation['message'];
        }

        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('help_ai_search', [
                'provider' => $provider,
                'model' => (string) ($result['model'] ?? ''),
                'input_length' => self::textLength($question),
                'output_length' => self::textLength($answer),
            ], $requestUserId, null);
        }

        return [
            'answer' => $answer,
            'provider' => $provider,
            'model' => (string) ($result['model'] ?? ''),
            'assistant_name' => self::getAssistantName($settings, self::isChineseLanguage($settings) ? 'AI 助手' : 'AI Assistant'),
            'category' => $route,
            'confidence' => $confidence,
            'should_escalate' => !empty($escalation['should_escalate']),
            'escalation_url' => (string) ($escalation['url'] ?? ''),
            'sensitive_detected' => !empty($sensitiveScan['detected']),
            'sensitive_notice' => (string) ($sensitiveScan['notice'] ?? ''),
            'retrieval_hits' => (int) ($retrieval['count'] ?? 0),
            'diagnostic_pack' => $diagnosticPack,
        ];
    }

    private static function retrieveFaqSnippets(string $question, string $route, array $settings = []): array
    {
        $kb = self::faqKnowledgeBase($settings);
        $q = strtolower($question);
        $hits = [];
        foreach ($kb as $item) {
            $score = 0;
            if (($item['category'] ?? '') === $route) { $score += 3; }
            if (($item['category'] ?? '') === 'module_help') { $score += 1; }
            foreach ((array) ($item['keywords'] ?? []) as $kw) {
                $kwN = strtolower(trim((string) $kw));
                if ($kwN !== '' && strpos($q, $kwN) !== false) { $score += 2; }
            }
            if ($score > 0) {
                $item['score'] = $score;
                $hits[] = $item;
            }
        }
        usort($hits, static function ($a, $b) {
            return intval($b['score'] ?? 0) <=> intval($a['score'] ?? 0);
        });
        $hits = array_slice($hits, 0, 4);
        $snippets = [];
        foreach ($hits as $idx => $hit) {
            $snippets[] = '[' . ($idx + 1) . '] ' . trim((string) ($hit['title'] ?? '')) . "\n" . trim((string) ($hit['content'] ?? ''));
        }
        return ['count' => count($snippets), 'snippets' => $snippets];
    }

    private static function resolveSystemPrompt(array $settings, string $route = 'general', array $retrieval = [], string $question = ''): string
    {
        $isZh = self::isChineseLanguage($settings);
        $configured = trim((string) ($settings['help_ai_system_prompt'] ?? ''));
        if ($configured === '') {
            if ($isZh) {
                $configured = implode("\n", [
                    '你是 WHMCS 域名分发插件 domain_hub 的帮助中心 AI 助手。',
                    '你的目标是帮助用户排查域名注册、续期、DNS 解析、API 密钥和安全限制相关问题。',
                    '回答时优先给出明确步骤，尽量简洁。',
                    '如果问题超出插件能力范围，请明确说明并建议用户提交工单。',
                    '不要编造不存在的后台开关或接口。',
                    '请默认使用中文回复，除非用户明确要求英文。',
                ]);
            } else {
                $configured = implode("\n", [
                    'You are the Help Center AI assistant for the WHMCS domain distribution plugin "domain_hub".',
                    'Help users troubleshoot domain registration, renewal, DNS records, API keys and security restrictions.',
                    'Prefer concise, actionable step-by-step guidance.',
                    'If the request is outside plugin scope, clearly say so and suggest opening a support ticket.',
                    'Do not invent non-existent settings, capabilities, or APIs.',
                    'Reply in English by default unless the user explicitly asks for Chinese.',
                ]);
            }
        }

        $routePrompt = self::routePrompt($route, $settings);
        $ragPrompt = '';
        if (!empty($retrieval['snippets']) && is_array($retrieval['snippets'])) {
            if ($isZh) {
                $ragPrompt = "\n\n【帮助中心命中片段】\n" . implode("\n\n", $retrieval['snippets'])
                    . "\n\n要求：优先依据以上片段回答；若冲突或不确定，明确提示用户提交工单/TG。";
            } else {
                $ragPrompt = "\n\n[Matched Help-Center Snippets]\n" . implode("\n\n", $retrieval['snippets'])
                    . "\n\nRequirement: prioritize these snippets; if uncertain/conflicting, clearly advise submitting a ticket / joining TG.";
            }
        }
        $configured = self::normalizeConfiguredPromptByLanguage($configured, $question, $isZh);
        return $configured . "\n\n" . $routePrompt . $ragPrompt;
    }

    private static function requestQwenWithFallback(array $settings, string $systemPrompt, array $conversation, array $context = []): array
    {
        $apiKey = trim((string) ($settings['help_ai_qwen_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('未配置 Qwen API Key。');
        }
        $primary = trim((string) ($settings['help_ai_qwen_model'] ?? 'qwen3.6-flash'));
        if ($primary === '') { $primary = 'qwen3.6-flash'; }
        $fallback = trim((string) ($settings['help_ai_qwen_fallback_model'] ?? 'qwen3.5-flash'));
        if ($fallback === '') { $fallback = 'qwen3.5-flash'; }
        try {
            return self::requestQwenModel($apiKey, $primary, $systemPrompt, $conversation, $context);
        } catch (\Throwable $e) {
            if (strcasecmp($primary, $fallback) === 0) { throw $e; }
            return self::requestQwenModel($apiKey, $fallback, $systemPrompt, $conversation, $context);
        }
    }

    private static function requestQwenModel(string $apiKey, string $model, string $systemPrompt, array $conversation, array $context = []): array
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($conversation as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) ($message['content'] ?? '')];
        }
        $body = ['model' => $model, 'messages' => $messages, 'temperature' => 0.25, 'max_tokens' => 1024];
        $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'];
        $baseUrl = trim((string) ($context['qwen_base_url'] ?? ''));
        $url = self::buildQwenEndpoint($baseUrl);
        [$statusCode, $decoded, $rawBody] = self::requestJson('POST', $url, $headers, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($statusCode >= 400) {
            $message = self::extractErrorMessage($decoded, $rawBody);
            if ($message === '') { $message = 'Qwen 请求失败（HTTP ' . $statusCode . '）'; }
            throw new \RuntimeException($message);
        }
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        return ['answer' => trim((string) $content), 'model' => $model];
    }

    public static function testQwenConnectivity(string $apiKey, string $model): void
    {
        $apiKey = trim($apiKey);
        $model = trim($model);
        if ($apiKey === '') {
            throw new \RuntimeException('请先填写 Qwen API Key。');
        }
        if ($model === '') {
            throw new \RuntimeException('请先填写 Qwen 模型名。');
        }

        self::requestQwenModel($apiKey, $model, '连通性测试，请简短回复 OK。', [
            ['role' => 'user', 'content' => 'ping'],
        ], []);
    }

    private static function requestJson(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前环境缺少 cURL 扩展，无法调用 AI 接口。');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('初始化 AI 请求失败。');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'DomainHub-HelpAI/1.0',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $rawBody = curl_exec($ch);
        $curlError = trim((string) curl_error($ch));
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        if ($curlError !== '') {
            throw new \RuntimeException('AI 网络请求失败：' . $curlError);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [$statusCode, $decoded, $rawBody];
    }

    private static function resolveProvider(array $settings): string
    {
        $provider = strtolower(trim((string) ($settings['help_ai_provider'] ?? self::PROVIDER_QWEN)));
        if (!in_array($provider, [self::PROVIDER_QWEN], true)) {
            $provider = self::PROVIDER_QWEN;
        }
        return $provider;
    }

    private static function classifyQuestion(string $question): string
    {
        $q = strtolower($question);
        $map = [
            'dns' => ['dns', '解析', 'dig', 'nslookup', 'ttl', 'cname', 'txt', 'a记录', 'aaaa', 'ns'],
            'quota' => ['额度', 'quota', '兑换', 'redeem', '邀请', 'invite'],
            'ban' => ['封禁', 'ban', '停用', '申诉'],
            'ssl' => ['ssl', 'https', '证书', 'let\'s encrypt', 'tls'],
            'api' => ['api', 'token', '密钥', 'key', 'qwen', 'dashscope', 'alibaba', 'aliyun'],
        ];
        foreach ($map as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if ($kw !== '' && strpos($q, strtolower($kw)) !== false) {
                    return $cat;
                }
            }
        }
        return 'general';
    }

    private static function routePrompt(string $route, array $settings = []): string
    {
        $prompts = [
            'dns' => '【分类】DNS 故障排查。请优先给出可执行的 dig/nslookup 诊断步骤与判断依据。',
            'quota' => '【分类】额度/邀请/兑换。请说明当前限制、可操作路径与注意事项。',
            'ban' => '【分类】封禁与申诉。请避免承诺解封，给出合规申诉路径。',
            'ssl' => '【分类】SSL 证书申请。请给出 DNS 验证检查步骤与失败重试建议。',
            'api' => '【分类】API 与密钥。请强调密钥安全，不要求用户贴出完整密钥。',
            'general' => '【分类】通用咨询。请先澄清问题再给步骤。',
        ];
        $isZh = self::isChineseLanguage($settings);
        if (!$isZh) {
            $prompts = [
                'dns' => 'Category: DNS troubleshooting. Provide executable dig/nslookup checks and explain pass/fail criteria.',
                'quota' => 'Category: quota/invite/redeem. Explain limits, available actions, and caveats.',
                'ban' => 'Category: ban/appeal. Do not promise unban; provide compliant appeal path.',
                'ssl' => 'Category: SSL certificate request. Provide DNS validation checks and retry guidance.',
                'api' => 'Category: API and secrets. Emphasize key safety; never ask users for full keys.',
                'general' => 'Category: general. Ask clarifying questions before giving steps.',
            ];
        }
        return (string) ($prompts[$route] ?? $prompts['general']);
    }

    private static function extractDomainFromQuestion(string $question): string
    {
        if (preg_match('/\b([a-z0-9][a-z0-9\-]{0,62}(?:\.[a-z0-9][a-z0-9\-]{0,62})+)\b/i', $question, $m)) {
            return strtolower(trim((string) ($m[1] ?? '')));
        }
        return '';
    }

    private static function buildDnsDiagnosticTemplate(string $domain, bool $isZh = true): string
    {
        if (!$isZh) {
            return "Please include the following one-click diagnostics and explain how to interpret the results:\n"
                . "```bash\n"
                . "dig +short A {$domain}\n"
                . "dig +short AAAA {$domain}\n"
                . "dig +short CNAME {$domain}\n"
                . "dig +short NS {$domain}\n"
                . "dig +short TXT {$domain}\n"
                . "```\n"
                . "Suggested interpretation:\n"
                . "1) If NS is not using platform default nameservers, fix NS first.\n"
                . "2) If A/AAAA/CNAME has no answer, verify record name/type/TTL (typically 600s).\n"
                . "3) If only some regions fail, advise propagation wait and cross-check with multiple public resolvers.";
        }
        return "请附带以下一键诊断模板并解释如何判断：\n"
            . "```bash\n"
            . "dig +short A {$domain}\n"
            . "dig +short AAAA {$domain}\n"
            . "dig +short CNAME {$domain}\n"
            . "dig +short NS {$domain}\n"
            . "dig +short TXT {$domain}\n"
            . "```\n"
            . "判断建议：\n"
            . "1) 若 NS 不是平台默认 NS，先修正 NS。\n"
            . "2) 若 A/AAAA/CNAME 无结果，检查记录名、类型和 TTL（通常 600s）。\n"
            . "3) 若部分地区异常，提示等待传播或用多公共解析器复核。";
    }


    private static function buildDnsDiagnosticCommands(string $domain): string
    {
        $d = strtolower(trim($domain));
        if ($d === '') { return ''; }
        return "dig +short A {$d}
"
            . "dig +short AAAA {$d}
"
            . "dig +short CNAME {$d}
"
            . "dig +short NS {$d}
"
            . "dig +short TXT {$d}";
    }

    private static function sanitizeSensitiveInput(string $question): array
    {
        $patterns = [
            '/\b(sk-[a-z0-9]{10,})\b/i',
            '/\b(api[_-]?key\s*[:=]\s*[a-z0-9_\-]{8,})\b/i',
            '/\b(token\s*[:=]\s*[a-z0-9_\-]{8,})\b/i',
            '/\b(password\s*[:=]\s*\S+)\b/i',
        ];
        $detected = false;
        $sanitized = $question;
        foreach ($patterns as $p) {
            $sanitized = preg_replace_callback($p, static function ($m) use (&$detected) {
                $detected = true;
                $v = (string) ($m[0] ?? '');
                return substr($v, 0, min(4, strlen($v))) . '***[REDACTED]';
            }, $sanitized);
        }
        return [
            'detected' => $detected,
            'sanitized' => $sanitized,
            'notice' => $detected ? '检测到疑似敏感信息，已自动脱敏。建议立即重置对应密钥/口令。' : '',
        ];
    }

    private static function estimateConfidence(string $question, string $answer, string $route, array $retrieval): float
    {
        $score = 0.45;
        if (($retrieval['count'] ?? 0) > 0) { $score += 0.20; }
        if ($route !== 'general') { $score += 0.15; }
        if (strpos($answer, '```') !== false) { $score += 0.10; }
        if (self::textLength($answer) > 80) { $score += 0.05; }
        $q = strtolower($question);
        if (strpos($q, '不知道') !== false || strpos($q, '不确定') !== false) { $score -= 0.10; }
        return max(0.0, min(0.99, round($score, 2)));
    }

    private static function buildEscalationAdvice(float $confidence, array $context, array $settings = [], string $question = ''): array
    {
        $siteUrl = trim((string) ($context['site_url'] ?? ''));
        $ticketUrl = $siteUrl !== '' ? rtrim($siteUrl, '/') . '/submitticket.php' : 'submitticket.php';
        $tgUrl = trim((string) ($context['support_group_url'] ?? ''));
        if ($tgUrl === '') {
            $tgUrl = 'https://t.me/+l9I5TNRDLP5lZDBh';
        }
        if ($confidence >= 0.58) {
            return ['should_escalate' => false, 'url' => ''];
        }
        $locale = strtolower(trim((string) ($context['locale'] ?? ($settings['help_ai_locale'] ?? 'english'))));
        $isEnglish = self::looksLikeEnglish($question) || $locale === 'english';
        $message = $isEnglish
            ? "⚠️ The knowledge base does not have a confirmed answer for this question yet. Please contact an administrator in the official Telegram group for assistance.\n- Ticket: {$ticketUrl}\n- Telegram Group: {$tgUrl}"
            : "⚠️ 知识库暂无该问题的确定答案，建议前往 Telegram 官方群组联系管理员协助处理。\n- 工单：{$ticketUrl}\n- Telegram 社群：{$tgUrl}";
        return [
            'should_escalate' => true,
            'url' => $ticketUrl,
            'message' => $message,
        ];
    }

    private static function faqKnowledgeBase(array $settings = []): array
    {
        $source = strtolower(trim((string) ($settings['help_ai_kb_source'] ?? 'mixed')));
        if (!in_array($source, ['db','static','mixed'], true)) {
            $source = 'mixed';
        }
        $isZh = self::isChineseLanguage($settings);
        $static = $isZh
            ? [
                ['category' => 'dns', 'title' => 'DNS 生效慢', 'keywords' => ['生效', '传播', 'ttl', 'dig'], 'content' => '先确认记录值与类型正确，TTL 默认 600 秒，使用 dig 查询权威结果。'],
                ['category' => 'dns', 'title' => '冲突与已存在', 'keywords' => ['冲突', 'already exists', 'conflict'], 'content' => '可使用功能中心的冲突清理/自愈能力；若仍失败，检查同名异类型记录。'],
                ['category' => 'quota', 'title' => '额度不足', 'keywords' => ['额度', 'quota', 'redeem', '邀请'], 'content' => '可通过兑换码、邀请奖励、GitHub/Telegram 奖励增加额度。'],
                ['category' => 'ssl', 'title' => 'SSL 申请失败', 'keywords' => ['ssl', '证书', 'let\'s encrypt'], 'content' => '检查域名解析与 DNS 验证记录是否正确，等待传播后重试申请。'],
                ['category' => 'api', 'title' => 'API Key 安全', 'keywords' => ['api', 'key', 'token'], 'content' => '不要泄露密钥，疑似泄露请立即重置；请求失败时检查密钥状态与权限。'],
                ['category' => 'ban', 'title' => '封禁与申诉', 'keywords' => ['封禁', 'ban', '申诉'], 'content' => '封禁问题请走工单申诉流程，描述场景并提供必要日志截图。'],
            ]
            : [
                ['category' => 'dns', 'title' => 'Slow DNS propagation', 'keywords' => ['dns', 'propagation', 'ttl', 'dig'], 'content' => 'Verify record values and types first. Default TTL is usually 600 seconds. Use dig to check authoritative answers.'],
                ['category' => 'dns', 'title' => 'Conflict / already exists', 'keywords' => ['conflict', 'already exists', 'duplicate'], 'content' => 'Use the conflict cleanup/self-heal feature. If it still fails, check same-name records with incompatible types.'],
                ['category' => 'quota', 'title' => 'Quota insufficient', 'keywords' => ['quota', 'redeem', 'invite', 'limit'], 'content' => 'Increase quota via redeem codes, invite rewards, or GitHub/Telegram rewards where enabled.'],
                ['category' => 'ssl', 'title' => 'SSL request failed', 'keywords' => ['ssl', 'certificate', 'let\'s encrypt', 'dns-01'], 'content' => 'Check DNS records required for validation, wait for propagation, then retry the request.'],
                ['category' => 'api', 'title' => 'API key safety', 'keywords' => ['api', 'key', 'token', 'secret'], 'content' => 'Never share keys. If exposure is suspected, rotate immediately. For failed calls, verify key status and permissions.'],
                ['category' => 'ban', 'title' => 'Ban and appeal', 'keywords' => ['ban', 'appeal', 'suspended'], 'content' => 'Submit an appeal ticket with scenario details and relevant logs/screenshots.'],
            ];

        $locale = strtolower(trim((string) ($settings['help_ai_locale'] ?? ($_SESSION['Language'] ?? ($_SESSION['language'] ?? 'english')))));
        $includeModuleHelp = !array_key_exists('help_ai_include_module_help', $settings)
            || in_array(strtolower(trim((string) ($settings['help_ai_include_module_help'] ?? '1'))), ['1','on','yes','true','enabled'], true);
        $moduleHelp = ($includeModuleHelp && class_exists('CfHelpCenterContentService'))
            ? CfHelpCenterContentService::getHelpFaqItems($locale)
            : [];

        if ($source === 'static') { return array_merge($moduleHelp, $static); }
        $db = self::loadHelpCenterKnowledgeFromDb($settings);
        if ($source === 'db') {
            $base = !empty($db) ? $db : $static;
            return array_merge($moduleHelp, $base);
        }
        return array_merge($moduleHelp, $db, $static);
    }

    private static function loadHelpCenterKnowledgeFromDb(array $settings = []): array
    {
        static $cache = [];
        $minutes = max(1, min(1440, intval($settings['help_ai_kb_refresh_minutes'] ?? 30)));
        $ck = 'm' . $minutes;
        $now = time();
        if (isset($cache[$ck]) && ($now - intval($cache[$ck]['ts'] ?? 0)) < ($minutes * 60)) {
            return (array) ($cache[$ck]['data'] ?? []);
        }
        $rows = [];
        try {
            if (class_exists('Capsule') && method_exists('Capsule', 'schema') && method_exists('Capsule', 'table')) {
                if (\Capsule::schema()->hasTable('tblknowledgebase')) {
                    $items = \Capsule::table('tblknowledgebase')
                        ->select('title', 'article')
                        ->whereNotNull('article')
                        ->where('article', '!=', '')
                        ->orderBy('id', 'desc')
                        ->limit(200)
                        ->get();
                    foreach ($items as $it) {
                        $title = trim((string) ($it->title ?? ''));
                        $article = trim(strip_tags((string) ($it->article ?? '')));
                        if ($title === '' || $article === '') { continue; }
                            $rows[] = [
                                'category' => 'general',
                                'title' => $title,
                                'keywords' => preg_split('/[\s,;|]+/u', strtolower($title)) ?: [],
                                'content' => self::truncateText($article, 500)
                            ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $rows = [];
        }
        $cache[$ck] = ['ts' => $now, 'data' => $rows];
        return $rows;
    }

    private static function isChineseLanguage(array $settings = []): bool
    {
        $lang = strtolower(trim((string) ($settings['help_ai_locale'] ?? ($_SESSION['Language'] ?? ($_SESSION['language'] ?? 'english')))));
        return $lang === 'chinese';
    }

    private static function responseLanguageInstruction(string $question, array $settings = []): string
    {
        if (self::looksLikeEnglish($question)) {
            return 'Response language policy: The user asked in English. Reply entirely in English.';
        }
        if (self::isChineseLanguage($settings)) {
            return '回复语言策略：用户当前使用中文环境，请使用中文完整回复。';
        }
        return 'Response language policy: Reply in English unless the user explicitly requests another language.';
    }

    private static function normalizeConfiguredPromptByLanguage(string $configured, string $question, bool $isZhLocale): string
    {
        $configured = trim($configured);
        if ($configured === '') {
            return $configured;
        }
        if (self::looksLikeEnglish($question) && self::containsChinese($configured)) {
            return $configured . "\n\nLanguage override: user asked in English. If any instruction conflicts, reply in English.";
        }
        if ($isZhLocale && !self::looksLikeEnglish($question) && !self::containsChinese($configured)) {
            return $configured . "\n\n语言覆盖：当前中文场景优先中文回复。";
        }
        return $configured;
    }

    private static function buildQwenEndpoint(string $baseUrl = ''): string
    {
        $defaultBase = 'https://dashscope-intl.aliyuncs.com';
        $base = trim($baseUrl);
        if ($base === '') {
            $base = $defaultBase;
        }
        $base = rtrim($base, '/');
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        return $base . '/compatible-mode/v1/chat/completions';
    }

    private static function containsChinese(string $text): bool
    {
        return preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
    }

    private static function looksLikeEnglish(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $len = strlen($text);
        $asciiLetters = preg_match_all('/[A-Za-z]/', $text, $m);
        $zhChars = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text, $m2);
        if ($asciiLetters === false || $zhChars === false) {
            return false;
        }
        if ($asciiLetters >= 6 && $zhChars === 0) {
            return true;
        }
        return ($asciiLetters > $zhChars * 2) && ($asciiLetters >= max(4, (int) floor($len * 0.2)));
    }

    private static function resolveMaxInputChars(array $settings): int
    {
        $value = (int) ($settings['help_ai_max_input_chars'] ?? 600);
        return max(200, min(2000, $value));
    }

    private static function buildAccountLinkedAnswer(string $question, array $accountSnapshot, array $settings = []): ?string
    {
        $q = strtolower(trim($question));
        if ($q === '') {
            return null;
        }
        $isZh = self::isChineseLanguage($settings) && !self::looksLikeEnglish($question);
        $wantQuota = (
            strpos($q, '额度') !== false
            || strpos($q, 'quota') !== false
            || strpos($q, '注册数') !== false
            || strpos($q, 'registration quota') !== false
            || strpos($q, 'remaining quota') !== false
        );
        $wantExpiry = (
            strpos($q, '到期') !== false
            || strpos($q, 'expires') !== false
            || strpos($q, 'expiry') !== false
            || strpos($q, 'expiring') !== false
            || strpos($q, 'upcoming domain') !== false
            || strpos($q, 'domain expiry') !== false
        );
        if (!$wantQuota && !$wantExpiry) {
            return null;
        }
        $timezone = trim((string) ($accountSnapshot['timezone'] ?? date_default_timezone_get()));
        $snapshotTime = trim((string) ($accountSnapshot['snapshot_time'] ?? date('Y-m-d H:i:s')));
        $lines = [];
        if ($wantQuota) {
            $quota = is_array($accountSnapshot['quota'] ?? null) ? $accountSnapshot['quota'] : [];
            $found = !empty($quota['found']);
            if (!$found) {
                $lines[] = $isZh
                    ? '【注册额度】当前未找到您的额度记录，请联系管理员核查。'
                    : '[Registration Quota] No quota record was found for your account. Please contact support.';
            } else {
                $used = max(0, (int) ($quota['used_count'] ?? 0));
                $max = max(0, (int) ($quota['max_count'] ?? 0));
                $remaining = max(0, (int) ($quota['remaining_count'] ?? ($max - $used)));
                $lines[] = $isZh
                    ? "【注册额度】已使用 {$used} / 总额度 {$max}，剩余 {$remaining}。"
                    : "[Registration Quota] Used {$used} / Total {$max}, Remaining {$remaining}.";
            }
        }
        if ($wantExpiry) {
            $items = is_array($accountSnapshot['expiring_domains'] ?? null) ? $accountSnapshot['expiring_domains'] : [];
            if (empty($items)) {
                $lines[] = $isZh
                    ? '【最近到期域名】当前没有可展示的到期域名（仅展示 active 且已设置到期时间的数据）。'
                    : '[Upcoming Expiry Domains] No expiring domains are available to display right now (active domains with expiry only).';
            } else {
                $lines[] = $isZh ? '【最近到期域名（最多 8 条）】' : '[Upcoming Expiry Domains (up to 8)]';
                $count = 0;
                foreach ($items as $item) {
                    if ($count >= 8) {
                        break;
                    }
                    $fqdn = trim((string) ($item['fqdn'] ?? ''));
                    $exp = trim((string) ($item['expires_at'] ?? ''));
                    if ($fqdn === '' || $exp === '') {
                        continue;
                    }
                    $lines[] = '- ' . $fqdn . ' → ' . $exp . ' (' . $timezone . ')';
                    $count++;
                }
                if ($count === 0) {
                    $lines[] = $isZh ? '- 暂无可展示条目。' : '- No entries available.';
                }
            }
        }
        $lines[] = $isZh
            ? "数据更新时间（按系统服务器时间）：{$snapshotTime} ({$timezone})。"
            : "Data snapshot time (server timezone): {$snapshotTime} ({$timezone}).";
        return implode("\n", $lines);
    }

    private static function buildDeterministicAccountResponse(string $answer, array $settings, array $context, string $question, array $sensitiveScan): array
    {
        $escalation = self::buildEscalationAdvice(0.96, $context, $settings, $question);
        if (!empty($sensitiveScan['detected'])) {
            $escalation['should_escalate'] = true;
            $escalation['url'] = (string) ($escalation['url'] ?? 'submitticket.php');
        }
        if (!empty($escalation['should_escalate']) && !empty($escalation['message'])) {
            $answer .= "\n\n" . (string) $escalation['message'];
        }
        return [
            'answer' => $answer,
            'provider' => 'account_snapshot',
            'model' => 'deterministic',
            'assistant_name' => self::getAssistantName($settings, self::isChineseLanguage($settings) ? 'AI 助手' : 'AI Assistant'),
            'category' => 'account',
            'confidence' => 0.96,
            'should_escalate' => !empty($escalation['should_escalate']),
            'escalation_url' => (string) ($escalation['url'] ?? ''),
            'sensitive_detected' => !empty($sensitiveScan['detected']),
            'sensitive_notice' => (string) ($sensitiveScan['notice'] ?? ''),
            'retrieval_hits' => 0,
            'diagnostic_pack' => '',
        ];
    }

    private static function buildDnsHealthAnswer(string $question, int $requestUserId, array $settings = [], array $accountSnapshot = []): ?string
    {
        $q = strtolower(trim($question));
        if ($q === '') { return null; }
        $isZh = self::isChineseLanguage($settings) && !self::looksLikeEnglish($question);
        $isDnsIntent = strpos($q, 'dns') !== false || strpos($q, '解析') !== false || strpos($q, 'cname') !== false || strpos($q, 'aaaa') !== false || strpos($q, 'a记录') !== false;
        if (!$isDnsIntent) { return null; }
        $domain = self::extractDomainFromQuestion($question);
        if ($domain === '') {
            return $isZh
                ? "【DNS 健康摘要】请在问题中附带完整域名（如 www.example.com），我才能检查 A/AAAA/CNAME、最近修改时间和冲突风险。"
                : "[DNS Health Summary] Please include a full domain in your question (e.g. www.example.com), then I can check A/AAAA/CNAME, last update time, and conflict risk.";
        }
        if ($requestUserId <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) { return null; }
        try {
            $normalizedDomain = strtolower(trim($domain));
            $subRows = \WHMCS\Database\Capsule::table('mod_cloudflare_subdomain')
                ->select('id')
                ->where('userid', $requestUserId)
                ->where(function ($query) use ($normalizedDomain) {
                    $query->whereRaw('LOWER(subdomain) = ?', [$normalizedDomain])
                        ->orWhereRaw('LOWER(CONCAT(subdomain, ".", rootdomain)) = ?', [$normalizedDomain]);
                })
                ->limit(1)->get();
            if (count($subRows) === 0) {
                return $isZh
                    ? "【DNS 健康摘要】未在您的账户下找到域名 {$domain}，请确认域名属于当前账号。"
                    : "[DNS Health Summary] Domain {$domain} was not found under your account. Please confirm ownership in this account.";
            }
            $subId = (int) ($subRows[0]->id ?? 0);
            $dnsRows = \WHMCS\Database\Capsule::table('mod_cloudflare_dns_records')
                ->select('type', 'updated_at', 'created_at', 'name')
                ->where('subdomain_id', $subId)
                ->get();
            $types = ['A' => 0, 'AAAA' => 0, 'CNAME' => 0];
            $latestTs = '';
            $conflictRisk = false;
            foreach ($dnsRows as $row) {
                $type = strtoupper(trim((string) ($row->type ?? '')));
                if (isset($types[$type])) { $types[$type]++; }
                $updatedAt = trim((string) ($row->updated_at ?? $row->created_at ?? ''));
                if ($updatedAt !== '' && ($latestTs === '' || strtotime($updatedAt) > strtotime($latestTs))) {
                    $latestTs = $updatedAt;
                }
            }
            if (($types['CNAME'] ?? 0) > 0 && (($types['A'] ?? 0) > 0 || ($types['AAAA'] ?? 0) > 0)) {
                $conflictRisk = true;
            }
            $timezone = trim((string) ($accountSnapshot['timezone'] ?? date_default_timezone_get()));
            $snapshotTime = trim((string) ($accountSnapshot['snapshot_time'] ?? date('Y-m-d H:i:s')));
            $lines = [];
            $lines[] = $isZh ? "【DNS 健康摘要】域名：{$domain}" : "[DNS Health Summary] Domain: {$domain}";
            $lines[] = "- A: " . ($types['A'] > 0 ? ($isZh ? "存在（{$types['A']}）" : "present ({$types['A']})") : ($isZh ? '不存在' : 'missing'));
            $lines[] = "- AAAA: " . ($types['AAAA'] > 0 ? ($isZh ? "存在（{$types['AAAA']}）" : "present ({$types['AAAA']})") : ($isZh ? '不存在' : 'missing'));
            $lines[] = "- CNAME: " . ($types['CNAME'] > 0 ? ($isZh ? "存在（{$types['CNAME']}）" : "present ({$types['CNAME']})") : ($isZh ? '不存在' : 'missing'));
            $lines[] = $isZh
                ? "- 最近修改时间：".($latestTs !== '' ? "{$latestTs} ({$timezone})" : '暂无记录')
                : "- Last update time: ".($latestTs !== '' ? "{$latestTs} ({$timezone})" : 'N/A');
            $lines[] = $isZh
                ? ('- 冲突风险：' . ($conflictRisk ? '较高（同名 CNAME 与 A/AAAA 可能冲突）' : '未见明显冲突'))
                : ('- Conflict risk: ' . ($conflictRisk ? 'elevated (same-name CNAME may conflict with A/AAAA)' : 'no obvious conflict detected'));
            $lines[] = $isZh
                ? "下一步建议：\n1) 在功能中心运行 Dig DNS 探测核验全球生效；\n2) 若新增记录仍报冲突，使用“孤儿记录自助修复（冲突清理）”；\n3) 如仍失败请提交工单。"
                : "Next steps:\n1) Run Dig DNS Probe in Feature Center to validate global propagation;\n2) If add-record still reports conflict, use Orphan Record Self-Repair (Conflict Cleanup);\n3) Submit a ticket if issues persist.";
            $lines[] = $isZh
                ? "数据更新时间（按系统服务器时间）：{$snapshotTime} ({$timezone})。"
                : "Data snapshot time (server timezone): {$snapshotTime} ({$timezone}).";
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function buildApiKeySummaryAnswer(string $question, int $requestUserId, array $settings = [], array $accountSnapshot = []): ?string
    {
        $q = strtolower(trim($question));
        if ($q === '') { return null; }
        $isApiIntent = strpos($q, 'api key') !== false || strpos($q, 'api密钥') !== false || strpos($q, 'api 密钥') !== false || strpos($q, '密钥') !== false;
        if (!$isApiIntent || $requestUserId <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) { return null; }
        $isZh = self::isChineseLanguage($settings) && !self::looksLikeEnglish($question);
        try {
            $rows = \WHMCS\Database\Capsule::table('mod_cloudflare_api_keys')
                ->select('status', 'permissions', 'updated_at', 'created_at')
                ->where('userid', $requestUserId)
                ->orderBy('id', 'desc')
                ->get();
            $total = count($rows);
            $active = 0;
            $disabled = 0;
            $latestTs = '';
            $permissionSet = [];
            foreach ($rows as $row) {
                $status = strtolower(trim((string) ($row->status ?? 'active')));
                if ($status === 'active') { $active++; } else { $disabled++; }
                $ts = trim((string) ($row->updated_at ?? $row->created_at ?? ''));
                if ($ts !== '' && ($latestTs === '' || strtotime($ts) > strtotime($latestTs))) { $latestTs = $ts; }
                $p = json_decode((string) ($row->permissions ?? '{}'), true);
                if (is_array($p)) {
                    foreach ($p as $k => $v) {
                        if (!empty($v)) { $permissionSet[strtolower((string) $k)] = true; }
                    }
                }
            }
            $maxKeys = max(1, (int) ($settings['api_keys_per_user'] ?? 3));
            $limitReached = $total >= $maxKeys;
            $timezone = trim((string) ($accountSnapshot['timezone'] ?? date_default_timezone_get()));
            $snapshotTime = trim((string) ($accountSnapshot['snapshot_time'] ?? date('Y-m-d H:i:s')));
            $permText = empty($permissionSet) ? ($isZh ? '未解析到权限元数据' : 'no permission metadata') : implode(', ', array_keys($permissionSet));
            $lines = [];
            $lines[] = $isZh ? '【API 密钥状态摘要】' : '[API Key Status Summary]';
            $lines[] = $isZh ? "- 密钥总数：{$total} / 上限 {$maxKeys}" : "- Total keys: {$total} / limit {$maxKeys}";
            $lines[] = $isZh ? "- 启用：{$active}，停用：{$disabled}" : "- Active: {$active}, Disabled: {$disabled}";
            $lines[] = $isZh ? "- 是否达到创建上限：".($limitReached ? '是' : '否') : "- Reached create limit: ".($limitReached ? 'yes' : 'no');
            $lines[] = $isZh ? "- 权限元数据（汇总）：{$permText}" : "- Permission metadata (summary): {$permText}";
            $lines[] = $isZh
                ? "- 最近操作时间：".($latestTs !== '' ? "{$latestTs} ({$timezone})" : '暂无')
                : "- Latest operation time: ".($latestTs !== '' ? "{$latestTs} ({$timezone})" : 'N/A');
            $lines[] = $isZh
                ? "安全说明：仅返回 key 元数据（数量/状态/权限/上限），不展示 secret/token 明文。"
                : "Security note: this summary returns key metadata only (count/status/permissions/limits), never secret/token plaintext.";
            $lines[] = $isZh
                ? "数据更新时间（按系统服务器时间）：{$snapshotTime} ({$timezone})。"
                : "Data snapshot time (server timezone): {$snapshotTime} ({$timezone}).";
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function buildDelegatedMeaningAnswer(string $question, array $settings = []): ?string
    {
        $q = strtolower(trim($question));
        if ($q === '') { return null; }
        $isZh = self::isChineseLanguage($settings) && !self::looksLikeEnglish($question);
        $isIntent = strpos($q, '已委派') !== false
            || strpos($q, '委派') !== false
            || strpos($q, 'delegated') !== false
            || strpos($q, 'nameserver delegated') !== false
            || strpos($q, 'domain delegated') !== false;
        if (!$isIntent) { return null; }
        if ($isZh) {
            return '已委派表示您的域名已经托管到了第三方 DNS 服务商（如 Cloudflare、DNSPod 等），需要您到对应服务商控制台进行 DNS 解析管理操作。';
        }
        return 'Delegated means your domain has been hosted with a third-party DNS provider (such as Cloudflare or DNSPod). You need to manage DNS records in that provider\'s control panel.';
    }

    private static function buildQuotaIncreaseAnswer(string $question, array $settings = []): ?string
    {
        $q = strtolower(trim($question));
        if ($q === '') { return null; }
        $isZh = self::isChineseLanguage($settings) && !self::looksLikeEnglish($question);
        $isIntent = strpos($q, '注册额度') !== false
            || strpos($q, '增加额度') !== false
            || strpos($q, '增加账户') !== false
            || strpos($q, 'increase quota') !== false
            || strpos($q, 'registration quota') !== false
            || strpos($q, 'more quota') !== false;
        if (!$isIntent) { return null; }
        if ($isZh) {
            return '您可以通过邀请好友注册和在功能中心-选择-绑定 Telegram 社群奖励和 GitHub 点赞奖励来获取更多的注册额度。';
        }
        return 'You can gain more registration quota by inviting friends to register, and in Feature Center choose to bind Telegram group rewards and GitHub star rewards.';
    }

    private static function normalizeHistory(array $history): array
    {
        $result = [];
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $role = strtolower(trim((string) ($item['role'] ?? 'user')));
            if (!in_array($role, ['user', 'assistant', 'model'], true)) {
                $role = 'user';
            }
            if ($role === 'model') {
                $role = 'assistant';
            }
            $result[] = [
                'role' => $role,
                'content' => self::truncateText($content, 1000),
            ];
        }

        if (count($result) > 10) {
            $result = array_slice($result, -10);
        }

        return $result;
    }

    private static function extractErrorMessage(array $decoded, string $rawBody): string
    {
        $error = trim((string) ($decoded['error']['message'] ?? ''));
        if ($error !== '') {
            return $error;
        }

        $fallback = trim($rawBody);
        if ($fallback === '') {
            return '';
        }

        return self::truncateText($fallback, 200);
    }

    private static function truncateText(string $text, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $maxChars) {
                return $text;
            }
            return mb_substr($text, 0, $maxChars, 'UTF-8');
        }

        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars);
    }

    private static function textLength(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }
        return strlen($text);
    }
}
