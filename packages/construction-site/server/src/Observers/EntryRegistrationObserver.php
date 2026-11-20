<?php

namespace Fleetbase\ConstructionSite\Observers;

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Services\NotificationService;

class EntryRegistrationObserver
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function created(EntryRegistration $registration): void
    {
        if ($registration->status !== 'active') {
            return;
        }

        $this->notifications->notify(
            title: '车辆进场',
            message: '车辆已完成进场登记，请在有权限的登记记录中查看详情。',
            type: 'entry',
            severity: 'info',
            sourceType: EntryRegistration::class,
            sourceId: $registration->uuid,
            companyUuid: $registration->company_uuid,
            targetScope: 'all',
        );
    }
}
