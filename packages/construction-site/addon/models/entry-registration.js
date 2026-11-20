import Model, { attr } from '@ember-data/model';

export default class EntryRegistrationModel extends Model {
    @attr('string') public_id;
    @attr('string') driver_name;
    @attr('string') driver_phone;
    @attr('string') purpose;
    @attr('string') status;
    @attr('date') entered_at;
    @attr('date') exited_at;
    @attr('boolean') tracking_enabled;

    get isActive() {
        return this.status === 'active';
    }
}
