<?php

declare(strict_types=1);

class CfHelpCenterContentService
{
    private static function sectionKeywords(string $sectionId, bool $isZh): array
    {
        $mapZh = [
            'core' => ['帮助', '新手', '步骤', '失败', '排查', '日志', '工单'],
            'domain' => ['域名', '注册', '到期', '删除', '升级', '激励', '灰度'],
            'dns' => ['dns', '解析', '记录', 'ttl', 'cname', 'a记录', 'mx', 'srv', 'caa', '冲突'],
        ];
        $mapEn = [
            'core' => ['help', 'start', 'steps', 'failure', 'troubleshoot', 'logs', 'ticket'],
            'domain' => ['domain', 'register', 'expiry', 'delete', 'upgrade', 'incentive', 'rollout'],
            'dns' => ['dns', 'record', 'ttl', 'cname', 'a', 'mx', 'srv', 'caa', 'conflict', 'dig'],
        ];
        $map = $isZh ? $mapZh : $mapEn;
        return (array) ($map[$sectionId] ?? []);
    }

    public static function getHelpFaqItems(string $locale = 'english'): array
    {
        $isZh = strtolower(trim($locale)) === 'chinese';
        $sections = self::getHelpSections($isZh);
        $rows = [];
        foreach ($sections as $section) {
            $sectionId = trim((string) ($section['id'] ?? ''));
            $title = trim((string) ($section['title'] ?? ''));
            $items = is_array($section['items'] ?? null) ? $section['items'] : [];
            foreach ($items as $tip) {
                $content = trim(strip_tags((string) $tip));
                if ($content === '') {
                    continue;
                }
                $baseKeywords = preg_split('/[\s,;|]+/u', strtolower($title . ' ' . $content)) ?: [];
                $extraKeywords = self::sectionKeywords($sectionId, $isZh);
                $rows[] = [
                    'category' => 'module_help',
                    'title' => $title !== '' ? $title : ($isZh ? '帮助中心' : 'Help Center'),
                    'keywords' => array_values(array_unique(array_filter(array_merge($baseKeywords, $extraKeywords)))),
                    'content' => $content,
                ];
            }
        }
        return $rows;
    }

    public static function getHelpSections(bool $isChinese = false): array
    {
        if ($isChinese) {
            return [
                ['id' => 'core', 'icon' => 'far fa-compass', 'title' => '新手必读', 'expanded' => true, 'items' => [
                    '先在“我的域名”中确认域名状态、到期时间与根域名限制，再进行 DNS 操作。',
                    '遇到失败先查看操作日志，优先排查权限、限流、灰度与根域名维护状态。',
                    '如需人工协助，请提交工单并附上域名、操作时间和报错截图，处理更快。',
                ]],
                ['id' => 'domain', 'icon' => 'far fa-clone', 'title' => '域名相关', 'expanded' => false, 'items' => [
                    '注册失败常见原因：额度不足、邀请准入未解锁、命中风控或根域名临时维护。',
                    '永久升级/激励功能有独立规则，请先确认活动窗口、灰度范围和资格条件。',
                    '删除域名前请确认当前策略：可能仅允许删除从未有过 DNS 记录的域名。',
                ]],
                ['id' => 'dns', 'icon' => 'far fa-hdd', 'title' => 'DNS 相关', 'expanded' => false, 'items' => [
                    '新增记录前建议先检查同名同类型记录是否已存在，避免冲突。',
                    '记录修改后未立即生效通常是传播延迟，TTL 默认 600 秒可作为参考。',
                    '配置 SRV、CAA、MX 等记录时，请完整填写优先级与值并确认格式。',
                ]],
            ];
        }

        return [
            ['id' => 'core', 'icon' => 'far fa-compass', 'title' => 'Getting Started', 'expanded' => true, 'items' => [
                'Check domain status, expiry, and root-domain limits before changing DNS.',
                'If an action fails, review logs first: permissions, rate limits, gray rollout, and maintenance.',
                'For manual support, submit a ticket with domain, timestamp, and error screenshot.',
            ]],
            ['id' => 'domain', 'icon' => 'far fa-clone', 'title' => 'Domain Topics', 'expanded' => false, 'items' => [
                'Common registration failures: insufficient quota, invite gate not unlocked, risk control, or maintenance.',
                'Permanent upgrade/incentive has its own rules: verify campaign window, rollout scope, and eligibility.',
                'Before deleting a domain, verify current policy (some modes only allow domains with no DNS history).',
            ]],
            ['id' => 'dns', 'icon' => 'far fa-hdd', 'title' => 'DNS Topics', 'expanded' => false, 'items' => [
                'Before adding a record, check if same-name same-type records already exist to avoid conflicts.',
                'Propagation delay is common after updates; default TTL is 600 seconds as a baseline.',
                'For SRV/CAA/MX records, fill all required fields (such as priority) and verify format.',
            ]],
        ];
    }
}
