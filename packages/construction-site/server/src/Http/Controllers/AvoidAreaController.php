<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\AvoidArea;
use Fleetbase\FleetOps\Models\ServiceArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AvoidAreaController extends ConstructionSiteController
{
    use \Fleetbase\ConstructionSite\Support\ServiceAreaGeometry;
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'avoid_area';

    /**
     * Create an avoid area inside a ServiceArea (GCC).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request, true);
        $data['company_uuid'] = session('company');
        $data['created_by_uuid'] = session('user');
        $data['status'] = $data['status'] ?? 'active';
        $data['reason'] = $data['reason'] ?? '施工占道';

        $avoidArea = AvoidArea::create($data);

        return response()->json([
            'status'     => 'ok',
            'avoid_area' => $avoidArea,
        ], 201);
    }

    /**
     * Update an avoid area.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $avoidArea = AvoidArea::query()
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $data = $this->validatedPayload($request, false);
        $avoidArea->fill($data);
        $avoidArea->save();

        return response()->json([
            'status'     => 'ok',
            'avoid_area' => $avoidArea->fresh(),
        ]);
    }

    /**
     * Soft-delete an avoid area.
     */
    public function destroy(string $id): JsonResponse
    {
        $avoidArea = AvoidArea::query()
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $avoidArea->delete();

        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPayload(Request $request, bool $creating): array
    {
        $rules = [
            'geometry'             => [$creating ? 'required' : 'sometimes', 'array'],
            'geometry.type'        => ['required_with:geometry', 'in:LineString,Polygon'],
            'geometry.coordinates' => ['required_with:geometry', 'array'],
            'service_area_uuid'    => [$creating ? 'required' : 'sometimes', 'uuid'],
            'zone_uuid'            => ['nullable', 'uuid'],
            'status'               => ['nullable', 'in:active,inactive'],
            'reason'               => ['nullable', 'string', 'max:255'],
            'starts_at'            => ['nullable', 'date'],
            'ends_at'              => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];

        $data = $request->validate($rules);

        $serviceAreaUuid = $data['service_area_uuid'] ?? null;
        if ($serviceAreaUuid) {
            $area = ServiceArea::where('uuid', $serviceAreaUuid)
                ->where('company_uuid', session('company'))
                ->first();

            if (!$area) {
                throw ValidationException::withMessages([
                    'service_area_uuid' => ['Service area not found for this company.'],
                ]);
            }

            if (!empty($data['geometry']) && !$this->geometryCentroidInsideServiceArea($data['geometry'], $area)) {
                throw ValidationException::withMessages([
                    'geometry' => ['Avoid area must be inside the selected ServiceArea (GCC).'],
                ]);
            }
        }

        return $data;
    }

}
