<?php

namespace Fleetbase\ConstructionSite\Providers;

use Fleetbase\ConstructionSite\Http\Middleware\SessionTokenAuth;
use Fleetbase\ConstructionSite\Models\AvoidArea;
use Fleetbase\ConstructionSite\Models\ConstructionActivity;
use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\RoadEvent;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Observers\AvoidAreaObserver;
use Fleetbase\ConstructionSite\Observers\ConstructionActivityObserver;
use Fleetbase\ConstructionSite\Observers\EntryRegistrationObserver;
use Fleetbase\ConstructionSite\Observers\RoadEventObserver;
use Fleetbase\ConstructionSite\Observers\RoadSegmentObserver;
use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\Providers\CoreServiceProvider;
use Illuminate\Support\ServiceProvider;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('ConstructionSite cannot be loaded without `fleetbase/core-api` installed!');
}

if (!class_exists(FleetOpsServiceProvider::class)) {
    throw new \Exception('ConstructionSite cannot be loaded without `fleetbase/fleetops-api` installed!');
}

/**
 * Construction Site management extension service provider.
 */
class ConstructionSiteServiceProvider extends ServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [
        RoadSegment::class          => RoadSegmentObserver::class,
        RoadEvent::class            => RoadEventObserver::class,
        ConstructionActivity::class => ConstructionActivityObserver::class,
        EntryRegistration::class    => EntryRegistrationObserver::class,
        AvoidArea::class            => AvoidAreaObserver::class,
    ];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/construction-site.php', 'construction-site');
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        foreach (['site-gps' => 30, 'site-routing' => 12, 'site-socket' => 20] as $name => $limit) {
            \Illuminate\Support\Facades\RateLimiter::for($name, fn ($request) =>
                \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)->by(hash('sha256', (string) $request->bearerToken())));
        }
        $this->app->booted(function () {
            \Illuminate\Support\Facades\Broadcast::extend('socketcluster', fn () => new \Fleetbase\ConstructionSite\Support\SecureSocketBroadcaster());
        });
        $this->registerObservers();
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        $this->app['router']->aliasMiddleware('construction-site.session', SessionTokenAuth::class);
        $this->app['router']->aliasMiddleware('construction-site.authorize', \Fleetbase\ConstructionSite\Http\Middleware\ConstructionSiteAuthorization::class);

        // Ensure the `fleetbaseRoutes` router macro exists before loading routes.
        // It is normally registered by Fleetbase\Providers\CoreServiceProvider::boot(),
        // but this provider may boot BEFORE core-api because the package manifest
        // is sorted alphabetically ('construction-site-api' < 'core-api').
        // Re-registration is idempotent (Route::macro overwrites the same keys).
        $this->app->booted(function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        });
    }

    /**
     * Register the model observers defined by the service provider.
     *
     * @return void
     */
    protected function registerObservers(): void
    {
        foreach ($this->observers as $model => $observer) {
            if (class_exists($model)) {
                $model::observe($observer);
            }
        }
    }
}
