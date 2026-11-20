import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
export default class RoadsEditRoute extends Route {
    @service store;
    model(params) { return this.store.findRecord('site-road', params.public_id); }
}
