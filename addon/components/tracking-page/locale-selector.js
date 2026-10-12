import LocaleSelectorTrayComponent from '@fleetbase/ember-ui/components/locale-selector-tray';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * The console's locale switcher, for the tracking page: it offers only the languages the
 * company's page is in (`@locales`), and switches the page without saving the choice to
 * an account, since the visitor is most often a customer who isn't signed in.
 */
export default class TrackingPageLocaleSelectorComponent extends LocaleSelectorTrayComponent {
    @service('language') languageService;

    get language() {
        const all = this.languageService.availableLocales ?? {};
        const enabled = this.args.locales ?? [];
        const availableLocales = {};
        for (const code of Object.keys(all)) {
            if (all[code] && (enabled.length === 0 || enabled.includes(code))) {
                availableLocales[code] = all[code];
            }
        }

        return { availableLocales, loadAvailableCountries: this.languageService.loadAvailableCountries };
    }

    @action changeLocale(locale) {
        this.currentLocale = locale;
        this.intl.setLocale([locale]);
    }
}
