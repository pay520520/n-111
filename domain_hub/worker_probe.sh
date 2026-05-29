#!/bin/bash
set -x
echo "==== $(date '+%F %T') START ===="
whoami
id
pwd
echo "PHP: $(/www/server/php/74/bin/php -v | head -n 1)"
echo "MODULES:"
/www/server/php/74/bin/php -m | egrep -i 'pdo|mysql|ioncube' || true

cd /www/wwwroot/my.dnshe.com || { echo "cd failed"; exit 2; }

# 1) 先看 WHMCS 能否加载
/www/server/php/74/bin/php -d display_errors=1 -d error_reporting=32767 -r "
chdir('/www/wwwroot/my.dnshe.com');
require 'init.php';
echo 'init_ok'.PHP_EOL;
require 'modules/addons/domain_hub/worker.php';
echo 'worker_loaded'.PHP_EOL;
echo 'run_fn='.(function_exists('run_cf_queue_once')?'yes':'no').PHP_EOL;
"

# 2) 实跑一次队列
/www/server/php/74/bin/php -d display_errors=1 -d error_reporting=32767 /www/wwwroot/my.dnshe.com/modules/addons/domain_hub/worker.php 5
echo "exit_code=$?"

# 3) 看锁文件
ls -l /tmp/domain_hub_worker.lock || true
lsof /tmp/domain_hub_worker.lock || true

echo "==== $(date '+%F %T') END ===="