import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class NotificationBellComponent extends Component {
    @service constructionSiteSocket;
    @service fetch;
    @tracked unreadCount = 0;
    @tracked recentNotifications = [];
    @tracked showPanel = false;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.loadUnreadCount();
        this.removeReadListener = this.constructionSiteSocket.on('notifications:read', () => this.loadUnreadCount());
        this.constructionSiteSocket.subscribe();
        this.removeListener = this.constructionSiteSocket.on('construction-site.notification_created', (notification) => {
            this.loadUnreadCount();
            this.recentNotifications = [notification, ...this.recentNotifications].slice(0, 5);
        });
    }

    async loadUnreadCount() {
        try {
            const data = await this.fetch.get('notifications/unread-count', {}, { namespace: 'construction-site/int/v1' });
            this.unreadCount = data.count || 0;
        } catch (e) {
            this.error = '通知暂时不可用，请稍后重试。';
        }
    }

    @action togglePanel() {
        this.showPanel = !this.showPanel;
        if (this.showPanel) this.loadRecent();
    }

    async loadRecent() {
        try {
            const data = await this.fetch.get('notifications', { limit: 5, sort: 'latest' }, { namespace: 'construction-site/int/v1' });
            this.recentNotifications = data.site_notifications || data.data || [];
            this.error = null;
        } catch (e) { this.error = '通知暂时不可用，请稍后重试。'; }
    }

    @action async markAllRead() {
        try {
            await this.fetch.post('notifications/read-all', {}, { namespace: 'construction-site/int/v1' });
            this.constructionSiteSocket.emit('notifications:read');
            this.unreadCount = 0;
            this.recentNotifications = this.recentNotifications.map((n) => ({ ...n, read_at: new Date() }));
        } catch (e) {
            this.error = '标记失败，请重试。';
        }
    }

    willDestroy() {
        this.removeListener?.();
        this.removeReadListener?.();
        super.willDestroy(...arguments);
    }
}
