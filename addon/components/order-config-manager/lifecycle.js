import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { underscore } from '@ember/string';
import generateUUID from '@fleetbase/ember-core/utils/generate-uuid';
import normalizeOrderConfigFlow, { getOrderConfigFlowRootCode } from '../../utils/normalize-order-config-flow';

/** Keys of `meta.lifecycle` understood by the server (see OrderConfig::lifecycle()). */
const DEFAULTS = { initial: null, completed: null, canceled: null, terminal: [], dispatch: true, strict_transitions: false };

const NEW_ACTIVITY = { status: '', code: '', details: '', color: '#1f2937' };

/**
 * Manages an order config's configured lifecycle (`meta.lifecycle`): the activity new orders start
 * at, the activities that complete, cancel and end an order, whether orders may be dispatched and
 * whether transitions must follow the flow. Without a configured lifecycle the default dispatch
 * lifecycle applies.
 *
 * The activities themselves (the config's `flow`) can be defined here too, so a lifecycle can be
 * built from scratch without the graph editor: add or remove activities, set their labels and the
 * activities each one can move to. Flow and lifecycle are saved together.
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

    /** Working copy of the config's flow, keyed by activity code, with next activities as codes. */
    @tracked flow = {};

    /** Flow as last saved, serialized, to tell whether there is anything to save. */
    @tracked savedFlow = '{}';

    /** The activity being added. */
    @tracked newActivity = { ...NEW_ACTIVITY };

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
        return Object.values(this.flow).map((activity) => ({ code: activity.code, label: activity.status || activity.code }));
    }

    /** Activities with their next activities resolved, for the editor. */
    get activityRows() {
        return Object.values(this.flow).map((activity) => ({
            ...activity,
            next: (activity.activities ?? []).map((code) => this.option(code)).filter(Boolean),
            isInitial: this.lifecycle?.initial === activity.code,
        }));
    }

    get hasActivities() {
        return Object.keys(this.flow).length > 0;
    }

    get newActivityCode() {
        return underscore((this.newActivity.code || this.newActivity.status || '').trim());
    }

    get canAddActivity() {
        const code = this.newActivityCode;
        return this.canEdit && this.newActivity.status.trim() !== '' && code !== '' && !this.flow[code];
    }

    get newActivityCodeTaken() {
        const code = this.newActivityCode;
        return code !== '' && Boolean(this.flow[code]);
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
        return JSON.stringify(this.lifecycle) !== JSON.stringify(this.saved) || this.serializeFlow() !== this.savedFlow;
    }

    /** Problems that make the lifecycle unusable server-side. */
    get problems() {
        if (!this.lifecycle) {
            return [];
        }

        const problems = [];
        const codes = this.activities.map((activity) => activity.code);
        if (codes.length === 0) {
            problems.push(this.intl.t('order-config-manager.lifecycle.problem-no-activities'));
        }
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
        this.flow = this.readFlow(config?.flow);
        this.savedFlow = this.serializeFlow();
        this.newActivity = { ...NEW_ACTIVITY };
    }

    /** The stored flow as plain activity objects with next activities as codes. */
    readFlow(flow) {
        const normalized = normalizeOrderConfigFlow(flow ?? {});
        const result = {};
        Object.values(normalized).forEach((activity, index) => {
            if (!activity || typeof activity !== 'object') {
                return;
            }
            const code = activity.code ?? activity.key;
            if (!code) {
                return;
            }
            result[code] = {
                ...activity,
                code,
                key: activity.key ?? code,
                sequence: activity.sequence ?? index,
                activities: (isArray(activity.activities) ? activity.activities : []).map((next) => (typeof next === 'string' ? next : (next?.code ?? next?.key))).filter(Boolean),
            };
        });
        return result;
    }

    serializeFlow() {
        const serialized = {};
        Object.values(this.flow).forEach((activity) => {
            // Graph-only state never belongs in the stored flow.
            // eslint-disable-next-line no-unused-vars
            const { node, id, parentId, _internalModel, ...stored } = activity;
            serialized[activity.code] = { ...stored, activities: [...(activity.activities ?? [])] };
        });
        return JSON.stringify(serialized);
    }

    @action setNewActivityField(field, event) {
        this.newActivity = { ...this.newActivity, [field]: event.target.value };
    }

    @action addActivity() {
        if (!this.canAddActivity) {
            return;
        }

        const code = this.newActivityCode;
        const activity = {
            key: code,
            code,
            status: this.newActivity.status.trim(),
            details: this.newActivity.details.trim(),
            color: this.newActivity.color || '#1f2937',
            sequence: Object.keys(this.flow).length,
            activities: [],
            events: [],
            logic: [],
            actions: [],
            entities: [],
            complete: false,
            require_pod: false,
            pod_method: 'scan',
            options: {},
            internalId: generateUUID(),
        };

        this.flow = { ...this.flow, [code]: activity };
        if (this.lifecycle && !this.lifecycle.initial) {
            this.lifecycle = { ...this.lifecycle, initial: code };
        }
        this.newActivity = { ...NEW_ACTIVITY };
    }

    @action removeActivity(code) {
        const flow = { ...this.flow };
        delete flow[code];
        Object.keys(flow).forEach((key) => {
            flow[key] = { ...flow[key], activities: flow[key].activities.filter((next) => next !== code) };
        });
        this.flow = flow;

        if (this.lifecycle) {
            const without = (value) => (value === code ? null : value);
            this.lifecycle = {
                ...this.lifecycle,
                initial: without(this.lifecycle.initial),
                completed: without(this.lifecycle.completed),
                canceled: without(this.lifecycle.canceled),
                terminal: this.lifecycle.terminal.filter((item) => item !== code),
            };
        }
    }

    @action setActivityField(code, field, event) {
        this.flow = { ...this.flow, [code]: { ...this.flow[code], [field]: event.target.value } };
    }

    @action setNextActivities(code, selected) {
        this.flow = { ...this.flow, [code]: { ...this.flow[code], activities: (selected ?? []).map((activity) => activity.code) } };
    }

    /** Drops the default dispatch activities so a lifecycle can be designed from nothing. */
    @action startBlankFlow() {
        this.modalsManager.confirm({
            title: this.intl.t('order-config-manager.lifecycle.blank-flow-title'),
            body: this.intl.t('order-config-manager.lifecycle.blank-flow-body'),
            acceptButtonText: this.intl.t('order-config-manager.lifecycle.blank-flow-confirm'),
            confirm: (modal) => {
                this.flow = {};
                if (this.lifecycle) {
                    this.lifecycle = { ...this.lifecycle, initial: null, completed: null, canceled: null, terminal: [] };
                }
                modal.done();
            },
        });
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
            this.config.set('flow', JSON.parse(this.serializeFlow()));
            yield this.config.save();
            this.saved = this.normalize(this.lifecycle);
            this.lifecycle = this.normalize(this.lifecycle);
            this.savedFlow = this.serializeFlow();
            this.notifications.success(this.intl.t('order-config-manager.lifecycle.saved', { orderConfigName: this.config.name }));
            if (typeof this.args.onConfigUpdated === 'function') {
                this.args.onConfigUpdated(this.config);
            }
        } catch (error) {
            this.config.rollbackAttributes();
            this.lifecycle = this.normalize(this.config.meta?.lifecycle);
            this.flow = this.readFlow(this.config.flow);
            this.notifications.serverError(error);
        }
    }
}
