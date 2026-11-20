<?php

namespace Fleetbase\ConstructionSite\Services;

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\Gate;
use Fleetbase\ConstructionSite\Support\RegistrationInput;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EntrySessionService
{
    /**
     * Register a new vehicle entry session at a gate.
     *
     * Creates/upserts the vehicle by plate number, resolves or creates a
     * driver profile (User -> Driver) for tracking, and issues a new
     * EntryRegistration with a session token.
     *
     * @return \Fleetbase\ConstructionSite\Models\EntryRegistration
     *
     * @throws \Exception
     */
    public function register(array $data): EntryRegistration
    {
        $data = RegistrationInput::validate($data);
        $companyUuid = (string) config('construction-site.default_company_uuid');
        $gateUuid = config('construction-site.default_gate_uuid') ?: null;
        return DB::transaction(function () use ($data, $companyUuid, $gateUuid) {
            // The configured company determines ownership and serializes registrations.
            if (!Company::where('uuid', $companyUuid)->lockForUpdate()->first(['uuid'])) {
                abort(403, '此站点尚未配置司机登记所属的组织。');
            }
            if ($gateUuid && !Gate::where('company_uuid', $companyUuid)->where('uuid', $gateUuid)->exists()) {
                abort(403, '此站点配置的登记门岗不可用。');
            }
            $vehicle = $this->resolveVehicle($companyUuid, $data['plate_number'], $data);
            $active = EntryRegistration::where('company_uuid', $companyUuid)->where('vehicle_uuid', $vehicle->uuid)
                ->where('status', 'active')->whereNull('exited_at')->lockForUpdate()->first();
            if ($active && $active->token_expires_at?->isFuture()) {
                abort(409, '该车辆已有有效进场会话，请在原设备退场后重新登记。');
            }
            if ($active) $active->exit();
            $driver = $this->resolveDriver($companyUuid, $data['driver_name'], $data['driver_phone'], $data);
            if (EntryRegistration::where('company_uuid', $companyUuid)->where('driver_uuid', $driver->uuid)
                ->where('status', 'active')->whereNull('exited_at')->where('token_expires_at', '>', now())->exists()) {
                abort(409, '该司机正在另一辆车的有效会话中，请先退场。');
            }
            $previousVehicle = $driver->vehicle_uuid;
            $driver->vehicle_uuid = $vehicle->uuid;
            $driver->save();
            $rawToken = Str::random(64);
            $registration = EntryRegistration::create([
                'company_uuid' => $companyUuid, 'vehicle_uuid' => $vehicle->uuid, 'driver_uuid' => $driver->uuid,
                'gate_uuid' => $gateUuid, 'driver_name' => $data['driver_name'],
                'driver_phone' => $data['driver_phone'], 'purpose' => $data['purpose'] ?? '施工运输',
                'status' => 'active', 'entered_at' => now(), 'tracking_enabled' => true,
                'session_token' => hash('sha256', $rawToken),
                'token_expires_at' => now()->addHours(config('construction-site.session_hours', 12)),
            ]);
            DB::table('entry_session_audits')->insert([
                'company_uuid' => $companyUuid, 'entry_uuid' => $registration->uuid,
                'driver_uuid' => $driver->uuid, 'vehicle_uuid' => $vehicle->uuid,
                'previous_vehicle_uuid' => $previousVehicle,
                'action' => 'registered', 'created_at' => now(),
            ]);
            $registration->issuedSessionToken = $rawToken;
            return $registration->load(['vehicle', 'driver', 'gate']);
        }, 3);
    }

    /**
     * Exit an active entry registration, disabling tracking.
     *
     * @return \Fleetbase\ConstructionSite\Models\EntryRegistration
     */
    public function exit(EntryRegistration $registration): EntryRegistration
    {
        if ($registration->isActive()) {
            $registration->exit();
        }

        return $registration->refresh();
    }

    /**
     * Find or create the vehicle for the given company by plate number.
     */
    protected function resolveVehicle(string $companyUuid, string $plateNumber, array $data): Vehicle
    {
        $vehicle = Vehicle::where('company_uuid', $companyUuid)
            ->where('plate_number', $plateNumber)
            ->first();

        if ($vehicle) {
            return $vehicle;
        }

        return Vehicle::create(
            [
                'company_uuid'  => $companyUuid,
                'name'          => $data['vehicle_name'] ?? $plateNumber,
                'plate_number'  => $plateNumber,
                'make'          => $data['vehicle_make'] ?? null,
                'model'         => $data['vehicle_model'] ?? null,
                'year'          => $data['vehicle_year'] ?? null,
                'color'         => $data['vehicle_color'] ?? null,
                'status'        => 'active',
                'online'        => true,
            ]
        );
    }

    /**
     * Resolve or create a Driver (and backing User) for tracking purposes.
     *
     * Driver name/phone are virtual attributes sourced from the User model,
     * so a User must exist first (mirrors the fleetops DriverController flow).
     */
    protected function resolveDriver(?string $companyUuid, ?string $driverName, ?string $driverPhone, array $data): ?Driver
    {
        if (empty($driverPhone) && empty($driverName)) {
            return null;
        }

        $user = null;
        if (!empty($driverPhone)) {
            $user = User::where('company_uuid', $companyUuid)->where('phone', $driverPhone)->first();
        }

        if ($user) {
            if ($user->type !== 'driver') abort(409, '该手机号已关联非司机账号，请联系管理人员。');
            $driver = Driver::where('user_uuid', $user->uuid)
                ->where('company_uuid', $companyUuid)
                ->first();
            if ($driver) {
                return $driver;
            }
        } else {
            $userInput = [
                'name'         => $driverName ?? 'Construction Driver',
                'phone'        => $driverPhone,
                'company_uuid' => $companyUuid,
                'status'       => 'active',
            ];
            if (!empty($data['driver_email'])) {
                $userInput['email'] = $data['driver_email'];
            }

            $user = User::create($userInput);
            $user->setType('driver');
        }

        return Driver::create(
            [
                'company_uuid'            => $companyUuid,
                'user_uuid'               => $user->uuid,
                'drivers_license_number'  => 'CS' . Str::random(12),
                'location'                => new Point(0, 0),
                'online'                  => true,
                'status'                  => 'active',
                'slug'                    => $user->slug ?? ('cs-' . Str::random(8)),
            ]
        );
    }
}
