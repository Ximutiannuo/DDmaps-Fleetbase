import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { underscore } from '@ember/string';

function relationshipKeys(key) {
    return [key, underscore(key)];
}

function relationshipKind(descriptor) {
    return descriptor?.kind ?? descriptor?.meta?.kind;
}

const NULLABLE_HAS_MANY_KEYS = [
    'road_segments',
    'segments',
    'road_conditions',
    'conditions',
    'road_events',
    'events',
    'entry_registrations',
    'entries',
    'construction_activities',
    'notifications',
];

function coerceNullHasMany(resourceHash, keys) {
    if (!resourceHash || typeof resourceHash !== 'object' || Array.isArray(resourceHash)) {
        return;
    }

    for (const key of keys) {
        if (resourceHash[key] === null) {
            resourceHash[key] = [];
        }
    }
}

export default class ConstructionSiteSerializer extends ApplicationSerializer {
    /**
     * The Fleetbase REST API returns collection payloads keyed by the
     * snake_case plural of the model name (e.g. site_roads,
     * entry_registrations), while Ember Data's REST serializer defaults to
     * the dasherized plural (site-roads). Without this override the
     * serializer extracts zero records and Ember Data's array manager
     * crashes with "Cannot read properties of null (reading 'length')".
     */
    payloadKeyFromModelName(modelName) {
        return underscore(super.payloadKeyFromModelName(modelName));
    }

    /**
     * Model attributes are declared snake_case (e.g. road_type, created_at)
     * and the API returns them verbatim, so serialize/deserialize keys as-is.
     */
    keyForAttribute(key) {
        return key;
    }

    /**
     * Relationship keys are camelCase in models and snake_case in payloads.
     */
    keyForRelationship(key) {
        return underscore(key);
    }

    normalize(modelClass, resourceHash, prop) {
        if (resourceHash) {
            const keys = new Set(NULLABLE_HAS_MANY_KEYS);

            if (typeof modelClass?.eachRelationship === 'function') {
                modelClass.eachRelationship((key, descriptor) => {
                    if (relationshipKind(descriptor) !== 'hasMany') {
                        return;
                    }

                    for (const candidate of relationshipKeys(key)) {
                        keys.add(candidate);
                    }
                });
            }

            coerceNullHasMany(resourceHash, keys);
        }

        return super.normalize(modelClass, resourceHash, prop);
    }

    normalizeResponse(store, primaryModelClass, payload, id, requestType) {
        if (payload && typeof payload === 'object') {
            const topLevelKeys = new Set(NULLABLE_HAS_MANY_KEYS);

            if (primaryModelClass) {
                topLevelKeys.add(this.payloadKeyFromModelName(primaryModelClass.modelName));
            }

            for (const key of topLevelKeys) {
                if (payload[key] === null) {
                    payload[key] = [];
                }
            }

            if (primaryModelClass) {
                const payloadKey = this.payloadKeyFromModelName(primaryModelClass.modelName);
                const records = payload[payloadKey];

                if (Array.isArray(records)) {
                    for (const record of records) {
                        coerceNullHasMany(record, NULLABLE_HAS_MANY_KEYS);
                    }
                } else if (records && typeof records === 'object') {
                    coerceNullHasMany(records, NULLABLE_HAS_MANY_KEYS);
                }
            }
        }

        return super.normalizeResponse(store, primaryModelClass, payload, id, requestType);
    }
}
