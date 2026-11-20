<?php

namespace Fleetbase\ConstructionSite\Observers;

use Fleetbase\ConstructionSite\Models\RoadEvent;
use Fleetbase\ConstructionSite\Services\NotificationService;

class RoadEventObserver
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function created(RoadEvent $event): void
    {
        if ($event->status !== 'active') {
            return;
        }

        $segmentName = $event->segment?->name ?? '未知路段';

        $this->notifications->notify(
            title: "新道路事件: {$event->title}",
            message: "路段 {$segmentName} 新增事件: {$event->title}",
            type: 'event',
            severity: 'warning',
            sourceType: RoadEvent::class,
            sourceId: $event->uuid,
            companyUuid: $event->company_uuid,
        );
    }

    public function updated(RoadEvent $event): void
    {
        if (!$event->wasChanged('status') || $event->status !== 'resolved') {
            return;
        }

        $this->notifications->notify(
            title: "道路事件已解除: {$event->title}",
            message: "事件 {$event->title} 已解除",
            type: 'event',
            severity: 'info',
            sourceType: RoadEvent::class,
            sourceId: $event->uuid,
            companyUuid: $event->company_uuid,
        );
    }
}
