import Model, { attr, belongsTo } from '@ember-data/model';

export default class RoadEventModel extends Model {
    @attr('string') public_id;
    @attr('string') event_type;
    @attr('string') title;
    @attr('string') description;
    @attr('date') starts_at;
    @attr('date') ends_at;
    @attr('string') status;
    @attr() location;
    @belongsTo('road-segment') segment;

    get isActive() {
        return this.status === 'active';
    }
}
