<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\ConstructionSite\Database\Factories\RoadEventFactory;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadEvent extends Model
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
    protected $table = 'road_events';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'road_event';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'road_segment_uuid',
        'event_type',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'status',
        'location',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'location'  => Json::class,
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return RoadEventFactory::new();
    }

    /**
     * The road segment this event was recorded for.
     *
     * @return BelongsTo
     */
    public function roadSegment(): BelongsTo
    {
        return $this->belongsTo(RoadSegment::class, 'road_segment_uuid', 'uuid');
    }
}
