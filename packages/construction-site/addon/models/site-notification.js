import Model, { attr } from '@ember-data/model';

export default class SiteNotificationModel extends Model {
    @attr('string') public_id;
    @attr('string') title;
    @attr('string') message;
    @attr('string') type;
    @attr('string') severity;
    @attr('date') read_at;
    @attr('date') created_at;

    get isRead() {
        return this.read_at !== null;
    }

    get isCritical() {
        return this.severity === 'critical';
    }
}
