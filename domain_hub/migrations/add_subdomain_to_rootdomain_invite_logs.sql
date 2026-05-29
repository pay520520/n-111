-- 添加 subdomain 字段到根域名邀请日志表
-- 执行日期: 2024-01-21
-- 问题: Column not found: 1054 Unknown column 'subdomain' in 'field list'

-- 检查表是否存在
SET @table_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mod_cloudflare_rootdomain_invite_logs'
);

-- 如果表存在，检查字段是否存在
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mod_cloudflare_rootdomain_invite_logs'
    AND COLUMN_NAME = 'subdomain'
);

-- 添加 subdomain 字段（如果不存在）
SET @sql = IF(
    @table_exists > 0 AND @column_exists = 0,
    'ALTER TABLE `mod_cloudflare_rootdomain_invite_logs` 
     ADD COLUMN `subdomain` VARCHAR(255) NULL DEFAULT NULL AFTER `invitee_email`',
    'SELECT "Field subdomain already exists or table does not exist" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 验证字段已添加
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'mod_cloudflare_rootdomain_invite_logs'
AND COLUMN_NAME = 'subdomain';
