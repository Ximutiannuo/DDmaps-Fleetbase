<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\ConstructionSite\Database\Factories\ConstructionActivityFactory;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ConstructionActivity extends Model
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
    protected $table = 'construction_activities';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'construction_activity';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'title',
        'type',
        'status',
        'starts_at',
        'ends_at',
        'description',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return ConstructionActivityFactory::new();
    }

    /**
     * The road segments affected by this construction activity.
     *
     * @return BelongsToMany
     */
    public function roadSegments(): BelongsToMany
    {
        return $this->belongsToMany(
            RoadSegment::class,
            'construction_activity_road_segment',
            'construction_activity_uuid',
            'road_segment_uuid',
            'uuid',
            'uuid'
        );
    }
}
