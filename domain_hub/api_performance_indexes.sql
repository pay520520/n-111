-- API性能优化索引
-- 用于优化API域名列表查询、搜索和过滤功能
-- 针对1万+域名场景的性能优化

-- 1. 优化用户ID查询的复合索引（最重要）
-- 加速分页、状态过滤和排序
ALTER TABLE `mod_cloudflare_subdomain` 
ADD INDEX `idx_userid_status_created` (`userid`, `status`, `created_at`);

-- 2. 优化按过期时间查询和排序
ALTER TABLE `mod_cloudflare_subdomain` 
ADD INDEX `idx_userid_expires` (`userid`, `expires_at`);

-- 3. 优化按更新时间排序
ALTER TABLE `mod_cloudflare_subdomain` 
ADD INDEX `idx_userid_updated` (`userid`, `updated_at`);

-- 4. 优化域名前缀搜索（仅适用于前缀匹配）
-- 如：subdomain LIKE 'test%'
ALTER TABLE `mod_cloudflare_subdomain` 
ADD INDEX `idx_userid_subdomain` (`userid`, `subdomain`(50));

-- 5. 优化根域名过滤
-- 加速按根域名筛选的查询
ALTER TABLE `mod_cloudflare_subdomain` 
ADD INDEX `idx_userid_rootdomain` (`userid`, `rootdomain`(50));

-- 6. 优化复合查询：用户ID + 根域名 + 状态
-- 加速组合过滤查询
ALTER TABLE `mod_cloudflare_subdomain` 
ADD INDEX `idx_userid_root_status` (`userid`, `rootdomain`(50), `status`);

-- 7. 验证索引是否创建成功
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'mod_cloudflare_subdomain'
  AND INDEX_NAME LIKE 'idx_userid%'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

-- 性能测试查询（可选）
-- 测试1：分页查询（第100页，每页100条）
-- EXPLAIN SELECT * FROM mod_cloudflare_subdomain WHERE userid = 1 ORDER BY id DESC LIMIT 9900, 100;

-- 测试2：搜索查询
-- EXPLAIN SELECT * FROM mod_cloudflare_subdomain WHERE userid = 1 AND subdomain LIKE 'test%' LIMIT 100;

-- 测试3：根域名过滤
-- EXPLAIN SELECT * FROM mod_cloudflare_subdomain WHERE userid = 1 AND rootdomain = 'example.com' LIMIT 100;

-- 测试4：状态过滤 + 排序
-- EXPLAIN SELECT * FROM mod_cloudflare_subdomain WHERE userid = 1 AND status = 'active' ORDER BY created_at DESC LIMIT 100;

-- 测试5：组合查询
-- EXPLAIN SELECT * FROM mod_cloudflare_subdomain WHERE userid = 1 AND rootdomain = 'example.com' AND status = 'active' ORDER BY created_at DESC LIMIT 100;

-- 预期性能提升：
-- - 基础分页查询：5-10倍
-- - 根域名过滤：10-20倍
-- - 状态过滤：5-10倍
-- - 组合查询：10-30倍
-- - COUNT查询：3-5倍
