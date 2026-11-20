<?php

// Run inside the application container. All fixtures live in one rolled-back transaction.
// docker compose exec -T application php ../packages/construction-site/server/tests/traffic-integration.php
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\ConstructionSite\Http\Controllers\RoadTrafficController;
use Fleetbase\ConstructionSite\Http\Controllers\TrackingController;
use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function trafficCheck(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo "PASS $description\n";
}

Event::fake([
    Fleetbase\ConstructionSite\Events\RoadNetworkChanged::class,
    Fleetbase\ConstructionSite\Events\SiteNotificationCreated::class,
]);
$company = (string) Str::uuid();
session(['company' => $company]);
DB::beginTransaction();
try {
    DB::table('companies')->insert(['uuid' => $company, 'name' => 'Traffic integration fixture']);
    $area = ServiceArea::create([
        'company_uuid' => $company, 'name' => 'Traffic integration fixture', 'status' => 'active',
        'border' => new MultiPolygon([new Polygon([new LineString([
            new Point(60.59, -135.74), new Point(60.59, -135.71), new Point(60.61, -135.71), new Point(60.61, -135.74), new Point(60.59, -135.74),
        ])])]),
    ]);
    $roads = [];
    foreach ([
        'west' => [[-135.73, 60.6], [-135.725, 60.6]],
        'east' => [[-135.725, 60.6], [-135.72, 60.6]],
        'detour' => [[-135.73, 60.6], [-135.73, 60.601], [-135.72, 60.601], [-135.72, 60.6]],
    ] as $name => $coordinates) {
        $roads[$name] = RoadSegment::create(['company_uuid' => $company, 'name' => $name, 'status' => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates]]);
    }
    foreach (['fresh', 'fresh', 'fresh', 'expired', 'exited', 'disabled', 'inaccurate', 'unknown-accuracy'] as $type) {
        $entry = EntryRegistration::create(['company_uuid' => $company, 'status' => $type === 'exited' ? 'exited' : 'active', 'tracking_enabled' => $type !== 'disabled']);
        $entry->forceFill(['gps_latitude' => 60.6, 'gps_longitude' => -135.728, 'gps_speed' => null,
            'gps_accuracy' => $type === 'unknown-accuracy' ? null : ($type === 'inaccurate' ? 150 : 10),
            'gps_reported_at' => $type === 'expired' ? now()->subMinutes(3) : now()])->saveQuietly();
    }
    $service = app(RoadTrafficService::class);
    $snapshot = $service->snapshot($company);
    $west = collect($snapshot['road_segments'])->firstWhere('road_segment_uuid', $roads['west']->uuid);
    trafficCheck($west['driver_count'] === 3 && $west['congestion'] === 'light', 'snapshot excludes stale, exited, disabled, inaccurate and unknown-accuracy GPS');
    $controller = app(RoadTrafficController::class);
    $undrawn = RoadSegment::create(['company_uuid' => $company, 'name' => 'Undrawn road', 'status' => 'open']);
    $areaRequest = Request::create('/', 'GET', ['service_area_uuid' => $area->uuid]);
    $areaNetwork = $controller->traffic($areaRequest, $service)->getData(true);
    trafficCheck(count($areaNetwork['road_segments']) === 3, 'GCC snapshot ignores undrawn roads without failing');
    $payload = ['service_area_uuid' => $area->uuid, 'target_key' => $west['target_key'], 'status' => 'open',
        'congestion_mode' => 'manual', 'manual_congestion' => 'heavy', 'light_threshold' => 3, 'moderate_threshold' => 6, 'heavy_threshold' => 10, 'note' => 'Integration fixture'];
    $makeRequest = fn ($body) => Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $controller->saveControl($makeRequest($payload), $service);
    $saved = collect($service->snapshot($company)['road_segments'])->firstWhere('target_key', $west['target_key']);
    trafficCheck($saved['congestion'] === 'heavy' && $saved['congestion_mode'] === 'manual', 'road override persisted and applied to network');
    $payload['congestion_mode'] = 'auto';
    $controller->saveControl($makeRequest($payload), $service);
    $released = collect($service->snapshot($company)['road_segments'])->firstWhere('target_key', $west['target_key']);
    trafficCheck($released['congestion'] === 'light', 'switching back to auto releases manual override');
    try {
        $controller->saveControl($makeRequest(array_merge($payload, ['moderate_threshold' => 2])), $service);
        throw new RuntimeException('Invalid threshold accepted');
    } catch (ValidationException $error) {
        trafficCheck(isset($error->errors()['moderate_threshold']), 'invalid threshold ordering rejected');
    }
    $junction = collect($snapshot['junctions'])->first(fn ($j) => abs($j['longitude'] - -135.725) < 0.000001);
    $payload['target_key'] = $junction['target_key'];
    $payload['status'] = 'construction';
    $controller->saveControl($makeRequest($payload), $service);
    $driverEntry = EntryRegistration::where('company_uuid', $company)->where('status', 'active')->first();
    $tracking = app(TrackingController::class);
    $request = Request::create('/', 'GET');
    $request->attributes->set('entryRegistration', $driverEntry);
    $network = $tracking->roadNetwork($request, $service)->getData(true);
    trafficCheck(collect($network['junctions'])->firstWhere('target_key', $junction['target_key'])['status'] === 'construction', 'driver network receives junction construction state');
    $routeRequest = $makeRequest(['start' => ['latitude' => 60.6, 'longitude' => -135.73], 'end' => ['latitude' => 60.6, 'longitude' => -135.72]]);
    $routeRequest->attributes->set('entryRegistration', $driverEntry);
    $route = $tracking->route($routeRequest, app(SiteRoadGraphService::class), $service)->getData(true);
    $detourSection = collect($network['road_segments'])->firstWhere('road_segment_uuid', $roads['detour']->uuid);
    trafficCheck($route['status'] === 'ok' && in_array($detourSection['uuid'], $route['route']['segment_uuids'], true), 'driver route avoids construction junction');
    trafficCheck($route['route']['optimization'] === 'travel_time', 'driver API advertises travel time optimization');
    $roads['detour']->update(['status' => 'closed']);
    $blocked = $tracking->route($routeRequest, app(SiteRoadGraphService::class), $service);
    trafficCheck($blocked->getStatusCode() === 422 && $driverEntry->fresh()->active_route === null, 'unreachable route clears obsolete route in dispatch state');
    $otherCompany = (string) Str::uuid();
    DB::table('companies')->insert(['uuid' => $otherCompany, 'name' => 'Traffic isolation fixture']);
    $otherArea = ServiceArea::create(['company_uuid' => $otherCompany, 'name' => 'Other company']);
    try {
        $controller->saveControl($makeRequest(array_merge($payload, ['service_area_uuid' => $otherArea->uuid])), $service);
        throw new RuntimeException('Cross-company area accepted');
    } catch (Illuminate\Database\Eloquent\ModelNotFoundException $error) {
        trafficCheck(true, 'cross-company service area rejected');
    }
    $outside = RoadSegment::create(['company_uuid' => $company, 'name' => 'Outside GCC', 'status' => 'open', 'geometry' => ['type' => 'LineString', 'coordinates' => [[30, 60], [30.01, 60]]]]);
    $outsideSection = collect($service->snapshot($company)['road_segments'])->firstWhere('road_segment_uuid', $outside->uuid);
    try {
        $controller->saveControl($makeRequest(array_merge($payload, ['target_key' => $outsideSection['target_key']])), $service);
        throw new RuntimeException('Outside GCC road accepted');
    } catch (ValidationException $error) {
        trafficCheck(isset($error->errors()['target_key']), 'outside GCC road rejected');
    }

    // One stored road crosses two junctions: only its middle section is changed.
    $longRoad = RoadSegment::create(['company_uuid' => $company, 'name' => 'Long road', 'status' => 'open',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.735, 60.605], [-135.715, 60.605]]]]);
    foreach ([-135.729, -135.721] as $lng) {
        RoadSegment::create(['company_uuid' => $company, 'name' => 'Junction branch', 'status' => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[$lng, 60.605], [$lng, 60.603]]]]);
    }
    $before = $service->snapshot($company);
    $sections = collect($before['road_segments'])->where('road_segment_uuid', $longRoad->uuid);
    trafficCheck($sections->count() === 3, 'one stored road is split at two neighboring junctions into three sections');
    $middle = $sections->first(fn ($s) => $s['start_junction_key'] && $s['end_junction_key']);
    $middlePayload = array_merge($payload, ['target_key' => $middle['target_key'], 'status' => 'construction']);
    $controller->saveControl($makeRequest($middlePayload), $service);
    $after = $service->snapshot($company);
    $sections = collect($after['road_segments'])->where('road_segment_uuid', $longRoad->uuid);
    trafficCheck($sections->where('status', 'construction')->count() === 1 && $sections->where('status', 'open')->count() === 2
        && $longRoad->fresh()->status === 'open', 'saving middle section leaves both neighbors and parent road status unchanged');
    $persisted = RoadTrafficControl::where('company_uuid', $company)->where('target_key', $middle['target_key'])->first();
    trafficCheck($persisted->road_segment_uuid === $longRoad->uuid && $persisted->geometry === $middle['geometry'], 'section control persists parent identity and only the selected geometry');
    trafficCheck($before['revision'] !== $after['revision'], 'section state changes routing revision');
    $driverSections = collect($tracking->roadNetwork($request, $service)->getData(true)['road_segments'])->where('road_segment_uuid', $longRoad->uuid);
    trafficCheck($driverSections->where('status', 'construction')->count() === 1 && $driverSections->where('status', 'open')->count() === 2, 'driver receives section-specific closure while both neighbors stay open');
    $graph = app(SiteRoadGraphService::class);
    trafficCheck($graph->routeFromSegments($after['road_segments'], 60.605, -135.735, 60.605, -135.715, [], $after['junctions']) === null
        && $graph->routeFromSegments($after['road_segments'], 60.605, -135.735, 60.605, -135.729, [], $after['junctions']) !== null
        && $graph->routeFromSegments($after['road_segments'], 60.605, -135.721, 60.605, -135.715, [], $after['junctions']) !== null,
        'driver cannot cross closed middle section but can still use either approach');
    try {
        $controller->saveControl($makeRequest(array_merge($middlePayload, ['target_key' => 'road:' . $longRoad->uuid])), $service);
        throw new RuntimeException('Whole-road traffic edit accepted');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        trafficCheck($error->getStatusCode() === 404, 'traffic editor rejects old whole-road target keys');
    }
    RoadSegment::create(['company_uuid' => $company, 'name' => 'New middle junction', 'status' => 'open',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.725, 60.605], [-135.725, 60.607]]]]);
    try {
        $controller->saveControl($makeRequest($middlePayload), $service);
        throw new RuntimeException('Stale topology target accepted');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        trafficCheck($error->getStatusCode() === 404, 'changed topology rejects stale section selection');
    }
    // Exercise the actual controller and GPS service with a fixture driver.
    $gpsUser = (string) Str::uuid();
    DB::table('users')->insert(['uuid' => $gpsUser, 'company_uuid' => $company,
        'name' => 'GPS integration fixture', 'email' => $gpsUser . '@example.invalid']);
    $gpsDriver = (string) Str::uuid();
    DB::table('drivers')->insert(['uuid' => $gpsDriver, 'company_uuid' => $company, 'user_uuid' => $gpsUser,
        'location' => DB::raw("ST_GeomFromText('POINT(-135.728 60.6)', 4326, 'axis-order=long-lat')")]);
    $gpsEntry = EntryRegistration::create(['company_uuid' => $company, 'driver_uuid' => $gpsDriver,
        'status' => 'active', 'tracking_enabled' => true]);
    $observed = now()->subSeconds(10)->startOfSecond();
    $gpsBody = ['latitude' => 60.6, 'longitude' => -135.728, 'accuracy' => 10, 'speed' => 3,
        'observed_at' => $observed->toIso8601String()];
    $gpsRequest = $makeRequest($gpsBody);
    $gpsRequest->attributes->set('entryRegistration', $gpsEntry);
    $gpsResponse = $tracking->update($gpsRequest, app(Fleetbase\ConstructionSite\Services\GpsTrackingService::class));
    $ack = $gpsResponse->getData(true);
    if ($gpsResponse->getStatusCode() !== 200) {
        throw new RuntimeException('GPS fixture response ' . $gpsResponse->getStatusCode() . ': ' . json_encode($ack['message'] ?? null));
    }
    trafficCheck($gpsResponse->getStatusCode() === 200 && $ack['status'] === 'ok'
        && $ack['gps_observed_at'] === $observed->toIso8601String()
        && $gpsEntry->fresh()->gps_reported_at->gt($gpsEntry->fresh()->gps_observed_at),
        'GPS acknowledgement distinguishes observation time from server receipt time');
    $receipt = $gpsEntry->fresh()->gps_reported_at->toIso8601String();
    $olderSession = EntryRegistration::create(['company_uuid' => $company, 'driver_uuid' => $gpsDriver,
        'status' => 'active', 'tracking_enabled' => true]);
    $olderSession->forceFill(['gps_latitude' => 60.605, 'gps_longitude' => -135.717, 'gps_accuracy' => 10,
        'gps_observed_at' => now()->subMinutes(3), 'gps_reported_at' => now()])->saveQuietly();
    $deduplicated = collect($service->snapshot($company)['road_segments'])->firstWhere('road_segment_uuid', $roads['west']->uuid);
    trafficCheck($deduplicated['driver_count'] === 4,
        'duplicate sessions select latest observed location rather than latest delayed receipt');
    foreach ([-20, 0] as $offset) {
        $oldRequest = $makeRequest(array_merge($gpsBody, ['longitude' => -135.721,
            'observed_at' => $observed->copy()->addSeconds($offset)->toIso8601String()]));
        $oldRequest->attributes->set('entryRegistration', $gpsEntry->fresh());
        $oldResponse = $tracking->update($oldRequest, app(Fleetbase\ConstructionSite\Services\GpsTrackingService::class));
        trafficCheck($oldResponse->getStatusCode() === 200 && abs($gpsEntry->fresh()->gps_longitude - -135.728) < 0.000001
            && $gpsEntry->fresh()->gps_reported_at->toIso8601String() === $receipt,
            $offset === 0 ? 'duplicate GPS is idempotent and does not refresh freshness' : 'out-of-order GPS cannot overwrite current location');
    }
    $lateDriver = (string) Str::uuid();
    DB::table('drivers')->insert(['uuid' => $lateDriver, 'company_uuid' => $company, 'user_uuid' => $gpsUser,
        'location' => DB::raw("ST_GeomFromText('POINT(-135.728 60.6)', 4326, 'axis-order=long-lat')")]);
    $lateEntry = EntryRegistration::create(['company_uuid' => $company, 'driver_uuid' => $lateDriver,
        'status' => 'active', 'tracking_enabled' => true]);
    $lateRequest = $makeRequest(array_merge($gpsBody, ['latitude' => 60.605, 'longitude' => -135.717,
        'observed_at' => now()->subMinutes(3)->toIso8601String()]));
    $lateRequest->attributes->set('entryRegistration', $lateEntry);
    $tracking->update($lateRequest, app(Fleetbase\ConstructionSite\Services\GpsTrackingService::class));
    $staleSection = collect($service->snapshot($company)['road_segments'])->where('road_segment_uuid', $longRoad->uuid)
        ->first(fn ($s) => $s['data_status'] === 'stale');
    trafficCheck($staleSection !== null && $staleSection['driver_count'] === 0 && $staleSection['automatic_congestion'] === 'unknown',
        'recently received but old GPS remains stale for automatic traffic');
    $gpsEntry->exit();
    $futureRequest = $makeRequest(array_merge($gpsBody, ['observed_at' => now()->addMinutes(5)->toIso8601String()]));
    $futureRequest->attributes->set('entryRegistration', $lateEntry);
    trafficCheck($tracking->update($futureRequest, app(Fleetbase\ConstructionSite\Services\GpsTrackingService::class))->getStatusCode() === 422,
        'future device timestamps outside clock tolerance are rejected');
    $endedRequest = $makeRequest(array_merge($gpsBody, ['observed_at' => now()->toIso8601String()]));
    $endedRequest->attributes->set('entryRegistration', $gpsEntry);
    trafficCheck($tracking->update($endedRequest, app(Fleetbase\ConstructionSite\Services\GpsTrackingService::class))->getStatusCode() === 401,
        'an exited session cannot upload after middleware authentication');
    echo "GPS integration checks passed; rolling back fixtures.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    DB::rollBack();
    exit(1);
} finally {
    if (DB::transactionLevel() > 0)
    DB::rollBack();
}
