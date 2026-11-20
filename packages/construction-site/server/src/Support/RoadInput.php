<?php

namespace Fleetbase\ConstructionSite\Support;

use Fleetbase\ConstructionSite\Models\SiteRoad;
use Fleetbase\ConstructionSite\Models\RoadCondition;
use Illuminate\Support\Facades\Validator;

final class RoadInput
{
    public static function segment(array $data, bool $creating): array
    {
        $result = Validator::make($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:open,partial,construction,closed'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'site_road_uuid' => ['nullable', 'uuid'],
            'current_condition_uuid' => ['nullable', 'uuid'],
            'geometry' => [$creating ? 'required' : 'sometimes', 'array'],
            'geometry.type' => ['required_with:geometry', 'in:LineString'],
            'geometry.coordinates' => ['required_with:geometry', 'array', 'min:2', 'max:10000'],
            'geometry.coordinates.*' => ['array', 'size:2'],
            'geometry.coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'geometry.coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
        ])->validate();
        if (!empty($result['site_road_uuid'])) {
            SiteRoad::where('company_uuid', session('company'))->where('uuid', $result['site_road_uuid'])->firstOrFail();
        }
        if (!empty($result['current_condition_uuid'])) {
            RoadCondition::where('company_uuid', session('company'))->where('uuid', $result['current_condition_uuid'])->firstOrFail();
        }
        return $result;
    }
}
