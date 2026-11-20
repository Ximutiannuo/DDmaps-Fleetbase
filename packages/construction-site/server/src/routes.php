<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which is
| assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(config('construction-site.api.routing.prefix', 'construction-site'))->namespace('Fleetbase\ConstructionSite\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Internal API Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for the Fleetbase console.
        */
        $router->prefix(config('construction-site.api.routing.internal_prefix', 'int'))->group(
            function ($router) {
                $router->group(
                    ['prefix' => 'v1', 'middleware' => ['fleetbase.protected', 'construction-site.authorize']],
                    function ($router) {
                        $router->get('socket-token', 'SiteSocketController@member')->middleware('throttle:site-socket');
                        $router->get('traffic', 'RoadTrafficController@traffic');
                        $router->post('traffic-controls', 'RoadTrafficController@saveControl');
                        $router->post('road-sections/remove', 'RoadSectionController@remove');
                        $router->post('road-sections/restore', 'RoadSectionController@restore');
                        $router->post('road-imports/preview', 'RoadImportController@preview');
                        $router->post('road-imports/commit', 'RoadImportController@commit');
                        $router->post('road-segments/{id}/restore', 'RoadRecoveryController@restore');

                        // Roads
                        $router->fleetbaseRoutes(
                            'roads',
                            function ($router, $controller) {
                                $router->get('{id}/segments', $controller('segments'));
                            },
                            [],
                            'SiteRoadController'
                        );

                        // Road Segments
                        // Custom write routes: the generic fleetbaseRoutes
                        // createRecord/updateRecord strip payload fields, but
                        // explicit routes parse input directly (same pattern
                        // as storeForSegment below).
                        $router->post('road-segments/create', 'RoadSegmentController@createSegment');
                        $router->patch('road-segments/{id}/update', 'RoadSegmentController@updateSegment');
                        $router->delete('road-segments/{id}', 'RoadSegmentController@deleteSegment');
                        $router->fleetbaseRoutes('road-segments', [], ['only' => ['query', 'find']], 'RoadSegmentController');
                        // Cross-resource: create a condition for a segment
                        $router->post('road-segments/{id}/conditions', 'RoadConditionController@storeForSegment');

                        // Gates
                        $router->fleetbaseRoutes('gates', [], [], 'GateController');

                        // Road Conditions (no update — conditions are historical)
                        $router->fleetbaseRoutes('road-conditions', [], ['except' => ['update']], 'RoadConditionController');

                        // Road Events
                        $router->fleetbaseRoutes(
                            'road-events',
                            function ($router, $controller) {
                                $router->patch('{id}/resolve', $controller('resolve'));
                            },
                            [],
                            'RoadEventController'
                        );

                        // Avoid areas (GCC construction / closure overlays)
                        $router->fleetbaseRoutes(
                            'avoid-areas',
                            function ($router, $controller) {
                                $router->post('/', $controller('store'));
                                $router->put('{id}', $controller('update'));
                                $router->patch('{id}', $controller('update'));
                                $router->delete('{id}', $controller('destroy'));
                            },
                            ['except' => ['create', 'update', 'delete']],
                            'AvoidAreaController'
                        );

                        // Navigating entry sessions (for Live Map route sync)
                        $router->get('navigating-entries', 'EntryRegistrationController@navigating');
                        $router->get('active-vehicle-locations', 'EntryRegistrationController@activeLocations');

                        // Construction Activities
                        $router->fleetbaseRoutes(
                            'construction-activities',
                            function ($router, $controller) {
                                $router->post('{id}/segments', $controller('attachSegments'));
                                $router->delete('{id}/segments/{segment}', $controller('detachSegment'));
                            },
                            [],
                            'ConstructionActivityController'
                        );

                        // Vehicle Construction Meta
                        $router->fleetbaseRoutes('vehicle-meta', [], [], 'VehicleMetaController');

                        // Entry Registrations (query, find, delete + exit)
                        $router->fleetbaseRoutes(
                            'entries',
                            function ($router, $controller) {
                                $router->patch('{id}/exit', $controller('exit'));
                            },
                            ['only' => ['query', 'find', 'delete']],
                            'EntryRegistrationController'
                        );

                        // Site Notifications (query + lifecycle)
                        $router->fleetbaseRoutes(
                            'notifications',
                            function ($router, $controller) {
                                $router->get('unread-count', $controller('unreadCount'));
                                $router->patch('{id}/read', $controller('markAsRead'));
                                $router->post('read-all', $controller('markAllAsRead'));
                            },
                            ['only' => ['query']],
                            'SiteNotificationController'
                        );
                    }
                );

                /*
                |--------------------------------------------------------------------------
                | Driver Tracking API Routes
                |--------------------------------------------------------------------------
                |
                | Lightweight session-token based tracking endpoints. These do not
                | use fleetbase.protected; the SessionTokenAuth middleware resolves
                | the active EntryRegistration from the bearer/ session token.
                */
                $router->post('v1/tracking/register', 'EntryRegistrationController@register')->middleware('throttle:10,1');
                $router->group(
                    ['prefix' => 'v1/tracking', 'middleware' => ['construction-site.session']],
                    function ($router) {
                        $router->get('socket-token', 'SiteSocketController@driver')->middleware('throttle:site-socket');
                        $router->post('update', 'TrackingController@update')->middleware('throttle:site-gps');
                        $router->post('exit', 'TrackingController@exit');
                        $router->get('status', 'TrackingController@status');
                        $router->get('service-areas', 'TrackingController@serviceAreas');
                        $router->post('route', 'TrackingController@route')->middleware('throttle:site-routing');
                        $router->get('road-network', 'TrackingController@roadNetwork')->middleware('throttle:site-routing');
                        $router->post('navigation/stop', 'TrackingController@stopNavigation');
                    }
                );
            }
        );
    }
);
