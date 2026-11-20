<?php

namespace Fleetbase\ConstructionSite\Observers;

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Fleetbase\Support\ApiModelCache;

class RoadSegmentObserver
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function created(RoadSegment $segment): void
    {
        $this->broadcastChange($segment, 'created');
    }

    public function updated(RoadSegment $segment): void
    {
        if (!$segment->wasChanged('status') && !$segment->wasChanged('geometry') && !$segment->wasChanged('name')) {
            return;
        }

        $this->broadcastChange($segment, 'updated');

        if (!$segment->wasChanged('status')) {
            return;
        }

        $newStatus = $segment->status;
        $severity = $newStatus === 'closed' ? 'critical' : 'warning';
        $statusText = [
            'open'        => '通畅',
            'closed'      => '封闭',
            'partial'     => '部分封闭',
            'construction'=> '施工中',
        ][$newStatus] ?? $newStatus;

        $this->notifications->notify(
            title: "路段状态变更: {$segment->name}",
            message: "路段 {$segment->name} 状态变更为: {$statusText}",
            type: 'road_status',
            severity: $severity,
            sourceType: RoadSegment::class,
            sourceId: $segment->uuid,
            companyUuid: $segment->company_uuid,
        );
    }

    public function deleted(RoadSegment $segment): void
    {
        $this->broadcastChange($segment, 'deleted');
    }

    public function restored(RoadSegment $segment): void
    {
        $this->broadcastChange($segment, 'restored');
    }

    /**
     * Broadcast a road-network change so driver clients reload the network
     * and recalculate routes (e.g. when a segment becomes closed).
     */
    private function broadcastChange(RoadSegment $segment, string $action): void
    {
        if (!$segment->company_uuid) {
            return;
        }

        $change = new RoadNetworkChanged(
            (string) $segment->company_uuid,
            'road_segment',
            $action,
            [
                'uuid'     => $segment->uuid,
                'name'     => $segment->name,
                'status'   => $segment->status,
            ],
            null
        );
        DB::afterCommit(function () use ($segment, $change) {
            ApiModelCache::invalidateModelCache(new RoadSegment(), (string) $segment->company_uuid);
            event($change);
        });
    }
}
