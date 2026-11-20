<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Services\EntrySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntryRegistrationController extends ConstructionSiteController
{
    use \Fleetbase\ConstructionSite\Support\PublicErrors;
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'entry_registration';

    /**
     * Register a new vehicle entry session (driver tracking flow).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request, EntrySessionService $service): JsonResponse
    {
        $data = $request->has('entry') ? $request->input('entry') : $request->all();

        try {
            $registration = $service->register(is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            return $this->publicError($e);
        }

        return response()->json(
            [
                'status'              => 'ok',
                'session_token'       => $registration->issuedSessionToken,
                'entry_registration'  => $registration,
            ]
        );
    }

    /**
     * Exit an entry registration from the management API.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function exit(Request $request, string $id): JsonResponse
    {
        $registration = EntryRegistration::where(
            function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            }
        )
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        if ($registration->isActive()) {
            $registration->exit();
        }

        return response()->json(
            [
                'status'              => 'ok',
                'entry_registration'  => $registration->refresh(),
            ]
        );
    }

    /**
     * List active entry sessions that are currently navigating (for Live Map).
     */
    public function navigating(): JsonResponse
    {
        $entries = EntryRegistration::query()
            ->where('company_uuid', session('company'))
            ->where('status', 'active')
            ->where('is_navigating', true)
            ->with(['vehicle:uuid,public_id,plate_number,name', 'driver:uuid,public_id,user_uuid'])
            ->get([
                'uuid',
                'public_id',
                'vehicle_uuid',
                'driver_uuid',
                'destination_zone_uuid',
                'is_navigating',
                'active_route',
                'entered_at',
            ]);

        return response()->json([
            'status'  => 'ok',
            'entries' => $entries,
        ]);
    }

    /**
     * List current coordinates of every active entry's vehicle (for Live Map
     * driver markers). Uses the vehicle's last reported GPS location.
     */
    public function activeLocations(): JsonResponse
    {
        $entries = EntryRegistration::query()
            ->where('company_uuid', session('company'))
            ->where('status', 'active')
            ->with(['vehicle:uuid,public_id,plate_number,location'])
            ->get(['uuid', 'driver_name', 'vehicle_uuid', 'updated_at']);

        $locations = [];
        foreach ($entries as $entry) {
            $vehicle = $entry->vehicle;
            if (!$vehicle || !$vehicle->location) {
                continue;
            }

            $locations[] = [
                'entry_uuid'   => $entry->uuid,
                'driver_name'  => $entry->driver_name,
                'plate_number' => $vehicle->plate_number,
                'lat'          => (float) $vehicle->location->getLat(),
                'lng'          => (float) $vehicle->location->getLng(),
                'updated_at'   => $entry->updated_at?->toIso8601String(),
            ];
        }

        return response()->json(['status' => 'ok', 'locations' => $locations]);
    }

    /**
     * Resolve a suitable HTTP status code from an exception.
     */
    protected function resolveStatusCode(\Throwable $e): int
    {
        $code = (int) $e->getCode();

        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        return 422;
    }
}
