import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import normalizeOrderConfigFlow, { getOrderConfigFlowRootCode } from '../../utils/normalize-order-config-flow';
import sentenceParts from '../../utils/sentence-parts';

const L = 'order-config-manager.lifecycle';
const DEFAULTS = { initial: null, completed: null, canceled: null, terminal: [], dispatch: true, strict_transitions: false };
const STANDARD = ['created', 'dispatched', 'started', 'completed'];

/**
 * How orders of a config start and end: whether they follow the standard dispatch steps or the
 * config's own, which step they start at, which complete or cancel them, which other steps end
 * them, whether they're dispatched, and whether they must follow the flow's arrows. Stored under
 * `meta.lifecycle`; without one the config follows the standard dispatch steps.
 *
 * The steps themselves are edited on Activity Flow. Checks here point out steps orders would get
 * stuck at.
 *
 * @extends Component
 */
export default class OrderConfigManagerLifecycleComponent extends Component {
    @service notifications;
    @service abilities;
    @service intl;

    @tracked config;
    @tracked lifecycle = null;
    @tracked saved = null;

    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.changeConfig(config);

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.changeConfig(newConfig);
        });
    }

    get canEdit() {
        return this.abilities.can('fleet-ops update order-config') && !this.config?.core_service;
    }

    get readonly() {
        return !this.canEdit;
    }

    get isSystemConfig() {
        return Boolean(this.config?.core_service);
    }

    get own() {
        return this.lifecycle !== null;
    }

    get flow() {
        return normalizeOrderConfigFlow(this.config?.flow ?? {});
    }

    get steps() {
        return Object.values(this.flow)
            .filter((activity) => activity && (activity.code || activity.key))
            .map((activity) => ({
                code: activity.code ?? activity.key,
                label: activity.status || activity.code || activity.key,
                next: (isArray(activity.activities) ? activity.activities : []).map((next) => (typeof next === 'string' ? next : (next?.code ?? next?.key))).filter(Boolean),
                complete: Boolean(activity.complete),
            }));
    }

    label(code) {
        return this.steps.find((step) => step.code === code)?.label ?? code;
    }

    t(key, values) {
        return this.intl.t(`${L}.${key}`, values);
    }

    // ----- editing

    patch(patch) {
        this.lifecycle = { ...(this.lifecycle ?? DEFAULTS), ...patch };
    }

    @action setMode(own) {
        if (!own) {
            this.lifecycle = null;
            return;
        }
        this.lifecycle = this.saved ? { ...this.saved, terminal: [...this.saved.terminal] } : { ...DEFAULTS, terminal: [], initial: getOrderConfigFlowRootCode(this.flow) ?? null };
    }

    @action discard() {
        this.lifecycle = this.saved ? { ...this.saved, terminal: [...this.saved.terminal] } : null;
    }

    @action endAt(code) {
        this.patch({ terminal: [...new Set([...(this.lifecycle?.terminal ?? []), code])] });
    }

    // ----- sentences

    isUnsaved(key) {
        return JSON.stringify(this.lifecycle?.[key] ?? null) !== JSON.stringify(this.saved?.[key] ?? null);
    }

    stepChoice(key, ariaKey, { allowNone } = {}) {
        const options = this.steps.map((step) => ({ value: step.code, label: step.label }));
        return {
            kind: 'choice',
            value: this.lifecycle?.[key] ?? null,
            isDefault: false,
            label: this.lifecycle?.[key] ? this.label(this.lifecycle[key]) : this.t('not-set'),
            invalid: !this.lifecycle?.[key] && !allowNone,
            ariaLabel: this.t(ariaKey),
            options: allowNone ? [{ value: null, label: this.t('not-set') }, ...options] : options,
            onChange: (value) => this.patch({ [key]: value }),
        };
    }

    flagChoice(key, pairs, ariaKey) {
        const value = this.lifecycle?.[key] ?? DEFAULTS[key];
        return {
            kind: 'choice',
            value,
            isDefault: value === DEFAULTS[key],
            ariaLabel: this.t(ariaKey),
            options: pairs.map(([optionValue, label]) => ({ value: optionValue, label, isDefault: optionValue === DEFAULTS[key] })),
            onChange: (next) => this.patch({ [key]: next }),
        };
    }

    line(keys, key, controls, help, error) {
        return { keys, parts: sentenceParts(this.intl, `${L}.${key}`, controls), help, error, unsaved: keys.some((k) => this.isUnsaved(k)) };
    }

    get lines() {
        const modeLine = {
            keys: ['mode'],
            parts: sentenceParts(this.intl, `${L}.mode.sentence`, {
                choice: {
                    kind: 'choice',
                    value: this.own,
                    isDefault: !this.own,
                    ariaLabel: this.t('mode-aria'),
                    options: [
                        { value: false, label: this.t('mode.standard'), isDefault: true },
                        { value: true, label: this.t('mode.own') },
                    ],
                    onChange: (own) => this.setMode(own),
                },
            }),
            help: this.own ? this.t('mode-help-own') : this.t('mode-help-standard'),
            unsaved: (this.lifecycle === null) !== (this.saved === null),
        };

        if (!this.own) {
            return [modeLine];
        }

        const ends = new Set([this.lifecycle.completed, this.lifecycle.canceled].filter(Boolean));
        const endable = this.steps.filter((step) => !ends.has(step.code) && step.code !== this.lifecycle.initial).map((step) => step.code);

        return [
            modeLine,
            this.line(
                ['initial'],
                'initial',
                { step: this.stepChoice('initial', 'initial-aria') },
                this.t('initial-help'),
                this.lifecycle.initial ? null : this.t('problem-initial-required')
            ),
            this.line(['completed'], 'completed', { step: this.stepChoice('completed', 'completed-aria', { allowNone: true }) }, this.t('completed-help')),
            this.line(['canceled'], 'canceled', { step: this.stepChoice('canceled', 'canceled-aria', { allowNone: true }) }, this.t('canceled-help')),
            this.line(
                ['terminal'],
                'terminal',
                {
                    steps: {
                        kind: 'list',
                        items: (this.lifecycle.terminal ?? []).map((code) => ({ value: code, label: this.label(code) })),
                        options: endable.map((code) => ({ value: code, label: this.label(code) })),
                        emptyLabel: this.t('terminal-none'),
                        ariaLabel: this.t('terminal-aria'),
                        onChange: (terminal) => this.patch({ terminal }),
                    },
                },
                this.t('terminal-help')
            ),
            this.line(
                ['dispatch'],
                'dispatch.sentence',
                {
                    choice: this.flagChoice(
                        'dispatch',
                        [
                            [true, this.t('dispatch.on')],
                            [false, this.t('dispatch.off')],
                        ],
                        'dispatch-aria'
                    ),
                },
                this.t('dispatch-help')
            ),
            this.line(
                ['strict_transitions'],
                'strict.sentence',
                {
                    choice: this.flagChoice(
                        'strict_transitions',
                        [
                            [false, this.t('strict.off')],
                            [true, this.t('strict.on')],
                        ],
                        'strict-aria'
                    ),
                },
                this.t('strict-help')
            ),
        ];
    }

    // ----- checks

    get endCodes() {
        if (!this.lifecycle) {
            return new Set();
        }
        return new Set([this.lifecycle.completed, this.lifecycle.canceled, ...(this.lifecycle.terminal ?? [])].filter(Boolean));
    }

    /** Steps with nowhere to go that don't end the order: orders reaching them get stuck. */
    get deadEnds() {
        return this.steps.filter((step) => step.next.length === 0 && !step.complete && !this.endCodes.has(step.code));
    }

    get missing() {
        if (!this.lifecycle) {
            return [];
        }
        const codes = this.steps.map((step) => step.code);
        const referenced = [this.lifecycle.initial, this.lifecycle.completed, this.lifecycle.canceled, ...(this.lifecycle.terminal ?? [])].filter(Boolean);
        return [...new Set(referenced)].filter((code) => !codes.includes(code));
    }

    get checks() {
        if (!this.own) {
            return [];
        }

        const checks = [];
        if (this.lifecycle.completed) {
            checks.push({ ok: true, title: this.t('check-completable', { step: this.label(this.lifecycle.completed) }) });
        }
        this.missing.forEach((code) => checks.push({ ok: false, title: this.t('problem-missing-activity', { code }) }));
        this.deadEnds.forEach((step) =>
            checks.push({
                ok: false,
                title: this.t('check-dead-end', { step: step.label }),
                body: this.t('check-dead-end-body'),
                fix: this.canEdit ? () => this.endAt(step.code) : null,
            })
        );
        if (this.deadEnds.length === 0) {
            checks.push({ ok: true, title: this.t('check-no-dead-ends') });
        }

        return checks;
    }

    // ----- at a glance

    get glance() {
        if (!this.own) {
            const label = (code) => this.intl.t(`${L}.standard.${code}`);
            return [
                { title: this.t('glance-starts'), items: [{ role: 'start', label: label('created') }] },
                { title: this.t('glance-along'), items: STANDARD.slice(1, 3).map((code) => ({ role: 'step', label: label(code) })) },
                {
                    title: this.t('glance-ends'),
                    items: [
                        { role: 'complete', label: label('completed'), note: this.t('glance-complete') },
                        { role: 'end', label: label('canceled'), note: this.t('glance-canceled') },
                    ],
                },
            ];
        }

        const { initial, completed, canceled, terminal = [] } = this.lifecycle;
        const ends = this.endCodes;
        return [
            { title: this.t('glance-starts'), items: initial ? [{ role: 'start', label: this.label(initial) }] : [] },
            { title: this.t('glance-along'), items: this.steps.filter((step) => step.code !== initial && !ends.has(step.code)).map((step) => ({ role: 'step', label: step.label })) },
            {
                title: this.t('glance-ends'),
                items: [
                    completed ? { role: 'complete', label: this.label(completed), note: this.t('glance-complete') } : null,
                    canceled ? { role: 'end', label: this.label(canceled), note: this.t('glance-canceled') } : null,
                    ...terminal.map((code) => ({ role: 'end', label: this.label(code) })),
                ].filter(Boolean),
            },
            {
                title: this.t('glance-rules'),
                items: [
                    { role: 'step', label: this.lifecycle.dispatch === false ? this.t('glance-never-dispatched') : this.t('glance-dispatched') },
                    { role: 'step', label: this.lifecycle.strict_transitions ? this.t('glance-strict') : this.t('glance-any') },
                ],
            },
        ];
    }

    // ----- saving

    get unsavedCount() {
        return this.lines.filter((line) => line.unsaved).length;
    }

    get errorHint() {
        if (!this.lifecycle) {
            return null;
        }
        return !this.lifecycle.initial || this.missing.length ? this.t('save-blocked') : null;
    }

    normalize(lifecycle) {
        if (!lifecycle || typeof lifecycle !== 'object' || Object.keys(lifecycle).length === 0) {
            return null;
        }

        return {
            ...DEFAULTS,
            ...lifecycle,
            terminal: isArray(lifecycle.terminal) ? [...lifecycle.terminal] : [],
            dispatch: !['false', '0', 0, false].includes(lifecycle.dispatch),
            strict_transitions: Boolean(lifecycle.strict_transitions),
        };
    }

    changeConfig(config) {
        this.config = config;
        this.saved = this.normalize(config?.meta?.lifecycle);
        this.lifecycle = this.normalize(config?.meta?.lifecycle);
    }

    @task *save() {
        if (this.errorHint) {
            return;
        }

        const meta = { ...(this.config.meta ?? {}) };
        if (this.lifecycle) {
            meta.lifecycle = { ...this.lifecycle };
        } else {
            delete meta.lifecycle;
        }

        try {
            this.config.set('meta', meta);
            yield this.config.save();
            this.saved = this.normalize(this.lifecycle);
            this.lifecycle = this.normalize(this.lifecycle);
            this.notifications.success(this.t('saved', { orderConfigName: this.config.name }));
            if (typeof this.args.onConfigUpdated === 'function') {
                this.args.onConfigUpdated(this.config);
            }
        } catch (error) {
            this.config.rollbackAttributes();
            this.lifecycle = this.normalize(this.config.meta?.lifecycle);
            this.notifications.serverError(error);
        }
    }
}
