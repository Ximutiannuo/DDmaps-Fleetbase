import Model, { attr, hasMany } from '@ember-data/model';

export default class ConstructionActivityModel extends Model {
    @attr('string') public_id;
    @attr('string') title;
    @attr('string') type;
    @attr('string') status;
    @attr('date') starts_at;
    @attr('date') ends_at;
    @attr('string') description;
    @hasMany('road-segment') roadSegments;

    get isActive() {
        return this.status === 'active';
    }
}
