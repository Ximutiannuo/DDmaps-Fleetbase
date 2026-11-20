import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const TRACKING_API = 'construction-site/int/v1/tracking';
const STORAGE_KEY = 'cs-driver-session-token';

export default class DriverTrackRegistrationFormComponent extends Component {
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

    @action setPlateNumber(event) {
        this.plateNumber = event.target.value;
    }

    @action setDriverName(event) {
        this.driverName = event.target.value;
    }

    @action setDriverPhone(event) {
        this.driverPhone = event.target.value;
    }

    @action async submit(event) {
        event.preventDefault();
        if (this.submitting) return;
        this.submitting = true;
        this.error = null;

        try {
            const formData = new URLSearchParams();
            formData.append('plate_number', this.plateNumber);
            formData.append('vehicle_category', this.vehicleCategory);
            formData.append('driver_name', this.driverName);
            formData.append('driver_phone', this.driverPhone);
            formData.append('purpose', this.purpose);

            const response = await fetch(`/${TRACKING_API}/register`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const validationMessage = Object.values(errorData.errors || {}).flat()[0];
                throw new Error(validationMessage || errorData.message || '登记失败');
            }

            const result = await response.json();
            const token = result.session_token || result.entry_registration?.session_token;
            if (token) {
                localStorage.setItem(STORAGE_KEY, token);
            }

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
