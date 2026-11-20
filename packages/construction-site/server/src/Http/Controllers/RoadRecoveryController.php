<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Services\RoadControlInheritanceService;
use Fleetbase\ConstructionSite\Services\OsmRoadImportService;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoadRecoveryController extends ConstructionSiteController
{
    use \Fleetbase\ConstructionSite\Support\ServiceAreaGeometry;
    public $resource = 'road_segment';
    public function restore(Request $request, string $id, RoadTrafficService $traffic)
    {
        $data = Validator::make(json_decode($request->getContent(), true) ?? $request->all(), [
            'service_area_uuid' => ['required', 'uuid'],
            'expected_geometry' => ['required', 'array'], 'expected_geometry.type' => ['required', 'in:LineString'],
            'expected_geometry.coordinates' => ['required', 'array', 'min:2'], 'expected_geometry.coordinates.*' => ['array', 'size:2'],
            'expected_geometry.coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'expected_geometry.coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
        ])->validate();
        $company = (string) session('company');
        return DB::transaction(function () use ($data, $id, $company, $traffic) {
            DB::table('companies')->where('uuid', $company)->lockForUpdate()->first();
            $area = ServiceArea::where('company_uuid', $company)->where('uuid', $data['service_area_uuid'])->lockForUpdate()->firstOrFail();
            $segment = RoadSegment::withTrashed()->where('company_uuid', $company)->where('uuid', $id)->lockForUpdate()->firstOrFail();
            if (($segment->source_meta['service_area_uuid'] ?? $area->uuid) !== $area->uuid
                || !$this->roadInsideArea($segment->geometry, $area)) abort(422, '请选择原道路所在的 GCC。');
            if ($segment->geometry != $data['expected_geometry']) abort(409, '道路范围已经改变，请刷新后重新选择。');
            if ($segment->trashed()) {
                $graph = app(SiteRoadGraphService::class);
                $length = 0; $coords = $segment->geometry['coordinates'];
                for ($i = 1; $i < count($coords); $i++) $length += $graph->haversineMeters($coords[$i - 1][1], $coords[$i - 1][0], $coords[$i][1], $coords[$i][0]);
                $candidate = ['key' => 'restore:' . $segment->uuid, 'geometry' => $segment->geometry, 'length_meters' => $length,
                    'routing' => array_merge(['layer' => '0'], $segment->routing ?? []), 'restricted' => false];
                $review = app(OsmRoadImportService::class)->markExisting([$candidate], RoadSegment::where('company_uuid', $company)->lockForUpdate()->get()->toArray())[0];
                if ($review['availability'] === 'overlap') abort(422, '原道路' . $review['availability_label'] . '，请处理重叠道路后再恢复。');
                // Restore the original UUID and metadata; never create a duplicate or reset partial deletions.
                $before = $traffic->snapshot($company);
                $segment->restore();
                $after = $traffic->snapshot($company);
                app(RoadControlInheritanceService::class)->inheritControls($company, $before, $after, $graph);
            }
            return response()->json(['status' => 'ok', 'road_segment' => $segment->fresh()]);
        }, 3);
    }
}
