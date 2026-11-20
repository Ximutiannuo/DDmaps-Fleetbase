import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import L from 'leaflet';

export default class MobileTrackingViewComponent extends Component {
    @service driverTracking;
    @tracked showExitConfirm = false;
    @tracked mapInstance = null;
    @tracked driverMarker = null;
    @tracked routePolyline = null;
    @tracked routeInfo = null;

    constructor() {
        super(...arguments);
        if (!this.driverTracking.isTracking) {
            this.driverTracking.startTracking();
        }
    }

    willDestroy() {
        this.driverTracking.stopTracking();
        if (this.mapInstance) {
            this.mapInstance.remove();
            this.mapInstance = null;
        }
        super.willDestroy(...arguments);
    }

    get position() {
        return this.driverTracking.currentPosition;
    }

    get error() {
        return this.driverTracking.error;
    }

    get isTracking() {
        return this.driverTracking.isTracking;
    }

    get latitude() {
        return this.position?.coords?.latitude;
    }

    get longitude() {
        return this.position?.coords?.longitude;
    }

    get accuracy() {
        return this.position?.coords?.accuracy;
    }

    @action setupMap(element) {
        if (this.mapInstance) {
            return;
        }

        const map = L.map(element, {
            zoomControl: true,
            attributionControl: true,
        }).setView([39.9042, 116.4074], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        this.mapInstance = map;
        this.updateMarkerFromPosition();
    }

    @action updateMarkerFromPosition() {
        if (!this.mapInstance || !this.position) {
            return;
        }

        const lat = this.position.coords.latitude;
        const lng = this.position.coords.longitude;
        const latlng = [lat, lng];

        if (!this.driverMarker) {
            const icon = L.divIcon({
                className: 'driver-marker',
                html: '<div style="width:20px;height:20px;background:#2563eb;border:2px solid white;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,0.3);"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10],
            });
            this.driverMarker = L.marker(latlng, { icon }).addTo(this.mapInstance);
            this.mapInstance.setView(latlng, 16);
        } else {
            this.driverMarker.setLatLng(latlng);
            this.mapInstance.panTo(latlng);
        }
    }

    @action retryLocation() {
        this.driverTracking.stopTracking();
        this.driverTracking.startTracking();
    }

    @action confirmExit() {
        this.showExitConfirm = true;
    }

    @action cancelExit() {
        this.showExitConfirm = false;
    }

    @action async doExit() {
        await this.driverTracking.exit();
        if (this.args.onExited) {
            this.args.onExited();
        }
    }
}
