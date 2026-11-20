<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\ConstructionSite\Database\Factories\RoadConditionFactory;
use Fleetbase\Models\Model;
use Fleetbase\Traits\DisablesSoftDeletes;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadCondition extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use DisablesSoftDeletes;
    use HasFactory;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'road_conditions';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'road_condition';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'road_segment_uuid',
        'status',
        'surface_condition',
        'congestion',
        'note',
        'reported_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'reported_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($condition) {
            $condition->company_uuid = session('company');
            $condition->reported_by_uuid = session('user');
            \Illuminate\Support\Facades\Validator::make($condition->getAttributes(), [
                'road_segment_uuid' => ['required', 'uuid'],
                'status' => ['required', 'in:open,partial,construction,closed'],
                'surface_condition' => ['required', 'in:dry,wet,muddy,icy,snowy,damaged'],
                'congestion' => ['required', 'in:none,light,moderate,heavy,standstill,unknown'],
                'note' => ['nullable', 'string', 'max:1000'],
                'reported_at' => ['nullable', 'date'],
            ])->validate();
            RoadSegment::where('company_uuid', $condition->company_uuid)->where('uuid', $condition->road_segment_uuid)->firstOrFail();
        });
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return RoadConditionFactory::new();
    }

    /**
     * The road segment this condition was recorded for.
     *
     * @return BelongsTo
     */
    public function roadSegment(): BelongsTo
    {
        return $this->belongsTo(RoadSegment::class, 'road_segment_uuid', 'uuid');
    }
}
