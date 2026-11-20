import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DashboardRoute extends Route {
    @service store;

    async model() {
        const roads = await this.store.findAll('site-road');
        const activeEntries = await this.store.query('entry-registration', { status: 'active' });
        const activeEvents = await this.store.query('road-event', { status: 'active' });
        const notifications = await this.store.query('site-notification', { unread_only: true });

        return {
            roads,
            activeEntries,
            activeEvents,
            unreadNotifications: notifications,
        };
    }
}
