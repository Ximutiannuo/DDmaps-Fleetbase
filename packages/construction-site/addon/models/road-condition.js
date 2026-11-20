import Model, { attr, belongsTo } from '@ember-data/model';

export default class RoadConditionModel extends Model {
    @attr('string') public_id;
    @attr('string') status;
    @attr('string') surface_condition;
    @attr('string') congestion;
    @attr('string') note;
    @attr('date') reported_at;
    @belongsTo('road-segment') segment;
}
