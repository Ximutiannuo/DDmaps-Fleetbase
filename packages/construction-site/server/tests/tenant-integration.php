<?php
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
function tenantCheck($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
DB::beginTransaction();
try {
    $a = (string) Str::uuid(); $b = (string) Str::uuid();
    foreach ([$a, $b] as $company) DB::table('companies')->insert(['uuid' => $company, 'name' => 'Tenant rollback fixture']);
    $resources = [
        'SiteRoad' => ['site_roads', ['name' => 'Fixture', 'road_type' => 'main']],
        'RoadSegment' => ['road_segments', []], 'Gate' => ['gates', ['name' => 'Fixture', 'gate_type' => 'both']],
        'RoadCondition' => ['road_conditions', []],
        'RoadEvent' => ['road_events', ['title' => 'Fixture', 'event_type' => 'other']],
        'ConstructionActivity' => ['construction_activities', ['title' => 'Fixture', 'type' => 'road_work']],
        'VehicleMeta' => ['vehicle_construction_meta', []],
        'EntryRegistration' => ['entry_registrations', []],
        'SiteNotification' => ['site_notifications', ['title' => 'Fixture', 'message' => 'Fixture']],
        'AvoidArea' => ['avoid_areas', ['service_area_uuid' => (string) Str::uuid(), 'geometry' => '{}']],
    ];
    foreach ($resources as $name => [$table, $fields]) {
        $ids = [];
        foreach ([$a, $b] as $company) {
            $id = $ids[] = (string) Str::uuid();
            $row = ['uuid' => $id, 'public_id' => 'fixture_' . $id, 'company_uuid' => $company] + $fields;
            if ($table === 'vehicle_construction_meta') $row['vehicle_uuid'] = (string) Str::uuid();
            if ($table === 'entry_registrations') $row['session_token'] = hash('sha256', $id);
            DB::table($table)->insert($row);
        }
        session(['company' => $a]);
        $controller = app('Fleetbase\\ConstructionSite\\Http\\Controllers\\' . $name . 'Controller');
        foreach ([[], ['company_uuid' => $b], ['uuid' => $ids[1]], ['single' => true, 'uuid' => $ids[1]]] as $filter) {
            $request = Request::create('/construction-site/int/v1/fixture', 'GET', $filter);
            $request->setLaravelSession(app('session.store'));
            $route = new Illuminate\Routing\Route('GET', 'construction-site/int/v1/fixture', ['uses' => get_class($controller) . '@queryRecord', 'controller' => get_class($controller) . '@queryRecord']);
            $route->controller = $controller;
            $request->setRouteResolver(fn () => $route);
            app()->instance('request', $request);
            $response = $controller->queryRecord($request);
            $json = json_encode($response instanceof Illuminate\Http\JsonResponse ? $response->getData(true) : $response->resolve($request));
            tenantCheck(!str_contains($json, $ids[1]), "$name query excludes foreign company (" . json_encode($filter) . ')');
            if (!$filter) tenantCheck(str_contains($json, $ids[0]), "$name query retains own record");
        }
        foreach (['findRecord', 'updateRecord', 'deleteRecord'] as $method) {
            try {
                $request = Request::create('/fixture', $method === 'findRecord' ? 'GET' : 'PATCH', ['name' => 'changed']);
                $method === 'deleteRecord' ? $controller->$method($ids[1], $request) : $controller->$method($request, $ids[1]);
                throw new RuntimeException("$name $method allowed foreign company");
            } catch (Illuminate\Database\Eloquent\ModelNotFoundException $expected) {
                tenantCheck(true, "$name $method rejects foreign company");
            }
        }
    }
    $gate = app(Fleetbase\ConstructionSite\Http\Controllers\GateController::class);
    $created = $gate->createRecord(Request::create('/fixture', 'POST', ['gate' => ['name' => 'Owned gate', 'gate_type' => 'both', 'company_uuid' => $b]]));
    tenantCheck(is_array($created) && $created['gate']->resource->company_uuid === $a, 'Generic create forces authenticated company');
    $segment = DB::table('road_segments')->where('company_uuid', $b)->value('uuid');
    $event = app(Fleetbase\ConstructionSite\Http\Controllers\RoadEventController::class)->createRecord(Request::create('/fixture', 'POST',
        ['road_event' => ['title' => 'Bad association', 'event_type' => 'other', 'road_segment_uuid' => $segment]]));
    tenantCheck($event->getStatusCode() === 422, 'Foreign road relation rejected');
    echo "Tenant integration passed; fixtures rolled back.\n";
} catch (\Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
finally { DB::rollBack(); }
