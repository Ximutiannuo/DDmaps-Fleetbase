const UPDATE_URL = '/construction-site/int/v1/tracking/update';
const FRESH_MS = 90000;

/** Keep only the latest position, acknowledge server success, and retry transient failures. */
export default class GpsUploader {
    constructor({ token, onStatus = () => {}, request = (...args) => fetch(...args), now = Date.now,
        setTimer = (...args) => setTimeout(...args), clearTimer = (...args) => clearTimeout(...args),
        isOnline = () => navigator.onLine }) {
        Object.assign(this, { token, onStatus, request, now, setTimer, clearTimer, isOnline });
        this.pending = null;
        this.timer = null;
        this.controller = null;
        this.lastAttempt = -Infinity;
        this.lastObserved = -Infinity;
        this.failures = 0;
        this.inFlight = false;
        this.stopped = false;
    }

    enqueue(position) {
        if (this.stopped) return;
        const timestamp = position.timestamp ?? this.now();
        if (!Number.isFinite(timestamp) || timestamp <= this.lastObserved) return;
        const { latitude, longitude, heading, speed, accuracy } = position.coords;
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
        this.lastObserved = timestamp;
        this.pending = { latitude, longitude, timestamp, observed_at: new Date(timestamp).toISOString() };
        for (const [key, value] of Object.entries({ heading, speed, accuracy })) {
            if (Number.isFinite(value) && value >= 0) this.pending[key] = value;
        }
        if (!this.inFlight && this.timer === null) {
            this.onStatus({ state: 'queued' });
            this.schedule(Math.max(0, 5000 - (this.now() - this.lastAttempt)));
        }
    }

    schedule(delay) {
        if (this.stopped || this.timer !== null) return;
        this.timer = this.setTimer(() => {
            this.timer = null;
            void this.flush();
        }, delay);
    }

    resume() {
        if (this.stopped || this.inFlight || !this.pending) return;
        this.clearTimer(this.timer);
        this.timer = null;
        this.schedule(Math.max(0, 5000 - (this.now() - this.lastAttempt)));
    }

    async flush() {
        if (this.stopped || this.inFlight || !this.pending) return;
        this.clearTimer(this.timer);
        this.timer = null;
        const sample = this.pending;
        if (this.now() - sample.timestamp > FRESH_MS) {
            this.pending = null;
            this.onStatus({ state: 'stale' });
            return;
        }
        if (!this.isOnline()) {
            this.onStatus({ state: 'offline' });
            this.schedule(5000);
            return;
        }
        this.lastAttempt = this.now();
        this.inFlight = true;
        this.controller = new AbortController();
        const timeout = this.setTimer(() => this.controller?.abort(), 10000);
        this.onStatus({ state: 'uploading' });
        try {
            const body = new URLSearchParams();
            for (const [key, value] of Object.entries(sample)) {
                if (key !== 'timestamp') body.append(key, value);
            }
            const response = await this.request(UPDATE_URL, {
                method: 'POST', headers: { Authorization: 'Bearer ' + this.token,
                    'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(), signal: this.controller.signal,
            });
            if (this.stopped) return;
            if (response.status === 401 || response.status === 403) {
                this.onStatus({ state: 'expired' });
                this.stop();
                return;
            }
            if (response.status === 422) {
                if (this.pending === sample) this.pending = null;
                // A corrected device clock may produce an earlier, now valid timestamp.
                this.lastObserved = this.pending?.timestamp ?? -Infinity;
                this.onStatus({ state: 'rejected' });
                return;
            }
            const result = await response.json();
            if (this.stopped) return;
            const acknowledged = Date.parse(result.gps_observed_at);
            if (!response.ok || result.status !== 'ok'
                || !(acknowledged >= Math.floor(sample.timestamp / 1000) * 1000)) {
                throw new Error('Position was not acknowledged');
            }
            if (this.pending === sample) this.pending = null;
            this.failures = 0;
            this.onStatus({ state: this.pending ? 'queued' : 'confirmed',
                confirmedAt: result.gps_received_at, observedAt: result.gps_observed_at });
        } catch {
            if (this.stopped) return;
            this.failures++;
            this.onStatus({ state: this.isOnline() ? 'retry' : 'offline' });
        } finally {
            this.clearTimer(timeout);
            this.controller = null;
            this.inFlight = false;
            if (!this.stopped && this.pending) {
                this.schedule(Math.min(30000, 5000 * 2 ** Math.min(this.failures, 3)));
            }
        }
    }

    stop() {
        this.stopped = true;
        this.clearTimer(this.timer);
        this.timer = null;
        this.pending = null;
        this.controller?.abort();
    }
}
