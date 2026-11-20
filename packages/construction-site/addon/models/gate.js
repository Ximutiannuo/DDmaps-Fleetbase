import Model, { attr, hasMany } from '@ember-data/model';

export default class GateModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') gate_type;
    @attr('string') status;
    @attr() location;
    @hasMany('entry-registration') entries;

    get isOpen() {
        return this.status === 'open';
    }
}
