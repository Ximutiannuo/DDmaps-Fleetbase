<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConstructionActivityController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'construction_activity';

    /**
     * Attach road segments to a construction activity.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function attachSegments(Request $request, string $id): JsonResponse
    {
        $request->validate(
            [
                'segments'   => 'required|array|min:1',
                'segments.*' => 'required|string',
            ]
        );

        $activity = $this->findActivity($id);
        $segments = array_unique($request->input('segments'));
        abort_unless(\Fleetbase\ConstructionSite\Models\RoadSegment::where('company_uuid', session('company'))
            ->whereIn('uuid', $segments)->count() === count($segments), 422, 'Invalid company road segments.');
        $activity->roadSegments()->syncWithoutDetaching($request->input('segments'));

        return response()->json(
            [
                'status'                => 'ok',
                'construction_activity' => $activity->fresh('roadSegments'),
            ]
        );
    }

    /**
     * Detach a single road segment from a construction activity.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function detachSegment(Request $request, string $id, string $segment): JsonResponse
    {
        $activity = $this->findActivity($id);
        $activity->roadSegments()->detach($segment);

        return response()->json(
            [
                'status'                => 'ok',
                'construction_activity' => $activity->fresh('roadSegments'),
            ]
        );
    }

    /**
     * Find a construction activity scoped to the current company.
     */
    protected function findActivity(string $id)
    {
        return $this->model->where(
            function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            }
        )
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }
}
