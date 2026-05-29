<?php
$file = '/home/engine/project/domain_hub/worker.php';
$content = file_get_contents($file);

$old = <<<OLD
    foreach (\$subsArray as \$sub) {
        \$stats['processed_subdomains']++;
        \$subId = intval(\$sub->id ?? 0);
        if (\$subId <= 0) {
            \$stats['warnings'][] = 'subdomain:invalid';
            continue;
        }

        try {
            if (\$deleteRecords) {
                \$deleted = cfmod_admin_deep_delete_subdomain(null, \$sub);
                \$stats['dns_records_deleted'] += max(0, intval(\$deleted));
            }

            if (\$deleteDomains) {
                \$recordsToDelete[\$subId] = \$sub;
                \$quotaUserId = intval(\$sub->userid ?? 0);
                if (\$quotaUserId > 0) {
                    \$quotaDecrements[\$quotaUserId] = intval(\$quotaDecrements[\$quotaUserId] ?? 0) + 1;
                }
            }
        } catch (\Throwable \$e) {
            \$stats['warnings'][] = 'subdomain:' . \$subId;
            cfmod_report_exception('cleanup_user_subdomains', \$e);
        }
    }
OLD;

$new = <<<NEW
    foreach (\$subsArray as \$sub) {
        if (function_exists('cfmod_worker_touch_progress')) {
            cfmod_worker_touch_progress(true);
        }
        \$stats['processed_subdomains']++;
        \$subId = intval(\$sub->id ?? 0);
        if (\$subId <= 0) {
            \$stats['warnings'][] = 'subdomain:invalid';
            continue;
        }

        try {
            if (\$deleteRecords) {
                \$deleted = cfmod_admin_deep_delete_subdomain(null, \$sub);
                \$stats['dns_records_deleted'] += max(0, intval(\$deleted));
            }

            if (\$deleteDomains) {
                \$recordsToDelete[\$subId] = \$sub;
                \$quotaUserId = intval(\$sub->userid ?? 0);
                if (\$quotaUserId > 0) {
                    \$quotaDecrements[\$quotaUserId] = intval(\$quotaDecrements[\$quotaUserId] ?? 0) + 1;
                }
            }
        } catch (\Throwable \$e) {
            \$stats['warnings'][] = 'subdomain:' . \$subId;
            cfmod_report_exception('cleanup_user_subdomains', \$e);
        }
    }
NEW;

$content = str_replace($old, $new, $content);
file_put_contents($file, $content);
echo "Replaced successfully!\n";
