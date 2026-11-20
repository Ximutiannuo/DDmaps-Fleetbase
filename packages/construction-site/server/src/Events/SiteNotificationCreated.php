<?php

namespace Fleetbase\ConstructionSite\Events;

use Fleetbase\ConstructionSite\Models\SiteNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SiteNotificationCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The event id.
     *
     * @var string
     */
    public $eventId;

    /**
     * The datetime the broadcast was triggered.
     *
     * @var string
     */
    public $sentAt;

    /**
     * The site notification instance.
     *
     * @var \Fleetbase\ConstructionSite\Models\SiteNotification
     */
    public $notification;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(SiteNotification $notification)
    {
        $this->eventId      = uniqid('event_');
        $this->sentAt       = Carbon::now()->toDateTimeString();
        $this->notification = $notification;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel('company.' . $this->notification->company_uuid),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'construction-site.notification_created';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'id'          => $this->eventId,
            'api_version' => config('construction-site.api.version'),
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => [
                'id'           => $this->notification->public_id,
                'uuid'         => $this->notification->uuid,
                'title'        => $this->notification->title,
                'message'      => $this->notification->message,
                'type'         => $this->notification->type,
                'severity'     => $this->notification->severity,
                'source_type'  => $this->notification->source_type,
                'source_uuid'  => $this->notification->source_uuid,
                'target_scope' => $this->notification->target_scope,
            ],
        ];
    }
}
