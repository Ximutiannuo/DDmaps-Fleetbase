import Model, { attr, hasMany } from '@ember-data/model';

export default class SiteRoadModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') road_type;
    @attr('string') status;
    @attr() geometry;
    @hasMany('road-segment') segments;

    get statusColor() {
        return { open: 'green', closed: 'red', partial: 'yellow', construction: 'orange' }[this.status] || 'gray';
    }

    get statusClasses() {
        return { open: 'bg-green-100 text-green-700', closed: 'bg-red-100 text-red-700',
            partial: 'bg-yellow-100 text-yellow-700', construction: 'bg-orange-100 text-orange-700' }[this.status] || 'bg-gray-100 text-gray-700';
    }
}
