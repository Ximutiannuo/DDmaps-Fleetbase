import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NotificationsRoute extends Route {
    @service store;

    async model() {
        return this.store.query('site-notification', {});
    }
}
