#!/bin/bash
set -euo pipefail

APP="/www/wwwroot/my.dnshe.com"
PHP="/www/server/php/74/bin/php"
LOG="$APP/modules/addons/domain_hub/worker.log"
LOCK="/tmp/domain_hub_worker.cron.lock"

cd "$APP"

/usr/bin/flock -n "$LOCK" /usr/bin/timeout 55s \
  "$PHP" -r "require 'init.php'; require 'modules/addons/domain_hub/worker.php'; run_cf_queue_once(5);" \
  >> "$LOG" 2>&1