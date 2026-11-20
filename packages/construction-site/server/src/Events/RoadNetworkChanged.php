<?php

namespace Fleetbase\ConstructionSite\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class RoadNetworkChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $eventId;
    public string $sentAt;
    public string $companyUuid;
    public string $type;
    public string $action;
    public ?string $serviceAreaUuid;
    public ?array $avoidArea;

    public function __construct(
        string $companyUuid,
        string $type,
        string $action,
        ?array $avoidArea = null,
        ?string $serviceAreaUuid = null
    ) {
        $this->eventId = uniqid('event_');
        $this->sentAt = Carbon::now()->toDateTimeString();
        $this->companyUuid = $companyUuid;
        $this->type = $type;
        $this->action = $action;
        $this->avoidArea = $avoidArea;
        $this->serviceAreaUuid = $serviceAreaUuid ?? ($avoidArea['service_area_uuid'] ?? null);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('company.' . $this->companyUuid),
            new Channel('road-network.' . $this->companyUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'construction-site.road_network_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->eventId,
            'api_version' => config('construction-site.api.version'),
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => [
                'type'               => $this->type,
                'action'             => $this->action,
                'service_area_uuid'  => $this->serviceAreaUuid,
                'company_uuid'       => $this->companyUuid,
                'avoid_area'         => $this->avoidArea,
            ],
        ];
    }
}
