import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import humanize from '@fleetbase/ember-core/utils/humanize';

const ACTIVITY_PREFIX = 'activity:';

/**
 * Manages what an order config does when things happen to its orders: when an order reaches one
 * of its activities (the activity's `actions` in the flow) or on an order event such as
 * `order.created` or `driver.approaching` (the config's `triggers`). Both are edited here as one
 * list of "when ..., do ..." rows. The actions and events come from the server
 * (`order-configs/capabilities`), so an extension's actions show up too.
 *
 * A trigger's conditions (`when`) are kept as they are; they are set through the API.
 *
 * @extends Component
 */
export default class OrderConfigManagerTriggersComponent extends Component {
    @service fetch;
    @service notifications;
    @service abilities;
    @service intl;

    /** The config being managed. */
    @tracked config;

    /** The registered actions and trigger events. */
    @tracked actions = [];
    @tracked events = [];

    /** Working list of triggers: `{ id, on, action, params, options, when }`. */
    @tracked rows = [];

    /** Rows as last saved, serialized, to tell whether there is anything to save. */
    @tracked saved = '[]';

    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.changeConfig(config);
        this.loadCapabilities.perform();

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.changeConfig(newConfig);
        });
    }

    get canEdit() {
        return this.abilities.can('fleet-ops update order-config');
    }

    get isDirty() {
        return JSON.stringify(this.serialize(this.rows)) !== this.saved;
    }

    /** What a trigger can run on: reaching an activity, or an order event. */
    get eventOptions() {
        const activities = Object.values(this.config?.flow ?? {})
            .filter((activity) => activity && activity.code)
            .map((activity) => ({
                value: ACTIVITY_PREFIX + activity.code,
                label: this.intl.t('order-config-manager.triggers.reaches-activity', { activity: activity.status || activity.code }),
            }));
        const events = this.events.map((event) => ({ value: event, label: this.intl.t(`order-config-manager.triggers.events.${event}`, { default: humanize(event) }) }));

        return [...activities, ...events];
    }

    get actionOptions() {
        return this.actions.map((action) => ({ value: action.name, label: action.label }));
    }

    /** Rows with what the template needs to show each one. */
    get editableRows() {
        return this.rows.map((row) => {
            const definition = this.actions.find((action) => action.name === row.action);
            return {
                ...row,
                parameters: Object.entries(definition?.parameters ?? {}).map(([name, description]) => ({ name, description, label: humanize(name), value: row.params?.[name] ?? '' })),
                isApproaching: row.on === 'driver.approaching',
                conditionCount: Array.isArray(row.when) ? row.when.length : 0,
            };
        });
    }

    @action addRow() {
        this.rows = [
            ...this.rows,
            { id: this.nextId(), on: this.eventOptions[0]?.value ?? 'order.created', action: this.actionOptions[0]?.value ?? 'notify_customer', params: {}, options: {}, when: [] },
        ];
    }

    @action removeRow(id) {
        this.rows = this.rows.filter((row) => row.id !== id);
    }

    @action setRowField(id, field, value) {
        this.rows = this.rows.map((row) => (row.id === id ? { ...row, [field]: value } : row));
    }

    @action setParam(id, name, event) {
        const value = event?.target?.value ?? event;
        this.rows = this.rows.map((row) => (row.id === id ? { ...row, params: { ...row.params, [name]: value } } : row));
    }

    @action setOption(id, name, event) {
        let value = event?.target?.value ?? event;
        if (name === 'distance') {
            value = value === '' ? undefined : Number(value);
        }
        this.rows = this.rows.map((row) => (row.id === id ? { ...row, options: { ...row.options, [name]: value } } : row));
    }

    @task *loadCapabilities() {
        try {
            const { actions, events } = yield this.fetch.get('order-configs/capabilities');
            this.actions = actions ?? [];
            this.events = events ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *save() {
        const { triggers, flow } = this.split(this.rows);
        this.config.triggers = triggers;
        this.config.flow = flow;

        try {
            yield this.config.save();
            this.saved = JSON.stringify(this.serialize(this.rows));
            this.notifications.success(this.intl.t('order-config-manager.saved-success-message', { orderConfigName: this.config.name }));
            if (typeof this.args.onConfigUpdated === 'function') {
                this.args.onConfigUpdated(this.config);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** The rows as the server keeps them: activity rows in the flow, the rest in `triggers`. */
    split(rows) {
        const flow = JSON.parse(JSON.stringify(this.config.flow ?? {}));
        Object.values(flow).forEach((activity) => {
            if (activity && typeof activity === 'object') {
                activity.actions = [];
            }
        });

        const triggers = [];
        for (const row of this.serialize(rows)) {
            const { on, ...trigger } = row;
            if (on.startsWith(ACTIVITY_PREFIX)) {
                const activity = flow[on.slice(ACTIVITY_PREFIX.length)];
                if (activity) {
                    delete trigger.options;
                    activity.actions.push(trigger);
                }
            } else {
                triggers.push({ event: on, ...trigger });
            }
        }

        return { triggers, flow };
    }

    /** Rows without their editor ids and empty values. */
    serialize(rows) {
        return rows.map(({ on, action, params, options, when }) => {
            const row = { on, action };
            const cleanParams = Object.fromEntries(Object.entries(params ?? {}).filter(([, value]) => value !== '' && value !== undefined && value !== null));
            const cleanOptions = Object.fromEntries(Object.entries(options ?? {}).filter(([, value]) => value !== '' && value !== undefined && value !== null));
            if (Object.keys(cleanParams).length) {
                row.params = cleanParams;
            }
            if (on === 'driver.approaching' && Object.keys(cleanOptions).length) {
                row.options = cleanOptions;
            }
            if (Array.isArray(when) && when.length) {
                row.when = when;
            }

            return row;
        });
    }

    /** The config's triggers and its activities' actions as one list of rows. */
    read(config) {
        const rows = [];
        Object.values(config?.flow ?? {}).forEach((activity) => {
            (activity?.actions ?? []).forEach((trigger) => {
                rows.push({ id: this.nextId(), on: ACTIVITY_PREFIX + activity.code, action: trigger.action, params: { ...(trigger.params ?? {}) }, options: {}, when: trigger.when ?? [] });
            });
        });
        (config?.triggers ?? []).forEach((trigger) => {
            rows.push({
                id: this.nextId(),
                on: trigger.event,
                action: trigger.action,
                params: { ...(trigger.params ?? {}) },
                options: { ...(trigger.options ?? {}) },
                when: trigger.when ?? [],
            });
        });

        return rows;
    }

    nextId() {
        this._id = (this._id ?? 0) + 1;
        return this._id;
    }

    changeConfig(config) {
        this.config = config;
        this.rows = this.read(config);
        this.saved = JSON.stringify(this.serialize(this.rows));
    }
}
