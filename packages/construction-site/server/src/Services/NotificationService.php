<?php

namespace Fleetbase\ConstructionSite\Services;

use Fleetbase\ConstructionSite\Events\SiteNotificationCreated;
use Fleetbase\ConstructionSite\Models\SiteNotification;

class NotificationService
{
    /**
     * Create a site notification and broadcast it via the company channel.
     *
     * @return \Fleetbase\ConstructionSite\Models\SiteNotification
     */
    public function notify(
        string $title,
        string $message,
        string $type = 'info',
        string $severity = 'normal',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $targetScope = null,
        ?string $companyUuid = null
    ): SiteNotification {
        $companyUuid = $companyUuid ?? session('company');

        $notification = SiteNotification::create(
            [
                'company_uuid'  => $companyUuid,
                'title'         => $title,
                'message'       => $message,
                'type'          => $type,
                'severity'      => $severity,
                'source_type'   => $sourceType,
                'source_uuid'   => $sourceId,
                'target_scope'  => $targetScope ?? 'all',
            ]
        );

        broadcast(new SiteNotificationCreated($notification));

        return $notification;
    }
}
