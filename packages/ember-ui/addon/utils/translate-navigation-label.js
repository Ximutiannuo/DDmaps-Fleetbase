import { dasherize } from '@ember/string';

export default function translateNavigationLabel(intl, label) {
    if (typeof label !== 'string' || !label) {
        return label;
    }

    const key = `ddmaps-navigation.labels.${dasherize(label)}`;
    return intl.exists(key) ? intl.t(key) : label;
}
