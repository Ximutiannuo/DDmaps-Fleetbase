<?php

namespace Fleetbase\ConstructionSite\Observers;

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Models\AvoidArea;
use Fleetbase\ConstructionSite\Services\NotificationService;

class AvoidAreaObserver
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function created(AvoidArea $avoidArea): void
    {
        $this->broadcastAndNotify($avoidArea, 'created');
    }

    public function updated(AvoidArea $avoidArea): void
    {
        $this->broadcastAndNotify($avoidArea, 'updated');
    }

    public function deleted(AvoidArea $avoidArea): void
    {
        $this->broadcastAndNotify($avoidArea, 'deleted');
    }

    private function broadcastAndNotify(AvoidArea $avoidArea, string $action): void
    {
        $payload = [
            'uuid'               => $avoidArea->uuid,
            'public_id'          => $avoidArea->public_id,
            'service_area_uuid'  => $avoidArea->service_area_uuid,
            'zone_uuid'          => $avoidArea->zone_uuid,
            'geometry'           => $avoidArea->geometry,
            'status'             => $avoidArea->status,
            'reason'             => $avoidArea->reason,
        ];

        broadcast(new RoadNetworkChanged(
            (string) $avoidArea->company_uuid,
            'avoid_area',
            $action,
            $payload,
            $avoidArea->service_area_uuid
        ));

        $reason = $avoidArea->reason ?: '施工占道';
        $this->notifications->notify(
            title: '禁行区' . ($action === 'deleted' ? '已删除' : ($action === 'created' ? '已创建' : '已更新')),
            message: "GCC 禁行区（{$reason}）已{$this->actionLabel($action)}，导航车辆将重新规划路线",
            type: 'road_status',
            severity: $avoidArea->status === 'active' ? 'warning' : 'normal',
            sourceType: AvoidArea::class,
            sourceId: $avoidArea->uuid,
            companyUuid: $avoidArea->company_uuid,
        );
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'created' => '创建',
            'deleted' => '删除',
            default   => '更新',
        };
    }
}
