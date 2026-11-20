<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class AvoidArea extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'avoid_areas';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'avoid_area';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'service_area_uuid',
        'zone_uuid',
        'geometry',
        'status',
        'reason',
        'starts_at',
        'ends_at',
        'created_by_uuid',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'geometry'  => Json::class,
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /**
     * Active avoid areas that have not expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $inner) {
                $inner->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }
}
