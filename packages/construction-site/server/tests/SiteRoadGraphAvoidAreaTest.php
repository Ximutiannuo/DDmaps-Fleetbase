<?php

use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;

it('blocks an edge that crosses an avoid polygon', function () {
    $svc = new SiteRoadGraphService();

    $a = [60.6000, -135.7300];
    $b = [60.6000, -135.7280];
    $ring = [
        [-135.7295, 60.5995],
        [-135.7285, 60.5995],
        [-135.7285, 60.6005],
        [-135.7295, 60.6005],
        [-135.7295, 60.5995],
    ];

    expect($svc->edgeIntersectsPolygonRing($a, $b, $ring))->toBeTrue();
});

it('blocks an edge within the LineString avoid buffer', function () {
    $svc = new SiteRoadGraphService();

    $a = [60.6000, -135.7300];
    $b = [60.6001, -135.7300];
    $line = [
        [-135.7300, 60.60005],
        [-135.7295, 60.60005],
    ];

    expect($svc->edgeIntersectsLineStringBuffer($a, $b, $line, 12.0))->toBeTrue();
});

it('does not block a distant edge from a LineString avoid', function () {
    $svc = new SiteRoadGraphService();

    $a = [60.6100, -135.7200];
    $b = [60.6101, -135.7200];
    $line = [
        [-135.7300, 60.6000],
        [-135.7295, 60.6000],
    ];

    expect($svc->edgeIntersectsLineStringBuffer($a, $b, $line, 12.0))->toBeFalse();
});

it('routes around a polygon avoid when an alternate segment exists', function () {
    $svc = new SiteRoadGraphService();

    $segments = [
        [
            'uuid' => 'seg-direct',
            'name' => 'direct',
            'status' => 'open',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [
                    [-135.7300, 60.6000],
                    [-135.7280, 60.6000],
                ],
            ],
        ],
        [
            'uuid' => 'seg-north',
            'name' => 'north',
            'status' => 'open',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [
                    [-135.7300, 60.6000],
                    [-135.7300, 60.6010],
                    [-135.7280, 60.6010],
                    [-135.7280, 60.6000],
                ],
            ],
        ],
    ];

    $avoids = [
        [
            'uuid' => 'avoid-1',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-135.7295, 60.5998],
                    [-135.7285, 60.5998],
                    [-135.7285, 60.6002],
                    [-135.7295, 60.6002],
                    [-135.7295, 60.5998],
                ]],
            ],
        ],
    ];

    $route = $svc->routeFromSegments($segments, 60.6000, -135.7300, 60.6000, -135.7280, $avoids);

    expect($route)->not->toBeNull()
        ->and($route['segment_uuids'])->toContain('seg-north')
        ->and($route['segment_uuids'])->not->toContain('seg-direct');
});

it('returns null when avoid cuts all paths', function () {
    $svc = new SiteRoadGraphService();

    $segments = [
        [
            'uuid' => 'seg-only',
            'name' => 'only',
            'status' => 'open',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [
                    [-135.7300, 60.6000],
                    [-135.7280, 60.6000],
                ],
            ],
        ],
    ];

    $avoids = [
        [
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-135.7295, 60.5998],
                    [-135.7285, 60.5998],
                    [-135.7285, 60.6002],
                    [-135.7295, 60.6002],
                    [-135.7295, 60.5998],
                ]],
            ],
        ],
    ];

    $route = $svc->routeFromSegments($segments, 60.6000, -135.7300, 60.6000, -135.7280, $avoids);

    expect($route)->toBeNull();
});
