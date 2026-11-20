import Model, { attr, belongsTo, hasMany } from '@ember-data/model';

export default class RoadSegmentModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('number') sequence;
    @attr('string') status;
    @attr() geometry;
    @belongsTo('site-road') road;
    @belongsTo('road-condition') currentCondition;
    @hasMany('road-condition') conditions;
    @hasMany('road-event') events;

    get statusColor() {
        return {
            open: 'green',
            closed: 'red',
            partial: 'yellow',
            construction: 'orange',
        }[this.status] || 'gray';
    }
}
