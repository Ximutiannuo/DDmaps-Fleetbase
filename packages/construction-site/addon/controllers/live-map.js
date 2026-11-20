import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const API_NAMESPACE = 'construction-site/int/v1';

export default class LiveMapController extends Controller {
    @service fetch;
    @service notifications;
    @service store;
    @service socket;
    @service currentUser;
    @service constructionSiteSocket;

    @tracked latestNotification = null;
    @tracked selectedSegment = null;
    @tracked formStatus = 'open';
    @tracked formSurface = 'dry';
    @tracked formCongestion = 'none';
    @tracked formNote = '';
    @tracked isSaving = false;
    @tracked mapRevision = 0;
    @tracked avoidAreas = [];
    @tracked navigatingEntries = [];
    @tracked serviceAreas = [];
    @tracked selectedServiceAreaUuid = null;
    @tracked avoidReason = '施工占道';
    @tracked segmentDraft = null;
    @tracked isSavingSegment = false;
    @tracked drawSegmentRequest = 0;
    @tracked editingSegmentRecord = null;
    @tracked isSavingGeometry = false;
    @tracked isSettingStatus = false;
    @tracked showDriverRoutes = true;
    @tracked driverLocations = [];
    @tracked renamingSegment = null;
    @tracked renameValue = '';
    @tracked isRenaming = false;
    @tracked isRenameMode = false;
    @tracked mapHeight = 384;
    @tracked rightWidth = 360;
    @tracked activePanel = 'traffic';
    @tracked panelCollapsed = false;
    @tracked trafficSearch = '';
    @tracked trafficStatusFilter = 'all';
    @tracked showRoadLayer = true;
    @tracked showJunctionLayer = true;
    @tracked showAvoidLayer = true;
    @tracked showNavigationLayer = true;
    @tracked selectedDriverKey = null;
    @tracked focusTarget = null;
    @tracked focusRequest = 0;
    @tracked drawPurpose = null;
    @tracked isMapFullscreen = false;
    @tracked trafficRoads = [];
    @tracked trafficJunctions = [];
    @tracked removedTrafficRoads = [];
    @tracked roadDeletePrompt = null;
    @tracked isDeletingRoad = false;
    @tracked lastRemovedSection = null;
    trafficRequest = 0;
    @tracked selectedTraffic = null;
    @tracked trafficForm = null;
    @tracked isSavingTraffic = false;
    @tracked trafficUpdatedAt = null;
    @tracked trafficError = null;
    trafficTimer = null;
    @tracked roadImportPreview = null;
    @tracked roadImportSelectedKeys = [];
    @tracked roadImportFilter = 'all';
    @tracked roadImportAvailabilityFilter = 'all';
    @tracked roadImportError = null;
    @tracked isImportSelectionMode = false;
    @tracked deletedRoads = [];
    @tracked roadRestorePrompt = null;
    @tracked isLoadingRoadImport = false;
    @tracked isCommittingRoadImport = false;
    roadImportRequest = 0;

    matchesTraffic(item) {
        const query = this.trafficSearch.trim().toLocaleLowerCase();
        const text = [item.name, item.road_name, item.source_meta?.way_id, item.start_label, item.end_label].filter(Boolean).join(' ').toLocaleLowerCase();
        return (!query || text.includes(query)) && (this.trafficStatusFilter === 'all'
            || (this.trafficStatusFilter === 'congested' ? ['light', 'moderate', 'heavy', 'standstill'].includes(item.congestion) : item.status === this.trafficStatusFilter));
    }
    get filteredTrafficRoads() { return this.trafficRoads.filter(item => this.matchesTraffic(item)); }
    get filteredTrafficJunctions() { return this.trafficJunctions.filter(item => this.matchesTraffic(item)); }
    get panelTabs() { return [{key:'traffic',label:'路况'}, {key:'drivers',label:'司机'}, {key:'import',label:'导入'}, {key:'recovery',label:'恢复'}, {key:'events',label:'动态'}]; }
    get mapLayerOptions() { return [{key:'showRoadLayer',label:'道路路况',visible:this.showRoadLayer}, {key:'showJunctionLayer',label:'交汇点',visible:this.showJunctionLayer}, {key:'showDriverRoutes',label:'司机位置',visible:this.showDriverRoutes}, {key:'showNavigationLayer',label:'选中司机路线',visible:this.showNavigationLayer}, {key:'showAvoidLayer',label:'禁行区',visible:this.showAvoidLayer}]; }
    get driverItems() {
        const items = this.driverLocations.map(location => ({...location, key:location.entry_uuid, hasLocation:true,
            route:this.navigatingEntries.find(entry => entry.uuid === location.entry_uuid)?.active_route}));
        for (const entry of this.navigatingEntries) {
            if (!items.some(item => item.key === entry.uuid)) items.push({key:entry.uuid, plate_number:entry.vehicle?.plate_number, driver_name:entry.driver_name, route:entry.active_route, hasLocation:false});
        }
        return items;
    }
    get selectedDriverRoute() { return this.driverItems.find(item => item.key === this.selectedDriverKey)?.route; }
    @action setActivePanel(key) {
        if (!this.panelTabs.some(tab => tab.key === key)) return;
        this.activePanel = key; this.panelCollapsed = false;
        if (!this.roadMutationBusy) this.isImportSelectionMode = key === 'import' && Boolean(this.roadImportPreview);
    }
    @action togglePanel() { this.panelCollapsed = !this.panelCollapsed; }
    @action setTrafficSearch(event) { this.trafficSearch = event.target.value; }
    @action setTrafficStatusFilter(event) { this.trafficStatusFilter = event.target.value; }
    @action toggleMapLayer(key) {
        if (this.mapLayerOptions.some(layer => layer.key === key)) this[key] = !this[key];
    }
    @action locateTraffic(target) {
        if (this.roadMutationBusy) return;
        this.selectTraffic(target);
        this.showRoadLayer = true; this.showJunctionLayer = true;
        this.focusTarget = target.geometry || {type:'Point',coordinates:[target.longitude,target.latitude]};
        this.focusRequest++;
    }
    @action selectDriver(item) {
        this.selectedDriverKey = item.key;
        this.showDriverRoutes = true; this.showNavigationLayer = true;
        if (item.hasLocation) { this.focusTarget = {type:'Point',coordinates:[item.lng,item.lat]}; this.focusRequest++; }
    }
    @action clearDriverSelection() { this.selectedDriverKey = null; }
    @action onDrawModeChange(purpose) {
        this.drawPurpose = purpose;
        if (purpose) { this.setActivePanel('traffic'); this.isImportSelectionMode = false; }
    }
    @action async toggleFullscreen(event) {
        const root = event.currentTarget.closest('.construction-site-live-map');
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await root.requestFullscreen();
        } catch { this.notifications.info('当前浏览器不支持全屏，可收起操作面板扩大地图。'); }
    }
    @action setupLayout() {
        this._fullscreenHandler = () => { this.isMapFullscreen = Boolean(document.fullscreenElement); };
        document.addEventListener('fullscreenchange', this._fullscreenHandler);
    }
    @action setupPanel(element) { this.panelElement = element; }
    @action resetPanelScroll() { if (this.panelElement) this.panelElement.scrollTop = 0; }
    @action teardownLayout() {
        if (this._fullscreenHandler) document.removeEventListener('fullscreenchange', this._fullscreenHandler);
        this._fullscreenHandler = null;
        this.panelElement = null;
        this._onRightEnd?.(); this._onResizeEnd?.();
    }

    get canPreviewRoadImport() {
        return Boolean(this.selectedServiceAreaUuid) && !this.isLoadingRoadImport && !this.isCommittingRoadImport && !this.isDeletingRoad;
    }

    get canGenerateRoadSections() {
        return this.canPreviewRoadImport && !this.roadMutationBusy;
    }

    get roadImportCandidates() {
        return (this.roadImportPreview?.candidates || []).filter((road) => this.roadImportFilter === 'all'
            || (this.roadImportFilter === 'internal' ? ['service', 'track', 'living_street'].includes(road.highway)
                : !['service', 'track', 'living_street'].includes(road.highway))).filter((road) => this.roadImportAvailabilityFilter === 'all'
                    || (this.roadImportAvailabilityFilter === 'available' ? road.availability === 'new'
                        : this.roadImportAvailabilityFilter === 'restricted' ? road.availability === 'new' && road.restricted : road.availability !== 'new')).map((road) => ({
            ...road, selected: this.roadImportSelectedKeys.includes(road.key), disabled: road.availability !== 'new',
            directionLabel: road.routing.direction === 0 ? '双向' : '单行（见地图箭头）',
            highwayLabel: ({ service: '内部／服务道路', track: '便道', living_street: '生活街道', residential: '居民道路', unclassified: '普通道路', construction: '施工道路', road: '待确认道路' })[road.highway] || '主干／连接道路',
        }));
    }

    get roadImportMapCandidates() {
        return this.roadImportPreview && this.isImportSelectionMode ? this.roadImportCandidates : [];
    }

    get roadImportSelectedCount() { return this.roadImportCandidates.filter((road) => road.selected && !road.disabled).length; }
    get roadImportAvailableCount() { return this.roadImportCandidates.filter((road) => !road.disabled).length; }
    get roadImportRestrictedSelectedCount() { return this.roadImportCandidates.filter((road) => road.selected && !road.disabled && road.restricted).length; }
    get canSelectRoadImports() { return this.roadImportAvailableCount > 0 && !this.isCommittingRoadImport && !this.isDeletingRoad; }
    get roadImportFetchedAt() { return this.roadImportPreview ? new Date(this.roadImportPreview.fetched_at).toLocaleString('zh-CN') : ''; }
    get canCommitRoadImport() { return this.roadImportSelectedCount > 0 && !this.isCommittingRoadImport && !this.isLoadingRoadImport && !this.isDeletingRoad; }
    get roadMutationBusy() { return this.isDeletingRoad || this.isSavingTraffic || this.isCommittingRoadImport || this.isSavingGeometry || this.isSavingSegment || this.isRenaming; }
    get canUndoSectionRemoval() { return this.lastRemovedSection?.service_area_uuid === this.selectedServiceAreaUuid; }

    @action async previewRoadImport() {
        if (!this.canPreviewRoadImport) return;
        this.setActivePanel('import');
        const area = this.selectedServiceAreaUuid;
        const request = ++this.roadImportRequest;
        this.isLoadingRoadImport = true;
        this.roadImportPreview = null;
        this.roadImportError = null;
        try {
            const result = await this.fetch.post('road-imports/preview', { service_area_uuid: area }, { namespace: API_NAMESPACE });
            if (request !== this.roadImportRequest || area !== this.selectedServiceAreaUuid) return;
            this.roadImportPreview = result;
            this.roadImportFilter = 'all';
            this.roadImportAvailabilityFilter = 'all';
            this.roadImportSelectedKeys = result.candidates.filter((road) => road.availability === 'new' && !road.restricted).map((road) => road.key);
            this.isImportSelectionMode = result.candidates.some((road) => road.availability === 'new');
            this.notifications.info(result.candidates.length ? '道路预览已加载，请核对地图并选择要导入的道路' : 'OSM 在当前 GCC 内没有可用道路，可继续手绘补充');
        } catch (e) {
            if (request === this.roadImportRequest) this.roadImportError = e.message || '道路获取失败，请稍后重试';
        } finally {
            if (request === this.roadImportRequest) this.isLoadingRoadImport = false;
        }
    }

    @action cancelRoadImport() {
        if (this.isCommittingRoadImport) return;
        this.roadImportRequest++;
        this.isLoadingRoadImport = false;
        this.roadImportPreview = null;
        this.roadImportSelectedKeys = [];
        this.roadImportError = null;
        this.isImportSelectionMode = false;
    }

    @action setImportSelectionMode(value) {
        if (!this.roadMutationBusy) this.isImportSelectionMode = value;
    }

    @action filterRoadImport(event) { if (!this.isCommittingRoadImport) this.roadImportFilter = event.target.value; }
    @action filterRoadImportAvailability(event) { if (!this.isCommittingRoadImport) this.roadImportAvailabilityFilter = event.target.value; }
    @action toggleRoadImport(road, latlng) {
        if (this.isCommittingRoadImport || this.isDeletingRoad) return;
        // Resolve the current server preview instead of trusting an old map layer.
        road = this.roadImportCandidates.find((candidate) => candidate.key === road.key);
        if (!road) return;
        if (road.availability === 'deleted') {
            this.requestRoadRestore({ uuid: road.existing_road_uuid, name: road.existing_road_name || road.name, geometry: road.existing_road_geometry });
            return;
        }
        if (road.availability === 'existing' && road.existing_road_uuid) {
            this.editImportedRoad(road, latlng);
            return;
        }
        if (road.disabled) {
            this.notifications.info(`${road.name}：${road.availability_label || '已有或重叠道路不能重复导入'}`);
            return;
        }
        this.roadImportSelectedKeys = this.roadImportSelectedKeys.includes(road.key)
            ? this.roadImportSelectedKeys.filter((key) => key !== road.key) : [...this.roadImportSelectedKeys, road.key];
    }
    @action selectAllRoadImports() {
        this.selectRoadImports(true);
    }

    @action editImportedRoad(road, latlng) {
        if (this.roadMutationBusy) return;
        const sections = this.trafficRoads.filter((item) => item.road_segment_uuid === road.existing_road_uuid);
        let target = sections[0]; let best = Infinity;
        if (Number.isFinite(latlng?.lat) && Number.isFinite(latlng?.lng)) {
            const scale = Math.cos(latlng.lat * Math.PI / 180);
            for (const section of sections) {
                const coords = section.geometry.coordinates;
                for (let i = 1; i < coords.length; i++) {
                    const a = coords[i - 1]; const b = coords[i];
                    const x = (a[0] - latlng.lng) * scale; const y = a[1] - latlng.lat;
                    const dx = (b[0] - a[0]) * scale; const dy = b[1] - a[1];
                    const length = dx * dx + dy * dy;
                    const t = length > 0 ? Math.max(0, Math.min(1, -(x * dx + y * dy) / length)) : 0;
                    const distance = (x + t * dx) ** 2 + (y + t * dy) ** 2;
                    if (distance < best) { best = distance; target = section; }
                }
            }
        }
        if (target) this.selectTraffic(target);
        else this.notifications.info('此道路没有可编辑的活动路段，请先从“已删除路段”中恢复，或刷新当前 GCC。');
    }
    @action selectUnrestrictedRoadImports() { this.selectRoadImports(false); }
    selectRoadImports(includeRestricted) {
        if (!this.canSelectRoadImports) return;
        const visible = this.roadImportCandidates;
        const keys = visible.filter((road) => !road.disabled && (includeRestricted || !road.restricted)).map((road) => road.key);
        const visibleKeys = new Set(visible.map((road) => road.key));
        this.roadImportSelectedKeys = [...new Set([...this.roadImportSelectedKeys.filter((key) => !visibleKeys.has(key)), ...keys])];
    }
    @action clearRoadImports() {
        if (this.isCommittingRoadImport || this.isDeletingRoad) return;
        const visibleKeys = new Set(this.roadImportCandidates.map((road) => road.key));
        this.roadImportSelectedKeys = this.roadImportSelectedKeys.filter((key) => !visibleKeys.has(key));
    }

    @action async commitRoadImport() {
        if (!this.canCommitRoadImport) return;
        const area = this.selectedServiceAreaUuid;
        this.isCommittingRoadImport = true;
        this.roadImportError = null;
        try {
            const keys = this.roadImportCandidates.filter((road) => road.selected && !road.disabled).map((road) => road.key);
            await this.importRoadCandidates(area, this.roadImportPreview.preview_token, keys);
        } catch (e) {
            this.roadImportError = e.message || '道路导入失败；可以重试，已保存道路不会重复创建';
        } finally { this.isCommittingRoadImport = false; }
    }

    @action async generateRoadSections() {
        if (!this.canGenerateRoadSections) return;
        const area = this.selectedServiceAreaUuid;
        this.setActivePanel('import');
        this.cancelRoadImport();
        this.isCommittingRoadImport = true;
        try {
            const preview = await this.fetch.post('road-imports/preview', { service_area_uuid: area }, { namespace: API_NAMESPACE });
            const keys = preview.candidates.filter((road) => road.availability === 'new').map((road) => road.key);
            if (keys.length) {
                await this.importRoadCandidates(area, preview.preview_token, keys);
            } else {
                this.notifications.info(preview.candidates.length ? '当前 GCC 没有新的可导入道路' : 'OpenStreetMap 在当前 GCC 内没有可用的机动车道路');
                await this.loadTraffic();
            }
            this.showRoadLayer = true;
            this.showJunctionLayer = true;
            this.setActivePanel('traffic');
        } catch (e) {
            this.roadImportError = e.message || '自动生成路段失败，请稍后重试';
        } finally { this.isCommittingRoadImport = false; }
    }

    async importRoadCandidates(area, previewToken, keys) {
        const result = await this.fetch.post('road-imports/commit', {
            service_area_uuid: area, preview_token: previewToken, candidate_keys: keys,
        }, { namespace: API_NAMESPACE });
        this.roadImportPreview = null;
        this.roadImportSelectedKeys = [];
        this.isImportSelectionMode = false;
        this.clearSelection();
        this.notifications.success(`已导入 ${result.created_count} 条道路，跳过 ${result.skipped_count} 条已有或重叠道路`);
        await this.store.findAll('road-segment', { reload: true });
        await this.loadTraffic();
    }

    get selectedTrafficInfo() {
        const key = this.selectedTraffic?.target_key;
        return [...this.trafficRoads, ...this.trafficJunctions].find((item) => item.target_key === key) || this.selectedTraffic;
    }

    get selectedTrafficTargetKey() {
        return this.selectedTraffic?.target_key;
    }

    get selectedObservationLabel() {
        const observedAt = this.selectedTrafficInfo?.last_observed_at;
        return observedAt ? '最近采集 ' + new Date(observedAt).toLocaleString('zh-CN') : '尚无可用采集记录';
    }

    @action selectTraffic(target) {
        if (this.roadMutationBusy) return;
        this.setActivePanel('traffic');
        this.isImportSelectionMode = false;
        this.roadRestorePrompt = null;
        this.cancelRename();
        this.roadDeletePrompt = null;
        this.selectedSegment = null;
        this.selectedTraffic = target;
        this.trafficForm = {
            status: target.status || 'open', congestion_mode: target.congestion_mode || 'auto',
            manual_congestion: target.manual_congestion || 'none', light_threshold: target.light_threshold ?? 3,
            moderate_threshold: target.moderate_threshold ?? 6, heavy_threshold: target.heavy_threshold ?? 10, note: target.note || '',
        };
    }

    @action changeTrafficField(field, event) {
        this.trafficForm = { ...this.trafficForm, [field]: event.target.value };
    }

    @action closeTraffic() {
        this.selectedTraffic = null;
        this.trafficForm = null;
    }

    @action async saveTraffic() {
        if (!this.selectedTraffic || !this.selectedServiceAreaUuid || this.roadMutationBusy) return;
        this.isSavingTraffic = true;
        try {
            await this.fetch.post('traffic-controls', {
                ...this.trafficForm, target_key: this.selectedTraffic.target_key, service_area_uuid: this.selectedServiceAreaUuid,
            }, { namespace: API_NAMESPACE });
            await this.loadTraffic();
            this.notifications.success('路况已保存，司机端将更新路况并按预计通行时间重规划');
        } catch (e) {
            this.notifications.error(e.message || '路况保存失败，请检查拥堵阈值依次递增');
        } finally {
            this.isSavingTraffic = false;
        }
    }

    async loadTraffic() {
        const area = this.selectedServiceAreaUuid;
        const request = ++this.trafficRequest;
        if (!area) return;
        try {
            const data = await this.fetch.get('traffic', { service_area_uuid: area }, { namespace: API_NAMESPACE });
            if (area !== this.selectedServiceAreaUuid || request !== this.trafficRequest) return;
            this.trafficRoads = data.road_segments || [];
            this.trafficJunctions = data.junctions || [];
            this.removedTrafficRoads = (data.removed_sections || []).map((road) => ({ ...road, removedAtLabel: new Date(road.removed_at).toLocaleString('zh-CN') }));
            this.deletedRoads = (data.deleted_roads || []).map((road) => ({ ...road, deletedAtLabel: new Date(road.deleted_at).toLocaleString('zh-CN') }));
            if (this.selectedTraffic && ![...this.trafficRoads, ...this.trafficJunctions].some((item) => item.target_key === this.selectedTraffic.target_key)) {
                this.closeTraffic();
                this.notifications.info('道路交汇结构已更新，请重新选择要编辑的路段');
            }
            this.trafficUpdatedAt = new Date(data.generated_at).toLocaleTimeString('zh-CN', { hour12: false });
            this.trafficError = null;
            this.mapRevision++;
        } catch (e) {
            if (area === this.selectedServiceAreaUuid && request === this.trafficRequest) this.trafficError = '路况获取失败，正在显示上次数据';
        }
    }

    startTrafficRefresh() {
        this.stopTrafficRefresh();
        const generation = this.trafficRefreshGeneration;
        let failures = 0;
        let busy = false;
        const refresh = async () => {
            if (generation !== this.trafficRefreshGeneration || busy) return;
            busy = true;
            if (typeof document === 'undefined' || !document.hidden) {
                await Promise.allSettled([this.loadTraffic(), this.refreshNavigatingEntries()]);
                failures = this.trafficError ? Math.min(failures + 1, 3) : 0;
            }
            busy = false;
            if (generation === this.trafficRefreshGeneration) this.trafficTimer = setTimeout(refresh, 15000 * (2 ** failures));
        };
        this.trafficTimer = setTimeout(refresh, 15000);
        this.trafficVisibilityHandler = () => {
            if (!document.hidden) { clearTimeout(this.trafficTimer); void refresh(); }
        };
        if (typeof document !== 'undefined') document.addEventListener('visibilitychange', this.trafficVisibilityHandler);
    }

    stopTrafficRefresh() {
        this.trafficRefreshGeneration = (this.trafficRefreshGeneration || 0) + 1;
        clearTimeout(this.trafficTimer);
        this.trafficTimer = null;
        if (typeof document !== 'undefined' && this.trafficVisibilityHandler) document.removeEventListener('visibilitychange', this.trafficVisibilityHandler);
    }

    resizeStartY = null;
    resizeStartHeight = null;

    driverRouteColors = ['#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#db2777', '#65a30d'];

    get selectedSegmentUuid() {
        return this.selectedSegment?.uuid ?? this.selectedSegment?.id ?? null;
    }

    @action selectSegment(segment) {
        if (this.roadMutationBusy) return;
        this.setActivePanel('traffic');
        this.isImportSelectionMode = false;
        this.roadRestorePrompt = null;
        this.cancelRename();
        this.roadDeletePrompt = null;
        this.closeTraffic();
        this.selectedSegment = segment;
        this.formStatus = segment.status || 'open';
        this.formSurface = 'dry';
        this.formCongestion = 'none';
        this.formNote = '';
    }

    @action clearSelection() {
        this.roadDeletePrompt = null;
        this.roadRestorePrompt = null;
        this.closeTraffic();
        this.selectedSegment = null;
        this.isRenameMode = false;
        this.renamingSegment = null;
        this.renameValue = '';
    }

    @action setFormStatus(event) {
        this.formStatus = event.target.value;
    }

    @action setFormSurface(event) {
        this.formSurface = event.target.value;
    }

    @action setFormCongestion(event) {
        this.formCongestion = event.target.value;
    }

    @action setFormNote(event) {
        this.formNote = event.target.value;
    }

    @action setSelectedServiceArea(event) {
        if (this.roadMutationBusy) return;
        this.cancelRoadImport();
        this.selectedServiceAreaUuid = event.target.value || null;
        this.clearSelection();
        this.trafficRoads = [];
        this.trafficJunctions = [];
        this.removedTrafficRoads = [];
        this.deletedRoads = [];
        this.loadTraffic();
    }

    @action setAvoidReason(event) {
        this.avoidReason = event.target.value;
    }

    @action async submitCondition() {
        if (!this.selectedSegment || this.isSaving) {
            return;
        }

        this.isSaving = true;

        try {
            const id = this.selectedSegment.id;
            const response = await this.fetch.post(
                `road-segments/${id}/conditions`,
                {
                    status: this.formStatus,
                    surface_condition: this.formSurface,
                    congestion: this.formCongestion,
                    note: this.formNote,
                },
                { namespace: API_NAMESPACE }
            );

            if (response?.road_segment) {
                this.selectedSegment.set('status', response.road_segment.status);
            } else {
                this.selectedSegment.set('status', this.formStatus);
            }

            this.notifications.success('路况已更新');
            this.mapRevision++;
            this.clearSelection();
        } catch (e) {
            this.notifications.error(e.message || '保存失败');
            console.error(e);
        } finally {
            this.isSaving = false;
        }
    }

    @action async createAvoidArea(geometry) {
        if (!this.selectedServiceAreaUuid) {
            this.notifications.error('请先选择 GCC（ServiceArea）');
            return;
        }

        this.isSaving = true;
        try {
            const response = await this.fetch.post(
                'avoid-areas',
                {
                    service_area_uuid: this.selectedServiceAreaUuid,
                    geometry,
                    reason: this.avoidReason || '施工占道',
                    status: 'active',
                },
                { namespace: API_NAMESPACE }
            );

            if (response?.avoid_area) {
                this.avoidAreas = [response.avoid_area, ...this.avoidAreas];
            }

            this.notifications.success('禁行区已创建，导航车辆将重规划');
            this.mapRevision++;
            await this.refreshNavigatingEntries();
        } catch (e) {
            this.notifications.error(e.message || '创建禁行区失败');
            console.error(e);
        } finally {
            this.isSaving = false;
        }
    }

    @action async deactivateAvoidArea(avoid) {
        const id = avoid.uuid || avoid.id;
        if (!id) {
            return;
        }

        try {
            await this.fetch.patch(
                `avoid-areas/${id}`,
                { status: 'inactive' },
                { namespace: API_NAMESPACE }
            );
            this.avoidAreas = this.avoidAreas.filter((item) => (item.uuid || item.id) !== id);
            this.notifications.success('禁行区已停用');
            this.mapRevision++;
            await this.refreshNavigatingEntries();
        } catch (e) {
            this.notifications.error(e.message || '停用失败');
        }
    }

    @action async refreshNavigatingEntries() {
        try {
            const response = await this.fetch.get('navigating-entries', {}, { namespace: API_NAMESPACE });
            this.navigatingEntries = response.entries || [];
        } catch (e) {
            console.warn('refreshNavigatingEntries failed', e);
        }

        try {
            const response = await this.fetch.get('active-vehicle-locations', {}, { namespace: API_NAMESPACE });
            this.driverLocations = (response.locations || []).map((location, index) => ({
                ...location,
                color: this.driverRouteColors[index % this.driverRouteColors.length],
            }));
        } catch (e) {
            console.warn('refreshDriverLocations failed', e);
        }
    }

    /**
     * 画完路段几何后回调: 打开路段表单.
     */
    @action onDrawSegmentFinish(geometry) {
        this.setActivePanel('traffic');
        const roads = this.model?.roads?.toArray?.() ?? [];
        this.segmentDraft = {
            editingUuid: this.segmentDraft?.editingUuid ?? null,
            name: this.segmentDraft?.name ?? '',
            status: this.segmentDraft?.status ?? 'open',
            siteRoadUuid: this.segmentDraft?.siteRoadUuid ?? (roads[0]?.uuid ?? roads[0]?.id ?? null),
            sequence: this.segmentDraft?.sequence ?? 0,
            geometry,
        };
    }

    /**
     * 点选路段后"重新描画几何": 进入画段模式并保留编辑目标.
     */
    @action startRedrawSegment(segment) {
        this.segmentDraft = {
            editingUuid: segment.uuid ?? segment.id,
            name: segment.name ?? '',
            status: segment.status ?? 'open',
            siteRoadUuid: segment.site_road_uuid ?? segment.siteRoadUuid ?? null,
            sequence: segment.sequence ?? 0,
            geometry: null,
        };
        this.drawSegmentRequest++;
    }

    @action cancelSegmentDraft() {
        this.segmentDraft = null;
    }

    /**
     * 进入几何编辑模式 (拖拽端点改长短、点击线段加拐点).
     */
    @action enterEditGeometry(segment) {
        this.editingSegmentRecord = segment;
    }

    @action cancelEditGeometry() {
        this.editingSegmentRecord = null;
    }

    @action toggleDriverRoutes() {
        this.showDriverRoutes = !this.showDriverRoutes;
        this.mapRevision++;
    }

    /**
     * 拖拽拉伸地图视窗高度.
     */
    @action startResize(event) {
        event.preventDefault();
        this.resizeStartY = event.clientY;
        this.resizeStartHeight = this.mapHeight;

        this._onResizeMove = (e) => {
            e.preventDefault();
            const delta = e.clientY - this.resizeStartY;
            this.mapHeight = Math.min(820, Math.max(240, this.resizeStartHeight + delta));
        };
        this._onResizeEnd = () => {
            window.removeEventListener('mousemove', this._onResizeMove);
            window.removeEventListener('mouseup', this._onResizeEnd);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            this._onResizeMove = null;
            this._onResizeEnd = null;
        };

        document.body.style.cursor = 'row-resize';
        document.body.style.userSelect = 'none';
        window.addEventListener('mousemove', this._onResizeMove);
        window.addEventListener('mouseup', this._onResizeEnd);
    }

    /**
     * 拖拽调整右侧列表宽度.
     */
    @action startRightResize(event) {
        event.preventDefault();
        this._rightStartX = event.clientX;
        this._rightStartWidth = this.rightWidth;

        this._onRightMove = (e) => {
            e.preventDefault();
            const delta = e.clientX - this._rightStartX;
            this.rightWidth = Math.min(440, Math.max(320, this._rightStartWidth - delta));
        };
        this._onRightEnd = () => {
            window.removeEventListener('mousemove', this._onRightMove);
            window.removeEventListener('mouseup', this._onRightEnd);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            this._onRightMove = null;
            this._onRightEnd = null;
        };

        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        window.addEventListener('mousemove', this._onRightMove);
        window.addEventListener('mouseup', this._onRightEnd);
    }

    /**
     * 重命名路段.
     */
    @action startRename(segment) {
        if (this.roadMutationBusy) return;
        this.renamingSegment = segment;
        this.renameValue = segment.name || '';
        this.isRenaming = false;
        this.isRenameMode = true;
    }

    @action setRenameValue(event) {
        this.renameValue = event.target.value;
    }

    @action renameSelectedRoad() {
        const road = this.selectedTrafficInfo;
        if (road?.road_segment_uuid) this.startRename({ uuid: road.road_segment_uuid, name: road.road_name || road.name });
    }

    @action cancelRename() {
        this.renamingSegment = null;
        this.renameValue = '';
        this.isRenameMode = false;
    }

    @action async saveRename() {
        const segment = this.renamingSegment;
        const id = segment?.uuid ?? segment?.id;
        const name = (this.renameValue || '').trim();
        if (!id || !name || this.roadMutationBusy) {
            return;
        }

        this.isRenaming = true;
        try {
            await this.fetch.patch(`road-segments/${id}/update`, { name }, { namespace: API_NAMESPACE });
            this.notifications.success('道路名称已更新，各路段与交汇点名称将同步刷新');
            this.renamingSegment = null;
            this.renameValue = '';
            this.isRenameMode = false;
            this.mapRevision++;
            await this.store.findAll('road-segment', { reload: true });
            await this.loadTraffic();
            if (this.roadImportPreview) this.roadImportPreview = { ...this.roadImportPreview, candidates: this.roadImportPreview.candidates.map((road) => road.existing_road_uuid === id ? { ...road, name, existing_road_name: name } : road) };
        } catch (e) {
            this.notifications.error(e.message || '重命名失败');
            console.error(e);
        } finally {
            this.isRenaming = false;
        }
    }

    /**
     * 保存编辑后的几何 (拖拽/加点后的坐标).
     */
    @action async saveEditedGeometry(uuid, geometry) {
        if (!uuid || this.isSavingGeometry) {
            return;
        }

        this.isSavingGeometry = true;
        try {
            await this.fetch.patch(`road-segments/${uuid}/update`, { geometry }, { namespace: API_NAMESPACE });
            this.notifications.success('路段几何已更新');
            this.editingSegmentRecord = null;
            this.mapRevision++;
            await this.store.findAll('road-segment', { reload: true });
        } catch (e) {
            this.notifications.error(e.message || '保存几何失败');
            console.error(e);
        } finally {
            this.isSavingGeometry = false;
        }
    }

    /**
     * 快捷修改路段通行状态 (不写路况历史, 只改当前状态).
     */
    @action async quickSetStatus(segment, status) {
        const id = segment.uuid ?? segment.id;
        if (!id || this.isSettingStatus) {
            return;
        }

        this.isSettingStatus = true;
        try {
            await this.fetch.patch(`road-segments/${id}/update`, { status }, { namespace: API_NAMESPACE });
            this.notifications.success('路段状态已更新');
            this.mapRevision++;
            await this.store.findAll('road-segment', { reload: true });
        } catch (e) {
            this.notifications.error(e.message || '更新状态失败');
            console.error(e);
        } finally {
            this.isSettingStatus = false;
        }
    }

    @action setDraftName(event) {
        this.segmentDraft = { ...this.segmentDraft, name: event.target.value };
    }

    @action setDraftStatus(event) {
        this.segmentDraft = { ...this.segmentDraft, status: event.target.value };
    }

    @action setDraftRoad(event) {
        this.segmentDraft = { ...this.segmentDraft, siteRoadUuid: event.target.value || null };
    }

    @action async saveSegment() {
        const draft = this.segmentDraft;
        if (!draft || this.isSavingSegment) {
            return;
        }

        const payload = {
            name: draft.name || '未命名路段',
            status: draft.status || 'open',
            site_road_uuid: draft.siteRoadUuid,
            sequence: draft.sequence ?? 0,
            geometry: draft.geometry,
        };
        if (!payload.geometry) {
            this.notifications.error('请先在地图上描画路段几何');
            return;
        }

        this.isSavingSegment = true;
        try {
            if (draft.editingUuid) {
                await this.fetch.patch(`road-segments/${draft.editingUuid}/update`, payload, { namespace: API_NAMESPACE });
            } else {
                await this.fetch.post('road-segments/create', payload, { namespace: API_NAMESPACE });
            }

            this.notifications.success(draft.editingUuid ? '路段已更新' : '路段已创建');
            this.segmentDraft = null;
            this.drawSegmentRequest++;
            this.mapRevision++;
            await this.store.findAll('road-segment', { reload: true });
        } catch (e) {
            this.notifications.error(e.message || '保存路段失败');
            console.error(e);
        } finally {
            this.isSavingSegment = false;
        }
    }

    @action requestSectionRemoval() {
        const section = this.selectedTrafficInfo;
        if (this.roadMutationBusy || !section?.road_segment_uuid || !this.selectedServiceAreaUuid) return;
        this.roadDeletePrompt = { kind: 'section', name: section.name, start: section.start_label, end: section.end_label,
            length: section.length_meters, target_key: section.target_key, road_segment_uuid: section.road_segment_uuid,
            expected_geometry: section.geometry, service_area_uuid: this.selectedServiceAreaUuid };
    }

    @action requestRoadRemoval(segment) {
        const id = segment.uuid ?? segment.id;
        if (this.roadMutationBusy || !id) return;
        this.roadDeletePrompt = { kind: 'road', id, name: segment.name, segment, service_area_uuid: this.selectedServiceAreaUuid };
    }

    @action cancelRoadRemoval() { if (!this.isDeletingRoad) this.roadDeletePrompt = null; }

    @action async manageSelectedRoad() {
        const uuid = this.selectedTrafficInfo?.road_segment_uuid;
        if (!uuid || this.roadMutationBusy) return;
        try {
            const segment = this.store.peekRecord('road-segment', uuid) || await this.store.findRecord('road-segment', uuid);
            this.selectSegment(segment);
        } catch (e) { this.notifications.error(e.message || '道路读取失败'); }
    }

    @action async confirmRoadRemoval() {
        const prompt = this.roadDeletePrompt;
        if (!prompt || this.roadMutationBusy || prompt.service_area_uuid !== this.selectedServiceAreaUuid) return;
        this.isDeletingRoad = true;
        try {
            if (prompt.kind === 'section') {
                await this.fetch.post('road-sections/remove', { target_key: prompt.target_key, service_area_uuid: prompt.service_area_uuid,
                    expected_geometry: prompt.expected_geometry }, { namespace: API_NAMESPACE });
                this.trafficRoads = this.trafficRoads.filter((road) => road.target_key !== prompt.target_key);
                this.lastRemovedSection = prompt;
            } else {
                await this.fetch.delete(`road-segments/${prompt.id}`, {}, { namespace: API_NAMESPACE });
                if (typeof this.store.unloadRecord === 'function' && !prompt.segment.isDeleted) this.store.unloadRecord(prompt.segment);
                this.trafficRoads = this.trafficRoads.filter((road) => road.road_segment_uuid !== prompt.id);
                if (this.lastRemovedSection?.road_segment_uuid === prompt.id) this.lastRemovedSection = null;
            }
            this.clearSelection();
            this.mapRevision++;
            await this.loadTraffic();
            await this.refreshNavigatingEntries();
            this.notifications.success(prompt.kind === 'section' ? '此路段已删除，相邻路段已保留；可撤销或从已删除路段中恢复' : '整条道路及其所有路段已删除');
        } catch (e) {
            this.notifications.error(e.message || '删除失败，请刷新地图后重试');
        } finally { this.isDeletingRoad = false; }
    }

    @action async restoreRemovedSection(section) {
        if (!section || this.roadMutationBusy || !this.selectedServiceAreaUuid) return;
        this.isDeletingRoad = true;
        try {
            await this.fetch.post('road-sections/restore', { target_key: section.target_key, service_area_uuid: this.selectedServiceAreaUuid }, { namespace: API_NAMESPACE });
            if (this.lastRemovedSection?.target_key === section.target_key) this.lastRemovedSection = null;
            await this.loadTraffic();
            await this.refreshNavigatingEntries();
            this.notifications.success('路段已恢复，原路况设置已保留');
        } catch (e) { this.notifications.error(e.message || '恢复失败，请刷新地图后重试'); }
        finally { this.isDeletingRoad = false; }
    }

    @action requestRoadRestore(road) {
        this.panelCollapsed = false;
        if (!road?.uuid || !road.geometry || this.roadMutationBusy) return;
        this.roadDeletePrompt = null;
        this.roadRestorePrompt = { ...road, service_area_uuid: this.selectedServiceAreaUuid };
    }

    @action cancelRoadRestore() { if (!this.isDeletingRoad) this.roadRestorePrompt = null; }

    @action async confirmRoadRestore() {
        const prompt = this.roadRestorePrompt;
        if (!prompt || this.roadMutationBusy || prompt.service_area_uuid !== this.selectedServiceAreaUuid) return;
        this.isDeletingRoad = true;
        try {
            await this.fetch.post(`road-segments/${prompt.uuid}/restore`, { service_area_uuid: prompt.service_area_uuid,
                expected_geometry: prompt.geometry }, { namespace: API_NAMESPACE });
            if (this.roadImportPreview) this.roadImportPreview = { ...this.roadImportPreview, candidates: this.roadImportPreview.candidates.map((road) => road.existing_road_uuid === prompt.uuid ? { ...road, availability: 'existing', availability_label: '已恢复，点击编辑路况' } : road) };
            this.roadRestorePrompt = null;
            this.isImportSelectionMode = false;
            await this.store.findAll('road-segment', { reload: true });
            await this.loadTraffic();
            await this.refreshNavigatingEntries();
            this.notifications.success('整条道路已恢复，名称、原路况和单行限制已保留；单独删除的路段仍需单独恢复');
        } catch (e) { this.notifications.error(e.message || '恢复失败，请刷新地图后重试'); }
        finally { this.isDeletingRoad = false; }
    }

    async loadAvoidAreas() {
        try {
            const response = await this.fetch.get(
                'avoid-areas',
                { status: 'active' },
                { namespace: API_NAMESPACE }
            );
            this.avoidAreas = response?.avoid_areas || response?.data || response || [];
            if (!Array.isArray(this.avoidAreas)) {
                this.avoidAreas = [];
            }
        } catch (e) {
            console.warn('loadAvoidAreas failed', e);
            this.avoidAreas = [];
        }
    }

    async loadServiceAreas() {
        try {
            const areas = await this.store.query('service-area', {});
            this.serviceAreas = areas.toArray().filter((area) => !area.status || area.status === 'active');
            if (!this.serviceAreas.some((area) => area.id === this.selectedServiceAreaUuid)) {
                this.selectedServiceAreaUuid = this.serviceAreas[0]?.id ?? null;
            }
            await this.loadTraffic();
            this.startTrafficRefresh();
        } catch (e) {
            console.warn('loadServiceAreas failed', e);
            this.serviceAreas = [];
        }
    }

    subscribeRoadNetwork() {
        this.unsubscribeRoadNetwork();
        this.closeRoadNetwork = this.constructionSiteSocket.on('construction-site.road_network_changed', () => {
            this.loadAvoidAreas();
            this.loadTraffic();
            this.refreshNavigatingEntries();
            this.latestNotification = { title: '路网变更', message: '道路或禁行区已更新', severity: 'warning' };
        });
    }

    unsubscribeRoadNetwork() {
        this.closeRoadNetwork?.();
        this.closeRoadNetwork = null;
    }
}
