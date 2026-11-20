<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;

class RoadTrafficControl extends Model
{
    use HasUuid;
    use HasPublicId;

    protected $table = 'road_traffic_controls';
    protected $publicIdType = 'traffic_control';
    protected $fillable = [
        'company_uuid', 'service_area_uuid', 'target_key', 'road_segment_uuid', 'geometry',
        'status', 'congestion_mode', 'manual_congestion', 'light_threshold',
        'moderate_threshold', 'heavy_threshold', 'note', 'is_removed', 'removed_at', 'removal_origin_key',
    ];
    protected $casts = ['geometry' => Json::class, 'is_removed' => 'boolean', 'removed_at' => 'datetime'];
}
