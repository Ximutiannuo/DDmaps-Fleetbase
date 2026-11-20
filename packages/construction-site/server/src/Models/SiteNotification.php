<?php

namespace Fleetbase\ConstructionSite\Models;

use Fleetbase\ConstructionSite\Database\Factories\SiteNotificationFactory;
use Fleetbase\Models\Model;
use Fleetbase\Traits\DisablesSoftDeletes;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SiteNotification extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use DisablesSoftDeletes;
    use HasFactory;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'site_notifications';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'site_notification';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'title',
        'message',
        'type',
        'severity',
        'source_type',
        'source_uuid',
        'target_scope',
        'read_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return SiteNotificationFactory::new();
    }

    /**
     * The polymorphic source of this notification.
     *
     * @return MorphTo
     */
    public function source(): MorphTo
    {
        return $this->morphTo(null, 'source_type', 'source_uuid');
    }

    /**
     * Determine if the notification has been read.
     *
     * @return bool
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Mark the notification as read.
     *
     * @return bool
     */
    public function markAsRead(): bool
    {
        return $this->update(['read_at' => now()]);
    }
}
