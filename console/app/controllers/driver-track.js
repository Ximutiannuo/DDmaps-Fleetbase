import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const TRACKING_API = 'construction-site/int/v1/tracking';
const STORAGE_KEY = 'cs-driver-session-token';

export default class DriverTrackController extends Controller {
    @tracked hasActiveSession = false;
    @tracked isLoading = true;
    @tracked sessionError = null;

    @action
    startSessionCheck() {
        this.isLoading = true;
        this.sessionError = null;
        const token = localStorage.getItem(STORAGE_KEY);
        if (token) {
            this.validateSession(token);
        } else {
            this.hasActiveSession = false;
            this.isLoading = false;
        }
    }

    @action
    onRegistered() {
        this.hasActiveSession = true;
    }

    @action
    onExited() {
        this.hasActiveSession = false;
    }

    async validateSession(token) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(`/${TRACKING_API}/status`, {
                headers: { Authorization: `Bearer ${token}` },
                signal: controller.signal,
            });
            if (response.status === 401) {
                localStorage.removeItem(STORAGE_KEY);
                this.hasActiveSession = false;
            } else if (response.ok) {
                this.hasActiveSession = true;
            } else {
                throw new Error('Session check unavailable');
            }
        } catch (e) {
            this.sessionError = '暂时无法连接服务器。入场凭证已保留，请恢复网络后重试。';
        } finally {
            clearTimeout(timeout);
            this.isLoading = false;
        }
    }
}
