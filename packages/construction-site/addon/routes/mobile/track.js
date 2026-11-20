import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MobileTrackRoute extends Route {
    @service driverTracking;

    async setupController(controller, model) {
        super.setupController(controller, model);
        const existingToken = this.driverTracking.restoreSession();
        controller.set('sessionError', null);
        try {
            controller.set('hasActiveSession', existingToken ? !!(await this.driverTracking.fetchStatus()) : false);
        } catch (error) {
            controller.set('sessionError', '暂时无法连接服务器，入场凭证已保留，请恢复网络后刷新页面。');
        }
    }
}
