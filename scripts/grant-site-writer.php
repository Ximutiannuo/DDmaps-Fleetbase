<?php
// Explicit operator action: php /tmp/grant-site-writer.php COMPANY_UUID USER_UUID
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (count($argv) !== 3 || !Illuminate\Support\Str::isUuid($argv[1]) || !Illuminate\Support\Str::isUuid($argv[2])) {
    fwrite(STDERR, "Usage: grant-site-writer.php COMPANY_UUID USER_UUID\n"); exit(1);
}
session(['company' => $argv[1]]);
$member = Fleetbase\Models\CompanyUser::where('company_uuid', $argv[1])->where('user_uuid', $argv[2])->where('status', 'active')->firstOrFail();
$permission = Fleetbase\Models\Permission::firstOrCreate(['name' => 'construction-site write', 'guard_name' => 'sanctum']);
$member->givePermissionTo($permission);
echo "Construction-site write permission granted to the specified existing company member.\n";
