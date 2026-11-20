import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import L from 'leaflet';
import installLeafletRotation from '../utils/leaflet-rotation';

const DEFAULT_CENTER = [20, 0];
const DEFAULT_ZOOM = 2;

const DRIVER_ROUTE_COLORS = [
    '#2563eb',
    '#dc2626',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#db2777',
    '#65a30d',
];

const STATUS_COLORS = {
    open: '#22c55e',
    partial: '#eab308',
    construction: '#f97316',
    closed: '#ef4444',
};

export default class LiveMapViewComponent extends Component {
    @tracked mapBearing = 0;
    @tracked isRotating = false;
    @tracked drawMode = null;
    @tracked drawPurpose = null;
    @tracked draftPoints = [];
    @tracked isEditingGeometry = false;

    mapInstance = null;
    segmentLayers = [];
    junctionLayers = [];
    avoidLayers = [];
    routeLayers = [];
    importLayers = [];
    lastImportToken = null;
    draftLayer = null;
    clickHandler = null;

    editingUuid = null;
    editingCoordinates = null;
    editPolyline = null;
    editHandles = [];

    willDestroy() {
        this.stopRotationDrag();
        this.cancelBearingFrame();
        if (this.mapInstance && this._onBearingChange) this.mapInstance.off('rotate', this._onBearingChange);
        this.resizeObserver?.disconnect();
        clearTimeout(this._resizeTimer);
        this.teardownDrawHandler();
        this.clearSegmentLayers();
        this.clearJunctionLayers();
        this.clearAvoidLayers();
        this.clearRouteLayers();
        this.clearImportLayers();
        this.clearDraftLayer();
        this.clearEditLayer();
        if (this.mapInstance) {
            this.mapInstance.remove();
            this.mapInstance = null;
        }
        super.willDestroy(...arguments);
    }

    getStatusColor(status) {
        return STATUS_COLORS[status] || '#6b7280';
    }

    segmentLatLngs(segment) {
        const coordinates = segment.geometry?.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length < 2) {
            return null;
        }

        return coordinates.map(([lng, lat]) => [lat, lng]);
    }

    clearSegmentLayers() {
        if (!this.mapInstance) {
            return;
        }

        for (const layer of this.segmentLayers) {
            this.mapInstance.removeLayer(layer);
        }

        this.segmentLayers = [];
    }

    clearAvoidLayers() {
        if (!this.mapInstance) {
            return;
        }

        for (const layer of this.avoidLayers) {
            this.mapInstance.removeLayer(layer);
        }

        this.avoidLayers = [];
    }

    clearRouteLayers() {
        if (!this.mapInstance) {
            return;
        }

        for (const layer of this.routeLayers) {
            this.mapInstance.removeLayer(layer);
        }

        this.routeLayers = [];
        this.navigationPaths = [];
        this.driverMarkers?.clear();
        this.navigationSignature = null;
    }

    clearDraftLayer() {
        if (this.draftLayer && this.mapInstance) {
            this.mapInstance.removeLayer(this.draftLayer);
            this.draftLayer = null;
        }
    }

    teardownDrawHandler() {
        if (this.mapInstance && this.clickHandler) {
            this.mapInstance.off('click', this.clickHandler);
            this.clickHandler = null;
        }
    }

    @action setupMap(element) {
        if (this.mapInstance) {
            return;
        }

        installLeafletRotation(L);
        const map = L.map(element, {
            rotate: true,
            rotateControl: false,
            bearing: 0,
            touchRotate: true,
            shiftKeyRotate: false,
            zoomControl: true,
            attributionControl: true,
        }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
            maxZoom: 19,
        }).addTo(map);

        this.mapInstance = map;
        this._onBearingChange = () => { this.mapBearing = this.normalizeBearing(map.getBearing()); };
        map.on('rotate', this._onBearingChange);
        this.resizeObserver = new ResizeObserver(() => this.refreshMapSize());
        this.resizeObserver.observe(element);
        this.redrawAll();
    }

    get bearingLabel() {
        // Round only the label; keep the actual view and slider at full precision.
        return (Math.round(this.mapBearing * 10) / 10 % 360).toFixed(1);
    }

    normalizeBearing(bearing) {
        return ((bearing % 360) + 360) % 360;
    }

    cancelBearingFrame() {
        if (this._bearingFrame != null) cancelAnimationFrame(this._bearingFrame);
        this._bearingFrame = null;
        this._pendingBearing = null;
    }

    queueBearing(bearing) {
        if (!this.mapInstance || !Number.isFinite(bearing)) return;
        this._pendingBearing = this.normalizeBearing(bearing);
        if (this._bearingFrame != null) return;
        // Coalesce pointer events into one real Leaflet update per animation frame.
        // Updating Leaflet itself keeps hit testing and drawing in sync with the view.
        this._bearingFrame = requestAnimationFrame(() => {
            this._bearingFrame = null;
            const next = this._pendingBearing;
            this._pendingBearing = null;
            if (this.mapInstance && next != null) this.mapInstance.setBearing(next);
        });
    }

    @action changeMapBearing(event) {
        this.stopRotationDrag();
        this.cancelBearingFrame();
        this.queueBearing(Number(event.target.value));
    }

    @action startRotationDrag(event) {
        if (!this.mapInstance || event.button !== 0 || this._rotationDrag) return;
        event.preventDefault();
        this.cancelBearingFrame();
        this._rotationDrag = {
            element: event.currentTarget,
            pointerId: event.pointerId,
            startX: event.clientX,
            bearing: this.mapInstance.getBearing(),
        };
        event.currentTarget.setPointerCapture(event.pointerId);
        this.isRotating = true;
    }

    @action moveRotationDrag(event) {
        const drag = this._rotationDrag;
        if (!drag || event.pointerId !== drag.pointerId) return;
        event.preventDefault();
        // Horizontal motion can continue outside the handle thanks to pointer capture.
        // There is no angle snapping, and repeated turns wrap through north naturally.
        this.queueBearing(drag.bearing + (event.clientX - drag.startX) * 0.6);
    }

    @action endRotationDrag(event) {
        if (event.pointerId !== this._rotationDrag?.pointerId) return;
        if (event.type === 'pointerup') this.moveRotationDrag(event);
        this.stopRotationDrag();
    }

    stopRotationDrag() {
        const drag = this._rotationDrag;
        this._rotationDrag = null;
        this.isRotating = false;
        if (drag?.element.hasPointerCapture(drag.pointerId)) drag.element.releasePointerCapture(drag.pointerId);
    }

    @action rotateWithKeyboard(event) {
        if (!this.mapInstance) return;
        if (event.key === 'Home') {
            event.preventDefault();
            this.resetMapBearing();
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const bearing = this._pendingBearing ?? this.mapInstance.getBearing();
            this.cancelBearingFrame();
            this.queueBearing(bearing + (event.key === 'ArrowLeft' ? -1 : 1) * (event.shiftKey ? 10 : 1));
        }
    }

    @action resetMapBearing() {
        if (!this.mapInstance) return;
        this.stopRotationDrag();
        this.cancelBearingFrame();
        const from = this.mapInstance.getBearing();
        const delta = ((-from + 540) % 360) - 180;
        if (Math.abs(delta) < 0.001 || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            this.mapInstance.setBearing(0);
            return;
        }
        let started;
        const animate = (now) => {
            this._bearingFrame = null;
            if (!this.mapInstance) return;
            started ??= now;
            const progress = Math.min(1, (now - started) / 320);
            const eased = 1 - (1 - progress) ** 3;
            this.mapInstance.setBearing(progress === 1 ? 0 : this.normalizeBearing(from + delta * eased));
            if (progress < 1) this._bearingFrame = requestAnimationFrame(animate);
        };
        this._bearingFrame = requestAnimationFrame(animate);
    }

    ensureMapPane(name, zIndex, rotates = true) {
        if (!this.mapInstance.getPane(name)) {
            // Vectors belong to the rotating pane; markers are repositioned by
            // Leaflet and must stay in the non-rotating pane to avoid double rotation.
            const parent = this.mapInstance.getPane(rotates ? 'rotatePane' : 'norotatePane');
            this.mapInstance.createPane(name, parent).style.zIndex = zIndex;
        }
    }

    @action redrawAll() {
        this.layerSignatures ||= new Map();
        const draw = (name, inputs, callback) => {
            const signature = JSON.stringify(inputs);
            if (this.layerSignatures.get(name) === signature) return;
            callback();
            this.layerSignatures.set(name, signature);
        };
        const a = this.args;
        // Ember RecordArray/Model instances reference the store and services.
        // Only the selected parent's geometry affects the highlight; never
        // serialize the collection or model itself when comparing map inputs.
        const parent = a.segments?.find((road) => (road.uuid || road.id) === a.selectedSegmentUuid);
        const parentHighlight = parent ? [parent.uuid || parent.id, parent.geometry] : null;
        draw('roads', [a.trafficRoads, parentHighlight, a.selectedSegmentUuid, a.selectedTrafficTargetKey, a.showRoadLayer], () => this.drawSegments());
        draw('junctions', [a.junctions, a.selectedTrafficTargetKey, a.showJunctionLayer], () => this.drawJunctions());
        draw('avoid', [a.avoidAreas, a.showAvoidLayer], () => this.drawAvoidAreas());
        draw('navigation', [a.selectedDriverRoute, a.driverLocations, a.showDriverRoutes, a.showNavigationLayer], () => this.drawNavigatingRoutes());
        draw('imports', [a.importCandidates, a.importPreview, a.importSelectionMode, this.drawMode, this.isEditingGeometry], () => this.drawImportPreview());
    }

    clearImportLayers() {
        if (!this.mapInstance) return;
        this.importLayers.forEach((layer) => this.mapInstance.removeLayer(layer));
        this.importLayers = [];
    }

    nearestImportRoad(latlng, candidates) {
        if (!Number.isFinite(latlng?.lat) || !Number.isFinite(latlng?.lng)) return null;
        const scale = Math.cos(latlng.lat * Math.PI / 180);
        let nearest = null;
        let best = Infinity;
        for (const road of candidates) {
            const coords = road.geometry.coordinates;
            for (let i = 1; i < coords.length; i++) {
                const a = coords[i - 1]; const b = coords[i];
                const x = (a[0] - latlng.lng) * scale; const y = a[1] - latlng.lat;
                const dx = (b[0] - a[0]) * scale; const dy = b[1] - a[1];
                const length = dx * dx + dy * dy;
                const t = length > 0 ? Math.max(0, Math.min(1, -(x * dx + y * dy) / length)) : 0;
                const distance = (x + t * dx) ** 2 + (y + t * dy) ** 2;
                if (distance < best - 1e-20 || (Math.abs(distance - best) <= 1e-20 && road.availability === 'new' && nearest?.availability !== 'new')) {
                    best = distance; nearest = road;
                }
            }
        }
        return nearest;
    }

    drawImportPreview() {
        this.clearImportLayers();
        if (!this.mapInstance) return;
        this.ensureMapPane('importPreview', 475);
        const candidates = this.args.importCandidates || [];
        candidates.forEach((road) => {
            const coords = road.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
            const disabled = road.availability !== 'new';
            // A solid, wide hit area keeps gaps in the dashed preview clickable.
            const hit = L.polyline(coords, { pane: 'importPreview', color: '#000000', weight: 18, opacity: 0,
                bubblingMouseEvents: false }).addTo(this.mapInstance);
            const line = L.polyline(coords, { pane: 'importPreview', interactive: false,
                color: disabled ? '#9ca3af' : road.selected ? '#7c3aed' : road.restricted ? '#ea580c' : '#64748b',
                weight: road.selected && !disabled ? 6 : 4, opacity: disabled ? 0.65 : 0.9, dashArray: disabled ? '3 7' : '8 6' }).addTo(this.mapInstance);
            const tooltip = document.createElement('span');
            tooltip.textContent = `${road.name} · ${road.length_meters} 米 · ${road.availability === 'deleted' ? '点击恢复原道路' : road.availability === 'existing' ? '点击编辑路况／名称' : disabled ? '不可重复导入' : road.selected ? '已勾选，点击取消' : '点击勾选'} · ${road.availability_label}`;
            hit.bindTooltip(tooltip);
            hit.on('click', (event) => {
                L.DomEvent.stopPropagation(event);
                // Wide hit areas can overlap; select by cursor distance, independent of layer order.
                if (!this.drawMode && !this.isEditingGeometry) this.args.onSelectImportRoad?.(this.nearestImportRoad(event.latlng, candidates) || road, event.latlng);
            });
            this.importLayers.push(hit, line);
            if (road.routing.direction && coords.length > 1) {
                const i = Math.floor((coords.length - 1) / 2);
                const a = coords[i]; const b = coords[i + 1];
                const mid = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
                const angle = Math.atan2(-(b[0] - a[0]), (b[1] - a[1]) * Math.cos(mid[0] * Math.PI / 180)) * 180 / Math.PI + (road.routing.direction === -1 ? 180 : 0);
                this.ensureMapPane('importDirection', 475, false);
                const arrow = L.marker(mid, { pane: 'importDirection', rotateWithView: true, interactive: false, icon: L.divIcon({
                    className: 'road-import-direction', iconSize: [20, 20], iconAnchor: [10, 10],
                    html: `<span style="display:block;color:${line.options.color};font-size:20px;line-height:20px;transform:rotate(${angle}deg);text-shadow:0 0 2px white">➤</span>`,
                }) }).addTo(this.mapInstance);
                this.importLayers.push(arrow);
            }
        });
        const token = this.args.importPreview?.preview_token;
        if (token && token !== this.lastImportToken && this.importLayers.length) {
            this.mapInstance.fitBounds(L.featureGroup(this.importLayers).getBounds(), { padding: [24, 24], maxZoom: 17 });
        }
        this.lastImportToken = token;
    }

    @action consumeEditRequest() {
        const segment = this.args.editingSegment;
        if (segment) {
            this.enterEditGeometry(segment);
        }
    }

    @action refreshMapSize() {
        if (this._resizeTimer) {
            clearTimeout(this._resizeTimer);
        }
        this._resizeTimer = setTimeout(() => {
            this.mapInstance?.invalidateSize({ animate: false, pan: false });
            this._resizeTimer = null;
        }, 120);
    }

    /**
     * Enter geometry edit mode for a segment: draw a dashed preview polyline
     * with draggable vertex handles. Clicking the line inserts a vertex.
     */
    @action enterEditGeometry(segment) {
        if (!this.mapInstance) {
            return;
        }

        const geometry = segment?.geometry;
        if (!geometry?.coordinates || geometry.coordinates.length < 2) {
            return;
        }

        this.clearEditLayer();

        this.editingUuid = segment.uuid ?? segment.id;
        this.editingCoordinates = geometry.coordinates.map(([lng, lat]) => [lng, lat]);

        this.renderEditOverlay();
        this.isEditingGeometry = true;
    }

    renderEditOverlay() {
        if (!this.mapInstance || !this.editingCoordinates) {
            return;
        }

        const latlngs = this.editingCoordinates.map(([lng, lat]) => [lat, lng]);

        this.editPolyline = L.polyline(latlngs, {
            color: '#f97316',
            weight: 4,
            dashArray: '6, 4',
            opacity: 0.95,
        }).addTo(this.mapInstance);

        this.editPolyline.on('click', (event) => {
            this.insertEditVertex(event.latlng);
        });

        this.rebuildEditHandles();
    }

    rebuildEditHandles() {
        if (!this.mapInstance) {
            return;
        }

        for (const handle of this.editHandles) {
            this.mapInstance.removeLayer(handle);
        }
        this.editHandles = [];

        this.editingCoordinates.forEach(([lng, lat], index) => {
            const handle = L.marker([lat, lng], {
                draggable: true,
                icon: L.divIcon({
                    className: 'edit-vertex-handle',
                    html: '<div style="width:14px;height:14px;background:#f97316;border:2px solid #fff;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,.5);cursor:grab;"></div>',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7],
                }),
            }).addTo(this.mapInstance);

            handle.on('drag', () => {
                const pos = handle.getLatLng();
                this.editingCoordinates[index] = [pos.lng, pos.lat];
                this.editPolyline?.setLatLngs(this.editingCoordinates.map(([lng, lat]) => [lat, lng]));
            });

            handle.on('dragend', () => {
                const pos = handle.getLatLng();
                this.editingCoordinates[index] = [pos.lng, pos.lat];
                this.editPolyline?.setLatLngs(this.editingCoordinates.map(([lng, lat]) => [lat, lng]));
            });

            this.editHandles.push(handle);
        });
    }

    insertEditVertex(latlng) {
        if (!this.editingCoordinates || this.editingCoordinates.length < 2) {
            return;
        }

        let bestIndex = 0;
        let bestDist = Infinity;

        for (let i = 0; i < this.editingCoordinates.length - 1; i++) {
            const [lng1, lat1] = this.editingCoordinates[i];
            const [lng2, lat2] = this.editingCoordinates[i + 1];
            const mid = [(lat1 + lat2) / 2, (lng1 + lng2) / 2];
            const dist = Math.abs(latlng.lat - mid[0]) + Math.abs(latlng.lng - mid[1]);
            if (dist < bestDist) {
                bestDist = dist;
                bestIndex = i + 1;
            }
        }

        this.editingCoordinates.splice(bestIndex, 0, [latlng.lng, latlng.lat]);
        this.editPolyline?.setLatLngs(this.editingCoordinates.map(([lng, lat]) => [lat, lng]));
        this.rebuildEditHandles();
    }

    @action saveEditGeometry() {
        if (this.editingUuid && this.editingCoordinates && this.args.onSaveGeometry) {
            this.args.onSaveGeometry(this.editingUuid, {
                type: 'LineString',
                coordinates: this.editingCoordinates,
            });
        }
        this.clearEditLayer();
    }

    @action cancelEditGeometry() {
        this.clearEditLayer();
        if (this.args.onCancelEditGeometry) {
            this.args.onCancelEditGeometry();
        }
    }

    clearEditLayer() {
        if (!this.mapInstance) {
            return;
        }
        if (this.editPolyline) {
            this.mapInstance.removeLayer(this.editPolyline);
            this.editPolyline = null;
        }
        for (const handle of this.editHandles) {
            this.mapInstance.removeLayer(handle);
        }
        this.editHandles = [];
        this.editingUuid = null;
        this.editingCoordinates = null;
        this.isEditingGeometry = false;
    }

    @action redrawSegments() {
        this.redrawAll();
    }

    drawSegments() {
        if (!this.mapInstance) {
            return;
        }

        this.clearSegmentLayers();
        if (this.args.showRoadLayer === false) return;

        this.ensureMapPane('trafficRoads', 450);

        const segments = this.args.trafficRoads || [];
        const bounds = [];

        const parent = this.args.segments?.find((road) => (road.uuid || road.id) === this.args.selectedSegmentUuid);
        const parentPoints = parent && this.segmentLatLngs(parent);
        if (parentPoints) {
            this.segmentLayers.push(L.polyline(parentPoints, { color: '#93c5fd', weight: 14, opacity: 0.65, interactive: false }).addTo(this.mapInstance));
        }

        for (const segment of segments) {
            const latlngs = this.segmentLatLngs(segment);
            if (!latlngs) {
                continue;
            }

            const isSelected = segment.target_key === this.args.selectedTrafficTargetKey;

            if (isSelected) {
                // White halo underneath for a clear highlight.
                const halo = L.polyline(latlngs, {
                    color: '#2563eb',
                    weight: 12,
                    opacity: 1,
                    interactive: false,
                }).addTo(this.mapInstance);
                this.segmentLayers.push(halo);
            }

            const hit = L.polyline(latlngs, { pane: 'trafficRoads', color: '#000000', weight: 18, opacity: 0, bubblingMouseEvents: false }).addTo(this.mapInstance);
            const polyline = L.polyline(latlngs, {
                pane: 'trafficRoads', interactive: false,
                color: segment.color || this.getStatusColor(segment.status),
                weight: isSelected ? 8 : 6,
                opacity: 1,
            }).addTo(this.mapInstance);

            hit.on('click', (event) => {
                if (this.drawMode || this.isEditingGeometry) {
                    return;
                }
                L.DomEvent.stopPropagation(event);
                this.args.onSelectTraffic?.(this.nearestImportRoad(event.latlng, segments) || segment);
            });

            const label = document.createElement('span');
            label.textContent = `${segment.name} · ${segment.start_label} → ${segment.end_label} · ${segment.length_meters} 米 · ${segment.status_label} · ${segment.congestion_label} · ${segment.driver_count} 辆 · ${segment.data_status_label || ''}`;
            hit.bindTooltip(label, { sticky: true });
            this.segmentLayers.push(hit, polyline);
            if (isSelected) {
                for (const point of [latlngs[0], latlngs[latlngs.length - 1]]) {
                    this.segmentLayers.push(L.circleMarker(point, { radius: 6, color: '#2563eb', weight: 3, fillColor: '#ffffff', fillOpacity: 1, interactive: false }).addTo(this.mapInstance));
                }
            }
            bounds.push(...latlngs);
        }

        if (bounds.length > 0 && !this.hasFittedBounds) {
            this.mapInstance.fitBounds(bounds, { padding: [24, 24] });
            this.hasFittedBounds = true;
        }
    }

    clearJunctionLayers() {
        for (const layer of this.junctionLayers) this.mapInstance?.removeLayer(layer);
        this.junctionLayers = [];
    }

    drawJunctions() {
        if (!this.mapInstance) return;
        this.clearJunctionLayers();
        if (this.args.showJunctionLayer === false) return;
        this.ensureMapPane('trafficJunctions', 490);
        for (const junction of this.args.junctions || []) {
            const isSelected = junction.target_key === this.args.selectedTrafficTargetKey;
            const marker = L.circleMarker([junction.latitude, junction.longitude], {
                pane: 'trafficJunctions', bubblingMouseEvents: false,
                radius: isSelected ? 10 : 8, color: isSelected ? '#2563eb' : '#ffffff', weight: isSelected ? 4 : 2, fillColor: junction.color, fillOpacity: 1,
            }).addTo(this.mapInstance);
            const label = document.createElement('span');
            label.textContent = `交汇点：${junction.name} · ${junction.congestion_label} · ${junction.driver_count} 辆 · ${junction.data_status_label || ''}`;
            marker.bindTooltip(label, { sticky: true });
            marker.on('click', () => {
                if (!this.drawMode && !this.isEditingGeometry) this.args.onSelectJunction?.(junction);
            });
            this.junctionLayers.push(marker);
        }
    }

    drawAvoidAreas() {
        if (!this.mapInstance) {
            return;
        }

        this.clearAvoidLayers();
        if (this.args.showAvoidLayer === false) return;
        const avoidAreas = this.args.avoidAreas || [];

        for (const avoid of avoidAreas) {
            const geometry = avoid.geometry;
            if (!geometry?.coordinates) {
                continue;
            }

            let layer = null;
            if (geometry.type === 'LineString') {
                const latlngs = geometry.coordinates.map(([lng, lat]) => [lat, lng]);
                layer = L.polyline(latlngs, {
                    color: '#ef4444',
                    weight: 8,
                    opacity: 0.85,
                    dashArray: '8, 6',
                });
            } else if (geometry.type === 'Polygon') {
                const ring = geometry.coordinates[0] || [];
                const latlngs = ring.map(([lng, lat]) => [lat, lng]);
                layer = L.polygon(latlngs, {
                    color: '#ef4444',
                    weight: 2,
                    fillColor: '#ef4444',
                    fillOpacity: 0.25,
                });
            }

            if (!layer) {
                continue;
            }

            const reasonLabel = document.createElement('span');
            reasonLabel.textContent = avoid.reason || '禁行区';
            layer.bindTooltip(reasonLabel, { sticky: true });
            layer.addTo(this.mapInstance);
            this.avoidLayers.push(layer);
        }
    }

    drawNavigatingRoutes() {
        if (!this.mapInstance) return;
        this.driverMarkers ||= new Map();
        this.navigationPaths ||= [];
        const coordinates = this.args.selectedDriverRoute?.coordinates;
        const signature = JSON.stringify([coordinates, this.args.showNavigationLayer]);
        if (signature !== this.navigationSignature) {
            for (const layer of this.navigationPaths) this.mapInstance.removeLayer(layer);
            this.navigationPaths = [];
            if (this.args.showNavigationLayer !== false && Array.isArray(coordinates) && coordinates.length >= 2) {
                this.ensureMapPane('driverNavigation', 550);
                const points = coordinates.filter(point => Array.isArray(point) && point.length >= 2 && point.every(Number.isFinite));
                if (points.length >= 2) {
                    this.navigationPaths.push(L.polyline(points, {pane:'driverNavigation',color:'#ffffff',weight:10,opacity:0.95,interactive:false}).addTo(this.mapInstance));
                    this.navigationPaths.push(L.polyline(points, {pane:'driverNavigation',color:'#2563eb',weight:6,opacity:1,interactive:false}).addTo(this.mapInstance));
                }
            }
            this.navigationSignature = signature;
        }
        const seen = new Set();
        for (const location of this.args.showDriverRoutes === false ? [] : this.args.driverLocations || []) {
            if (!Number.isFinite(location.lat) || !Number.isFinite(location.lng)) continue;
            const key = location.entry_uuid || location.plate_number || location.driver_name;
            if (seen.has(key)) continue;
            seen.add(key);
            let marker = this.driverMarkers.get(key);
            if (marker) marker.setLatLng([location.lat, location.lng]);
            else {
                marker = this.makeDriverMarker(location.lat, location.lng, location, this.driverMarkers.size + 1);
                this.driverMarkers.set(key, marker);
            }
            const label = document.createElement('span');
            label.textContent = '🚚 ' + (location.plate_number || location.driver_name || '司机');
            marker.bindTooltip(label, { sticky: true });
        }
        for (const [key, marker] of this.driverMarkers) {
            if (!seen.has(key)) { this.mapInstance.removeLayer(marker); this.driverMarkers.delete(key); }
        }
        this.routeLayers = [...this.navigationPaths, ...this.driverMarkers.values()];
    }

    @action resetAreaViewport() { this.hasFittedBounds = false; this.layerSignatures?.clear(); this.redrawAll(); }

    @action focusSelection() {
        const geometry = this.args.focusTarget;
        if (!this.mapInstance || !geometry) return;
        if (geometry.type === 'Point') {
            const [lng, lat] = geometry.coordinates || [];
            if (Number.isFinite(lat) && Number.isFinite(lng)) this.mapInstance.setView([lat,lng], Math.max(this.mapInstance.getZoom(), 17));
        } else if (geometry.type === 'LineString') {
            const points = this.segmentLatLngs({geometry});
            if (points) this.mapInstance.fitBounds(points, {padding:[40,40],maxZoom:18});
        }
    }

    makeDriverMarker(lat, lng, entry, index) {
        const color = DRIVER_ROUTE_COLORS[(index - 1) % DRIVER_ROUTE_COLORS.length];
        const label = entry.plate_number || entry.vehicle?.plate_number || entry.driver_name || `司机${index}`;
        const marker = L.marker([lat, lng], {
            icon: L.divIcon({
                className: 'cs-driver-pos',
                html: `<div style="width:16px;height:16px;background:${color};border:3px solid #fff;border-radius:50%;box-shadow:0 1px 6px rgba(0,0,0,.5);"></div>`,
                iconSize: [16, 16],
                iconAnchor: [8, 8],
            }),
        }).addTo(this.mapInstance);
        const tooltip = document.createElement('span');
        tooltip.textContent = `🚚 ${label}`;
        marker.bindTooltip(tooltip, { sticky: true });
        return marker;
    }

    @action startDrawLine() {
        this.beginDraw('LineString', 'avoid');
    }

    @action startDrawPolygon() {
        this.beginDraw('Polygon', 'avoid');
    }

    @action startDrawSegment() {
        this.beginDraw('LineString', 'segment');
    }

    @action consumeDrawSegmentRequest() {
        // Triggered from the page (e.g. redraw button): enter segment draw mode.
        if (!this.drawMode) {
            this.beginDraw('LineString', 'segment');
        }
    }

    beginDraw(mode, purpose = 'avoid') {
        if (this.args.mutationBusy || this.isEditingGeometry) return;
        if (!this.mapInstance) {
            return;
        }

        this.drawMode = mode;
        this.drawPurpose = purpose;
        this.args.onDrawModeChange?.(purpose);
        this.draftPoints = [];
        this.clearDraftLayer();
        this.teardownDrawHandler();

        this.clickHandler = (event) => {
            const next = [...this.draftPoints, [event.latlng.lat, event.latlng.lng]];
            this.draftPoints = next;
            this.renderDraft();
        };

        this.mapInstance.on('click', this.clickHandler);
    }

    @action cancelDraw() {
        this.drawMode = null;
        this.drawPurpose = null;
        this.args.onDrawModeChange?.(null);
        this.draftPoints = [];
        this.clearDraftLayer();
        this.teardownDrawHandler();
    }

    @action finishDraw() {
        const mode = this.drawMode;
        const points = this.draftPoints;
        const minPoints = mode === 'Polygon' ? 3 : 2;

        if (!mode || points.length < minPoints) {
            return;
        }

        const coordinates = points.map(([lat, lng]) => [lng, lat]);
        let geometry;

        if (mode === 'LineString') {
            geometry = { type: 'LineString', coordinates };
        } else {
            const ring = [...coordinates];
            const first = ring[0];
            const last = ring[ring.length - 1];
            if (first[0] !== last[0] || first[1] !== last[1]) {
                ring.push([...first]);
            }
            geometry = { type: 'Polygon', coordinates: [ring] };
        }

        if (this.drawPurpose === 'segment') {
            if (this.args.onDrawSegmentFinish) {
                this.args.onDrawSegmentFinish({ type: 'LineString', coordinates });
            }
        } else if (this.args.onCreateAvoidArea) {
            this.args.onCreateAvoidArea(geometry);
        }

        this.cancelDraw();
    }

    renderDraft() {
        this.clearDraftLayer();
        if (!this.mapInstance || this.draftPoints.length === 0) {
            return;
        }

        if (this.drawMode === 'Polygon' && this.draftPoints.length >= 3) {
            this.draftLayer = L.polygon(this.draftPoints, {
                color: '#f97316',
                weight: 2,
                fillOpacity: 0.2,
                dashArray: '4,4',
            }).addTo(this.mapInstance);
        } else {
            this.draftLayer = L.polyline(this.draftPoints, {
                color: '#f97316',
                weight: 4,
                dashArray: '4,4',
            }).addTo(this.mapInstance);
        }
    }
}
