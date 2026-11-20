<?php
// Invoked only by the explicit first-install CLI. Input arrives on stdin, never argv.
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

try {
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)
        || strlen($data['password'] ?? '') < 12
        || !trim($data['company'] ?? '') || !trim($data['name'] ?? '')) {
        throw new RuntimeException('Invalid first-administrator input.');
    }
    if (DB::table('users')->exists() || DB::table('companies')->exists()) {
        throw new RuntimeException('First-install only: users or companies already exist. No account was changed.');
    }
    DB::transaction(function () use ($data) {
        $company = (new Company())->forceFill(['name' => $data['company'], 'status' => 'active']);
        $company->save();
        // The model hashes a plain password; do not hash it a second time.
        $user = (new User())->forceFill(['name' => $data['name'], 'email' => $data['email'],
            'password' => $data['password'], 'company_uuid' => $company->uuid,
            'type' => 'admin', 'status' => 'active', 'email_verified_at' => now()]);
        $user->save();
        $company->owner_uuid = $user->uuid;
        $company->save();
        (new CompanyUser())->forceFill(['company_uuid' => $company->uuid,
            'user_uuid' => $user->uuid, 'status' => 'active'])->save();
    });
    if (Artisan::call('fleetbase:create-permissions') !== 0
        || Artisan::call('fleetbase:assign-admin-roles') !== 0) {
        throw new RuntimeException('Account created, but role setup failed. Follow the role-repair commands in DEPLOY.zh-CN.md.');
    }
    echo "First administrator and company created. Use your chosen email/password to log in.\n";
} catch (Throwable $exception) {
    // Do not print exception input or a stack containing the administrator's password.
    fwrite(STDERR, "First-admin setup failed; existing accounts are never overwritten.\n");
    exit(1);
}
