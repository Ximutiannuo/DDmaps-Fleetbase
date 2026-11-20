import Route from '@ember/routing/route';

export default class DriverTrackRoute extends Route {
    setupController(controller, model) {
        super.setupController(controller, model);
        controller.startSessionCheck();
    }
}
