<?php

namespace Fleetbase\ConstructionSite\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\ConstructionSite\Models\VehicleConstructionMeta;

class VehicleExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Fleetbase\FleetOps\Models\Vehicle::class;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public static function constructionMeta()
    {
        return function () {
            /** @var \Illuminate\Database\Eloquent\Model $this */

            return $this->hasOne(VehicleConstructionMeta::class, 'vehicle_uuid', 'uuid');
        };
    }
}
