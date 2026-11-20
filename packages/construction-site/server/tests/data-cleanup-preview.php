<?php
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

// Read-only. A name suggests review; it never establishes that a record is disposable.
$report = ['generated_at' => now()->toIso8601String(), 'mode' => 'review-only', 'candidates' => []];
foreach (DB::table('companies')->whereNull('deleted_at')->orderBy('uuid')->get(['uuid', 'name']) as $company) {
    if (!preg_match('/test|fixture|测试|验收/i', $company->name)) continue;
    $references = [];
    foreach (['users', 'company_users', 'vehicles', 'drivers', 'entry_registrations', 'site_roads', 'road_segments', 'road_conditions', 'road_traffic_controls', 'site_notifications', 'entry_admissions', 'entry_session_audits'] as $table) {
        $references[$table] = DB::table($table)->where('company_uuid', $company->uuid)->count();
    }
    $report['candidates'][] = ['uuid' => $company->uuid, 'name' => $company->name, 'reason' => 'Name matches a review hint; ownership and purpose unverified', 'references' => $references];
}
$report['counts'] = ['users_without_email' => DB::table('users')->whereNull('email')->count(),
    'active_entries' => DB::table('entry_registrations')->where('status', 'active')->whereNull('exited_at')->count()];
$report['deletion_allowed'] = false;
$report['note'] = 'No-email users may be legitimate drivers. No records were changed. Require an explicit UUID allowlist, verified ownership, reference handling, and a fresh backup before deletion.';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
