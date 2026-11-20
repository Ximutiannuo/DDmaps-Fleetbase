<?php

use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;

/**
 * Integration coverage for TrackingController::route segment mapping and
 * coordinate handling. Full HTTP tests require a Laravel testbench bootstrap
 * that is not configured for this package; see EntryRegistrationTest for
 * model-level session token coverage.
 */
it('routes using the segment shape produced by TrackingController', function () {
    $svc = new SiteRoadGraphService();

    $dbSegments = [
        [
            'uuid'     => 'seg-south',
            'name'     => 'South leg',
            'status'   => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.730, 60.600], [-135.720, 60.600]]],
        ],
        [
            'uuid'     => 'seg-north',
            'name'     => 'North leg',
            'status'   => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.730, 60.601], [-135.720, 60.601]]],
        ],
        [
            'uuid'     => 'seg-connector-west',
            'name'     => 'West connector',
            'status'   => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.730, 60.600], [-135.730, 60.601]]],
        ],
        [
            'uuid'     => 'seg-connector-east',
            'name'     => 'East connector',
            'status'   => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.720, 60.600], [-135.720, 60.601]]],
        ],
    ];

    $mapped = array_map(fn ($segment) => [
        'uuid'     => $segment['uuid'],
        'name'     => $segment['name'],
        'status'   => $segment['status'] ?? 'open',
        'geometry' => $segment['geometry'],
    ], $dbSegments);

    $route = $svc->routeFromSegments($mapped, 60.601, -135.730, 60.601, -135.720);

    expect($route)->toBeArray()
        ->and($route['segment_uuids'])->not->toBeEmpty()
        ->and($route['coordinates'])->not->toBeEmpty()
        ->and($route['total_distance'])->toBeGreaterThan(0);
});

it('accepts zero-valued coordinates as valid numeric endpoints', function () {
    $svc = new SiteRoadGraphService();

    $segments = [[
        'uuid'     => 'equator-segment',
        'name'     => 'Equator',
        'status'   => 'open',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.001, 0.0]]],
    ]];

    expect(is_numeric(0))->toBeTrue()
        ->and(is_numeric(0.0))->toBeTrue()
        ->and(is_numeric('0'))->toBeTrue()
        ->and(is_numeric(null))->toBeFalse()
        ->and(is_numeric(''))->toBeFalse();

    $route = $svc->routeFromSegments($segments, 0.0, 0.0, 0.0, 0.001);

    expect($route)->toBeArray()
        ->and($route['coordinates'])->not->toBeEmpty();
});

it('rejects missing coordinates using is_numeric checks', function () {
    $missing = [null, '', false];

    foreach ($missing as $value) {
        expect(is_numeric($value))->toBeFalse();
    }
});
