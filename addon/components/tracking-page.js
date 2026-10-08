import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { task, timeout } from 'ember-concurrency';
import trackingPageTheme from '../utils/tracking-page-theme';
import TrackingLiveSource from '../utils/tracking-live-source';

/**
 * The FleetOps API is mounted at the application root, so its public routes live under `public`.
 * `rawError` makes a failed request reject with its JSON body, whose `code` or `error` drives the page.
 */
const REQUEST_OPTIONS = { namespace: 'public', rawError: true };

/** How many neutral misses in a row before the page suggests a short break. */
const GENTLE_LIMIT_AFTER = 4;

/** The code length the server issues. */
export const CODE_LENGTH = 6;

/** Each locale, labelled in its own language. */
const LOCALE_LABELS = {
    'en-us': 'English',
    'ar-ae': 'العربية',
    'bg-bg': 'Български',
    'es-pa': 'Español',
    'fr-fr': 'Français',
    'mn-mn': 'Монгол',
    'pt-br': 'Português',
    'ru-ru': 'Русский',
    'uk-ua': 'Українська',
    'vi-vn': 'Tiếng Việt',
};

/**
 * The customer tracking page.
 *
 * Registered in the `auth:login` registry as `track` and `track-order`, so the console's
 * public `virtual` route serves it at `/~/track` and `/~/track-order`: outside the console,
 * signed in or not. `?order=` (or `?number=`) opens a tracking number and `?org=` a
 * company's own page.
 *
 * A tracking number alone shows a coarse status. A one-time code to the customer's own
 * contact details unlocks their stops on this device; a signed-in customer, or staff of
 * the owning company, sees them straight away. Live updates poll the same endpoint.
 */
export default class TrackingPageComponent extends Component {
    @service fetch;
    @service intl;
    @service urlSearchParams;
    @service session;

    @tracked screen = 'loading';
    @tracked page = null;
    @tracked data = null;
    @tracked trackingInput = '';
    @tracked lookupError = null;
    @tracked misses = 0;
    @tracked liveState = 'live';
    @tracked toast = null;
    @tracked orders = null;
    @tracked prefersDark = false;

    /* verification */
    @tracked verifyStep = 'choose';
    @tracked channel = null;
    @tracked masked = null;
    @tracked code = '';
    @tracked attemptsLeft = 3;
    @tracked cooldown = 0;
    @tracked lockLeft = 0;
    @tracked verifyMessage = null;

    live = null;
    ticker = null;

    constructor() {
        super(...arguments);
        this.org = this.urlSearchParams.get('org') || null;
        this.trackingInput = this.urlSearchParams.get('order') || this.urlSearchParams.get('number') || '';
        this.watchColorScheme();
        this.ticker = setInterval(() => this.tick(), 1000);
        this.boot.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this.ticker);
        this.live?.stop();
        this.colorScheme?.removeEventListener?.('change', this.onColorScheme);
    }

    /* ── Derived state ─────────────────────────────────────────────── */

    get company() {
        return this.data?.company ?? this.page?.company ?? {};
    }

    get monogram() {
        let monogram = '';
        for (const word of String(this.company.name ?? '').split(/\s+/)) {
            if (word && monogram.length < 2) {
                monogram += word[0].toUpperCase();
            }
        }

        return monogram;
    }

    get dark() {
        return this.company.theme !== 'light' && this.prefersDark;
    }

    get themeStyle() {
        return htmlSafe(trackingPageTheme(this.company, this.dark));
    }

    get locale() {
        return this.intl.primaryLocale ?? 'en-us';
    }

    get dir() {
        return this.locale.startsWith('ar') ? 'rtl' : 'ltr';
    }

    get localeOptions() {
        const enabled = this.company.locales?.length ? this.company.locales : Object.keys(LOCALE_LABELS);

        return enabled.map((code) => ({ code, label: LOCALE_LABELS[code] ?? code, selected: code === this.locale }));
    }

    get number() {
        return (this.data?.tracking_number ?? this.trackingInput ?? '').trim();
    }

    get isFull() {
        return this.data?.level >= 1;
    }

    get rateLimited() {
        return this.misses >= GENTLE_LIMIT_AFTER;
    }

    get channels() {
        return this.data?.verify?.channels ?? [];
    }

    get hasChannels() {
        return this.channels.length > 0;
    }

    get signInUrl() {
        return `/customer-portal/auth/login?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
    }

    get signedIn() {
        return this.session.isAuthenticated === true;
    }

    get cooldownText() {
        return formatCountdown(this.cooldown);
    }

    get lockText() {
        return formatCountdown(this.lockLeft);
    }

    get codeBoxes() {
        const digits = this.code.split('');
        return Array.from({ length: CODE_LENGTH }, (_, index) => ({
            digit: digits[index] ?? '',
            active: (this.verifyStep === 'sent' || this.verifyStep === 'wrong') && index === Math.min(digits.length, CODE_LENGTH - 1),
        }));
    }

    get selectedChannel() {
        return this.channel ?? this.channels[0]?.type ?? null;
    }

    get otherChannel() {
        return this.channels.find((channel) => channel.type !== this.selectedChannel) ?? null;
    }

    /* ── Loading ───────────────────────────────────────────────────── */

    @task *boot() {
        try {
            this.page = yield this.fetch.get('track/config', this.query(), REQUEST_OPTIONS);
            this.applyLocale(this.page?.company);
        } catch {
            this.screen = 'unavailable';
            return;
        }

        if (this.trackingInput) {
            yield this.lookup.perform(this.trackingInput, { quiet: true });
        } else {
            this.screen = 'lookup';
        }

        if (this.signedIn && !this.data) {
            this.loadOrders.perform();
        }
    }

    @task({ restartable: true }) *lookup(number, { quiet = false } = {}) {
        this.lookupError = null;
        const value = String(number ?? '').trim();
        if (!value) {
            this.lookupError = 'neutral';
            return;
        }

        try {
            const data = yield this.fetch.get(`track/${encodeURIComponent(value)}`, this.query(), REQUEST_OPTIONS);
            this.misses = 0;
            this.show(data);
            this.urlSearchParams.addParamToCurrentUrl('order', data.tracking_number);
        } catch (error) {
            this.misses += 1;
            this.lookupError = this.rateLimited ? 'rate' : 'neutral';
            this.screen = 'lookup';
            if (quiet) {
                this.misses = Math.max(0, this.misses - 1);
            }
            return error;
        }
    }

    @task *refresh() {
        return yield this.fetch.get(`track/${encodeURIComponent(this.number)}`, this.query(), REQUEST_OPTIONS);
    }

    @task *loadOrders() {
        try {
            const response = yield this.fetch.get('track/account/orders', {}, REQUEST_OPTIONS);
            this.orders = response?.orders ?? [];
            if (!this.data) {
                this.screen = 'list';
            }
        } catch {
            this.orders = null;
        }
    }

    show(data) {
        const before = this.data;
        this.data = data;
        this.applyLocale(data?.company);
        this.screen = data?.level >= 1 ? 'track' : 'public';

        if (before && before.stage !== data.stage && data.level >= 1) {
            this.notify(this.intl.t(`tracking-page.page.stage-toast-${data.stage}`), '');
        }

        if (!this.isFull) {
            this.live?.stop();
        } else if (!this.live?.running) {
            this.startLive();
        }
    }

    startLive() {
        this.live?.stop();

        this.live = new TrackingLiveSource(() => this.refresh.perform(), { isActive: () => this.data?.stage === 'in_transit' });
        this.live.start(this.onLiveUpdate, this.onLiveState);
    }

    onLiveUpdate = (data) => {
        this.show(data);
    };

    onLiveState = (state) => {
        this.liveState = state;
    };

    /* ── Lookup ────────────────────────────────────────────────────── */

    @action updateTracking(event) {
        this.trackingInput = event.target.value;
    }

    @action submitLookup(event) {
        event?.preventDefault?.();
        if (this.rateLimited) {
            return;
        }

        this.lookup.perform(this.trackingInput);
    }

    @action lookupAnother() {
        this.live?.stop();
        this.data = null;
        this.trackingInput = '';
        this.lookupError = null;
        this.urlSearchParams.removeParamFromCurrentUrl('order');
        this.screen = 'lookup';
    }

    @action openOrder(order) {
        this.trackingInput = order.tracking_number;
        this.lookup.perform(order.tracking_number);
    }

    @action backToOrders() {
        this.live?.stop();
        this.data = null;
        this.urlSearchParams.removeParamFromCurrentUrl('order');
        this.screen = 'list';
    }

    /* ── Verification ──────────────────────────────────────────────── */

    @action startVerify() {
        this.verifyStep = this.hasChannels ? 'choose' : 'none';
        this.channel = this.channels[0]?.type ?? null;
        this.code = '';
        this.verifyMessage = null;
        this.screen = 'verify';
    }

    @action cancelVerify() {
        this.screen = 'public';
    }

    @action pickChannel(type) {
        this.channel = type;
    }

    @action switchChannel() {
        if (this.otherChannel) {
            this.channel = this.otherChannel.type;
            this.sendCode.perform();
        }
    }

    @task({ drop: true }) *sendCode() {
        this.verifyStep = 'sending';
        this.code = '';
        this.verifyMessage = null;

        try {
            const response = yield this.fetch.post(`track/${encodeURIComponent(this.number)}/codes${this.queryString()}`, { channel: this.selectedChannel }, REQUEST_OPTIONS);
            this.masked = response.masked;
            this.cooldown = response.resend_after ?? 30;
            this.attemptsLeft = 3;
            this.verifyStep = 'sent';
        } catch (error) {
            this.handleVerifyError(error);
        }
    }

    @action resend() {
        if (this.cooldown === 0) {
            this.sendCode.perform();
        }
    }

    @action updateCode(event) {
        if (['locked', 'sending', 'success'].includes(this.verifyStep)) {
            return;
        }

        this.code = String(event.target.value ?? '').replace(/\D/g, '').slice(0, CODE_LENGTH);
        if (this.verifyStep === 'wrong') {
            this.verifyStep = 'sent';
        }

        if (this.code.length === CODE_LENGTH) {
            this.verifyCode.perform(this.code);
        }
    }

    @task({ drop: true }) *verifyCode(code) {
        try {
            const data = yield this.fetch.post(`track/${encodeURIComponent(this.number)}/codes/verify${this.queryString()}`, { code }, REQUEST_OPTIONS);
            this.verifyStep = 'success';
            yield timeout(700);
            this.show(data);
            this.notify(this.intl.t('tracking-page.page.verified-toast'), this.intl.t('tracking-page.page.verified-toast-help'));
        } catch (error) {
            this.code = '';
            this.handleVerifyError(error);
        }
    }

    handleVerifyError(error) {
        const code = error?.error ?? error?.code;
        if (code === 'invalid') {
            this.attemptsLeft = error.attempts_left ?? 0;
            this.verifyStep = 'wrong';
        } else if (code === 'paused') {
            this.lockLeft = error.retry_after ?? 300;
            this.verifyStep = 'locked';
        } else if (code === 'cooldown') {
            this.cooldown = error.retry_after ?? 30;
            this.verifyStep = 'sent';
        } else if (code === 'expired') {
            this.verifyStep = 'renew';
            this.verifyMessage = 'expired';
        } else if (code === 'limited') {
            this.verifyStep = 'renew';
            this.verifyMessage = 'limited';
        } else {
            this.verifyStep = this.hasChannels ? 'choose' : 'none';
            this.verifyMessage = 'failed';
        }
    }

    @task *signOut() {
        try {
            yield this.fetch.delete(`track/session${this.queryString()}`, {}, REQUEST_OPTIONS);
        } catch {
            // Signed out locally either way: the next lookup shows what the server allows.
        }

        this.live?.stop();
        this.notify(this.intl.t('tracking-page.page.signed-out-toast'), '');
        yield this.lookup.perform(this.number);
    }

    /* ── Full view actions ─────────────────────────────────────────── */

    @task *saveInstructions(text) {
        try {
            const data = yield this.fetch.put(`track/${encodeURIComponent(this.number)}/instructions${this.queryString()}`, { text }, REQUEST_OPTIONS);
            this.show(data);
            this.notify(this.intl.t('tracking-page.page.instructions-saved'), '');
        } catch {
            this.notify(this.intl.t('tracking-page.page.action-failed'), '');
        }
    }

    @task *reportProblem(message) {
        try {
            yield this.fetch.post(`track/${encodeURIComponent(this.number)}/report${this.queryString()}`, { message }, REQUEST_OPTIONS);
            this.notify(this.intl.t('tracking-page.page.report-sent'), this.intl.t('tracking-page.page.report-sent-help', { name: this.company.name }));
            return true;
        } catch {
            this.notify(this.intl.t('tracking-page.page.action-failed'), '');
            return false;
        }
    }

    @task *loadProof(id) {
        return yield this.fetch.get(`track/${encodeURIComponent(this.number)}/proofs/${encodeURIComponent(id)}`, this.query(), REQUEST_OPTIONS);
    }

    @action share() {
        const url = `${window.location.origin}/~/track?order=${encodeURIComponent(this.number)}${this.org ? `&org=${encodeURIComponent(this.org)}` : ''}`;
        try {
            if (navigator.share) {
                navigator.share({ url }).catch(() => {});
            } else {
                navigator.clipboard?.writeText(url);
            }
        } catch {
            // Copying is a convenience; the address bar still has the link.
        }

        this.notify(this.intl.t('tracking-page.page.share-copied'), this.intl.t('tracking-page.page.share-copied-help'));
    }

    /* ── Page chrome ───────────────────────────────────────────────── */

    @action changeLocale(event) {
        this.intl.setLocale([event.target.value]);
    }

    @action dismissToast() {
        this.toast = null;
    }

    notify(title, text) {
        this.toast = { title, text, id: Date.now() };
        const id = this.toast.id;
        setTimeout(() => {
            if (this.toast?.id === id && !this.isDestroyed) {
                this.toast = null;
            }
        }, 4500);
    }

    tick() {
        if (this.cooldown > 0) {
            this.cooldown -= 1;
        }

        if (this.verifyStep === 'locked') {
            if (this.lockLeft > 1) {
                this.lockLeft -= 1;
            } else {
                this.lockLeft = 0;
                this.verifyStep = 'renew';
                this.verifyMessage = 'unlocked';
            }
        }
    }

    applyLocale(company) {
        const preferred = this.urlSearchParams.get('lang') || company?.default_locale;
        if (!this.localeApplied && preferred && company?.locales?.includes(preferred)) {
            this.localeApplied = true;
            this.intl.setLocale([preferred]);
        }
    }

    watchColorScheme() {
        if (typeof window === 'undefined' || !window.matchMedia) {
            return;
        }

        this.colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
        this.prefersDark = this.colorScheme.matches;
        this.onColorScheme = (event) => {
            this.prefersDark = event.matches;
        };
        this.colorScheme.addEventListener?.('change', this.onColorScheme);
    }

    query() {
        return this.org ? { org: this.org } : {};
    }

    queryString() {
        return this.org ? `?org=${encodeURIComponent(this.org)}` : '';
    }
}

export function formatCountdown(seconds) {
    const value = Math.max(0, Math.floor(seconds ?? 0));
    const minutes = Math.floor(value / 60);
    const rest = value % 60;

    return `${minutes}:${rest < 10 ? '0' : ''}${rest}`;
}
