-- 邀请注册邀请码池（上线级独立迁移）
-- 执行前请先备份数据库

CREATE TABLE IF NOT EXISTS `mod_cloudflare_invite_registration_code_pool` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_userid` int(10) unsigned NOT NULL,
  `invite_code` varchar(20) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'unused',
  `used_by_userid` int(10) unsigned DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invite_code` (`invite_code`),
  KEY `idx_owner_status` (`owner_userid`,`status`),
  KEY `idx_invite_code` (`invite_code`),
  KEY `idx_used_by_userid` (`used_by_userid`),
  KEY `idx_used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
