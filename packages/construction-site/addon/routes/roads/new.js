import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
export default class RoadsNewRoute extends Route {
    @service store;
    model() { return this.store.createRecord('site-road', { name: '', road_type: 'main', status: 'open' }); }
    deactivate() { const record = this.currentModel; if (record?.isNew) record.unloadRecord(); }
}
