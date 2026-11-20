// Standalone behavioral checks; uses Node's built-in runner without an Ember server.
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');

const source = readFileSync(resolve(__dirname, '../app/utils/osrm-navigation.js'), 'utf8')
    .replace(/^import .*;\r?\n/gm, '')
    .replace('const decode = polyline.decode;', 'const decode = () => [];')
    .replace(/^export /gm, '');
const context = vm.createContext({ Math, config: {}, fetch: undefined });
vm.runInContext(source, context);

test('driver in the middle of a long road is on-route', () => {
    assert.ok(context.distanceToRoute(60.6, -135.725, [[60.6, -135.73], [60.6, -135.72]]) < 0.01);
});

test('a driver away from the road is still detected off-route', () => {
    assert.ok(context.distanceToRoute(60.601, -135.725, [[60.6, -135.73], [60.6, -135.72]]) > 100);
});

test('navigation progress includes partial edge travel', () => {
    const road = [[60.6, -135.73], [60.6, -135.72]];
    const projection = context.projectOnRoute(60.6, -135.725, road);
    const length = context.haversineDistance(...road[0], ...road[1]);
    assert.ok(Math.abs(projection.traveledDistance - length / 2) < 0.01);
    assert.equal(projection.segmentIndex, 0);
});

test('duplicate coordinates do not produce NaN', () => {
    const projection = context.projectOnRoute(60.6, -135.725, [[60.6, -135.73], [60.6, -135.73], [60.6, -135.72]]);
    assert.ok(Number.isFinite(projection.distance));
    assert.ok(Number.isFinite(projection.traveledDistance));
});

test('route response retains off-road connectors and estimated travel time', async () => {
    context.fetch = async () => ({ ok: true, json: async () => ({ status: 'ok', route: {
        coordinates: [[60.6, -135.73], [60.6, -135.72]], total_distance: 560, total_duration: 240,
        optimization: 'travel_time', off_road_segments: [[[60.6, -135.74], [60.6, -135.73]]],
        legs: [{ start_index: 0, end_index: 1, travel_duration: 240, delay_seconds: 0 }],
    } }) });
    const route = await context.calculateSiteRoute(60.6, -135.73, 60.6, -135.72, 'test-only-token');
    assert.equal(route.totalDuration, 240);
    assert.equal(route.optimization, 'travel_time');
    assert.equal(route.off_road_segments.length, 1);
    assert.equal(route.legs.length, 1);
});

test('remaining time preserves future congestion and removes passed junction delay', () => {
    const coords = [[60.6, -135.73], [60.6, -135.725], [60.6, -135.72]];
    const length = context.haversineDistance(...coords[0], ...coords[1]);
    const route = { coordinates: coords, totalDistance: length * 2, totalDuration: 330,
        legs: [
            { start_index: 0, end_index: 1, travel_duration: 60, delay_seconds: 30 },
            { start_index: 1, end_index: 2, travel_duration: 240, delay_seconds: 0 },
        ],
        steps: [{ distance: length }, { distance: length }, { distance: 0 }],
    };
    const before = context.navigationProgress(route, 60.6, -135.7275);
    assert.ok(Math.abs(before.remainingDuration - 300) < 0.01);
    assert.ok(Math.abs(before.stepRemainingDistance - length / 2) < 0.01);
    assert.equal(before.stepIndex, 0);
    const junction = context.navigationProgress(route, 60.6, -135.725);
    assert.ok(Math.abs(junction.remainingDuration - 240) < 0.01);
    assert.equal(junction.stepIndex, 1);
    const after = context.navigationProgress(route, 60.6, -135.7225);
    assert.ok(Math.abs(after.remainingDuration - 120) < 0.01);
    const arrived = context.navigationProgress(route, 60.6, -135.72);
    assert.ok(arrived.remainingDuration < 0.01);
    assert.ok(arrived.remainingDistance < 0.01);
    assert.equal(arrived.stepIndex, 2);
});

test('maneuvers include left, right and U-turn labels', () => {
    assert.equal(context.maneuverToIcon('turn', 'left').label, '左转');
    assert.equal(context.maneuverToIcon('turn', 'right').label, '右转');
    assert.equal(context.maneuverToIcon('turn', 'uturn').label, '掉头');
});

test('new route initializes distance and ETA before the next GPS callback', () => {
    const componentSource = readFileSync(resolve(__dirname, '../app/components/driver-track/tracking-view.js'), 'utf8')
        .replace(/^import .*;\r?\n/gm, '')
        .replace(/^\s*@service .*;\r?\n/gm, '')
        .replace(/@(?:tracked|action)\s+/g, '')
        .replace('export default class', 'class');
    const layer = () => ({ addTo() { return this; }, getBounds() { return {}; } });
    const componentContext = vm.createContext({ Component: class {}, navigationProgress: context.navigationProgress,
        L: { polyline: layer, marker: layer, divIcon: (value) => value } });
    vm.runInContext(componentSource, componentContext);
    const view = Object.create(vm.runInContext('DriverTrackTrackingViewComponent.prototype', componentContext));
    const coordinates = [[60.6, -135.73], [60.6, -135.72]];
    const distance = context.haversineDistance(...coordinates[0], ...coordinates[1]);
    Object.assign(view, { mapInstance: { fitBounds() {}, removeLayer() {} }, offRoadPolylines: [],
        position: { coords: { latitude: 60.6, longitude: -135.73 } },
        currentRoute: { coordinates, totalDistance: distance, totalDuration: 240, steps: [{ distance }, { distance: 0 }],
            legs: [{ start_index: 0, end_index: 1, travel_duration: 240, delay_seconds: 0 }] },
        navRemainingDistance: 0, navRemainingDuration: 0 });
    view.renderRouteOverlay();
    assert.ok(Math.abs(view.navRemainingDistance - distance) < 0.01);
    assert.equal(view.navRemainingDuration, 240);
    assert.ok(Math.abs(view.navStepRemainingDistance - distance) < 0.01);
});
