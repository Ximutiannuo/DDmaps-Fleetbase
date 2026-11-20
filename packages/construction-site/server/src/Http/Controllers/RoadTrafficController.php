<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoadTrafficController extends ConstructionSiteController
{
    use \Fleetbase\ConstructionSite\Support\ServiceAreaGeometry;
    public $resource = 'road_segment';
    public function traffic(Request $request, RoadTrafficService $traffic)
    {
        $snapshot = $traffic->snapshot((string) session('company'));
        $snapshot['road_segments'] = array_values(array_filter($snapshot['road_segments'], fn ($s) => empty($s['is_removed'])));
        $parents = RoadSegment::where('company_uuid', session('company'))->get(['uuid', 'name'])->keyBy('uuid');
        $snapshot['deleted_roads'] = RoadSegment::onlyTrashed()->where('company_uuid', session('company'))->orderByDesc('deleted_at')
            ->get(['uuid', 'name', 'status', 'geometry', 'source', 'source_meta', 'deleted_at'])->toArray();
        $snapshot['removed_sections'] = RoadTrafficControl::where('company_uuid', session('company'))->where('is_removed', true)
            ->whereColumn('target_key', 'removal_origin_key')->get()->filter(fn ($c) => isset($parents[$c->road_segment_uuid]))
            ->map(fn ($c) => ['target_key' => $c->target_key, 'road_segment_uuid' => $c->road_segment_uuid,
                'name' => $parents[$c->road_segment_uuid]->name, 'geometry' => $c->geometry, 'removed_at' => $c->removed_at?->toIso8601String()])->values()->all();
        if ($uuid = $request->input('service_area_uuid')) {
            $area = ServiceArea::where('company_uuid', session('company'))->where('uuid', $uuid)->firstOrFail();
            $snapshot['road_segments'] = array_values(array_filter($snapshot['road_segments'], fn ($s) => $this->geometryCentroidInsideServiceArea($s['geometry'], $area)));
            $snapshot['junctions'] = array_values(array_filter($snapshot['junctions'], fn ($j) => $this->pointInsideArea($j['geometry'], $area)));
            $snapshot['removed_sections'] = array_values(array_filter($snapshot['removed_sections'], fn ($s) => $this->geometryCentroidInsideServiceArea($s['geometry'], $area)));
            $snapshot['deleted_roads'] = array_values(array_filter($snapshot['deleted_roads'], fn ($s) => is_array($s['geometry'])
                && (($s['source_meta']['service_area_uuid'] ?? $area->uuid) === $area->uuid) && $this->roadInsideArea($s['geometry'], $area)));
        }
        return response()->json($snapshot);
    }

    public function saveControl(Request $request, RoadTrafficService $traffic)
    {
        // Accept the flat JSON payload without the generic resource wrapper dropping fields.
        $raw = json_decode($request->getContent(), true) ?? $request->all();
        $data = Validator::make($raw, [
            'service_area_uuid' => ['required', 'uuid'],
            'target_key' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:open,partial,construction,closed'],
            'congestion_mode' => ['required', 'in:auto,manual'],
            'manual_congestion' => ['required', 'in:none,light,moderate,heavy,standstill'],
            'light_threshold' => ['required', 'integer', 'min:1', 'max:1000'],
            'moderate_threshold' => ['required', 'integer', 'gt:light_threshold', 'max:1000'],
            'heavy_threshold' => ['required', 'integer', 'gt:moderate_threshold', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $company = (string) session('company');
        return DB::transaction(function () use ($data, $company, $traffic) {
            DB::table('companies')->where('uuid', $company)->lockForUpdate()->first();
            $area = ServiceArea::where('company_uuid', $company)->where('uuid', $data['service_area_uuid'])->firstOrFail();
            $snapshot = $traffic->snapshot($company);
            $target = collect(array_merge($snapshot['road_segments'], $snapshot['junctions']))->firstWhere('target_key', $data['target_key']);
            if (!$target || !empty($target['is_removed'])) abort(404, 'Road or junction no longer exists. Refresh the map.');
            $isRoad = str_starts_with($data['target_key'], 'section:');
            $inside = $isRoad ? $this->geometryCentroidInsideServiceArea($target['geometry'], $area) : $this->pointInsideArea($target['geometry'], $area);
            if (!$inside) {
                throw ValidationException::withMessages(['target_key' => ['请选择当前 GCC 内的道路或交汇点。']]);
            }
            $data['geometry'] = $target['geometry'];
            $data['road_segment_uuid'] = $isRoad ? $target['road_segment_uuid'] : null;
            RoadTrafficControl::updateOrCreate(['company_uuid' => $company, 'target_key' => $data['target_key']], $data);
            // Section controls never update the parent road's status.
            DB::afterCommit(fn () => event(new RoadNetworkChanged($company, 'traffic_control', 'updated', ['target_key' => $data['target_key']], $area->uuid)));
            return response()->json(['status' => 'ok']);
        }, 3);
    }

}
