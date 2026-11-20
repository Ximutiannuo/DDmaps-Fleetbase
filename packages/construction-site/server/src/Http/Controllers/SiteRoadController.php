<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteRoadController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'site_road';

    /**
     * Get the segments for a site road.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function segments(Request $request, string $id): JsonResponse
    {
        $road = $this->model->where(
            function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            }
        )
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $segments = $road->roadSegments()->orderBy('sequence', 'asc')->get();

        return response()->json(
            [
                'status'   => 'ok',
                'road'     => $road,
                'segments' => $segments,
            ]
        );
    }
}
