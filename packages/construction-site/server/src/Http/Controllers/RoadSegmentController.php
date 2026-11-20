<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoadSegmentController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'road_segment';

    /**
     * Collect input from the raw request body. Fleetbase's request wrapper
     * can return an empty all() for these payloads, so parse the raw JSON
     * content directly and accept a `{road_segment: {...}}` envelope or a
     * flat payload.
     *
     * @return array
     */
    protected function collectInput(Request $request): array
    {
        $raw = json_decode($request->getContent(), true);
        if (!is_array($raw)) {
            return [];
        }

        $data = $raw['road_segment'] ?? $raw;

        return is_array($data) ? $data : [];
    }

    /**
     * Create a road segment from a flat or enveloped payload.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function createSegment(Request $request)
    {
        return DB::transaction(function () use ($request) {
            DB::table('companies')->where('uuid', session('company'))->lockForUpdate()->first();
            $data = \Fleetbase\ConstructionSite\Support\RoadInput::segment($this->collectInput($request), true);
            $data['company_uuid'] = session('company');

            $segment = RoadSegment::create($data);

            return response()->json(['road_segment' => $segment->fresh()], 201);
        }, 3);
    }

    /**
     * Update a road segment (name, status, geometry, ...) by uuid or public_id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSegment(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            DB::table('companies')->where('uuid', session('company'))->lockForUpdate()->first();
            $segment = RoadSegment::where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
                ->where('company_uuid', session('company'))
                ->firstOrFail();

            $data = \Fleetbase\ConstructionSite\Support\RoadInput::segment($this->collectInput($request), false);
            Validator::make($data, ['name' => ['sometimes', 'required', 'string', 'max:255']])->validate();
            if (isset($data['geometry']) && $data['geometry'] != $segment->geometry
                && RoadTrafficControl::where('company_uuid', session('company'))->where('road_segment_uuid', $segment->uuid)->where('is_removed', true)->exists()) {
                abort(422, '这条道路含已删除路段，请先在“已删除路段”中恢复，再编辑整条道路几何。');
            }
            if (isset($data['geometry']) && $segment->source === 'osm') {
                $previous = $segment->geometry['coordinates'] ?? [];
                $routing = $segment->routing ?? [];
                $oldKeys = $routing['node_keys'] ?? [];
                $routing['node_keys'] = array_map(function ($p) use ($previous, $oldKeys, $segment) {
                    foreach ($previous as $i => $old) {
                        if (abs($p[0] - $old[0]) + abs($p[1] - $old[1]) < 1e-8 && isset($oldKeys[$i])) return $oldKeys[$i];
                    }
                    return 'edited:' . $segment->uuid . ':' . sprintf('%.8f,%.8f', $p[0], $p[1]);
                }, $data['geometry']['coordinates'] ?? []);
                $segment->routing = $routing;
            }
            foreach ($data as $key => $value) {
                if (!in_array($key, ['site_road_uuid', 'name', 'sequence', 'status', 'geometry', 'current_condition_uuid'], true)) {
                    continue;
                }
                $segment->{$key} = $value;
            }
            $segment->save();

            return response()->json(['road_segment' => $segment->fresh()]);
        }, 3);
    }

    /**
     * Delete a road segment by uuid or public_id. Tolerates repeat deletes
     * (a soft-deleted segment still resolves via withTrashed).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSegment(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            DB::table('companies')->where('uuid', session('company'))->lockForUpdate()->first();
            $segment = RoadSegment::withTrashed()
                ->where(function ($query) use ($id) {
                    $query->where('uuid', $id)->orWhere('public_id', $id);
                })
                ->where('company_uuid', session('company'))
                ->firstOrFail();

            if (!$segment->trashed()) {
                $segment->delete();
            }

            return response()->json(['status' => 'ok']);
        }, 3);
    }
}
