<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\SiteNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteNotificationController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'site_notification';

    public function onQueryRecord($query, Request $request): void
    {
        parent::onQueryRecord($query, $request);
        if ($request->boolean('unread') || $request->boolean('unread_only')) $query->whereNull('read_at');
    }

    /**
     * Get the count of unread notifications for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = SiteNotification::where('company_uuid', session('company'))
            ->whereNull('read_at')
            ->count();

        return response()->json(
            [
                'status' => 'ok',
                'count'  => $count,
            ]
        );
    }

    /**
     * Mark a single notification as read.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = SiteNotification::where(
            function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            }
        )
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(
            [
                'status'             => 'ok',
                'site_notification'  => $notification,
            ]
        );
    }

    /**
     * Mark all unread notifications as read for the current company.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = SiteNotification::where('company_uuid', session('company'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(
            [
                'status'  => 'ok',
                'updated' => $updated,
            ]
        );
    }
}
