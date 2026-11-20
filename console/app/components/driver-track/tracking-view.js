import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import L from 'leaflet';
import { calculateSiteRoute, stopSiteNavigation, distanceToRoute, navigationProgress, haversineDistance } from '../../utils/osrm-navigation';
import GpsUploader from '../../utils/gps-uploader';
import { inject as service } from '@ember/service';

const TRACKING_API = 'construction-site/int/v1/tracking';
const STORAGE_KEY = 'cs-driver-session-token';
const ROAD_STATUS_PANE = 'cs-road-status';
const NAVIGATION_PANE = 'cs-navigation';

export default class DriverTrackTrackingViewComponent extends Component {
    @service socket;

    @tracked showExitConfirm = false;
    @tracked error = null;
    @tracked position = null;
    @tracked isTracking = false;
    @tracked mapInstance = null;
    @tracked driverMarker = null;
    @tracked lastUpdateLabel = null;
    @tracked updateCount = 0;
    @tracked mode = 'tracking';
    @tracked currentRoute = null;
    @tracked destination = null;
    @tracked isCalculatingRoute = false;
    @tracked showRecalcNotice = false;
    @tracked navStepIndex = 0;
    @tracked navTraveledDistance = 0;
    @tracked navRemainingDistance = 0;
    @tracked navRemainingDuration = 0;
    @tracked navStepRemainingDistance = 0;
    @tracked gpsUploadState = 'waiting';
    @tracked lastUploadAt = null;
    @tracked statusNow = Date.now();
    statusTimer = null;
    gpsUploader = null;
    onlineHandler = null;
    @tracked trafficSummary = '路况加载中';
    @tracked trafficUpdatedAt = null;
    trafficTimer = null;
    trafficRevision = null;
    isLoadingNetwork = false;
    isSubscribedNetwork = false;
    pendingRouteRefresh = false;

    watchId = null;
    sessionToken = null;
    accuracyCircle = null;
    followMode = true;
    offRouteCount = 0;
    routePolyline = null;
    offRoadPolylines = [];
    traveledPolyline = null;
    startMarker = null;
    endMarker = null;
    serviceAreaPolygons = [];
    roadNetworkLayers = [];
    networkChannelClose = null;
    companyUuid = null;

    constructor() {
        super(...arguments);
        this.sessionToken = localStorage.getItem(STORAGE_KEY);
        this.socket.useDriverToken(this.sessionToken);
        this.statusTimer = setInterval(() => { this.statusNow = Date.now(); }, 5000);
        this.startTracking();
    }

    willDestroy() {
        clearInterval(this.trafficTimer);
        clearInterval(this.statusTimer);
        this.stopTracking();
        this.clearRouteOverlay();
        this.clearRoadNetworkLayer();
        this.unsubscribeRoadNetwork();
        this.socket.useDriverToken(null);
        if (this.mapInstance) {
            this.mapInstance.remove();
            this.mapInstance = null;
        }
        super.willDestroy(...arguments);
    }

    get accuracy() {
        return this.position?.coords?.accuracy;
    }

    get accuracyLabel() {
        const acc = this.accuracy;
        if (acc == null) return null;
        if (acc < 10) return `精度: ±${acc.toFixed(1)} m (优)`;
        if (acc < 50) return `精度: ±${acc.toFixed(0)} m (良)`;
        if (acc < 200) return `精度: ±${acc.toFixed(0)} m (一般)`;
        return `精度: ±${acc.toFixed(0)} m (差)`;
    }

    get accuracyGood() {
        const acc = this.accuracy;
        return acc != null && acc < 50;
    }

    get coordsLabel() {
        if (!this.position) return null;
        const lat = this.position.coords.latitude;
        const lng = this.position.coords.longitude;
        return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    }

    get gpsUploadLabel() {
        return { waiting: '等待定位上传', queued: '等待上传最新定位', uploading: '定位上传中',
            confirmed: '服务器已确认定位', retry: '上传失败，正在自动重试', offline: '网络离线，恢复后自动上传',
            expired: '登记会话已失效，请重新登记', stale: '定位已过期，等待新定位',
            rejected: '定位数据被拒绝，请检查设备时间和定位' }[this.gpsUploadState] || '等待定位上传';
    }

    get gpsUploadWarning() {
        return ['retry', 'offline', 'expired', 'stale', 'rejected'].includes(this.gpsUploadState);
    }

    get lastUploadLabel() {
        return this.lastUploadAt ? new Date(this.lastUploadAt).toLocaleTimeString('zh-CN') : '尚未确认';
    }

    get positionFreshnessLabel() {
        if (!this.position) return '等待本机定位';
        const age = Math.max(0, Math.floor((this.statusNow - this.position.timestamp) / 1000));
        return age > 90 ? `本机定位已过期（${age} 秒前）` : `本机定位 ${age} 秒前`;
    }

    @action setupMap(element) {
        if (this.mapInstance) {
            return;
        }

        const map = L.map(element, {
            zoomControl: true,
            attributionControl: true,
        }).setView([39.9042, 116.4074], 13);

        // Separate panes keep navigation above traffic, even when a later
        // polling response redraws the road network after the route.
        map.createPane(ROAD_STATUS_PANE).style.zIndex = '450';
        const navigationPane = map.createPane(NAVIGATION_PANE);
        navigationPane.style.zIndex = '550';
        navigationPane.style.pointerEvents = 'none';

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
            maxZoom: 19,
        }).addTo(map);

        this.mapInstance = map;

        map.on('dragstart', () => {
            this.followMode = false;
        });

        this.updateMarkerFromPosition();
        this.loadServiceAreaLayer();
        this.loadRoadNetworkLayer();
        this.trafficTimer = setInterval(() => this.loadRoadNetworkLayer(), 15000);
    }

    @action recenter() {
        this.followMode = true;
        if (this.mapInstance && this.position) {
            const lat = this.position.coords.latitude;
            const lng = this.position.coords.longitude;
            this.mapInstance.setView([lat, lng], 18, { animate: true });
        }
    }

    @action updateMarkerFromPosition() {
        if (!this.mapInstance || !this.position) {
            return;
        }

        const lat = this.position.coords.latitude;
        const lng = this.position.coords.longitude;
        const latlng = [lat, lng];
        const acc = this.position.coords.accuracy || 50;

        if (!this.driverMarker) {
            const icon = L.divIcon({
                className: 'driver-marker',
                html: '<div style="width:22px;height:22px;background:#2563eb;border:3px solid white;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.4);position:relative;"><div class="driver-marker-pulse" style="position:absolute;top:-8px;left:-8px;width:38px;height:38px;background:#2563eb;border-radius:50%;"></div></div>',
                iconSize: [22, 22],
                iconAnchor: [11, 11],
            });
            this.driverMarker = L.marker(latlng, { icon }).addTo(this.mapInstance);

            this.accuracyCircle = L.circle(latlng, {
                radius: acc,
                color: '#2563eb',
                fillColor: '#2563eb',
                fillOpacity: 0.08,
                weight: 1,
            }).addTo(this.mapInstance);

            this.mapInstance.setView(latlng, 18);
        } else {
            this.driverMarker.setLatLng(latlng);
            if (this.accuracyCircle) {
                this.accuracyCircle.setLatLng(latlng);
                this.accuracyCircle.setRadius(acc);
            }
            if (this.followMode) {
                this.mapInstance.panTo(latlng, { animate: true, duration: 0.5 });
            }
        }
    }

    async loadServiceAreaLayer() {
        if (!this.mapInstance) return;

        this.ensureZoneLabelStyle();

        const token = localStorage.getItem(STORAGE_KEY);
        if (!token) return;

        try {
            const statusResponse = await fetch(`/${TRACKING_API}/status`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            if (statusResponse.ok) {
                const statusData = await statusResponse.json();
                this.companyUuid = statusData?.entry_registration?.company_uuid || this.companyUuid;
                this.subscribeRoadNetwork();
            }

            const response = await fetch(`/${TRACKING_API}/service-areas`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            if (!response.ok) return;

            const data = await response.json();
            const areas = data.service_areas || [];

            for (const area of areas) {
                // Render service area border (light style, not clickable for navigation)
                if (area.border?.coordinates) {
                    for (const polygon of area.border.coordinates) {
                        for (const ring of polygon) {
                            const latlngs = ring.map(([lng, lat]) => [lat, lng]);
                            const polygonLayer = L.polygon(latlngs, {
                                fillColor: area.color || '#3498db',
                                fillOpacity: 0.05,
                                color: area.stroke_color || '#2980b9',
                                weight: 1,
                                dashArray: '5, 5',
                            }).addTo(this.mapInstance);

                            this.serviceAreaPolygons.push(polygonLayer);
                        }
                    }
                }

                // Render zones within the service area (clickable for navigation)
                const zones = area.zones || [];
                for (const zone of zones) {
                    if (!zone.border?.coordinates) continue;

                    // GeoJSON Polygon: coordinates = array of rings, each = array of [lng,lat] points
                    for (const ring of zone.border.coordinates) {
                        const latlngs = ring.map(([lng, lat]) => [lat, lng]);
                        const zoneLayer = L.polygon(latlngs, {
                            fillColor: zone.color || '#e67e22',
                            fillOpacity: 0.25,
                            color: zone.stroke_color || '#d35400',
                            weight: 2,
                        }).addTo(this.mapInstance);

                        // Tooltip with zone name — permanent & centered on the zone
                        const zoneText = document.createElement('span');
                        zoneText.textContent = zone.name;
                        zoneLayer.bindTooltip(zoneText, {
                            permanent: true,
                            direction: 'center',
                            className: 'cs-zone-tooltip',
                        });

                        // Anchor the permanent label precisely at the zone's computed center
                        if (zone.center) {
                            const tooltip = zoneLayer.getTooltip();
                            if (tooltip) {
                                tooltip.setLatLng([zone.center.latitude, zone.center.longitude]);
                            }
                        }

                        zoneLayer.on('click', () => {
                            this.showZonePopup(zone, area);
                        });

                        zoneLayer.on('mouseover', () => {
                            zoneLayer.setStyle({ fillOpacity: 0.45 });
                        });
                        zoneLayer.on('mouseout', () => {
                            zoneLayer.setStyle({ fillOpacity: 0.25 });
                        });

                        this.serviceAreaPolygons.push(zoneLayer);
                    }
                }
            }
        } catch (e) {
            console.error('Failed to load service areas:', e);
        }
    }

    ensureZoneLabelStyle() {
        if (document.getElementById('cs-zone-tooltip-style')) return;
        const style = document.createElement('style');
        style.id = 'cs-zone-tooltip-style';
        style.textContent = `
            .cs-zone-tooltip {
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
                color: #1f2937;
                font-size: 12px;
                font-weight: 600;
                text-align: center;
                white-space: nowrap;
                pointer-events: none;
            }
            .cs-zone-tooltip::before {
                display: none !important;
            }
        `;
        document.head.appendChild(style);
    }

    showZonePopup(zone, area) {
        const center = zone.center;
        const latlng = [center.latitude, center.longitude];

        const popupContent = document.createElement('div');
        popupContent.style.cssText = 'text-align:center;min-width:140px;';
        const zoneLabel = document.createElement('div');
        zoneLabel.style.cssText = 'font-weight:600;color:#333;margin-bottom:4px;font-size:14px;';
        zoneLabel.textContent = zone.name;
        const areaLabel = document.createElement('div');
        areaLabel.style.cssText = 'font-size:11px;color:#999;margin-bottom:10px;';
        areaLabel.textContent = area.name;
        popupContent.append(zoneLabel, areaLabel);

        const btn = document.createElement('button');
        btn.textContent = '\u5BFC\u822A\u5230\u6B64\u5904';
        btn.style.cssText = 'background:#2563eb;color:white;border:none;border-radius:6px;padding:8px 16px;font-size:13px;cursor:pointer;width:100%;';
        btn.addEventListener('click', () => {
            this.startNavigation(zone);
            this.mapInstance.closePopup();
        });
        popupContent.appendChild(btn);

        L.popup({ maxWidth: 200 })
            .setLatLng(latlng)
            .setContent(popupContent)
            .openOn(this.mapInstance);
    }

    @action async startNavigation(serviceArea) {
        if (!this.position || !serviceArea?.center) return;

        const destLat = serviceArea.center.latitude;
        const destLng = serviceArea.center.longitude;

        this.destination = {
            lat: destLat,
            lng: destLng,
            name: serviceArea.name,
            zoneUuid: serviceArea.uuid,
        };
        this.mode = 'navigating';
        this.isCalculatingRoute = true;
        this.offRouteCount = 0;
        this.error = null;

        try {
            const startLat = this.position.coords.latitude;
            const startLng = this.position.coords.longitude;
            this.currentRoute = await calculateSiteRoute(startLat, startLng, destLat, destLng, this.sessionToken);
            this.renderRouteOverlay();
            this.subscribeRoadNetwork();
        } catch (e) {
            this.error = e.message || '路线计算失败';
            this.mode = 'tracking';
        } finally {
            this.isCalculatingRoute = false;
            this.consumePendingRouteRefresh();
        }
    }

    @action async stopNavigation() {
        this.mode = 'tracking';
        this.pendingRouteRefresh = false;
        this.currentRoute = null;
        this.destination = null;
        this.offRouteCount = 0;
        this.showRecalcNotice = false;
        this.navStepIndex = 0;
        this.navStepRemainingDistance = 0;
        this.navTraveledDistance = 0;
        this.navRemainingDistance = 0;
        this.navRemainingDuration = 0;
        this.clearRouteOverlay();

        if (this.sessionToken) {
            try {
                await stopSiteNavigation(this.sessionToken);
            } catch (e) {
                console.warn('stopSiteNavigation failed', e);
            }
        }
    }

    renderRouteOverlay() {
        if (!this.mapInstance || !this.currentRoute) return;

        this.clearRouteOverlay();

        const coords = this.currentRoute.coordinates;

        this.routePolyline = L.polyline(coords, {
            pane: NAVIGATION_PANE,
            interactive: false,
            color: '#2196F3',
            weight: 6,
            opacity: 1,
        }).addTo(this.mapInstance);

        // Off-road connector legs (GPS -> network, network -> destination)
        // are drawn as dashed gray so they never look like a road route.
        const offRoad = this.currentRoute.off_road_segments;
        if (Array.isArray(offRoad)) {
            this.offRoadPolylines = [];
            for (const segment of offRoad) {
                if (!Array.isArray(segment) || segment.length < 2) {
                    continue;
                }
                this.offRoadPolylines.push(
                    L.polyline(segment, {
                        pane: NAVIGATION_PANE,
                        interactive: false,
                        color: '#9ca3af',
                        weight: 4,
                        opacity: 0.9,
                        dashArray: '8, 6',
                    }).addTo(this.mapInstance)
                );
            }
        }

        this.startMarker = L.marker(coords[0], {
            icon: L.divIcon({
                className: 'cs-route-start',
                html: '<div style="width:14px;height:14px;background:#4CAF50;border:3px solid white;border-radius:50%;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>',
                iconSize: [14, 14],
                iconAnchor: [7, 7],
            }),
        }).addTo(this.mapInstance);

        this.endMarker = L.marker(coords[coords.length - 1], {
            icon: L.divIcon({
                className: 'cs-route-end',
                html: '<div style="font-size:24px;">🚩</div>',
                iconSize: [24, 24],
                iconAnchor: [12, 24],
            }),
        }).addTo(this.mapInstance);

        this.mapInstance.fitBounds(this.routePolyline.getBounds(), { padding: [50, 50] });
        if (this.position) {
            this.updateNavigationProgress(this.position.coords.latitude, this.position.coords.longitude);
        }
    }

    clearRouteOverlay() {
        if (this.routePolyline && this.mapInstance) {
            this.mapInstance.removeLayer(this.routePolyline);
            this.routePolyline = null;
        }
        for (const layer of this.offRoadPolylines) {
            if (this.mapInstance) {
                this.mapInstance.removeLayer(layer);
            }
        }
        this.offRoadPolylines = [];
        if (this.traveledPolyline && this.mapInstance) {
            this.mapInstance.removeLayer(this.traveledPolyline);
            this.traveledPolyline = null;
        }
        if (this.startMarker && this.mapInstance) {
            this.mapInstance.removeLayer(this.startMarker);
            this.startMarker = null;
        }
        if (this.endMarker && this.mapInstance) {
            this.mapInstance.removeLayer(this.endMarker);
            this.endMarker = null;
        }
    }

    async recalculateRoute() {
        if (!this.position || !this.destination) return;
        if (this.isCalculatingRoute) {
            this.pendingRouteRefresh = true;
            return;
        }

        this.showRecalcNotice = true;
        this.isCalculatingRoute = true;

        try {
            const startLat = this.position.coords.latitude;
            const startLng = this.position.coords.longitude;
            this.currentRoute = await calculateSiteRoute(startLat, startLng, this.destination.lat, this.destination.lng, this.sessionToken);
            this.renderRouteOverlay();
            this.error = null;
        } catch (e) {
            console.error('Route recalculation failed:', e);
            this.error = e.message || '无法到达：请等待路况恢复或联系调度';
            this.currentRoute = null;
            this.clearRouteOverlay();
            this.navRemainingDistance = 0;
            this.navRemainingDuration = 0;
        } finally {
            this.isCalculatingRoute = false;
            this.showRecalcNotice = false;
            this.consumePendingRouteRefresh();
        }
    }

    consumePendingRouteRefresh() {
        if (this.pendingRouteRefresh && this.mode === 'navigating' && !this.isDestroying && !this.isDestroyed) {
            this.pendingRouteRefresh = false;
            this.recalculateRoute();
        }
    }

    subscribeRoadNetwork() {
        if (this.isSubscribedNetwork || !this.companyUuid || !this.socket?.listen) {
            return;
        }

        this.isSubscribedNetwork = true;
        this.networkChannelClose = this.socket.listen(`road-network.${this.companyUuid}`, (output) => {
            if (this.isDestroying || this.isDestroyed) return;
            const eventName = output?.event || output?.data?.event;
            if (eventName !== 'construction-site.road_network_changed') {
                return;
            }
            this.loadRoadNetworkLayer();
            if (this.mode === 'navigating') {
                this.recalculateRoute();
            }
        });
    }

    /**
     * Fetch the construction-site road network and draw it on the map so the
     * driver sees which roads exist and their current status.
     */
    async loadRoadNetworkLayer() {
        if (!this.mapInstance || !this.sessionToken || this.isLoadingNetwork) {
            return;
        }
        this.isLoadingNetwork = true;

        try {
            const response = await fetch(`/${TRACKING_API}/road-network`, {
                headers: { Authorization: `Bearer ${this.sessionToken}` },
            });
            if (!response.ok) {
                return;
            }

            const data = await response.json();
            if (this.isDestroying || this.isDestroyed) return;
            const changed = this.trafficRevision && this.trafficRevision !== data.revision;
            this.trafficRevision = data.revision;
            this.trafficUpdatedAt = new Date(data.generated_at).toLocaleTimeString('zh-CN', { hour12: false });
            const allTargets = [...(data.road_segments || []), ...(data.junctions || [])];
            const blocked = allTargets.filter((target) => ['closed', 'construction'].includes(target.status)).length;
            const congested = allTargets.filter((target) => ['light', 'moderate', 'heavy', 'standstill'].includes(target.congestion)).length;
            const unknown = allTargets.filter((target) => target.congestion === 'unknown').length;
            this.trafficSummary = `施工／封闭 ${blocked} 处 · 拥堵 ${congested} 处 · 数据不足 ${unknown} 处`;
            this.clearRoadNetworkLayer();

            const segments = data?.road_segments || [];
            for (const segment of segments) {
                const coordinates = segment.border?.coordinates;
                if (!Array.isArray(coordinates) || coordinates.length < 2) {
                    continue;
                }
                const latlngs = coordinates.map(([lng, lat]) => [lat, lng]);
                const polyline = L.polyline(latlngs, {
                    pane: ROAD_STATUS_PANE,
                    color: segment.color || '#22c55e',
                    weight: 5,
                    opacity: 0.9,
                }).addTo(this.mapInstance);
                const label = document.createElement('span');
                const statusText = { open: '可通行', partial: '部分封闭', construction: '施工禁行', closed: '封闭' }[segment.status];
                const scope = segment.start_label ? ` · ${segment.start_label} → ${segment.end_label} · ${segment.length_meters} 米` : '';
                label.textContent = `${segment.name || '路段'}${scope} · ${statusText} · ${segment.congestion_label} · ${segment.driver_count} 辆 · ${segment.data_status_label || ''}${segment.note ? ' · ' + segment.note : ''}`;
                polyline.bindTooltip(label, { sticky: true });
                this.roadNetworkLayers.push(polyline);
            }

            for (const junction of data.junctions || []) {
                const marker = L.circleMarker([junction.latitude, junction.longitude], {
                    pane: ROAD_STATUS_PANE,
                    radius: 7, color: '#ffffff', weight: 2, fillColor: junction.color, fillOpacity: 1,
                }).addTo(this.mapInstance);
                const label = document.createElement('span');
                const status = { open: '可通行', partial: '部分封闭', construction: '施工禁行', closed: '封闭' }[junction.status];
                label.textContent = `交汇点 ${junction.name} · ${status} · ${junction.congestion_label} · ${junction.driver_count} 辆 · ${junction.data_status_label || ''}${junction.note ? ' · ' + junction.note : ''}`;
                marker.bindTooltip(label);
                this.roadNetworkLayers.push(marker);
            }

            const avoidAreas = data?.avoid_areas || [];
            for (const avoid of avoidAreas) {
                const geometry = avoid.geometry || avoid.border;
                if (!geometry?.coordinates) continue;
                const layer = L.geoJSON(geometry, { pane: ROAD_STATUS_PANE, style: { color: '#ef4444', weight: 6, opacity: 0.85, fillOpacity: 0.15, dashArray: '8, 6' } }).addTo(this.mapInstance);
                const label = document.createElement('span');
                label.textContent = avoid.reason || '禁行区';
                layer.bindTooltip(label);
                this.roadNetworkLayers.push(layer);
            }
            if (changed && this.mode === 'navigating') await this.recalculateRoute();
        } catch (e) {
            console.warn('loadRoadNetworkLayer failed', e);
            this.trafficSummary = '路况刷新失败，请留意上次更新时间';
        } finally {
            this.isLoadingNetwork = false;
        }
    }

    clearRoadNetworkLayer() {
        if (!this.mapInstance) {
            return;
        }
        for (const layer of this.roadNetworkLayers) {
            this.mapInstance.removeLayer(layer);
        }
        this.roadNetworkLayers = [];
    }

    unsubscribeRoadNetwork() {
        this.networkChannelClose?.();
        this.isSubscribedNetwork = false;
        this.networkChannelClose = null;
    }

    updateNavigationProgress(lat, lng) {
        if (!this.currentRoute) return;

        const routeCoords = this.currentRoute.coordinates;
        const projection = navigationProgress(this.currentRoute, lat, lng);
        const traveledDistance = projection.traveledDistance;

        const { remainingDistance, remainingDuration } = projection;

        this.navTraveledDistance = traveledDistance;
        this.navRemainingDistance = remainingDistance;
        this.navRemainingDuration = remainingDuration;

        this.navStepIndex = projection.stepIndex;
        this.navStepRemainingDistance = projection.stepRemainingDistance;

        if (this.traveledPolyline && this.mapInstance) {
            this.mapInstance.removeLayer(this.traveledPolyline);
            this.traveledPolyline = null;
        }
        if (traveledDistance > 0 && this.mapInstance) {
            this.traveledPolyline = L.polyline(
                [...routeCoords.slice(0, projection.segmentIndex + 1), projection.coordinate],
                { pane: NAVIGATION_PANE, interactive: false, color: '#9E9E9E', weight: 5, opacity: 0.6 }
            ).addTo(this.mapInstance);
        }
    }

    checkArrival(lat, lng) {
        if (!this.destination) return;

        const dist = haversineDistance(lat, lng, this.destination.lat, this.destination.lng);
        if (dist < 30) {
            this.stopNavigation();
            const arrivalDiv = document.createElement('div');
            arrivalDiv.className = 'cs-arrival-notice';
            arrivalDiv.textContent = '已到达目的地';
            arrivalDiv.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#4CAF50;color:white;padding:20px 40px;border-radius:8px;font-size:18px;z-index:1000;box-shadow:0 4px 12px rgba(0,0,0,0.3);';
            const container = document.querySelector('.relative.w-full.h-screen');
            if (container) {
                container.appendChild(arrivalDiv);
                setTimeout(() => arrivalDiv.remove(), 3000);
            }
        }
    }

    checkOffRoute(lat, lng) {
        if (!this.currentRoute) return;

        const distToRoute = distanceToRoute(lat, lng, this.currentRoute.coordinates);

        if (distToRoute > 50) {
            this.offRouteCount++;
            if (this.offRouteCount >= 3) {
                this.offRouteCount = 0;
                this.recalculateRoute();
            }
        } else {
            this.offRouteCount = 0;
        }
    }

    stopTracking() {
        this.gpsUploader?.stop();
        this.gpsUploader = null;
        if (this.onlineHandler) window.removeEventListener('online', this.onlineHandler);
        this.onlineHandler = null;
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.watchId = null;
        }
        this.isTracking = false;
    }

    sendUpdate(position) {
        this.gpsUploader?.enqueue(position);
    }

    getLocationErrorMessage(error) {
        const messages = {
            1: '定位权限被拒绝，请在浏览器设置中允许定位',
            2: '定位不可用，请检查GPS是否开启',
            3: '定位超时，请重试',
        };
        return messages[error.code] || '定位失败';
    }

    get needsHttps() {
        if (!navigator.geolocation) {
            return false;
        }
        return !window.isSecureContext;
    }

    @action startTracking() {
        if (this.watchId !== null) return;
        if (!navigator.geolocation) {
            this.error = '设备不支持定位';
            return;
        }

        if (!window.isSecureContext) {
            this.error = '定位需要 HTTPS 连接。请使用 https:// 开头的地址访问。';
            return;
        }

        this.isTracking = true;
        this.error = null;
        if (this.sessionToken) {
            this.gpsUploader = new GpsUploader({
                token: this.sessionToken,
                onStatus: ({ state, confirmedAt }) => {
                    this.gpsUploadState = state;
                    if (confirmedAt) this.lastUploadAt = confirmedAt;
                },
            });
            this.onlineHandler = () => this.gpsUploader?.resume();
            window.addEventListener('online', this.onlineHandler);
        }

        this.watchId = navigator.geolocation.watchPosition(
            (position) => {
                this.position = position;
                this.statusNow = Date.now();
                this.error = null;
                this.updateCount++;
                const t = new Date();
                this.lastUpdateLabel = `${t.getHours().toString().padStart(2, '0')}:${t.getMinutes().toString().padStart(2, '0')}:${t.getSeconds().toString().padStart(2, '0')}`;
                this.sendUpdate(position);
                this.updateMarkerFromPosition();

                if (this.mode === 'navigating') {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    this.checkOffRoute(lat, lng);
                    this.updateNavigationProgress(lat, lng);
                    this.checkArrival(lat, lng);
                }
            },
            (error) => {
                this.error = this.getLocationErrorMessage(error);
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 }
        );
    }

    @action retryLocation() {
        this.stopTracking();
        this.startTracking();
    }

    @action confirmExit() {
        this.showExitConfirm = true;
    }

    @action cancelExit() {
        this.showExitConfirm = false;
    }

    @action registerAgain() {
        this.stopTracking();
        localStorage.removeItem(STORAGE_KEY);
        this.sessionToken = null;
        this.args.onExited?.();
    }

    @action async doExit() {
        if (this.sessionToken) {
            try {
                const response = await fetch(`/${TRACKING_API}/exit`, {
                    method: 'POST',
                    headers: { Authorization: `Bearer ${this.sessionToken}` },
                });
                if (!response.ok && response.status !== 401) throw new Error('退场未成功，请重试');
            } catch (error) {
                this.error = '退场未确认，请检查网络后重试。';
                return;
            }
            this.stopTracking();
            localStorage.removeItem(STORAGE_KEY);
            this.sessionToken = null;
        }

        if (this.args.onExited) {
            this.args.onExited();
        }
    }
}
