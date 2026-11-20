<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\AvoidArea;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Services\EntrySessionService;
use Fleetbase\ConstructionSite\Services\GpsTrackingService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TrackingController extends Controller
{
    use \Fleetbase\ConstructionSite\Support\PublicErrors;
    /**
     * Update the driver's GPS location for an active entry session.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, GpsTrackingService $service): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $gps = $request->has('gps')
            ? $request->input('gps')
            : $request->only(['latitude', 'longitude', 'altitude', 'heading', 'speed', 'accuracy', 'observed_at']);

        try {
            $driver = $service->updateLocation($registration, is_array($gps) ? $gps : []);
        } catch (ValidationException $e) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => $e->errors(),
                ],
                422
            );
        } catch (\Throwable $e) {
            return $this->publicError($e);
        }

        return response()->json(
            [
                'status' => 'ok',
                'driver' => $driver,
                'gps_observed_at' => $registration->gps_observed_at?->toIso8601String(),
                'gps_received_at' => $registration->gps_reported_at?->toIso8601String(),
            ]
        );
    }

    /**
     * Exit an active entry session from the tracking API.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function exit(Request $request, EntrySessionService $service): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $service->exit($registration);

        return response()->json(
            [
                'status'              => 'ok',
                'entry_registration'  => $registration->fresh(),
            ]
        );
    }

    /**
     * Get the current status of an active entry session.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $registration->load(['vehicle', 'driver', 'gate']);

        return response()->json(
            [
                'status'              => 'ok',
                'entry_registration'  => $registration,
            ]
        );
    }

    /**
     * Get active service areas (with nested zones) for the driver's company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function serviceAreas(Request $request): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $companyUuid = $registration->company_uuid;

        $serviceAreas = \Fleetbase\FleetOps\Models\ServiceArea::where('company_uuid', $companyUuid)
            ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))
            ->whereNull('deleted_at')
            ->with([
                'zones' => function ($query) {
                    $query->where(function ($statusQuery) {
                        $statusQuery->where('status', 'active')
                            ->orWhereNull('status');
                    })->whereNull('deleted_at');
                },
            ])
            ->get(['uuid', 'public_id', 'name', 'type', 'parent_uuid', 'border', 'color', 'stroke_color']);

        $mapped = $serviceAreas->map(function ($area) {
            $zones = $area->zones->map(function ($zone) {
                return $this->serializeNavigableArea($zone, 'zone', '#e67e22', '#d35400');
            })->values();

            return [
                'uuid'         => $area->uuid,
                'name'         => $area->name,
                'type'         => $area->type,
                'parent_uuid'  => $area->parent_uuid,
                'border'       => $this->spatialToGeoJson($area->border),
                'center'       => $this->resolveCenter($area),
                'color'        => $area->color ?? '#3498db',
                'stroke_color' => $area->stroke_color ?? '#2980b9',
                'zones'        => $zones,
            ];
        });

        // Attach nested service areas (parent_uuid) as additional internal targets.
        $result = $mapped->map(function ($area) use ($mapped) {
            $children = $mapped
                ->where('parent_uuid', $area['uuid'])
                ->map(function ($child) {
                    return [
                        'uuid'         => $child['uuid'],
                        'name'         => $child['name'],
                        'type'         => 'service_area',
                        'border'       => $child['border'],
                        'center'       => $child['center'],
                        'color'        => $child['color'] ?? '#e67e22',
                        'stroke_color' => $child['stroke_color'] ?? '#d35400',
                    ];
                })
                ->values();

            $area['children'] = $children;

            return $area;
        })->values();

        return response()->json(['service_areas' => $result]);
    }

    /**
     * Get the site road network for the driver's company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function roadNetwork(Request $request, RoadTrafficService $traffic): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $snapshot = $traffic->snapshot((string) $registration->company_uuid);

        $avoidAreas = AvoidArea::query()
            ->where('company_uuid', $registration->company_uuid)
            ->active()
            ->get(['uuid', 'public_id', 'service_area_uuid', 'zone_uuid', 'geometry', 'status', 'reason'])
            ->map(function ($avoid) {
                return [
                    'uuid'              => $avoid->uuid,
                    'service_area_uuid' => $avoid->service_area_uuid,
                    'zone_uuid'         => $avoid->zone_uuid,
                    'geometry'          => $avoid->geometry,
                    'status'            => $avoid->status,
                    'reason'            => $avoid->reason,
                    'color'             => '#ef4444',
                ];
            })
            ->values();

        return response()->json(
            [
                'status'        => 'ok',
                'road_segments' => array_values(array_filter($snapshot['road_segments'], fn ($s) => empty($s['is_removed']))),
                'junctions'     => $snapshot['junctions'],
                'revision'      => sha1($snapshot['revision'] . json_encode($avoidAreas)),
                'generated_at'  => $snapshot['generated_at'],
                'fresh_seconds' => $snapshot['fresh_seconds'],
                'avoid_areas'   => $avoidAreas,
            ]
        );
    }

    /**
     * Calculate a route over the site road network.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function route(Request $request, SiteRoadGraphService $graph, RoadTrafficService $traffic): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $start = $request->input('start', []);
        $end   = $request->input('end', []);

        $startLatRaw = $start['latitude'] ?? $request->input('start_latitude');
        $startLngRaw = $start['longitude'] ?? $request->input('start_longitude');
        $endLatRaw   = $end['latitude'] ?? $request->input('end_latitude');
        $endLngRaw   = $end['longitude'] ?? $request->input('end_longitude');

        if (!is_numeric($startLatRaw) || !is_numeric($startLngRaw) || !is_numeric($endLatRaw) || !is_numeric($endLngRaw)) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'start/end coordinates required',
                ],
                422
            );
        }

        $startLat = (float) $startLatRaw;
        $startLng = (float) $startLngRaw;
        $endLat   = (float) $endLatRaw;
        $endLng   = (float) $endLngRaw;

        if (abs($startLat) > 90 || abs($endLat) > 90 || abs($startLng) > 180 || abs($endLng) > 180) {
            return response()->json(['message' => 'Invalid coordinates.'], 422);
        }
        $snapshot = $traffic->snapshot((string) $registration->company_uuid);
        $segments = $snapshot['road_segments'];

        $avoids = AvoidArea::query()
            ->where('company_uuid', $registration->company_uuid)
            ->active()
            ->get(['uuid', 'geometry', 'service_area_uuid'])
            ->map(fn ($a) => [
                'uuid'     => $a->uuid,
                'geometry' => $a->geometry,
            ])
            ->all();

        $route = $graph->routeFromSegments($segments, $startLat, $startLng, $endLat, $endLng, $avoids, $snapshot['junctions']);
        if (!$route) {
            $registration->update(['active_route' => null]);
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => '无法到达：路网中断、终点附近道路封闭或禁行区阻断',
                ],
                422
            );
        }

        $registration->update([
            'is_navigating'          => (bool) $request->input('is_navigating', true),
            'destination_zone_uuid'  => $request->input('destination_zone_uuid', $registration->destination_zone_uuid),
            'active_route'           => [
                'coordinates'    => $route['coordinates'],
                'total_distance' => $route['total_distance'],
                'total_duration' => $route['total_duration'],
                'updated_at'     => now()->toIso8601String(),
            ],
        ]);

        return response()->json(
            [
                'status' => 'ok',
                'route'  => $route,
            ]
        );
    }

    /**
     * Clear active navigation state for the entry session.
     */
    public function stopNavigation(Request $request): JsonResponse
    {
        $registration = $request->attributes->get('entryRegistration');
        if (!$registration) {
            return response()->json(
                [
                    'status'  => 'error',
                    'message' => 'No active session.',
                ],
                401
            );
        }

        $registration->update([
            'is_navigating'         => false,
            'destination_zone_uuid' => null,
            'active_route'          => null,
        ]);

        return response()->json([
            'status'             => 'ok',
            'entry_registration' => $registration->fresh(),
        ]);
    }

    /**
     * Serialize a zone/service-area model into a navigable map target.
     */
    protected function serializeNavigableArea($model, string $type, string $defaultColor, string $defaultStroke): array
    {
        return [
            'uuid'         => $model->uuid,
            'name'         => $model->name,
            'type'         => $type,
            'border'       => $this->spatialToGeoJson($model->border),
            'center'       => $this->resolveCenter($model),
            'color'        => $model->color ?? $defaultColor,
            'stroke_color' => $model->stroke_color ?? $defaultStroke,
        ];
    }

    /**
     * Resolve a safe center point from a service area or zone model.
     */
    protected function resolveCenter($model): array
    {
        $latitude  = 0.0;
        $longitude = 0.0;

        try {
            if (method_exists($model, 'getLatitudeAttribute')) {
                $latitude  = (float) $model->latitude;
                $longitude = (float) $model->longitude;
            }
        } catch (\Throwable $e) {
            $latitude  = 0.0;
            $longitude = 0.0;
        }

        if (($latitude === 0.0 && $longitude === 0.0) || !is_finite($latitude) || !is_finite($longitude)) {
            $fallback = $this->centerFromBorder($model->border ?? null);
            if ($fallback) {
                return $fallback;
            }
        }

        return [
            'latitude'  => is_finite($latitude) ? $latitude : 0.0,
            'longitude' => is_finite($longitude) ? $longitude : 0.0,
        ];
    }

    /**
     * Approximate a center point from the first ring of a spatial border.
     */
    protected function centerFromBorder($border): ?array
    {
        $geoJson = $this->spatialToGeoJson($border);
        if (!$geoJson || empty($geoJson['coordinates'])) {
            return null;
        }

        $ring = null;
        $coords = $geoJson['coordinates'];

        // MultiPolygon: [ [ [ [lng,lat], ... ] ] ]
        if (isset($coords[0][0][0]) && is_array($coords[0][0][0])) {
            $ring = $coords[0][0];
        } elseif (isset($coords[0][0]) && is_array($coords[0][0])) {
            // Polygon: [ [ [lng,lat], ... ] ]
            $ring = $coords[0];
        }

        if (!$ring || count($ring) === 0) {
            return null;
        }

        $sumLat = 0.0;
        $sumLng = 0.0;
        $count  = 0;

        foreach ($ring as $point) {
            if (!is_array($point) || count($point) < 2) {
                continue;
            }
            $sumLng += (float) $point[0];
            $sumLat += (float) $point[1];
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return [
            'latitude'  => $sumLat / $count,
            'longitude' => $sumLng / $count,
        ];
    }

    /**
     * Convert a spatial geometry to GeoJSON format.
     */
    protected function spatialToGeoJson($geometry): ?array
    {
        if (!$geometry) {
            return null;
        }

        if (is_array($geometry)) {
            return $geometry;
        }

        if (method_exists($geometry, 'toGeoJSON')) {
            return json_decode($geometry->toGeoJSON(), true);
        }

        if (method_exists($geometry, 'toJson')) {
            return json_decode($geometry->toJson(), true);
        }

        return null;
    }

    /**
     * Resolve a suitable HTTP status code from an exception.
     */
    protected function resolveStatusCode(\Throwable $e): int
    {
        $code = (int) $e->getCode();

        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        return 500;
    }
}
