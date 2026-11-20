import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class LiveMapRoute extends Route {
    @service store;
    @service constructionSiteSocket;

    async model() {
        const [roads, segments, gates, activeEvents, activities] = await Promise.all([
            this.store.findAll('site-road'), this.store.findAll('road-segment'), this.store.findAll('gate'),
            this.store.query('road-event', { status: 'active' }), this.store.query('construction-activity', { status: 'active' }),
        ]);

        return { roads, segments, gates, activeEvents, activities };
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        this.constructionSiteSocket.subscribe();
        this.removeNotification?.();
        this.removeNotification = this.constructionSiteSocket.on('construction-site.notification_created', (notification) => {
            controller.set('latestNotification', notification);
        });
        controller.loadAvoidAreas();
        controller.loadServiceAreas();
        controller.refreshNavigatingEntries();
        controller.subscribeRoadNetwork();
    }

    deactivate() {
        this.controllerFor('live-map').stopTrafficRefresh();
        this.controllerFor('live-map').unsubscribeRoadNetwork();
        this.removeNotification?.();
        this.removeNotification = null;
    }

    @action loading(transition) {
        return true;
    }
}
