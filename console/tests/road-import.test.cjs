const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = readFileSync(resolve(__dirname, '../../packages/construction-site/addon/controllers/live-map.js'), 'utf8')
    .replace(/^import .*;\r?\n/gm, '').replace(/@service \w+;/g, '').replace(/@tracked /g, '').replace(/@action /g, '')
    .replace('export default class LiveMapController', 'class LiveMapController');
const context = vm.createContext({ Controller: class {}, setInterval, clearInterval, setTimeout, clearTimeout, Date, Set, console });
vm.runInContext(source + '\n globalThis.ImportController = LiveMapController;', context);
function controller() {
    const c = new context.ImportController();
    c.selectedServiceAreaUuid = 'area';
    c.notifications = { info() {}, success() {}, error() {} };
    c.store = { async findAll() {} };
    c.loadTraffic = async () => {};
    return c;
}
function candidate(key, restricted = false, availability = 'new', highway = 'service') {
    return { key, name: key, highway, restricted, availability, routing: { direction: 0 } };
}
test('preview defaults to unrestricted roads and one-click also selects new restricted roads', async () => {
    const c = controller();
    c.fetch = { async post() { return { fetched_at: new Date().toISOString(), candidates: [candidate('normal'), candidate('private', true), candidate('overlap', false, 'overlap')] }; } };
    await c.previewRoadImport();
    assert.equal(c.roadImportSelectedCount, 1);
    c.clearRoadImports(); c.selectAllRoadImports();
    assert.equal(c.roadImportSelectedCount, 2);
    c.selectUnrestrictedRoadImports();
    assert.equal(c.roadImportSelectedCount, 1);
    c.toggleRoadImport(candidate('private', true));
    assert.equal(c.roadImportSelectedCount, 2);
    c.toggleRoadImport(candidate('overlap', false, 'overlap'));
    assert.equal(c.roadImportSelectedCount, 2);
});

test('bulk selection respects both filters and never includes existing, deleted or overlapping candidates', () => {
    const c = controller();
    c.roadImportPreview = { candidates: [candidate('normal'), candidate('private', true), candidate('existing', false, 'existing'),
        candidate('overlap', true, 'overlap'), candidate('main', false, 'new', 'primary')] };
    c.roadImportFilter = 'internal'; c.roadImportAvailabilityFilter = 'available';
    c.selectAllRoadImports();
    assert.deepEqual(Array.from(c.roadImportSelectedKeys).sort(), ['normal', 'private']);
    assert.equal(c.roadImportRestrictedSelectedCount, 1);
    c.roadImportFilter = 'main'; c.selectAllRoadImports();
    assert.deepEqual(Array.from(c.roadImportSelectedKeys).sort(), ['main', 'normal', 'private']);
    c.clearRoadImports();
    assert.deepEqual(Array.from(c.roadImportSelectedKeys).sort(), ['normal', 'private']);
});

test('an orange restricted map candidate can be selected and deselected', () => {
    const c = controller(); const privateRoad = candidate('private', true);
    c.roadImportPreview = { candidates: [privateRoad] };
    c.toggleRoadImport(privateRoad);
    assert.equal(c.roadImportCandidates[0].selected, true);
    assert.equal(c.roadImportRestrictedSelectedCount, 1);
    c.toggleRoadImport(privateRoad);
    assert.equal(c.roadImportSelectedCount, 0);
});

test('blocked map candidates explain why they cannot be selected', () => {
    const c = controller(); let notice;
    c.notifications.info = (message) => { notice = message; };
    const overlap = { ...candidate('overlap', false, 'overlap'), availability_label: '与主路重叠' };
    c.roadImportPreview = { candidates: [overlap] };
    c.toggleRoadImport(overlap);
    assert.equal(c.roadImportSelectedCount, 0);
    assert.match(notice, /与主路重叠/);
    assert.equal(c.canSelectRoadImports, false);
});

test('hidden and stale map candidates cannot change the current selection', () => {
    const c = controller();
    const normal = candidate('normal'); const main = candidate('main', false, 'new', 'primary');
    c.roadImportPreview = { candidates: [normal, main] }; c.roadImportFilter = 'internal';
    c.toggleRoadImport(main); c.toggleRoadImport(candidate('unknown'));
    assert.equal(c.roadImportSelectedCount, 0);
    c.isCommittingRoadImport = true;
    c.toggleRoadImport(normal); c.selectAllRoadImports(); c.filterRoadImport({target:{value:'main'}});
    c.filterRoadImportAvailability({target:{value:'restricted'}});
    assert.equal(c.roadImportSelectedCount, 0); assert.equal(c.roadImportFilter, 'internal');
    assert.equal(c.roadImportAvailabilityFilter, 'all');
});

test('restricted-only filter and its commit send only selected new restricted roads', async () => {
    const c = controller(); let keys;
    c.roadImportPreview = { preview_token: 'token', candidates: [candidate('normal'), candidate('private', true), candidate('overlap', true, 'overlap')] };
    c.roadImportAvailabilityFilter = 'restricted'; c.selectAllRoadImports();
    assert.equal(c.roadImportCandidates.length, 1);
    c.fetch = { async post(path, body) { keys = body.candidate_keys; return {created_count:1,skipped_count:0}; } };
    await c.commitRoadImport(); assert.deepEqual(Array.from(keys), ['private']);
});
test('switching GCC discards an in-flight old preview', async () => {
    const c = controller(); let reply;
    c.fetch = { post() { return new Promise((resolve) => { reply = resolve; }); } };
    const request = c.previewRoadImport();
    c.setSelectedServiceArea({ target: { value: 'other' } });
    reply({ candidates: [candidate('wrong-area')] }); await request;
    assert.equal(c.roadImportPreview, null);
    assert.equal(c.isLoadingRoadImport, false);
});
test('filtered commit sends only visible selected keys, then reloads local road records', async () => {
    const c = controller(); let payload; let reloaded = false;
    c.roadImportPreview = { preview_token: 'token', candidates: [candidate('internal'), candidate('main', false, 'new', 'primary')] };
    c.roadImportSelectedKeys = ['internal', 'main']; c.roadImportFilter = 'internal';
    c.fetch = { async post(path, body) { assert.equal(path, 'road-imports/commit'); payload = body; return { created_count: 1, skipped_count: 0 }; } };
    c.store.findAll = async () => { reloaded = true; };
    await c.commitRoadImport();
    assert.deepEqual(Array.from(payload.candidate_keys), ['internal']);
    assert.ok(reloaded); assert.equal(c.roadImportPreview, null);
});
test('failed commits retain the token and selection for safe retry', async () => {
    const c = controller(); c.roadImportPreview = { preview_token: 'token', candidates: [candidate('normal')] }; c.roadImportSelectedKeys = ['normal'];
    c.fetch = { async post() { throw new Error('连接中断'); } };
    await c.commitRoadImport();
    assert.equal(c.roadImportPreview.preview_token, 'token');
    assert.equal(c.roadImportSelectedCount, 1); assert.equal(c.isCommittingRoadImport, false); assert.equal(c.roadImportError, '连接中断');
});

test('automatic generation imports every new road in the selected GCC, independent of preview filters', async () => {
    const c = controller();
    const calls = [];
    c.roadImportFilter = 'internal';
    c.showRoadLayer = false;
    c.fetch = {
        async post(path, body) {
            calls.push({ path, body });
            return path.endsWith('/preview') ? {
                preview_token: 'token',
                candidates: [candidate('normal'), candidate('restricted-main', true, 'new', 'primary'),
                    candidate('existing', false, 'existing'), candidate('deleted', false, 'deleted'), candidate('overlap', false, 'overlap')],
            } : { created_count: 2, skipped_count: 0 };
        },
    };
    await c.generateRoadSections();
    assert.deepEqual(calls.map(({path}) => path), ['road-imports/preview', 'road-imports/commit']);
    assert.equal(calls[0].body.service_area_uuid, 'area');
    assert.equal(calls[1].body.service_area_uuid, 'area');
    assert.equal(calls[1].body.preview_token, 'token');
    assert.deepEqual(Array.from(calls[1].body.candidate_keys), ['normal', 'restricted-main']);
    assert.equal(c.activePanel, 'traffic');
    assert.equal(c.showRoadLayer, true);
});

test('automatic generation rejects a second click and skips the commit when roads already exist', async () => {
    const c = controller();
    let releasePreview;
    let posts = 0;
    c.fetch = { post() { posts++; return new Promise(resolve => { releasePreview = resolve; }); } };
    const pending = c.generateRoadSections();
    await c.generateRoadSections();
    c.setSelectedServiceArea({ target: { value: 'other' } });
    assert.equal(c.selectedServiceAreaUuid, 'area');
    releasePreview({ candidates: [candidate('existing', false, 'existing')] });
    await pending;
    assert.equal(posts, 1);
    assert.equal(c.canGenerateRoadSections, true);
});

test('a FleetOps service area with no explicit status is selectable for automatic generation', async () => {
    const c = controller();
    c.startTrafficRefresh = () => {};
    c.store.query = async (type, filter) => {
        assert.equal(type, 'service-area');
        assert.equal(filter.status, undefined);
        return { toArray: () => [{ id: 'inactive', status: 'inactive' }, { id: 'gcc', status: null }] };
    };
    await c.loadServiceAreas();
    assert.deepEqual(Array.from(c.serviceAreas, (area) => area.id), ['gcc']);
    assert.equal(c.selectedServiceAreaUuid, 'gcc');
    assert.equal(c.canGenerateRoadSections, true);
});


test('editing mode hides preview hit layers without discarding the import selection', () => {
    const c = controller(); c.roadImportPreview = {candidates:[candidate('new')]};
    c.roadImportSelectedKeys = ['new']; c.setImportSelectionMode(true);
    assert.equal(c.roadImportMapCandidates.length, 1);
    c.setImportSelectionMode(false); assert.equal(c.roadImportMapCandidates.length, 0);
    assert.deepEqual(Array.from(c.roadImportSelectedKeys), ['new']);
});

test('clicking an imported way edits the closest section and exits import selection', () => {
    const c = controller(); const road = {...candidate('old',false,'existing'),existing_road_uuid:'parent'};
    c.roadImportPreview = {candidates:[road]}; c.isImportSelectionMode = true;
    c.trafficRoads = [0,1].map(i=>({target_key:'section:'+i,road_segment_uuid:'parent',road_name:'My road',status:'open',
        geometry:{type:'LineString',coordinates:[[-135.73+i*.01,60.6],[-135.72+i*.01,60.6]]}}));
    c.toggleRoadImport(road,{lat:60.6,lng:-135.715});
    assert.equal(c.selectedTraffic.target_key,'section:1'); assert.equal(c.isImportSelectionMode,false);
    c.renameSelectedRoad(); assert.equal(c.renamingSegment.uuid,'parent'); assert.equal(c.renameValue,'My road');
});

test('a deleted import opens a cancellable recovery prompt and is never included in bulk import', async () => {
    const c = controller(); let writes=0; c.fetch={async post(){writes++;}};
    const road = {...candidate('deleted',false,'deleted'),existing_road_uuid:'old-uuid',existing_road_name:'Original name',
        existing_road_geometry:{type:'LineString',coordinates:[[-135.73,60.6],[-135.72,60.6]]}};
    c.roadImportPreview={candidates:[road]}; c.toggleRoadImport(road);
    assert.equal(c.roadRestorePrompt.uuid,'old-uuid'); assert.equal(c.roadRestorePrompt.name,'Original name');
    c.selectAllRoadImports(); assert.equal(c.roadImportSelectedCount,0);
    c.cancelRoadRestore(); await c.confirmRoadRestore(); assert.equal(writes,0);
});

test('explicit recovery posts the original UUID and geometry and refreshes traffic', async () => {
    const c=controller(); const geometry={type:'LineString',coordinates:[[-135.73,60.6],[-135.72,60.6]]};
    const calls=[]; c.fetch={async post(path,body){calls.push({path,body});}}; c.refreshNavigatingEntries=async()=>{};
    c.roadImportPreview={candidates:[{...candidate('deleted',false,'deleted'),existing_road_uuid:'original'}]};
    c.requestRoadRestore({uuid:'original',name:'Original',geometry}); await c.confirmRoadRestore();
    assert.equal(calls.length,1); assert.equal(calls[0].path,'road-segments/original/restore');
    assert.equal(calls[0].body.expected_geometry,geometry); assert.equal(calls[0].body.service_area_uuid,'area');
    assert.equal(c.roadImportPreview.candidates[0].availability,'existing'); assert.equal(c.roadRestorePrompt,null);
});

test('recovery cannot cross GCC selection and retains its prompt after an error', async () => {
    const c=controller(); let writes=0; c.fetch={async post(){writes++;throw Error('Failed');}};
    c.requestRoadRestore({uuid:'original',name:'Original',geometry:{type:'LineString',coordinates:[[0,0],[1,1]]}});
    c.selectedServiceAreaUuid='other'; await c.confirmRoadRestore(); assert.equal(writes,0);
    c.selectedServiceAreaUuid='area'; await c.confirmRoadRestore(); assert.equal(writes,1);
    assert.ok(c.roadRestorePrompt); assert.equal(c.isDeletingRoad,false);
});

test('rename sends the parent UUID even when no Ember parent record was loaded', async () => {
    const c=controller(); const calls=[]; c.fetch={async patch(path,body){calls.push({path,body});}};
    c.selectTraffic({target_key:'section:2',road_segment_uuid:'parent',name:'Original · 第2段',road_name:'Original'});
    c.renameSelectedRoad(); assert.equal(c.renameValue,'Original'); c.renameValue='  北门连接道  ';
    c.roadImportPreview={candidates:[{...candidate('old',false,'existing'),existing_road_uuid:'parent'}]};
    await c.saveRename(); assert.equal(calls[0].path,'road-segments/parent/update');
    assert.equal(calls[0].body.name,'北门连接道'); assert.equal(c.roadImportPreview.candidates[0].name,'北门连接道');
    assert.equal(c.isRenameMode,false);
});
