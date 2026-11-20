const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = readFileSync(resolve(__dirname, '../../packages/ember-core/addon/utils/gps-uploader.js'), 'utf8')
    .replace('export default class', 'class') + '\n globalThis.GpsUploader = GpsUploader;';
const context = vm.createContext({ Date, Number, URLSearchParams, AbortController, fetch: undefined });
vm.runInContext(source, context);

test('browser fetch and timers retain their global receiver during retry and exit', async () => {
    const browserContext = vm.createContext({ Date, Number, URLSearchParams, AbortController });
    vm.runInContext(`
        let timerSequence = 0;
        let requestCount = 0;
        let clearCount = 0;
        const navigator = { onLine: true };
        function setTimeout() {
            if (this !== globalThis) throw new TypeError('Illegal invocation');
            return ++timerSequence;
        }
        function clearTimeout() {
            if (this !== globalThis) throw new TypeError('Illegal invocation');
            clearCount++;
        }
        async function fetch(url, options) {
            if (this !== globalThis) throw new TypeError('Illegal invocation');
            requestCount++;
            return { status: 200, ok: true, json: async () => ({
                status: 'ok', gps_observed_at: new URLSearchParams(options.body).get('observed_at'),
                gps_received_at: new Date().toISOString(),
            }) };
        }
    ` + source, browserContext);
    const uploader = new browserContext.GpsUploader({ token: 'fixture-only' });
    uploader.enqueue({ timestamp: Date.now(), coords: { latitude: 60.6, longitude: -135.73 } });
    uploader.resume();
    await uploader.flush();
    assert.equal(uploader.pending, null);
    uploader.stop();
    assert.equal(uploader.stopped, true);
    assert.equal(vm.runInContext('requestCount', browserContext), 1);
    assert.ok(vm.runInContext('clearCount', browserContext) >= 3);
});

function harness(request, online = true) {
    let now = Date.parse('2026-10-08T08:00:00Z');
    let timerId = 0;
    const timers = new Map();
    const states = [];
    const uploader = new context.GpsUploader({
        token: 'fixture-only', request, now: () => now, isOnline: () => online,
        onStatus: (value) => states.push(value),
        setTimer: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimer: (id) => timers.delete(id),
    });
    return { uploader, states, timers,
        position: (seconds = 0) => ({ timestamp: now + seconds * 1000,
            coords: { latitude: 60.6, longitude: -135.73 + seconds / 10000, accuracy: 10, speed: 2 } }),
        advance: (ms) => { now += ms; },
        online: (value) => { online = value; },
    };
}
function acknowledged(body, status = 200) {
    return { status, ok: status === 200, json: async () => ({
        status: status === 200 ? 'ok' : 'error',
        gps_observed_at: new URLSearchParams(body).get('observed_at'),
        gps_received_at: '2026-10-08T08:00:10Z',
    }) };
}

test('HTTP 500 is retried and confirmed only after server acknowledgement', async () => {
    let calls = 0;
    const h = harness(async (_url, options) => acknowledged(options.body, ++calls === 1 ? 500 : 200));
    h.uploader.enqueue(h.position());
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'retry');
    assert.ok(h.uploader.pending);
    assert.equal(h.states.some((s) => s.confirmedAt), false);
    h.advance(10000);
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'confirmed');
    assert.equal(h.states.at(-1).confirmedAt, '2026-10-08T08:00:10Z');
    assert.equal(h.uploader.pending, null);
    assert.equal(h.timers.size, 0);
});

test('only the latest position is sent after offline recovery', async () => {
    const bodies = [];
    const h = harness(async (_url, options) => { bodies.push(options.body); return acknowledged(options.body); }, false);
    h.uploader.enqueue(h.position());
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'offline');
    assert.equal(bodies.length, 0);
    h.uploader.enqueue(h.position(10));
    h.uploader.enqueue(h.position(20));
    h.advance(20000);
    h.online(true);
    h.uploader.resume();
    await h.uploader.flush();
    assert.equal(bodies.length, 1);
    assert.ok(Math.abs(Number(new URLSearchParams(bodies[0]).get('longitude')) - h.position(20).coords.longitude) < 1e-9);
    assert.equal(h.states.at(-1).state, 'confirmed');
});

test('an in-flight acknowledgement cannot delete a newer pending sample', async () => {
    let resolveRequest;
    let body;
    const h = harness(async (_url, options) => {
        body = options.body;
        return new Promise((resolveResponse) => { resolveRequest = resolveResponse; });
    });
    h.uploader.enqueue(h.position());
    const inFlight = h.uploader.flush();
    h.uploader.enqueue(h.position(5));
    resolveRequest(acknowledged(body));
    await inFlight;
    assert.equal(h.states.at(-1).state, 'queued');
    assert.equal(h.uploader.pending.longitude, -135.7295);
    assert.equal(h.timers.size, 1);
});

test('invalid session stops retries and requires registration', async () => {
    const h = harness(async () => ({ status: 401, ok: false }));
    h.uploader.enqueue(h.position());
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'expired');
    assert.equal(h.uploader.stopped, true);
    assert.equal(h.timers.size, 0);
    h.uploader.enqueue(h.position(10));
    assert.equal(h.uploader.pending, null);
});

test('expired offline samples are discarded instead of reported as fresh', async () => {
    let calls = 0;
    const h = harness(async () => { calls++; });
    h.uploader.enqueue(h.position());
    h.advance(91000);
    await h.uploader.flush();
    assert.equal(calls, 0);
    assert.equal(h.states.at(-1).state, 'stale');
    assert.equal(h.uploader.pending, null);
});

test('a timed-out upload is aborted and retried', async () => {
    const h = harness(async (_url, options) => new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('aborted')));
    }));
    h.uploader.enqueue(h.position());
    const inFlight = h.uploader.flush();
    const timeout = [...h.timers.values()].find((timer) => timer.delay === 10000);
    timeout.callback();
    await inFlight;
    assert.equal(h.states.at(-1).state, 'retry');
    assert.ok(h.uploader.pending);
});

test('old server acknowledgement is never treated as current upload success', async () => {
    const h = harness(async () => ({ status: 200, ok: true, json: async () => ({
        status: 'ok', gps_observed_at: '2026-10-08T07:59:50Z', gps_received_at: '2026-10-08T08:00:10Z',
    }) }));
    h.uploader.enqueue(h.position());
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'retry');
    assert.equal(h.states.some((s) => s.confirmedAt), false);
});

test('stop cancels pending work without calling a destroyed component', async () => {
    let resolveRequest;
    const h = harness(async () => new Promise((resolveResponse) => { resolveRequest = resolveResponse; }));
    h.uploader.enqueue(h.position());
    const inFlight = h.uploader.flush();
    const count = h.states.length;
    h.uploader.stop();
    resolveRequest({ status: 200, ok: true, json: async () => ({ status: 'ok' }) });
    await inFlight;
    assert.equal(h.states.length, count);
    assert.equal(h.timers.size, 0);
});

test('validation errors wait for a new sample rather than retrying invalid data forever', async () => {
    const h = harness(async () => ({ status: 422, ok: false }));
    h.uploader.enqueue(h.position());
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'rejected');
    assert.equal(h.uploader.pending, null);
    assert.equal(h.timers.size, 0);
});

test('correcting a device clock permits a new sample earlier than the rejected one', async () => {
    let calls = 0;
    const h = harness(async (_url, options) => ++calls === 1
        ? ({ status: 422, ok: false }) : acknowledged(options.body));
    h.uploader.enqueue(h.position(300));
    await h.uploader.flush();
    h.uploader.enqueue(h.position());
    assert.ok(h.uploader.pending);
    await h.uploader.flush();
    assert.equal(h.states.at(-1).state, 'confirmed');
    assert.equal(calls, 2);
});

