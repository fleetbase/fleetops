import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import normalizeOrderConfigFlow, { getOrderConfigFlowRootCode } from '../../utils/normalize-order-config-flow';

/** Keys of `meta.lifecycle` understood by the server (see OrderConfig::lifecycle()). */
const DEFAULTS = { initial: null, completed: null, canceled: null, terminal: [], dispatch: true, strict_transitions: false };

/**
 * Manages an order config's configured lifecycle (`meta.lifecycle`): the activity new orders start
 * at, the activities that complete, cancel and end an order, whether orders may be dispatched and
 * whether transitions must follow the flow. Without a configured lifecycle the default dispatch
 * lifecycle applies.
 *
 * @extends Component
 */
export default class OrderConfigManagerLifecycleComponent extends Component {
    @service modalsManager;
    @service notifications;
    @service abilities;
    @service intl;

    /** The config being managed. */
    @tracked config;

    /** Working copy of `meta.lifecycle`, or null when the config has none. */
    @tracked lifecycle = null;

    /** Last saved lifecycle, to tell whether there is anything to save. */
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

    get enabled() {
        return this.lifecycle !== null;
    }

    /** Activities of the flow as select options. */
    get activities() {
        const flow = normalizeOrderConfigFlow(this.config?.flow ?? {});
        return Object.values(flow)
            .filter((activity) => activity && typeof activity === 'object')
            .map((activity) => ({ code: activity.code ?? activity.key, label: activity.status || activity.code || activity.key }))
            .filter((activity) => activity.code);
    }

    get selectedInitial() {
        return this.option(this.lifecycle?.initial);
    }

    get selectedCompleted() {
        return this.option(this.lifecycle?.completed);
    }

    get selectedCanceled() {
        return this.option(this.lifecycle?.canceled);
    }

    get selectedTerminal() {
        return (this.lifecycle?.terminal ?? []).map((code) => this.option(code)).filter(Boolean);
    }

    get isDirty() {
        return JSON.stringify(this.lifecycle) !== JSON.stringify(this.saved);
    }

    /** Problems that make the lifecycle unusable server-side. */
    get problems() {
        if (!this.lifecycle) {
            return [];
        }

        const problems = [];
        const codes = this.activities.map((activity) => activity.code);
        if (!this.lifecycle.initial) {
            problems.push(this.intl.t('order-config-manager.lifecycle.problem-initial-required'));
        }

        const referenced = [this.lifecycle.initial, this.lifecycle.completed, this.lifecycle.canceled, ...(this.lifecycle.terminal ?? [])].filter(Boolean);
        for (const code of new Set(referenced)) {
            if (!codes.includes(code)) {
                problems.push(this.intl.t('order-config-manager.lifecycle.problem-missing-activity', { code }));
            }
        }

        return problems;
    }

    get canSave() {
        return this.canEdit && this.isDirty && this.problems.length === 0;
    }

    /** Arrow function so templates can call it as a helper: (this.option code). */
    option = (code) => {
        return code ? (this.activities.find((activity) => activity.code === code) ?? { code, label: code }) : null;
    };

    changeConfig(config) {
        this.config = config;
        this.saved = this.normalize(config?.meta?.lifecycle);
        this.lifecycle = this.normalize(config?.meta?.lifecycle);
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

    @action toggleEnabled(enabled) {
        if (enabled) {
            const flow = normalizeOrderConfigFlow(this.config?.flow ?? {});
            this.lifecycle = this.saved ? { ...this.saved, terminal: [...this.saved.terminal] } : { ...DEFAULTS, terminal: [], initial: getOrderConfigFlowRootCode(flow) ?? null };
            return;
        }

        if (this.saved) {
            this.modalsManager.confirm({
                title: this.intl.t('order-config-manager.lifecycle.remove-title'),
                body: this.intl.t('order-config-manager.lifecycle.remove-body'),
                acceptButtonText: this.intl.t('order-config-manager.lifecycle.remove-confirm'),
                confirm: async (modal) => {
                    modal.startLoading();
                    this.lifecycle = null;
                    await this.save.perform();
                    modal.done();
                },
                decline: (modal) => {
                    modal.done();
                },
            });
            return;
        }

        this.lifecycle = null;
    }

    @action setActivity(key, activity) {
        this.lifecycle = { ...this.lifecycle, [key]: activity?.code ?? null };
    }

    @action setTerminal(activities) {
        this.lifecycle = { ...this.lifecycle, terminal: (activities ?? []).map((activity) => activity.code) };
    }

    @action setFlag(key, value) {
        this.lifecycle = { ...this.lifecycle, [key]: Boolean(value) };
    }

    @task *save() {
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
            this.notifications.success(this.intl.t('order-config-manager.lifecycle.saved', { orderConfigName: this.config.name }));
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
