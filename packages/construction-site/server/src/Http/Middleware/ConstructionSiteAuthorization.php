<?php

namespace Fleetbase\ConstructionSite\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ConstructionSiteAuthorization
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !session('company')) {
            return response()->json(['message' => 'Authenticated company required.'], 401);
        }
        if (!\Fleetbase\Models\CompanyUser::where('company_uuid', session('company'))
            ->where('user_uuid', $request->user()->uuid)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Active company membership required.'], 403);
        }
        // Notification lifecycle is available to authenticated company members.
        $notificationAction = in_array($request->route()?->getActionName(), [
            'Fleetbase\\ConstructionSite\\Http\\Controllers\\SiteNotificationController@markAsRead',
            'Fleetbase\\ConstructionSite\\Http\\Controllers\\SiteNotificationController@markAllAsRead',
        ], true);
        if (!$request->isMethodSafe() && !$notificationAction && !self::canWrite($request->user())) {
            return response()->json(['message' => 'Construction site write permission required.'], 403);
        }
        return $next($request);
    }

    public static function canWrite($user): bool
    {
        if (!$user) return false;
        if ($user->isAdmin()) return true;
        $user->loadCompanyUser();
        if (!$user->companyUser || $user->companyUser->company_uuid !== session('company')) return false;
        try {
            return $user->companyUser->hasPermissionTo('construction-site write');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            return false;
        }
    }
}
