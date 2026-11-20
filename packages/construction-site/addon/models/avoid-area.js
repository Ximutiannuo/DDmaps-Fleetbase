import Model, { attr } from '@ember-data/model';

export default class AvoidAreaModel extends Model {
    @attr('string') public_id;
    @attr('string') service_area_uuid;
    @attr('string') zone_uuid;
    @attr() geometry;
    @attr('string') status;
    @attr('string') reason;
    @attr('date') starts_at;
    @attr('date') ends_at;
}
