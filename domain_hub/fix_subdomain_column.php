<?php
/**
 * 快速修复脚本 - 添加 subdomain 字段
 * 
 * 使用方法:
 * 1. 通过浏览器访问: http://yourdomain.com/modules/addons/domain_hub/fix_subdomain_column.php
 * 2. 或通过 CLI: php fix_subdomain_column.php
 */

use WHMCS\Database\Capsule;

// 尝试在 WHMCS 环境中运行
$whmcsInit = dirname(dirname(dirname(__DIR__))) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
} else {
    die("错误: 无法找到 WHMCS init.php 文件\n请确保此脚本位于 modules/addons/domain_hub/ 目录下\n");
}

// 设置内容类型
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "修复根域名邀请日志表\n";
echo str_repeat('=', 50) . "\n\n";

try {
    $table = 'mod_cloudflare_rootdomain_invite_logs';
    
    // 1. 检查表是否存在
    echo "[1/3] 检查表是否存在...\n";
    if (!Capsule::schema()->hasTable($table)) {
        echo "      ❌ 表不存在: {$table}\n";
        echo "      请先在 WHMCS 后台激活模块\n";
        exit(1);
    }
    echo "      ✓ 表存在\n\n";
    
    // 2. 检查 subdomain 字段
    echo "[2/3] 检查 subdomain 字段...\n";
    if (Capsule::schema()->hasColumn($table, 'subdomain')) {
        echo "      ✓ 字段已存在，无需修复\n\n";
        echo "修复完成！\n";
        exit(0);
    }
    echo "      ⚠ 字段不存在，需要添加\n\n";
    
    // 3. 添加字段
    echo "[3/3] 添加 subdomain 字段...\n";
    Capsule::schema()->table($table, function ($table) {
        $table->string('subdomain', 255)->nullable()->after('invitee_email');
    });
    
    // 验证
    if (Capsule::schema()->hasColumn($table, 'subdomain')) {
        echo "      ✓ 字段添加成功\n\n";
        echo str_repeat('=', 50) . "\n";
        echo "修复完成！现在可以正常使用根域名邀请功能了。\n";
        exit(0);
    } else {
        echo "      ❌ 字段添加失败\n\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "\n错误: " . $e->getMessage() . "\n";
    echo "详情: " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}
