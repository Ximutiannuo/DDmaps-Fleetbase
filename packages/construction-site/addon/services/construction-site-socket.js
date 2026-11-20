import Service from '@ember/service';
import { inject as service } from '@ember/service';

export default class ConstructionSiteSocketService extends Service {
    @service socket;
    @service currentUser;
    handlers = new Map();
    closeChannel = null;
    company = null;

    subscribe() {
        const company = this.currentUser?.companyId;
        if (!company) return;
        if (this.closeChannel && this.company === company) return;
        this.closeChannel?.();
        this.company = company;
        this.closeChannel = this.socket.listen('company.' + company, (output) => {
            const event = output?.event || output?.data?.event;
            for (const callback of this.handlers.get(event) || []) callback(output?.data ?? output);
        });
    }

    emit(event, data) { for (const callback of this.handlers.get(event) || []) callback(data); }

    on(event, callback) {
        if (!this.handlers.has(event)) this.handlers.set(event, new Set());
        this.handlers.get(event).add(callback);
        this.subscribe();
        return () => {
            this.handlers.get(event)?.delete(callback);
            if (this.handlers.get(event)?.size === 0) this.handlers.delete(event);
            if (!this.handlers.size) this.unsubscribe();
        };
    }

    unsubscribe() {
        if (this.handlers.size) return;
        this.closeChannel?.();
        this.closeChannel = null;
        this.company = null;
    }

    willDestroy() {
        this.handlers.clear();
        this.unsubscribe();
        super.willDestroy(...arguments);
    }
}
