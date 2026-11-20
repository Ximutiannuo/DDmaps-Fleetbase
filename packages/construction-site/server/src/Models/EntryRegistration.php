<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\ConstructionSite\Database\Factories\EntryRegistrationFactory;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EntryRegistration extends Model
{
    // Raw tokens are returned once at registration, never serialized with resources.
    public ?string $issuedSessionToken = null;
    protected $hidden = ['session_token'];
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use HasFactory;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'entry_registrations';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'entry_registration';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'vehicle_uuid',
        'driver_uuid',
        'gate_uuid',
        'driver_name',
        'driver_phone',
        'purpose',
        'status',
        'entered_at',
        'exited_at',
        'tracking_enabled',
        'session_token',
        'token_expires_at',
        'destination_zone_uuid',
        'is_navigating',
        'active_route',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'token_expires_at' => 'datetime',
        'gps_observed_at' => 'datetime',
        'gps_reported_at' => 'datetime',
        'gps_speed' => 'float',
        'gps_accuracy' => 'float',
        'entered_at'        => 'datetime',
        'exited_at'         => 'datetime',
        'tracking_enabled'  => 'boolean',
        'is_navigating'     => 'boolean',
        'active_route'      => Json::class,
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return EntryRegistrationFactory::new();
    }

    /**
     * Boot the model and auto-generate a session token on creation.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->session_token)) {
                $model->session_token = hash('sha256', Str::random(64));
            }
            $model->token_expires_at ??= now()->addHours(config('construction-site.session_hours', 12));
        });
    }

    /**
     * Determine if the entry registration is still active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->exited_at === null;
    }

    /**
     * Mark the entry registration as exited.
     *
     * @return bool
     */
    public function exit(): bool
    {
        return $this->update([
            'status'           => 'exited',
            'exited_at'        => now(),
            'tracking_enabled' => false,
            'token_expires_at' => now(),
        ]);
    }

    /**
     * The vehicle associated with this entry registration.
     *
     * @return BelongsTo
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    /**
     * The driver associated with this entry registration.
     *
     * @return BelongsTo
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    /**
     * The gate this entry registration was recorded at.
     *
     * @return BelongsTo
     */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class, 'gate_uuid', 'uuid');
    }
}
