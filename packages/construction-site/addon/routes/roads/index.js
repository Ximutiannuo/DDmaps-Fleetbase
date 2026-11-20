import Route from '@ember/routing/route';
export default class RoadsIndexRoute extends Route {
    model() { return this.modelFor('roads'); }
}
