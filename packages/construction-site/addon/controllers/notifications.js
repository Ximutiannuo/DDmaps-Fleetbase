import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class NotificationsController extends Controller {
    @service fetch;
    @service constructionSiteSocket;
    @service notifications;
    @tracked busy = false;
    @action async markAllRead() {
        if (this.busy) return;
        this.busy = true;
        try {
            await this.fetch.post('notifications/read-all', {}, { namespace: 'construction-site/int/v1' });
            for (const item of this.model) item.set('read_at', new Date());
            this.constructionSiteSocket.emit('notifications:read');
            this.notifications.success('通知已全部标记为已读');
        } catch (error) { this.notifications.error(error.message || '操作失败，请重试'); }
        finally { this.busy = false; }
    }
}
