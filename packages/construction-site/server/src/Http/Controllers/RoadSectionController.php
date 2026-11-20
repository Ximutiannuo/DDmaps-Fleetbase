<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoadSectionController extends ConstructionSiteController
{
    use \Fleetbase\ConstructionSite\Support\ServiceAreaGeometry;
    public $resource = 'road_segment';
    public function remove(Request $request, RoadTrafficService $traffic)
    {
        $data = $this->input($request, true);
        $company = (string) session('company');
        DB::transaction(function () use ($data, $company, $traffic) {
            DB::table('companies')->where('uuid', $company)->lockForUpdate()->first();
            $area = ServiceArea::where('company_uuid', $company)->where('uuid', $data['service_area_uuid'])->lockForUpdate()->firstOrFail();
            RoadSegment::where('company_uuid', $company)->lockForUpdate()->get(['uuid']);
            $target = collect($traffic->snapshot($company)['road_segments'])->firstWhere('target_key', $data['target_key']);
            if (!$target) abort(404, '路段已改变或不存在，请刷新地图后重新选择。');
            if (!$this->geometryCentroidInsideServiceArea($target['geometry'], $area)) abort(422, '请选择当前 GCC 内的路段。');
            if ($target['geometry'] != $data['expected_geometry']) abort(409, '路段范围已改变，请刷新地图后重新选择。');
            if ($target['is_removed']) return; // Retry after a lost response is safe.
            $control = RoadTrafficControl::firstOrNew(['company_uuid' => $company, 'target_key' => $target['target_key']]);
            if (!$control->exists) $control->fill([
                'status' => $target['status'], 'congestion_mode' => $target['congestion_mode'], 'manual_congestion' => $target['manual_congestion'],
                'light_threshold' => $target['light_threshold'], 'moderate_threshold' => $target['moderate_threshold'],
                'heavy_threshold' => $target['heavy_threshold'], 'note' => $target['note'],
            ]);
            $control->fill(['service_area_uuid' => $area->uuid, 'road_segment_uuid' => $target['road_segment_uuid'],
                'geometry' => $target['geometry'], 'is_removed' => true, 'removed_at' => now(), 'removal_origin_key' => $target['target_key']])->save();
            $this->changed($company, $area->uuid, $target['target_key'], 'removed');
        }, 3);
        return response()->json(['status' => 'ok', 'target_key' => $data['target_key']]);
    }

    public function restore(Request $request)
    {
        $data = $this->input($request);
        $company = (string) session('company');
        DB::transaction(function () use ($data, $company) {
            DB::table('companies')->where('uuid', $company)->lockForUpdate()->first();
            $area = ServiceArea::where('company_uuid', $company)->where('uuid', $data['service_area_uuid'])->lockForUpdate()->firstOrFail();
            $control = RoadTrafficControl::where('company_uuid', $company)->where('target_key', $data['target_key'])->lockForUpdate()->firstOrFail();
            RoadSegment::where('company_uuid', $company)->where('uuid', $control->road_segment_uuid)->lockForUpdate()->firstOrFail();
            if ($control->removal_origin_key !== $data['target_key']) abort(422, '该记录不是一次路段删除操作。');
            if (!$this->geometryCentroidInsideServiceArea($control->geometry, $area)) abort(422, '请选择删除路段所在的 GCC。');
            $updated = RoadTrafficControl::where('company_uuid', $company)->where('road_segment_uuid', $control->road_segment_uuid)
                ->where('removal_origin_key', $data['target_key'])->where('is_removed', true)->update(['is_removed' => false, 'removed_at' => null]);
            if ($updated) $this->changed($company, $area->uuid, $data['target_key'], 'restored');
        }, 3);
        return response()->json(['status' => 'ok']);
    }

    private function input(Request $request, bool $remove = false): array
    {
        $rules = ['service_area_uuid' => ['required', 'uuid'], 'target_key' => ['required', 'string', 'max:100', 'regex:/^section:/']];
        if ($remove) $rules += [
            'expected_geometry' => ['required', 'array'], 'expected_geometry.type' => ['required', 'in:LineString'],
            'expected_geometry.coordinates' => ['required', 'array', 'min:2'], 'expected_geometry.coordinates.*' => ['array', 'size:2'],
            'expected_geometry.coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'expected_geometry.coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
        ];
        return Validator::make(json_decode($request->getContent(), true) ?? $request->all(), $rules)->validate();
    }

    private function changed(string $company, string $area, string $key, string $action): void
    {
        DB::afterCommit(fn () => event(new RoadNetworkChanged($company, 'road_section', $action, ['target_key' => $key], $area)));
    }
}
