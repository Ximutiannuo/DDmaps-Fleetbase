<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\RoadCondition;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoadConditionController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'road_condition';

    /**
     * Create a road condition for a specific road segment.
     *
     * Also syncs the segment's current condition and status.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeForSegment(Request $request, string $id): JsonResponse
    {
        $segment = RoadSegment::where(
            function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            }
        )
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $data = $this->collectInput($request);
        $data['company_uuid']      = session('company');
        $data['road_segment_uuid'] = $segment->uuid;
        $data['reported_at']       = $data['reported_at'] ?? now();
        $data['status']            = $data['status'] ?? 'open';
        $data['surface_condition'] = $data['surface_condition'] ?? 'dry';
        $data['congestion']        = $data['congestion'] ?? 'none';

        $condition = RoadCondition::create($data);
        $this->syncSegmentCondition($segment, $condition);

        return response()->json(
            [
                'status'         => 'ok',
                'road_condition' => $condition,
                'road_segment'   => $segment->fresh(),
            ]
        );
    }

    /**
     * After a condition is created via the standard store endpoint, sync it
     * to the associated road segment.
     *
     * @return void
     */
    public function onAfterCreate(Request $request, RoadCondition $record, array $input = [])
    {
        if (empty($record->road_segment_uuid)) {
            return;
        }

        $segment = RoadSegment::where('uuid', $record->road_segment_uuid)->first();
        if ($segment) {
            $this->syncSegmentCondition($segment, $record);
        }
    }

    /**
     * Sync a road condition onto its segment as the current condition.
     */
    protected function syncSegmentCondition(RoadSegment $segment, RoadCondition $condition): void
    {
        $segment->current_condition_uuid = $condition->uuid;
        if (!empty($condition->status)) {
            $segment->status = $condition->status;
        }
        $segment->save();
    }

    /**
     * Collect the condition input from the request payload.
     */
    protected function collectInput(Request $request): array
    {
        $data = $request->has('road_condition') ? $request->input('road_condition') : $request->all();

        return is_array($data) ? $data : [];
    }
}
