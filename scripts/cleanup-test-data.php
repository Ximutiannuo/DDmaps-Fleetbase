<?php
// Reviewed reset for this audited installation. Default is a read-only preview.
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$execute = in_array('--execute-confirmed-test-data', $argv, true);
$expectedDatabase = null;
foreach ($argv as $argument) if (str_starts_with($argument, '--expected-database=')) $expectedDatabase = substr($argument, 20);
if ($execute && (!$expectedDatabase || DB::connection()->getDatabaseName() !== $expectedDatabase)) {
    fwrite(STDERR, "Explicit expected database does not match; refusing cleanup.\n"); exit(1);
}
$geography = ['service_areas', 'zones', 'site_roads', 'road_segments', 'road_segments_bak_20260813',
    'road_conditions', 'road_traffic_controls', 'gates', 'avoid_areas', 'road_events', 'construction_activities',
    'construction_activity_road_segment'];
$tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));
$geography = array_values(array_intersect($geography, $tables));
$admins = DB::table('users')->where('type', 'admin')->whereNull('deleted_at')->get(['uuid', 'company_uuid']);
if ($admins->isEmpty()) throw new RuntimeException('No retained administrator; refusing cleanup.');
$keepUsers = $admins->pluck('uuid')->all();
$keepCompanies = $admins->pluck('company_uuid')->filter()->all();
foreach ($geography as $table) {
    if (Schema::hasColumn($table, 'company_uuid')) $keepCompanies = array_merge($keepCompanies,
        DB::table($table)->whereNotNull('company_uuid')->distinct()->pluck('company_uuid')->all());
}
$keepCompanies = array_values(array_unique($keepCompanies));
$removeUsers = DB::table('users')->whereNotIn('uuid', $keepUsers)->pluck('uuid')->all();
$removeCompanies = DB::table('companies')->whereNotIn('uuid', $keepCompanies)->pluck('uuid')->all();
$removeMemberships = DB::table('company_users')->whereIn('user_uuid', $removeUsers)->pluck('uuid')->all();
$purge = ['api_events', 'drivers', 'vehicles', 'entry_registrations', 'entry_admissions', 'entry_session_audits',
    'vehicle_construction_meta', 'positions', 'site_notifications', 'fleets', 'ledger_wallets', 'personal_access_tokens'];
$targets = [];
foreach ($tables as $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new RuntimeException('Unexpected table name');
    if (in_array($table, $geography, true) || in_array($table, ['users', 'companies', 'migrations', 'failed_jobs'], true)) continue;
    $all = in_array($table, $purge, true);
    $columns = Schema::getColumnListing($table);
    $query = DB::table($table);
    if (!$all) {
        $query->where(function ($q) use ($columns, $removeCompanies, $removeUsers, $removeMemberships, $table) {
            $q->whereRaw('1 = 0');
            if (in_array('company_uuid', $columns)) $q->orWhereIn('company_uuid', $removeCompanies);
            if (in_array('user_uuid', $columns)) $q->orWhereIn('user_uuid', $removeUsers);
            if (in_array('model_uuid', $columns)) $q->orWhereIn('model_uuid', array_merge($removeUsers, $removeMemberships));
            if (in_array('model_id', $columns)) $q->orWhereIn('model_id', array_merge($removeUsers, $removeMemberships));
            if ($table === 'user_devices') $q->orWhereIn('user_uuid', $removeUsers);
        });
    }
    if ($query->count()) $targets[$table] = $query;
}
$targets['users'] = DB::table('users')->whereIn('uuid', $removeUsers);
$targets['companies'] = DB::table('companies')->whereIn('uuid', $removeCompanies);
$snapshot = function () use ($geography) {
    $result = [];
    foreach ($geography as $table) {
        $rows = DB::table($table)->orderBy('id')->get()->map(function ($row) {
            $data = (array) $row;
            foreach (['created_by_uuid', 'updated_by_uuid', 'reported_by_uuid'] as $key) unset($data[$key]);
            return $data;
        });
        // MySQL spatial columns contain binary WKB and cannot be JSON encoded.
        $result[$table] = ['count' => $rows->count(), 'sha256' => hash('sha256', serialize($rows->all()))];
    }
    return $result;
};
$before = $snapshot();
$manifest = ['mode' => $execute ? 'execute' : 'preview', 'database' => DB::connection()->getDatabaseName(), 'created_at' => now()->toIso8601String(),
    'retained_administrators' => count($keepUsers), 'retained_companies' => count($keepCompanies),
    'delete_counts' => array_map(fn ($q) => $q->count(), $targets), 'geography_before' => $before];
if (!$execute) { echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }

DB::beginTransaction();
try {
    // Retained configuration must not reference removed authors/owners.
    foreach ($tables as $table) {
        if (in_array($table, ['users', 'companies'], true)) continue;
        $columns = Schema::getColumnListing($table);
        foreach (['created_by_uuid', 'updated_by_uuid', 'reported_by_uuid', 'owner_uuid'] as $field) {
            if (in_array($field, $columns)) {
                $nullable = DB::selectOne('SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $field]);
                DB::table($table)->whereIn($field, $removeUsers)->update([$field => $nullable->n === 'YES' ? null : $keepUsers[0]]);
            }
        }
    }
    DB::table('companies')->whereIn('uuid', $keepCompanies)->whereIn('owner_uuid', $removeUsers)->update(['owner_uuid' => $keepUsers[0]]);
    // Keep foreign-key checks enabled. Delete children before parents, retrying
    // only integrity constraints; any unresolved dependency rolls back everything.
    $pending = $targets; $deleted = [];
    while ($pending) {
        $progress = false;
        foreach ($pending as $table => $query) {
            try {
                $deleted[$table] = $query->delete();
                unset($pending[$table]); $progress = true;
            } catch (Illuminate\Database\QueryException $error) {
                if ((string) $error->getCode() !== '23000') throw $error;
            }
        }
        if (!$progress) throw new RuntimeException('Unresolved foreign key dependencies: ' . implode(', ', array_keys($pending)));
    }
    if (DB::table('users')->whereNotIn('uuid', $keepUsers)->exists() || DB::table('companies')->whereNotIn('uuid', $keepCompanies)->exists()) {
        throw new RuntimeException('Cleanup did not reach intended users/companies');
    }
    $after = $snapshot();
    if ($before !== $after) throw new RuntimeException('Retained geographic configuration changed; rolled back.');
    foreach ($admins as $admin) {
        if (!DB::table('company_users')->where('user_uuid', $admin->uuid)->where('company_uuid', $admin->company_uuid)->where('status', 'active')->exists()) {
            throw new RuntimeException('Retained administrator membership is unavailable');
        }
    }
    DB::commit();
    $manifest['deleted_counts'] = $deleted;
    $manifest['geography_after'] = $after;
    $manifest['status'] = 'committed';
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} catch (\Throwable $error) {
    DB::rollBack(); fwrite(STDERR, 'Cleanup rolled back: ' . $error->getMessage() . "\n"); exit(1);
}
