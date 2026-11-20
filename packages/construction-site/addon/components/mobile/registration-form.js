import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class MobileRegistrationFormComponent extends Component {
    @service driverTracking;
    @tracked plateNumber = '';
    @tracked vehicleCategory = 'earthwork';
    @tracked driverName = '';
    @tracked driverPhone = '';
    @tracked purpose = '运料';
    @tracked error = null;
    @tracked submitting = false;

    @action setCategory(event) {
        this.vehicleCategory = event.target.value;
    }

    @action setPurpose(event) {
        this.purpose = event.target.value;
    }

    @action setField(field, event) { this[field] = event.target.value; }

    @action async submit(event) {
        event.preventDefault();
        if (this.submitting) return;
        this.submitting = true;
        this.error = null;

        try {
            await this.driverTracking.register({
                plate_number: this.plateNumber,
                vehicle_category: this.vehicleCategory,
                driver_name: this.driverName,
                driver_phone: this.driverPhone,
                purpose: this.purpose,
            });

            if (this.args.onRegistered) {
                this.args.onRegistered();
            }
        } catch (e) {
            this.error = e.message;
        } finally {
            this.submitting = false;
        }
    }
}
