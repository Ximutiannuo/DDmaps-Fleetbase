const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const assert = require('node:assert/strict');
function load(file, name, globals) {
    const source = fs.readFileSync(path.join(__dirname, '..', file), 'utf8')
        .replace(/^import .*;\r?\n/gm, '').replace(/@(tracked|service|action)\s+/g, '')
        .replace('export default class', 'class');
    const context = vm.createContext({ Controller: class {}, Component: class {}, AbortController,
        setTimeout, clearTimeout, ...globals });
    vm.runInContext(source + '\nglobalThis.Subject = ' + name, context);
    return context.Subject;
}
function controller(response) {
    const storage = new Map([['cs-driver-session-token', 'fixture-token']]);
    const Subject = load('app/controllers/driver-track.js', 'DriverTrackController', {
        fetch: async () => { if (response instanceof Error) throw response; return response; },
        localStorage: { getItem: (key) => storage.get(key), removeItem: (key) => storage.delete(key) },
    });
    return { subject: new Subject(), storage };
}
for (const response of [new Error('offline'), { status: 503, ok: false }]) {
    test('transient session check failure preserves credential and shows retry', async () => {
        const { subject, storage } = controller(response);
        await subject.validateSession('fixture-token');
        assert.ok(subject.sessionError);
        assert.equal(storage.size, 1);
        assert.equal(subject.isLoading, false);
    });
}
test('confirmed invalid session clears the saved credential', async () => {
    const { subject, storage } = controller({ status: 401, ok: false });
    await subject.validateSession('fixture-token');
    assert.equal(storage.size, 0);
    assert.equal(subject.hasActiveSession, false);
    assert.equal(subject.sessionError, null);
});
test('valid session opens tracking', async () => {
    const { subject } = controller({ status: 200, ok: true });
    await subject.validateSession('fixture-token');
    assert.equal(subject.hasActiveSession, true);
});
test('failed exit retains GPS and credential and does not report success', async () => {
    let stopped = false, exited = false, removed = false;
    const Subject = load('app/components/driver-track/tracking-view.js', 'DriverTrackTrackingViewComponent', {
        fetch: async () => ({ ok: false, status: 503 }),
        localStorage: { removeItem: () => { removed = true; } }, Date,
    });
    const subject = Object.create(Subject.prototype);
    Object.assign(subject, { sessionToken: 'fixture-token', stopTracking: () => { stopped = true; }, args: { onExited: () => { exited = true; } } });
    await subject.doExit();
    assert.equal(subject.sessionToken, 'fixture-token');
    assert.equal(stopped || exited || removed, false);
    assert.ok(subject.error);
});
