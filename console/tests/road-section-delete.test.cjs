const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = readFileSync(resolve(__dirname, '../../packages/construction-site/addon/controllers/live-map.js'), 'utf8')
    .replace(/^import .*;\r?\n/gm, '').replace(/@service \w+;/g, '').replace(/@tracked /g, '').replace(/@action /g, '')
    .replace('export default class LiveMapController', 'class LiveMapController');
const context = vm.createContext({ Controller: class {}, setInterval, clearInterval, setTimeout, clearTimeout, Date, Set, console });
vm.runInContext(source + '\n globalThis.DeleteController = LiveMapController;', context);
function controller() {
    const c = new context.DeleteController();
    c.selectedServiceAreaUuid = 'area';
    c.notifications = { info() {}, success() {}, error() {} };
    c.store = { unloadRecord() {} };
    c.loadTraffic = async () => {};
    c.refreshNavigatingEntries = async () => {};
    c.trafficRoads = [section(), { ...section(), target_key: 'section:parent:neighbor' }];
    c.selectTraffic(c.trafficRoads[0]);
    return c;
}
function section() {
    return { target_key: 'section:parent:middle', road_segment_uuid: 'parent', name: '主路 · 第2段',
        start_label: 'A交汇点', end_label: 'B交汇点', length_meters: 200, geometry: { type: 'LineString', coordinates: [[1, 2], [3, 4]] } };
}
test('opening and cancelling the section confirmation never writes data', () => {
    const c = controller(); c.fetch = { post() { throw new Error('Unexpected mutation'); } };
    c.requestSectionRemoval(); assert.equal(c.roadDeletePrompt.kind, 'section');
    assert.equal(c.roadDeletePrompt.start, 'A交汇点');
    c.cancelRoadRemoval(); assert.equal(c.roadDeletePrompt, null);
    assert.equal(c.trafficRoads.length, 2);
});
test('confirmed section deletion sends its exact geometry and preserves its neighbor', async () => {
    const c = controller(); let request; let refreshed = false;
    c.fetch = { async post(path, body) { request = { path, body }; } };
    c.loadTraffic = async () => { refreshed = true; };
    c.requestSectionRemoval(); await c.confirmRoadRemoval();
    assert.equal(request.path, 'road-sections/remove');
    assert.equal(request.body.target_key, section().target_key);
    assert.deepEqual(request.body.expected_geometry, section().geometry);
    assert.equal(c.trafficRoads[0].target_key, 'section:parent:neighbor');
    assert.equal(c.selectedTraffic, null); assert.ok(c.canUndoSectionRemoval); assert.ok(refreshed);
});
test('double confirmation and GCC switches are blocked during deletion', async () => {
    const c = controller(); let reply; let count = 0;
    c.fetch = { post() { count++; return new Promise((resolve) => { reply = resolve; }); } };
    c.requestSectionRemoval(); const work = c.confirmRoadRemoval();
    await c.confirmRoadRemoval(); c.setSelectedServiceArea({ target: { value: 'other' } });
    assert.equal(count, 1); assert.equal(c.selectedServiceAreaUuid, 'area');
    reply({}); await work; assert.equal(c.isDeletingRoad, false);
});
test('a failed delete keeps the selected section and confirmation available for retry', async () => {
    const c = controller(); let error;
    c.notifications.error = (message) => { error = message; };
    c.fetch = { async post() { throw new Error('路段范围已改变'); } };
    c.requestSectionRemoval(); await c.confirmRoadRemoval();
    assert.equal(c.trafficRoads.length, 2); assert.ok(c.selectedTraffic); assert.ok(c.roadDeletePrompt);
    assert.equal(c.lastRemovedSection, null); assert.equal(error, '路段范围已改变');
});
test('whole-road deletion uses the parent endpoint and refreshes traffic immediately', async () => {
    const c = controller(); let removedRecord; let endpoint;
    c.store.unloadRecord = (record) => { removedRecord = record; };
    c.fetch = { async delete(path) { endpoint = path; } };
    const parent = { id: 'parent', name: '主路' };
    c.requestRoadRemoval(parent); await c.confirmRoadRemoval();
    assert.equal(endpoint, 'road-segments/parent'); assert.equal(removedRecord, parent);
    assert.equal(c.trafficRoads.length, 0); assert.equal(c.roadDeletePrompt, null);
});
test('undo restores the removal operation and clears its undo notice', async () => {
    const c = controller(); let endpoint;
    c.lastRemovedSection = { ...section(), service_area_uuid: 'area' };
    c.fetch = { async post(path) { endpoint = path; } };
    await c.restoreRemovedSection(c.lastRemovedSection);
    assert.equal(endpoint, 'road-sections/restore'); assert.equal(c.lastRemovedSection, null);
});
test('a stale polling reply cannot put a removed section back on the map', async () => {
    const c = controller(); c.loadTraffic = context.DeleteController.prototype.loadTraffic;
    let oldReply; let count = 0;
    c.fetch = { get() { count++; return count === 1 ? new Promise((resolve) => { oldReply = resolve; })
        : Promise.resolve({ road_segments: [], removed_sections: [section()], generated_at: new Date().toISOString() }); } };
    const old = c.loadTraffic(); await c.loadTraffic();
    oldReply({ road_segments: [section()], generated_at: new Date().toISOString() }); await old;
    assert.equal(c.trafficRoads.length, 0); assert.equal(c.removedTrafficRoads.length, 1);
});
