<?php

use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;

it('computes haversine distance in meters', function () {
    $svc = new SiteRoadGraphService();
    $d = $svc->haversineMeters(60.60, -135.73, 60.601, -135.73);
    expect($d)->toBeGreaterThan(100)->toBeLessThan(120);
});

it('treats closed and construction as impassable', function () {
    $svc = new SiteRoadGraphService();
    expect($svc->isImpassable('closed'))->toBeTrue()
        ->and($svc->isImpassable('construction'))->toBeTrue()
        ->and($svc->isImpassable('open'))->toBeFalse()
        ->and($svc->isImpassable('partial'))->toBeFalse();
});

it('applies weight penalty for partial status', function () {
    $svc = new SiteRoadGraphService();
    expect($svc->edgeWeight(100.0, 'open'))->toBe(100.0)
        ->and($svc->edgeWeight(100.0, 'partial'))->toBe(150.0);
});

it('routes around a closed middle segment', function () {
    $svc = new SiteRoadGraphService();
    $segments = [
        ['uuid'=>'s-open-south','name'=>'South','status'=>'open','geometry'=>['type'=>'LineString','coordinates'=>[[-135.730,60.600],[-135.720,60.600]]]],
        ['uuid'=>'s-closed-north','name'=>'North','status'=>'closed','geometry'=>['type'=>'LineString','coordinates'=>[[-135.730,60.601],[-135.720,60.601]]]],
        ['uuid'=>'s-connector-west','name'=>'West connector','status'=>'open','geometry'=>['type'=>'LineString','coordinates'=>[[-135.730,60.600],[-135.730,60.601]]]],
        ['uuid'=>'s-connector-east','name'=>'East connector','status'=>'open','geometry'=>['type'=>'LineString','coordinates'=>[[-135.720,60.600],[-135.720,60.601]]]],
    ];
    $route = $svc->routeFromSegments($segments, startLat: 60.601, startLng: -135.730, endLat: 60.601, endLng: -135.720);
    expect($route)->toBeArray()
        ->and($route['segment_uuids'])->not->toContain('s-closed-north')
        ->and($route['coordinates'])->not->toBeEmpty()
        ->and($route['total_distance'])->toBeGreaterThan(0);
});

it('returns null when destination is unreachable', function () {
    $svc = new SiteRoadGraphService();
    $segments = [[
        'uuid'=>'only-closed','name'=>'Closed','status'=>'closed',
        'geometry'=>['type'=>'LineString','coordinates'=>[[-135.730,60.600],[-135.720,60.600]]],
    ]];
    expect($svc->routeFromSegments($segments, 60.600, -135.730, 60.600, -135.720))->toBeNull();
});
