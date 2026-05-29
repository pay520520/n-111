<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$cwd = getcwd();
$dirs = [$cwd, dirname($cwd), dirname(dirname($cwd)), dirname(dirname(dirname($cwd)))];
foreach ($dirs as $dir) {
    if (is_file($dir . '/init.php')) {
        require_once $dir . '/init.php';
        break;
    }
}

require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/api_handler.php';

use WHMCS\Database\Capsule;

$chunk = max(100, intval($argv[1] ?? 1000));
$maxLoops = max(1, intval($argv[2] ?? 100000));
$processed = 0;
$updated = 0;
$loops = 0;

if (!CfSubdomainIdResolver::hasPublicIdColumn()) {
    echo "public_id column missing, run module upgrade first.\n";
    exit(1);
}

while ($loops < $maxLoops) {
    $loops++;
    $rows = Capsule::table('mod_cloudflare_subdomain')
        ->whereNull('public_id')
        ->orderBy('id', 'asc')
        ->limit($chunk)
        ->get(['id']);

    if ($rows instanceof \Illuminate\Support\Collection) {
        $rows = $rows->all();
    }
    if (!is_array($rows) || count($rows) === 0) {
        break;
    }

    foreach ($rows as $row) {
        $id = intval($row->id ?? 0);
        if ($id <= 0) {
            continue;
        }
        $processed++;
        $val = api_get_or_create_public_subdomain_id($id, true);
        if ($val > 0) {
            $updated++;
        }
    }

    echo sprintf("loop=%d processed=%d updated=%d\n", $loops, $processed, $updated);
}

$remaining = (int) Capsule::table('mod_cloudflare_subdomain')->whereNull('public_id')->count();
echo sprintf("done processed=%d updated=%d remaining_null=%d\n", $processed, $updated, $remaining);
exit(0);
