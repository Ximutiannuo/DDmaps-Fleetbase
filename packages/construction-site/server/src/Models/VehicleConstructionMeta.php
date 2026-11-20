<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\ConstructionSite\Database\Factories\VehicleConstructionMetaFactory;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleConstructionMeta extends Model
{
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
    protected $table = 'vehicle_construction_meta';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'vehicle_meta';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'vehicle_uuid',
        'vehicle_category',
        'specs',
        'capacity',
        'belonging_unit',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'specs'    => Json::class,
        'capacity' => 'decimal:2',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return VehicleConstructionMetaFactory::new();
    }

    /**
     * The vehicle this construction meta belongs to.
     *
     * @return BelongsTo
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }
}
