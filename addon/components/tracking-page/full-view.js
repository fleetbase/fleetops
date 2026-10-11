import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Coarse stages, in order, and the step each one has reached.
 */
const STEPS = ['created', 'dispatched', 'started', 'enroute', 'delivered'];
const REACHED = { preparing: 0, scheduled: 0, dispatched: 1, in_transit: 3, delivered: 5 };

function step(key, state, label, last) {
    return {
        key,
        state,
        label,
        last,
        isDone: state === 'done',
        isCurrent: state === 'current',
        isFailed: state === 'failed',
        isStop: state === 'stop',
    };
}

/**
 * One customer's view of their delivery: status, progress, live map, arrival, driver,
 * updates, items and proof of delivery. Everything it shows has already been filtered to
 * that customer's stops by the server.
 */
export default class TrackingPageFullViewComponent extends Component {
    @service intl;

    @tracked showOlder = false;
    @tracked editing = false;
    @tracked draft = '';
    @tracked reporting = false;
    @tracked reportText = '';
    @tracked proofUrls = {};
    @tracked lightbox = null;
    @tracked selectedStopId = null;

    get data() {
        return this.args.data ?? {};
    }

    get stage() {
        return this.data.stage ?? 'preparing';
    }

    get delayed() {
        return this.data.status?.delayed === true && this.stage === 'in_transit';
    }

    get tone() {
        if (this.stage === 'issue' || this.delayed) {
            return 'warn';
        }

        return this.stage === 'canceled' ? 'muted' : 'normal';
    }

    get viewerChip() {
        const viewer = this.data.viewer;
        if (viewer === 'staff') {
            return { icon: 'user-shield', label: this.intl.t('tracking-page.page.chip-staff') };
        }

        if (viewer === 'account') {
            return { icon: 'user', label: this.intl.t('tracking-page.page.chip-account') };
        }

        return { icon: 'shield-halved', label: this.intl.t('tracking-page.page.chip-verified') };
    }

    get canSignOut() {
        return this.data.viewer === 'verified';
    }

    /* ── Stops ─────────────────────────────────────────────────────── */

    get stops() {
        return this.data.stops ?? [];
    }

    get hasSeveralStops() {
        return this.stops.length > 1;
    }

    get stopOptions() {
        return this.stops.map((stop, index) => ({
            id: stop.id,
            label: this.intl.t('tracking-page.page.your-stop', { number: index + 1 }),
            selected: stop.id === this.currentStop?.id,
            complete: stop.complete,
        }));
    }

    get currentStop() {
        const chosen = this.stops.find((stop) => stop.id === (this.selectedStopId ?? this.data.focus_stop));

        return chosen ?? this.stops.find((stop) => !stop.complete) ?? this.stops[this.stops.length - 1] ?? null;
    }

    get route() {
        return this.data.route ?? { total_stops: 0, completed_stops: 0, stops_before: 0 };
    }

    get isMultiStop() {
        return this.route.total_stops > 2 && this.stage === 'in_transit';
    }

    get stopPosition() {
        return this.route.completed_stops + this.route.stops_before + 1;
    }

    @action selectStop(id) {
        this.selectedStopId = id;
    }

    /* ── Headline ──────────────────────────────────────────────────── */

    get eta() {
        return this.currentStop?.eta ?? this.data.eta ?? null;
    }

    get window() {
        const own = this.currentStop?.window;
        if (own?.start || own?.end) {
            return own;
        }

        return this.data.time_window ?? {};
    }

    get windowText() {
        const { start, end } = this.window;
        if (start && end) {
            return `${this.time(start)} – ${this.time(end)}`;
        }

        return start ? this.time(start) : end ? this.time(end) : null;
    }

    get headline() {
        const t = (key, values) => this.intl.t(`tracking-page.page.${key}`, values);
        switch (this.stage) {
            case 'scheduled':
                return t('headline-scheduled', { date: this.date(this.data.scheduled_at) });
            case 'dispatched':
                return t('headline-dispatched');
            case 'in_transit':
                if (this.delayed) {
                    return t('headline-delayed');
                }

                return this.eta?.at ? t('headline-arriving', { day: this.relativeDay(this.eta.at) }) : t('headline-in-transit');
            case 'delivered':
                return t('headline-delivered');
            case 'issue':
                return t('headline-issue');
            case 'canceled':
                return t('headline-canceled');
            default:
                return t('headline-preparing');
        }
    }

    get subline() {
        const t = (key, values) => this.intl.t(`tracking-page.page.${key}`, values);
        if (this.stage === 'in_transit' && this.eta?.at) {
            const relative = this.minutesUntil(this.eta.at);
            const window = this.windowText ?? this.time(this.eta.at);

            return relative > 0 ? t('sub-arriving', { window, minutes: relative }) : window;
        }

        if (this.stage === 'scheduled') {
            return this.windowText ? t('sub-window', { window: this.windowText }) : null;
        }

        if (this.stage === 'delivered') {
            const delivered = this.timeline.find((entry) => entry.complete) ?? this.timeline[0];

            return delivered?.created_at ? t('sub-delivered', { when: this.dateTime(delivered.created_at) }) : null;
        }

        if (this.stage === 'issue' || this.stage === 'canceled') {
            return this.timeline[0]?.status ?? null;
        }

        return this.stage === 'dispatched' ? t('sub-dispatched') : t('sub-preparing');
    }

    get live() {
        return this.stage === 'in_transit' && !!this.data.location && this.args.liveState === 'live' && !this.stale;
    }

    get stale() {
        const seenAt = this.data.location?.seen_at;
        if (!seenAt) {
            return false;
        }

        return (Date.now() - new Date(seenAt).getTime()) / 1000 > (this.data.stale_after ?? 300);
    }

    get staleText() {
        const minutes = Math.max(1, Math.round((Date.now() - new Date(this.data.location.seen_at).getTime()) / 60000));

        return this.intl.t('tracking-page.page.last-seen', { minutes });
    }

    get timezoneNote() {
        try {
            const format = new Intl.DateTimeFormat(this.locale, { timeZoneName: 'short' });
            const parts = format.formatToParts(new Date());
            const zone = parts.find((part) => part.type === 'timeZoneName')?.value;

            return zone ? this.intl.t('tracking-page.page.timezone', { zone }) : null;
        } catch {
            return null;
        }
    }

    /* ── Stepper ───────────────────────────────────────────────────── */

    get steps() {
        if (this.stage === 'canceled') {
            return [step('created', 'done', this.intl.t('tracking-page.page.step-created'), false), step('canceled', 'stop', this.intl.t('tracking-page.page.step-canceled'), true)];
        }

        const reached = REACHED[this.stage] ?? 3;

        return STEPS.map((key, index) => {
            let state = index < reached ? 'done' : index === reached ? 'current' : 'todo';
            let label = this.intl.t(`tracking-page.page.step-${key}`);
            if (this.stage === 'issue' && key === 'enroute') {
                state = 'failed';
                label = this.intl.t('tracking-page.page.step-issue');
            }

            return step(key, state, label, index === STEPS.length - 1);
        });
    }

    /* ── Cards ─────────────────────────────────────────────────────── */

    get etaCard() {
        if (this.stage !== 'in_transit' || !this.eta?.at) {
            return null;
        }

        return {
            time: this.time(this.eta.at),
            relative: this.minutesUntil(this.eta.at) > 0 ? this.intl.t('tracking-page.page.in-minutes', { minutes: this.minutesUntil(this.eta.at) }) : null,
            window: this.windowText,
            stopsBefore: this.route.stops_before,
            low: this.delayed || ['low', 'medium'].includes(this.eta.confidence ?? this.data.status?.confidence),
        };
    }

    get driver() {
        return this.stage === 'in_transit' ? this.data.driver : null;
    }

    get driverInitial() {
        return (this.driver?.name ?? '?').charAt(0).toUpperCase();
    }

    get contactPhone() {
        return this.args.company?.support_phone;
    }

    get contactEmail() {
        return this.args.company?.support_email;
    }

    get timeline() {
        return this.data.timeline ?? [];
    }

    get timelineGroups() {
        const groups = [];
        this.timeline.forEach((entry, index) => {
            const day = this.dayLabel(entry.created_at);
            let group = groups[groups.length - 1];
            if (!group || group.day !== day) {
                group = { day, entries: [] };
                groups.push(group);
            }

            group.entries.push({ ...entry, time: this.time(entry.created_at), latest: index === 0 });
        });

        return this.showOlder ? groups : groups.slice(0, 1);
    }

    get olderCount() {
        const first = this.timelineGroups[0]?.entries.length ?? 0;

        return Math.max(0, this.timeline.length - (this.showOlder ? this.timeline.length : first));
    }

    get hasOlder() {
        return this.showOlder || this.olderCount > 0;
    }

    @action toggleOlder() {
        this.showOlder = !this.showOlder;
    }

    get items() {
        return this.data.items ?? [];
    }

    get proofs() {
        return (this.data.proofs ?? []).map((proof) => ({ ...proof, url: this.proofUrls[proof.id] ?? null }));
    }

    @action async loadProofs() {
        for (const proof of this.data.proofs ?? []) {
            if (this.proofUrls[proof.id] || proof.type === 'scan') {
                continue;
            }

            try {
                const response = await this.args.loadProof(proof.id);
                this.proofUrls = { ...this.proofUrls, [proof.id]: response?.url ?? null };
            } catch {
                // The proof is listed even when its image can't be fetched.
            }
        }
    }

    @action openProof(proof) {
        if (proof.url) {
            this.lightbox = proof;
        }
    }

    @action closeProof() {
        this.lightbox = null;
    }

    @action focusElement(element) {
        element.focus();
    }

    @action closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.closeProof();
        }
    }

    /* ── Instructions and reports ──────────────────────────────────── */

    get instructions() {
        return this.data.instructions ?? {};
    }

    @action editInstructions() {
        this.draft = this.instructions.text ?? '';
        this.editing = true;
    }

    @action updateDraft(event) {
        this.draft = event.target.value;
    }

    @action cancelInstructions() {
        this.editing = false;
    }

    @action async saveInstructions() {
        await this.args.saveInstructions(this.draft);
        this.editing = false;
    }

    @action toggleReport() {
        this.reporting = !this.reporting;
    }

    @action updateReport(event) {
        this.reportText = event.target.value;
    }

    @action async sendReport() {
        const sent = await this.args.reportProblem(this.reportText);
        if (sent) {
            this.reportText = '';
            this.reporting = false;
        }
    }

    get canReport() {
        return this.data.actions?.report === true && !!this.contactEmail;
    }

    /* ── Formatting ────────────────────────────────────────────────── */

    get locale() {
        return this.intl.primaryLocale ?? 'en-us';
    }

    time(value) {
        return value ? new Intl.DateTimeFormat(this.locale, { hour: 'numeric', minute: '2-digit' }).format(new Date(value)) : '';
    }

    date(value) {
        return value ? new Intl.DateTimeFormat(this.locale, { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(value)) : '';
    }

    dateTime(value) {
        return `${this.dayLabel(value)}, ${this.time(value)}`;
    }

    dayLabel(value) {
        if (!value) {
            return '';
        }

        const day = new Date(value);
        const today = new Date();
        const yesterday = new Date(today.getTime() - 86400000);
        if (day.toDateString() === today.toDateString()) {
            return this.intl.t('tracking-page.page.today');
        }

        if (day.toDateString() === yesterday.toDateString()) {
            return this.intl.t('tracking-page.page.yesterday');
        }

        return this.date(value);
    }

    relativeDay(value) {
        const day = this.dayLabel(value);

        return day === this.intl.t('tracking-page.page.today') ? day.toLowerCase() : day;
    }

    minutesUntil(value) {
        return Math.round((new Date(value).getTime() - Date.now()) / 60000);
    }
}
