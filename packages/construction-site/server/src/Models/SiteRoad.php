<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\ConstructionSite\Database\Factories\SiteRoadFactory;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteRoad extends Model
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
    protected $table = 'site_roads';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'site_road';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'name',
        'road_type',
        'status',
        'geometry',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'geometry' => Json::class,
    ];

    protected static function booted(): void
    {
        static::saving(function ($road) {
            if (!$road->exists) $road->company_uuid = session('company');
            if ($road->company_uuid !== session('company')) abort(403);
            \Illuminate\Support\Facades\Validator::make($road->getAttributes(), [
                'name' => ['required', 'string', 'max:255'],
                'road_type' => ['required', 'in:main,branch,haul_road,ring_road'],
                'status' => ['required', 'in:open,partial,construction,closed'],
            ])->validate();
        });
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return SiteRoadFactory::new();
    }

    /**
     * The road segments that make up this site road.
     *
     * @return HasMany
     */
    public function roadSegments(): HasMany
    {
        return $this->hasMany(RoadSegment::class, 'site_road_uuid', 'uuid');
    }
}
