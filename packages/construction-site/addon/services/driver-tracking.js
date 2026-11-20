import GpsUploader from '../utils/gps-uploader';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

const TRACKING_API = 'construction-site/int/v1/tracking';
const STORAGE_KEY = 'cs-driver-session-token';

export default class DriverTrackingService extends Service {
    @tracked sessionToken = null;
    @tracked currentPosition = null;
    @tracked isTracking = false;
    @tracked error = null;
    @tracked status = null;

    watchId = null;
    lastSendTime = 0;

    restoreSession() {
        this.sessionToken = localStorage.getItem(STORAGE_KEY);
        return this.sessionToken;
    }

    saveSession(token) {
        this.sessionToken = token;
        localStorage.setItem(STORAGE_KEY, token);
    }

    clearSession() {
        this.sessionToken = null;
        this.status = null;
        localStorage.removeItem(STORAGE_KEY);
    }

    async register(data) {
        const formData = new URLSearchParams();
        for (const [key, value] of Object.entries(data)) {
            if (value !== null && value !== undefined) {
                formData.append(key, value);
            }
        }

        const response = await fetch(`/${TRACKING_API}/register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString(),
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || errorData.error || '登记失败');
        }

        const result = await response.json();
        if (result.session_token) {
            this.saveSession(result.session_token);
        }
        return result;
    }

    async fetchStatus() {
        if (!this.sessionToken) {
            return null;
        }

        const response = await fetch('/' + TRACKING_API + '/status', {
            headers: { Authorization: 'Bearer ' + this.sessionToken }, signal: AbortSignal.timeout(10000),
        });
        if (response.status === 401) { this.clearSession(); return null; }
        if (!response.ok) throw new Error('暂时无法确认入场状态，请重试');
        const result = await response.json();
        this.status = result.entry_registration || null;
        return this.status;
    }

    startTracking() {
        if (!navigator.geolocation) {
            this.error = '设备不支持定位';
            return;
        }

        if (this.isTracking) return;
        this.uploader = new GpsUploader({ token: this.sessionToken, onStatus: ({ state }) => {
            this.error = state === 'confirmed' ? null : ({ offline: '网络离线，定位等待上传', retrying: '定位上传失败，正在重试', expired: '入场凭证已失效，请重新登记', stale: '离线定位已过期', rejected: '定位数据被拒绝，请检查设备时间' }[state] || null);
            if (state === 'expired') { this.stopTracking(); this.clearSession(); }
        } });
        this.isTracking = true;
        this.error = null;

        this.watchId = navigator.geolocation.watchPosition(
            (position) => {
                this.currentPosition = position;
                this.sendUpdate(position);
            },
            (error) => {
                this.error = this.getLocationErrorMessage(error);
            },
            { enableHighAccuracy: true, maximumAge: 3000, timeout: 10000 }
        );
    }

    sendUpdate(position) { this.uploader?.enqueue(position); }

    stopTracking() {
        this.uploader?.stop();
        this.uploader = null;
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.watchId = null;
        }
        this.isTracking = false;
    }

    async exit() {
        if (!this.sessionToken) {
            return;
        }

        const response = await fetch('/' + TRACKING_API + '/exit', {
            method: 'POST', headers: { Authorization: 'Bearer ' + this.sessionToken }, signal: AbortSignal.timeout(10000),
        });
        if (!response.ok && response.status !== 401) throw new Error('退场未确认，请检查网络后重试');
        this.stopTracking();
        this.clearSession();
    }

    getLocationErrorMessage(error) {
        const messages = {
            1: '定位权限被拒绝，请在浏览器设置中允许定位',
            2: '定位不可用，请检查GPS是否开启',
            3: '定位超时，请重试',
        };
        return messages[error.code] || '定位失败';
    }
}
