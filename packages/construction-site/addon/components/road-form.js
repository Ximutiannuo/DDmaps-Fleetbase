import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class RoadFormComponent extends Component {
    @tracked name;
    @tracked type;
    @tracked status;
    @tracked busy = false;
    @tracked error = null;
    @tracked saved = false;
    constructor() {
        super(...arguments);
        this.name = this.args.road.name || '';
        this.type = this.args.road.road_type || 'main';
        this.status = this.args.road.status || 'open';
    }
    @action field(name, event) { this[name] = event.target.value; this.saved = false; }
    @action async save(event) {
        event.preventDefault();
        if (this.busy) return;
        this.busy = true;
        this.error = null;
        const road = this.args.road;
        try {
            road.setProperties({ name: this.name.trim(), road_type: this.type, status: this.status });
            await road.save();
            this.saved = true;
        } catch (error) {
            road.rollbackAttributes();
            this.error = error.message || '保存失败，请检查权限后重试';
        } finally { this.busy = false; }
    }
}
