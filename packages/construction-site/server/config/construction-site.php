<?php

/**
 * -------------------------------------------
 * Construction Site Extension Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => 'construction-site',
            'internal_prefix' => 'int'
        ],
    ],
    'default_gate_uuid' => env('CONSTRUCTION_SITE_DEFAULT_GATE_UUID'),
    'default_company_uuid' => env('CONSTRUCTION_SITE_DEFAULT_COMPANY_UUID'),
    'session_hours' => 12,
    'socket_key' => env('DDMAPS_SOCKET_AUTH_KEY'),
    'road_import' => [
        'endpoint' => env('OSM_OVERPASS_ENDPOINT', 'https://overpass-api.de/api/interpreter'),
        'max_bbox_km2' => 100,
        'max_candidates' => 1500,
        'cache_seconds' => 3600,
        'preview_seconds' => 900,
    ],
];
