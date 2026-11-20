import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import config from 'ember-get-config';

export default class SocketService extends Service {
    @service fetch;
    @service session;
    @tracked channels = [];
    @tracked error = null;
    subscriptions = new Map();
    authPromise = null;
    authScope = null;
    authUntil = 0;
    authTimer = null;
    driverToken = null;

    constructor() {
        super(...arguments);
        const socketConfig = { ...config.socket };
        socketConfig.hostname ||= window.location.hostname;
        socketConfig.secure = window.location.protocol === 'https:';
        socketConfig.port = window.location.port || (socketConfig.secure ? 443 : 80);
        // Socket JWTs stay in memory; company/driver switches must not reuse saved credentials.
        socketConfig.authEngine = { loadToken: async () => null, saveToken: async () => {}, removeToken: async () => {} };
        this.socket = socketClusterClient.create(socketConfig);
        this.connectionConsumer = this.socket.listener('connect').createConsumer();
        void (async () => {
            for await (const event of this.connectionConsumer) {
                if (this.isDestroying || this.isDestroyed) break;
                this.authUntil = 0;
                if (this.subscriptions.size && !event.isAuthenticated) this.authenticate().catch(() => this.retryAuthentication());
            }
        })();
    }

    instance() { return this.socket; }
    useDriverToken(token) { this.driverToken = token; this.authUntil = 0; }

    retryAuthentication() {
        clearTimeout(this.authTimer);
        if (!this.subscriptions.size || this.isDestroying || this.isDestroyed) return;
        this.error = '实时连接暂时不可用，正在重试';
        this.authTimer = setTimeout(() => this.authenticate().catch(() => this.retryAuthentication()), 10000);
    }

    async authenticate() {
        const scope = this.driverToken || (this.session.isAuthenticated ? this.session.data.authenticated.token : null);
        if (!scope) throw new Error('Socket authentication required');
        if (this.authScope === scope && Date.now() < this.authUntil && this.socket.authState === 'authenticated') return;
        if (this.authPromise) return this.authPromise;
        this.authPromise = (async () => {
            let data;
            if (!this.driverToken && this.session.isAuthenticated) {
                data = await this.fetch.get('socket-token', {}, { namespace: 'construction-site/int/v1' });
            } else {
                const response = await window.fetch('/construction-site/int/v1/tracking/socket-token', {
                    headers: { Authorization: 'Bearer ' + this.driverToken },
                });
                if (!response.ok) throw new Error('Socket authentication failed');
                data = await response.json();
            }
            await this.socket.authenticate(data.token);
            this.authScope = scope;
            this.authUntil = Date.now() + 240000;
            this.error = null;
            clearTimeout(this.authTimer);
            this.authTimer = setTimeout(() => {
                this.authUntil = 0;
                if (this.subscriptions.size) this.authenticate().catch(() => this.retryAuthentication());
            }, 240000);
        })();
        try { await this.authPromise; } finally { this.authPromise = null; }
    }

    listen(channelId, callback) {
        let item = this.subscriptions.get(channelId);
        if (!item) {
            item = { id: channelId, callbacks: new Set(), closed: false, channel: null, iterator: null };
            this.subscriptions.set(channelId, item);
            this.channels = [...this.subscriptions.values()];
            this.startSubscription(item);
        }
        item.callbacks.add(callback);
        return () => {
            item.callbacks.delete(callback);
            if (!item.callbacks.size) this.closeSubscription(item);
        };
    }

    async startSubscription(item) {
        try {
            await this.authenticate();
            if (item.closed) return;
            item.channel = this.socket.subscribe(item.id, { waitForAuth: true });
            item.iterator = item.channel[Symbol.asyncIterator]();
            for await (const output of item.iterator) {
                if (item.closed) break;
                for (const callback of item.callbacks) callback(output);
            }
        } catch (error) {
            if (!item.closed) {
                this.error = error.message;
                item.retryTimer = setTimeout(() => this.startSubscription(item), 10000);
            }
        }
    }

    closeSubscription(item) {
        item.closed = true;
        clearTimeout(item.retryTimer);
        item.callbacks.clear();
        item.iterator?.return?.();
        item.channel?.unsubscribe();
        if (this.subscriptions.get(item.id) === item) this.subscriptions.delete(item.id);
        this.channels = [...this.subscriptions.values()];
        if (!this.subscriptions.size) clearTimeout(this.authTimer);
    }

    closeChannels() {
        for (const item of [...this.subscriptions.values()]) this.closeSubscription(item);
    }

    willDestroy() {
        clearTimeout(this.authTimer);
        this.closeChannels();
        this.connectionConsumer.kill();
        this.socket.disconnect();
        super.willDestroy(...arguments);
    }
}
