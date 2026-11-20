import polyline from '@fleetbase/ember-core/utils/polyline';

const decode = polyline.decode;
import config from 'ember-get-config';

const TRACKING_API = 'construction-site/int/v1/tracking';

/**
 * Calculate a route over the internal site road network.
 *
 * @param {number} startLat
 * @param {number} startLng
 * @param {number} endLat
 * @param {number} endLng
 * @param {string} token - driver session Bearer token
 * @returns {Promise<{coordinates: number[][], steps: object[], totalDistance: number, totalDuration: number}>}
 */
export async function calculateSiteRoute(startLat, startLng, endLat, endLng, token, options = {}) {
    const body = {
        start: { latitude: startLat, longitude: startLng },
        end: { latitude: endLat, longitude: endLng },
        is_navigating: options.isNavigating !== false,
    };

    if (options.destinationZoneUuid) {
        body.destination_zone_uuid = options.destinationZoneUuid;
    }

    const response = await fetch(`/${TRACKING_API}/route`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
    });

    const data = await response.json();
    if (!response.ok || data.status !== 'ok' || !data.route) {
        throw new Error(data.message || `路线计算失败: ${response.status}`);
    }

    const route = data.route;
    return {
        coordinates: route.coordinates,
        steps: route.steps || [],
        totalDistance: route.total_distance || 0,
        totalDuration: route.total_duration || 0,
        off_road_segments: route.off_road_segments || [],
        optimization: route.optimization,
        legs: route.legs || [],
    };
}

/**
 * Clear navigation session flags on the server.
 */
export async function stopSiteNavigation(token) {
    const response = await fetch(`/${TRACKING_API}/navigation/stop`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({}),
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.message || `停止导航失败: ${response.status}`);
    }
}

/**
 * Calculate a driving route from start to end via OSRM.
 *
 * @param {number} startLat
 * @param {number} startLng
 * @param {number} endLat
 * @param {number} endLng
 * @returns {Promise<{coordinates: number[][], steps: object[], totalDistance: number, totalDuration: number}>}
 */
export async function calculateRoute(startLat, startLng, endLat, endLng) {
    const host = config.osrm?.host || 'https://router.project-osrm.org';
    const url = `${host}/route/v1/driving/${startLng},${startLat};${endLng},${endLat}` +
        `?overview=full&geometries=polyline&steps=true&annotations=true`;

    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(`OSRM request failed: ${response.status}`);
    }

    const data = await response.json();
    if (!data.routes || data.routes.length === 0) {
        throw new Error('No route found');
    }

    const route = data.routes[0];
    const coordinates = decode(route.geometry);

    const steps = [];
    for (const leg of route.legs) {
        for (const step of leg.steps) {
            steps.push(mapStep(step));
        }
    }

    return {
        coordinates,
        steps,
        totalDistance: route.distance,
        totalDuration: route.duration,
    };
}

function mapStep(step) {
    return {
        maneuverType: step.maneuver?.type || 'turn',
        modifier: step.maneuver?.modifier || null,
        name: step.name || '',
        distance: step.distance || 0,
        duration: step.duration || 0,
        coordinates: decode(step.geometry || ''),
    };
}

/**
 * Haversine distance between two coordinates in meters.
 */
export function haversineDistance(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
        Math.sin(dLng / 2) * Math.sin(dLng / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function toRad(deg) {
    return deg * Math.PI / 180;
}

/**
 * Find the minimum distance from a point to the nearest point on a route polyline.
 *
 * @param {number} lat
 * @param {number} lng
 * @param {number[][]} routeCoords - array of [lat, lng] pairs
 * @returns {number} distance in meters
 */
export function distanceToRoute(lat, lng, routeCoords) {
    return projectOnRoute(lat, lng, routeCoords).distance;
}

/** Project onto road edges so sparse road vertices do not trigger false off-route alerts. */
export function projectOnRoute(lat, lng, routeCoords) {
    let closest = { distance: Infinity, traveledDistance: 0, segmentIndex: 0, coordinate: routeCoords[0] };
    let traveled = 0;
    const scale = Math.cos(lat * Math.PI / 180);
    if (routeCoords.length === 1) return { ...closest, distance: haversineDistance(lat, lng, ...routeCoords[0]) };
    for (let i = 1; i < routeCoords.length; i++) {
        const a = routeCoords[i - 1];
        const b = routeCoords[i];
        const dx = (b[1] - a[1]) * scale;
        const dy = b[0] - a[0];
        const lengthSquared = dx * dx + dy * dy;
        const t = lengthSquared === 0 ? 0 : Math.max(0, Math.min(1, ((lng - a[1]) * scale * dx + (lat - a[0]) * dy) / lengthSquared));
        const coordinate = [a[0] + t * dy, a[1] + t * (b[1] - a[1])];
        const distance = haversineDistance(lat, lng, ...coordinate);
        const edgeLength = haversineDistance(...a, ...b);
        if (distance < closest.distance) closest = { distance, traveledDistance: traveled + edgeLength * t, segmentIndex: i - 1, coordinate };
        traveled += edgeLength;
    }
    return closest;
}

/** Remaining time follows each road's cost; junction delay is removed after passing it. */
export function navigationProgress(route, lat, lng) {
    const projection = projectOnRoute(lat, lng, route.coordinates);
    const traveledDistance = Math.min(route.totalDistance, projection.traveledDistance);
    const remainingDistance = Math.max(0, route.totalDistance - traveledDistance);
    let remainingDuration = route.totalDuration * remainingDistance / (route.totalDistance || 1);
    if (route.legs?.length) {
        remainingDuration = 0;
        for (const leg of route.legs) {
            if (leg.end_index <= projection.segmentIndex) continue;
            const a = route.coordinates[leg.start_index];
            const b = route.coordinates[leg.end_index];
            const length = haversineDistance(...a, ...b);
            const fraction = leg.start_index === projection.segmentIndex && length > 0
                ? Math.min(1, haversineDistance(...a, ...projection.coordinate) / length) : 0;
            remainingDuration += (leg.travel_duration || 0) * (1 - fraction)
                + (fraction < 1 - 1e-6 ? (leg.delay_seconds || 0) : 0);
        }
    }
    const steps = route.steps || [];
    let stepIndex = 0;
    let stepEnd = 0;
    for (let i = 0; i < steps.length; i++) {
        stepEnd = steps[i].distance_end ?? stepEnd + (steps[i].distance || 0);
        stepIndex = i;
        if (traveledDistance < stepEnd - 0.01 || i === steps.length - 1) break;
    }
    return { ...projection, traveledDistance, remainingDistance, remainingDuration, stepIndex,
        stepRemainingDistance: Math.max(0, stepEnd - traveledDistance) };
}

/**
 * Find the index of the nearest route coordinate to the given position.
 *
 * @param {number} lat
 * @param {number} lng
 * @param {number[][]} routeCoords
 * @returns {number} index into routeCoords
 */
export function nearestRouteIndex(lat, lng, routeCoords) {
    let minDist = Infinity;
    let minIdx = 0;
    for (let i = 0; i < routeCoords.length; i++) {
        const d = haversineDistance(lat, lng, routeCoords[i][0], routeCoords[i][1]);
        if (d < minDist) {
            minDist = d;
            minIdx = i;
        }
    }
    return minIdx;
}

/**
 * Map OSRM maneuver type + modifier to an icon character and Chinese label.
 */
export function maneuverToIcon(type, modifier) {
    const key = `${type}:${modifier || ''}`;
    const map = {
        'turn:left': { icon: '\u21B0', label: '\u5DE6\u8F6C' },
        'turn:right': { icon: '\u21B1', label: '\u53F3\u8F6C' },
        'turn:uturn': { icon: '\u21B6', label: '掉头' },
        'turn:slight left': { icon: '\u2196', label: '\u7A0D\u5DE6\u8F6C' },
        'turn:slight right': { icon: '\u2197', label: '\u7A0D\u53F3\u8F6C' },
        'turn:sharp left': { icon: '\u21B0', label: '\u5411\u5DE6\u8F6C' },
        'turn:sharp right': { icon: '\u21B1', label: '\u5411\u53F3\u8F6C' },
        'straight:': { icon: '\u2191', label: '\u76F4\u884C' },
        'arrive:': { icon: '\uD83C\uDFC1', label: '\u5230\u8FBE\u76EE\u7684\u5730' },
        'depart:': { icon: '\uD83D\uDE97', label: '\u51FA\u53D1' },
        'roundabout:': { icon: '\u21BB', label: '\u73AF\u5C9B' },
        'merge:left': { icon: '\u21E0', label: '\u6C47\u5165\u5DE6\u4FA7' },
        'merge:right': { icon: '\u21E2', label: '\u6C47\u5165\u53F3\u4FA7' },
        'fork:left': { icon: '\u2196', label: '\u9760\u5DE6\u5206\u5C94' },
        'fork:right': { icon: '\u2197', label: '\u9760\u53F3\u5206\u5C94' },
        'fork:slight left': { icon: '\u2196', label: '\u9760\u5DE6\u5206\u5C94' },
        'fork:slight right': { icon: '\u2197', label: '\u9760\u53F3\u5206\u5C94' },
        'end of road:left': { icon: '\u21B0', label: '\u5DE6\u8F6C' },
        'end of road:right': { icon: '\u21B1', label: '\u53F3\u8F6C' },
        'new name:': { icon: '\u2191', label: '\u76F4\u884C' },
        'continue:': { icon: '\u2191', label: '\u7EE7\u7EED\u76F4\u884C' },
        'continue:slight left': { icon: '\u2196', label: '\u7A0D\u5DE6\u7EE7\u7EED' },
        'continue:slight right': { icon: '\u2197', label: '\u7A0D\u53F3\u7EE7\u7EED' },
    };

    return map[key] || { icon: '\u2191', label: '\u7EE7\u7EED\u884C\u9A76' };
}

/**
 * Format distance for display.
 */
export function formatDistance(meters) {
    if (meters < 1000) {
        return `${Math.round(meters)}m`;
    }
    return `${(meters / 1000).toFixed(1)}km`;
}

/**
 * Format duration for display.
 */
export function formatDuration(seconds) {
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) {
        return `${minutes}\u5206\u949F`;
    }
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return `${hours}\u5C0F\u65F6${mins}\u5206\u949F`;
}
