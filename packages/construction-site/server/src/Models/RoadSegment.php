<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\ConstructionSite\Database\Factories\RoadSegmentFactory;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoadSegment extends Model
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
    protected $table = 'road_segments';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'road_segment';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'site_road_uuid',
        'name',
        'sequence',
        'status',
        'geometry',
        'current_condition_uuid',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'geometry'  => Json::class,
        'sequence'  => 'integer',
        'source_meta' => Json::class,
        'routing' => Json::class,
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return RoadSegmentFactory::new();
    }

    /**
     * The site road this segment belongs to.
     *
     * @return BelongsTo
     */
    public function siteRoad(): BelongsTo
    {
        return $this->belongsTo(SiteRoad::class, 'site_road_uuid', 'uuid');
    }

    /**
     * The historical road conditions recorded for this segment.
     *
     * @return HasMany
     */
    public function roadConditions(): HasMany
    {
        return $this->hasMany(RoadCondition::class, 'road_segment_uuid', 'uuid');
    }

    /**
     * The road events recorded for this segment.
     *
     * @return HasMany
     */
    public function roadEvents(): HasMany
    {
        return $this->hasMany(RoadEvent::class, 'road_segment_uuid', 'uuid');
    }

    /**
     * The current road condition applied to this segment.
     *
     * @return BelongsTo
     */
    public function currentCondition(): BelongsTo
    {
        return $this->belongsTo(RoadCondition::class, 'current_condition_uuid', 'uuid');
    }
}
