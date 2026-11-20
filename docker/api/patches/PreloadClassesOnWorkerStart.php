<?php

namespace App\Listeners;

use Laravel\Octane\Events\WorkerStarting;

class PreloadClassesOnWorkerStart
{
    public function handle(WorkerStarting $event): void
    {
        $classes = [
            "Fleetbase\\Models\\User",
            "Fleetbase\\Models\\Company",
            "Fleetbase\\Models\\ApiCredential",
            "Fleetbase\\Models\\Notification",
            "Fleetbase\\Models\\ChatParticipant",
            "Fleetbase\\FleetOps\\Models\\Vehicle",
            "Fleetbase\\FleetOps\\Models\\Driver",
            "Fleetbase\\FleetOps\\Models\\Order",
            "Fleetbase\\FleetOps\\Models\\Place",
            "Fleetbase\\FleetOps\\Models\\ServiceArea",
            "Fleetbase\\ConstructionSite\\Models\\SiteRoad",
            "Fleetbase\\ConstructionSite\\Models\\RoadSegment",
            "Fleetbase\\ConstructionSite\\Models\\Gate",
            "Fleetbase\\ConstructionSite\\Models\\RoadCondition",
            "Fleetbase\\ConstructionSite\\Models\\RoadEvent",
            "Fleetbase\\ConstructionSite\\Models\\ConstructionActivity",
            "Fleetbase\\ConstructionSite\\Models\\EntryRegistration",
            "Fleetbase\\ConstructionSite\\Models\\SiteNotification",
            "Fleetbase\\ConstructionSite\\Models\\AvoidArea",
            "Fleetbase\\ConstructionSite\\Models\\VehicleConstructionMeta",
        ];
        foreach ($classes as $class) {
            class_exists($class, true);
        }
    }
}
