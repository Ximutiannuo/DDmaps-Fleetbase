<?php
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (env('DDMAPS_INTEGRATION_TEST') !== '1') throw new RuntimeException('Disposable test environment required.');
$company = Fleetbase\Models\Company::firstOrCreate(['name' => '整改验收公司']);
$user = Fleetbase\Models\User::firstOrNew(['email' => 'audit@example.test']);
$user->forceFill(['name' => '验收管理员', 'company_uuid' => $company->uuid, 'type' => 'admin', 'status' => 'active',
    'password' => 'DdMaps-Fixture-2026!', 'email_verified_at' => now()])->save();
$company->owner_uuid = $user->uuid; $company->save();
$membership = Fleetbase\Models\CompanyUser::firstOrCreate(['company_uuid' => $company->uuid, 'user_uuid' => $user->uuid], ['status' => 'active']);
session(['company' => $company->uuid]);
$role = Fleetbase\Models\Role::firstOrCreate(['name' => '验收管理员', 'guard_name' => 'sanctum', 'company_uuid' => $company->uuid]);
$membership->assignRole($role);
echo "Disposable browser fixture ready; audit@example.test in 整改验收公司.\n";
