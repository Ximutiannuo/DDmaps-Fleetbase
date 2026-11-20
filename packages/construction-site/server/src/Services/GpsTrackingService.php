<?php

namespace Fleetbase\ConstructionSite\Services;

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\FleetOps\Events\DriverLocationChanged;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class GpsTrackingService
{
    /**
     * Update the driver's geolocation for an active entry registration.
     *
     * Validates GPS data, updates the Driver (and Vehicle) location, records
     * a position, and broadcasts a DriverLocationChanged event on the
     * company channel for real-time map updates.
     *
     * @return \Fleetbase\FleetOps\Models\Driver
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     */
    public function updateLocation(EntryRegistration $registration, array $gps): Driver
    {
        $validator = Validator::make(
            $gps,
            [
                'latitude'  => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'altitude'  => ['nullable', 'numeric'],
                'heading'   => ['nullable', 'numeric', 'between:0,360'],
                'speed'     => ['nullable', 'numeric', 'min:0'],
                'accuracy'  => ['nullable', 'numeric', 'min:0'],
                'observed_at' => ['nullable', 'date', 'before_or_equal:' . now()->addSeconds(30)->toIso8601String()],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data      = $validator->validated();
        $latitude  = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        session(['company' => $registration->company_uuid]);

        $driver = Driver::where('uuid', $registration->driver_uuid)->first();
        if (!$driver) {
            abort(404, 'Driver not found for this entry registration.');
        }

        $observedAt = isset($data['observed_at'])
            ? Carbon::parse($data['observed_at'])->setTimezone(config('app.timezone', 'UTC'))->startOfSecond()
            : now()->startOfSecond();
        $changed = false;
        DB::transaction(function () use ($registration, $driver, $data, $latitude, $longitude, $observedAt, &$changed) {
            // Serialize uploads for a session so delayed retries cannot move a driver backwards.
            $current = EntryRegistration::where('uuid', $registration->uuid)->lockForUpdate()->firstOrFail();
            if (!$current->isActive() || !$current->tracking_enabled) {
                abort(401, 'Tracking session is no longer active.');
            }
            $lastObserved = $current->gps_observed_at ?? $current->gps_reported_at;
            if ($lastObserved && $observedAt->lte($lastObserved)) {
                $driver->refresh();
                $registration->setRawAttributes($current->getAttributes(), true);
                return;
            }
            $positionData = [
                'location'  => new Point($latitude, $longitude),
                'altitude'  => $data['altitude'] ?? null,
                'heading'   => $data['heading'] ?? null,
                'speed'     => $data['speed'] ?? null,
            ];
            $driver->updateQuietly($positionData);
            if (method_exists($driver, 'createPosition')) {
                $driver->createPosition($positionData);
            }
            $driver->loadMissing('vehicle');
            if ($vehicle = $driver->vehicle) {
                $vehicle->updateQuietly($positionData);
                if (method_exists($vehicle, 'createPosition')) {
                    $vehicle->createPosition($positionData);
                }
            }
            $current->forceFill([
                'gps_latitude' => $latitude, 'gps_longitude' => $longitude,
                'gps_speed' => $data['speed'] ?? null, 'gps_accuracy' => $data['accuracy'] ?? null,
                'gps_reported_at' => now(), 'gps_observed_at' => $observedAt,
            ])->saveQuietly();
            $registration->setRawAttributes($current->getAttributes(), true);
            $changed = true;
        });

        if ($changed) {
            DB::afterCommit(function () use ($driver) {
                if ($driver->vehicle) {
                    broadcast(new VehicleLocationChanged($driver->vehicle));
                }
                broadcast(new DriverLocationChanged($driver));
            });
        }

        return $driver;
    }
}
