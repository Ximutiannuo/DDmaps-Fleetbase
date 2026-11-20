#!/bin/sh
set -eu
cd /fleetbase/api
for test in security-integration tenant-integration traffic-integration road-import-integration road-section-delete-integration road-recovery-integration; do
    php "../packages/construction-site/server/tests/$test.php"
done
if [ "${DDMAPS_INTEGRATION_TEST:-}" = "1" ]; then
    php ../packages/construction-site/server/tests/security-concurrency.php
    php ../packages/construction-site/server/tests/http-security-integration.php
fi
php artisan package:discover --no-ansi >/dev/null
php artisan route:list --path=construction-site --json | php -r '$r=json_decode(stream_get_contents(STDIN),true); if(count($r)<70) exit(1); echo "PASS routes survive package rediscovery\n";'
