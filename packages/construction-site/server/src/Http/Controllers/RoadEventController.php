<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoadEventController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'road_event';

    /**
     * Mark a road event as resolved.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $event = $this->model->where(
            function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            }
        )
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $event->status = 'resolved';
        $event->save();

        return response()->json(
            [
                'status'     => 'ok',
                'road_event' => $event,
            ]
        );
    }
}
