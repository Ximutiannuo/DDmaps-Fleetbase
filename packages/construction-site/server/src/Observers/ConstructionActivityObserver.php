<?php

namespace Fleetbase\ConstructionSite\Observers;

use Fleetbase\ConstructionSite\Models\ConstructionActivity;
use Fleetbase\ConstructionSite\Services\NotificationService;

class ConstructionActivityObserver
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function updated(ConstructionActivity $activity): void
    {
        if (!$activity->wasChanged('status')) {
            return;
        }

        $oldStatus = $activity->getOriginal('status');
        $newStatus = $activity->status;

        if ($oldStatus === 'planned' && $newStatus === 'active') {
            $segmentCount = $activity->roadSegments()->count();
            $this->notifications->notify(
                title: "施工活动开始: {$activity->title}",
                message: "施工活动 {$activity->title} 已开始" . ($segmentCount > 0 ? "，影响 {$segmentCount} 个路段" : ''),
                type: 'construction',
                severity: 'warning',
                sourceType: ConstructionActivity::class,
                sourceId: $activity->uuid,
                companyUuid: $activity->company_uuid,
            );
        } elseif (in_array($newStatus, ['completed', 'paused'])) {
            $statusText = $newStatus === 'completed' ? '已完成' : '已暂停';
            $this->notifications->notify(
                title: "施工活动{$statusText}: {$activity->title}",
                message: "施工活动 {$activity->title} {$statusText}",
                type: 'construction',
                severity: 'info',
                sourceType: ConstructionActivity::class,
                sourceId: $activity->uuid,
                companyUuid: $activity->company_uuid,
            );
        }
    }
}
