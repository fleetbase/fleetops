import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import trackingPageTheme, { contrastRatio, inkFor, normalizeHex, DEFAULT_ACCENT } from '../../utils/tracking-page-theme';

/**
 * The locales a tracking page can be shown in, labelled in their own language.
 */
export const TRACKING_PAGE_LOCALES = [
    { code: 'en-us', label: 'English' },
    { code: 'ar-ae', label: 'العربية' },
    { code: 'bg-bg', label: 'Български' },
    { code: 'es-pa', label: 'Español' },
    { code: 'fr-fr', label: 'Français' },
    { code: 'mn-mn', label: 'Монгол' },
    { code: 'pt-br', label: 'Português' },
    { code: 'ru-ru', label: 'Русский' },
    { code: 'uk-ua', label: 'Українська' },
    { code: 'vi-vn', label: 'Tiếng Việt' },
];

/**
 * Keys the server adds to the saved config when it reads it back. They are facts about
 * the instance, not settings, so they are kept apart and never sent back on save.
 */
const READ_ONLY_KEYS = ['slug_validation', 'accent_ink', 'accent_contrast', 'sms_available', 'customer_portal_installed', 'admin'];

/**
 * Settings::TrackingPageController
 *
 * The company's customer tracking page: which pages are on and at what address, branding,
 * how customers prove who they are, and what verified customers see.
 */
export default class SettingsTrackingPageController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service currentUser;

    @tracked config = null;
    @tracked slugValidation = null;
    @tracked smsAvailable = false;
    @tracked customerPortalInstalled = false;
    @tracked loadFailed = false;

    /**
     * Take the settings as the server returns them.
     */
    load(settings) {
        if (!settings) {
            this.loadFailed = true;
            return;
        }

        const config = { ...settings };
        READ_ONLY_KEYS.forEach((key) => delete config[key]);

        this.config = config;
        this.slugValidation = settings.slug_validation ?? null;
        this.smsAvailable = settings.sms_available === true;
        this.customerPortalInstalled = settings.customer_portal_installed === true;
        this.loadFailed = false;
    }

    get accent() {
        return normalizeHex(this.config?.branding?.accent) ?? DEFAULT_ACCENT;
    }

    get accentInk() {
        return inkFor(this.accent);
    }

    get accentContrast() {
        return contrastRatio(this.accent, this.accentInk);
    }

    get accentReadable() {
        return this.accentContrast >= 4.5;
    }

    get previewLightStyle() {
        return trackingPageTheme(this.config?.branding, false);
    }

    get previewDarkStyle() {
        return trackingPageTheme(this.config?.branding, true);
    }

    get companyName() {
        return this.currentUser.companyName ?? '';
    }

    get displayName() {
        return this.config?.branding?.display_name || this.companyName;
    }

    get orgPageBase() {
        return `${window.location.origin}/t/`;
    }

    get orgPageUrl() {
        return `${window.location.origin}/t/${this.config?.org_page?.slug ?? ''}`;
    }

    get genericPageUrl() {
        return `${window.location.origin}/track`;
    }

    get slugIsValid() {
        return this.slugValidation?.valid === true;
    }

    get localeOptions() {
        const enabled = this.config?.branding?.locales ?? [];
        const defaultLocale = this.config?.branding?.default_locale;

        return TRACKING_PAGE_LOCALES.map((locale) => ({
            ...locale,
            enabled: enabled.includes(locale.code),
            isDefault: locale.code === defaultLocale,
        }));
    }

    get linkTargetOptions() {
        return ['auto', 'org', 'generic'].map((value) => ({ value, label: this.intl.t(`tracking-page.settings.links-target-${value}`) }));
    }

    get themeOptions() {
        return ['system', 'light'].map((value) => ({ value, label: this.intl.t(`tracking-page.settings.theme-${value}`) }));
    }

    get driverContactOptions() {
        return ['company', 'driver'].map((value) => ({ value, label: this.intl.t(`tracking-page.settings.driver-contact-${value}`) }));
    }

    /**
     * Set one value by its dotted path, replacing the config so the form and preview update.
     */
    @action update(path, value) {
        const config = JSON.parse(JSON.stringify(this.config));
        const keys = path.split('.');
        const last = keys.pop();
        let target = config;
        for (const key of keys) {
            target = target[key];
        }

        target[last] = value;
        this.config = config;
    }

    @action updateFromEvent(path, event) {
        this.update(path, event.target.value);
    }

    @action updateNumber(path, event) {
        this.update(path, parseInt(event.target.value, 10));
    }

    @action selectOption(path, option) {
        this.update(path, option?.value ?? option);
    }

    @action updateSlug(event) {
        const slug = event.target.value.toLowerCase().replace(/\s+/g, '-');
        this.update('org_page.slug', slug);
        this.validateSlug.perform(slug);
    }

    @action toggleLocale(code) {
        const locales = this.config.branding.locales ?? [];
        if (code === this.config.branding.default_locale) {
            return;
        }

        this.update('branding.locales', locales.includes(code) ? locales.filter((locale) => locale !== code) : [...locales, code]);
    }

    @action setDefaultLocale(option) {
        const code = option?.value ?? option;
        const locales = this.config.branding.locales ?? [];
        this.update('branding.default_locale', code);
        if (!locales.includes(code)) {
            this.update('branding.locales', [...locales, code]);
        }
    }

    get defaultLocaleOptions() {
        return TRACKING_PAGE_LOCALES.map((locale) => ({ value: locale.code, label: locale.label }));
    }

    @task({ restartable: true }) *validateSlug(slug) {
        yield timeout(350);
        try {
            this.slugValidation = yield this.fetch.post('fleet-ops/settings/tracking-page-settings/validate-slug', { slug });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            const settings = yield this.fetch.post('fleet-ops/settings/tracking-page-settings', { trackingPage: this.config });
            this.load(settings);
            this.notifications.success(this.intl.t('tracking-page.settings.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
