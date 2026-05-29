<?php
/**
 * 根域名邀请日志表迁移脚本
 * 添加缺失的 subdomain 字段
 * 
 * 问题: Column not found: 1054 Unknown column 'subdomain' in 'field list'
 * 原因: 表创建于添加此功能之前，缺少 subdomain 字段
 * 解决: 添加 subdomain 字段到现有表
 */

// 确保在 WHMCS 环境中运行
if (!defined('ROOTDIR')) {
    define('ROOTDIR', dirname(dirname(dirname(__DIR__))));
}

require_once ROOTDIR . '/init.php';

use WHMCS\Database\Capsule;

echo "=== 根域名邀请日志表迁移 ===\n\n";

try {
    $tableName = 'mod_cloudflare_rootdomain_invite_logs';
    
    // 检查表是否存在
    if (!Capsule::schema()->hasTable($tableName)) {
        echo "[错误] 表 {$tableName} 不存在\n";
        echo "       请先运行模块激活创建表\n";
        exit(1);
    }
    
    echo "[信息] 表 {$tableName} 存在\n";
    
    // 检查字段是否存在
    $hasColumn = Capsule::schema()->hasColumn($tableName, 'subdomain');
    
    if ($hasColumn) {
        echo "[成功] 字段 'subdomain' 已存在，无需迁移\n";
        exit(0);
    }
    
    echo "[警告] 字段 'subdomain' 不存在，开始添加...\n";
    
    // 添加字段
    Capsule::schema()->table($tableName, function ($table) {
        $table->string('subdomain', 255)->nullable()->after('invitee_email');
    });
    
    // 验证字段已添加
    $hasColumnAfter = Capsule::schema()->hasColumn($tableName, 'subdomain');
    
    if ($hasColumnAfter) {
        echo "[成功] 字段 'subdomain' 已成功添加\n\n";
        
        // 显示表结构
        $columns = Capsule::select("
            SELECT 
                COLUMN_NAME,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ", [$tableName]);
        
        echo "当前表结构:\n";
        echo str_repeat('-', 80) . "\n";
        printf("%-30s %-25s %-10s %-15s\n", '字段名', '类型', '允许NULL', '默认值');
        echo str_repeat('-', 80) . "\n";
        
        foreach ($columns as $column) {
            printf(
                "%-30s %-25s %-10s %-15s\n",
                $column->COLUMN_NAME,
                $column->COLUMN_TYPE,
                $column->IS_NULLABLE,
                $column->COLUMN_DEFAULT ?? 'NULL'
            );
        }
        echo str_repeat('-', 80) . "\n";
        
        echo "\n[完成] 迁移成功完成\n";
        exit(0);
    } else {
        echo "[错误] 字段添加失败\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "[错误] 迁移失败: " . $e->getMessage() . "\n";
    echo "       错误位置: " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}
