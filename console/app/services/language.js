import LanguageService from '@fleetbase/ember-core/services/language';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ConsoleLanguageService extends LanguageService {
    @service currentUser;

    @action changeLocale(selectedLocale) {
        this.currentLocale = selectedLocale;
        this.currentUser.setLocale(selectedLocale);
        this.saveUserLocale.perform(selectedLocale);
    }

    _findCountryDataForLocale(locale) {
        const normalizedLocale = locale.toLowerCase().replaceAll('_', '-');
        const [language, region] = normalizedLocale.split('-');
        const country = this.countries.find((item) => item.cca2?.toLowerCase() === region);
        let languageName = country?.languages?.[language] || normalizedLocale;

        // A country's first language is not necessarily the translation's language.
        if (typeof Intl.DisplayNames === 'function') {
            languageName = new Intl.DisplayNames(['en'], { type: 'language' }).of(language) || languageName;
        }

        if (normalizedLocale === 'zh-cn') {
            languageName = '简体中文';
        }

        // Do not mutate the cached country object (several locales can share it).
        return { ...country, language: languageName };
    }
}
