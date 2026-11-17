import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import sentenceParts from '../../utils/sentence-parts';

const E = 'order-config-manager.events';
const EVENT_KEYS = {
    'order.created': 'created',
    'order.completed': 'completed',
    'order.canceled': 'canceled',
    'dispatch.offer_exhausted': 'exhausted',
    'driver.approaching': 'approaching',
};
const APPROACHING_DISTANCE = 500;

/**
 * An order config's order events: what happens when something happens to an order outside its
 * steps, such as being canceled or the driver approaching. Opened from the Activity Flow canvas.
 *
 * Stored triggers (`config.triggers`, each `{ event, options, action, params, when }`) are shown
 * grouped by event and options, each group listing its actions; saving writes them back flat.
 */
export default class OrderConfigOrderEventsComponent extends Component {
    @service abilities;
    @service notifications;
    @service intl;

    @tracked groups = [];
    @tracked saved = '[]';

    constructor() {
        super(...arguments);
        this.groups = this.read(this.config?.triggers ?? []);
        this.saved = JSON.stringify(this.serialize(this.groups));
    }

    get config() {
        return this.args.overlay?.config ?? this.args.resource;
    }

    get readonly() {
        return this.abilities.cannot('fleet-ops update order-config');
    }

    get isDirty() {
        return JSON.stringify(this.serialize(this.groups)) !== this.saved;
    }

    get eventMenu() {
        const events = this.args.overlay?.events?.length ? this.args.overlay.events : Object.keys(EVENT_KEYS);
        return events.map((event) => {
            const key = EVENT_KEYS[event];
            return {
                value: event,
                label: key ? this.intl.t(`${E}.${key}-menu`) : event,
                description: key ? this.intl.t(`${E}.${key}-description`) : '',
            };
        });
    }

    get cards() {
        return this.groups.map((group, index) => {
            const key = EVENT_KEYS[group.event];
            let parts;
            let help = null;
            if (group.event === 'driver.approaching') {
                const distance = group.options?.distance;
                const target = group.options?.target === 'dropoff' ? 'dropoff' : 'pickup';
                parts = sentenceParts(this.intl, `${E}.approaching`, {
                    metres: {
                        kind: 'number',
                        value: distance ?? '',
                        defaultLabel: String(APPROACHING_DISTANCE),
                        width: 128,
                        ariaLabel: this.intl.t(`${E}.distance-aria`),
                        invalid: distance !== undefined && (Number.isNaN(Number(distance)) || Number(distance) < 50),
                        onChange: (value) => this.setOption(index, 'distance', value),
                    },
                    target: {
                        kind: 'choice',
                        value: target,
                        isDefault: false,
                        ariaLabel: this.intl.t(`${E}.target-aria`),
                        options: [
                            { value: 'pickup', label: this.intl.t(`${E}.target-pickup`), isDefault: true },
                            { value: 'dropoff', label: this.intl.t(`${E}.target-dropoff`) },
                        ],
                        onChange: (value) => this.setOption(index, 'target', value),
                    },
                });
                help = this.intl.t(target === 'dropoff' ? `${E}.approaching-help-dropoff` : `${E}.approaching-help`);
            } else {
                parts = [{ kind: 'text', text: this.intl.t(`${E}.when`, { event: key ? this.intl.t(`${E}.${key}`) : group.event }) }];
            }

            return { index, group, parts, help, isNew: group.isNew };
        });
    }

    // ----- editing

    @action addEvent(event) {
        const options = event === 'driver.approaching' ? { target: 'pickup' } : {};
        this.groups = [...this.groups, { id: this.nextId(), event, options, triggers: [], isNew: true }];
    }

    @action removeGroup(index) {
        this.groups = this.groups.filter((_, i) => i !== index);
    }

    @action setTriggers(index, triggers) {
        this.groups = this.groups.map((group, i) => (i === index ? { ...group, triggers } : group));
    }

    setOption(index, name, value) {
        this.groups = this.groups.map((group, i) => {
            if (i !== index) return group;
            const options = { ...(group.options ?? {}) };
            if (value === undefined || value === null || value === '') {
                delete options[name];
            } else {
                options[name] = value;
            }
            return { ...group, options };
        });
    }

    @action discard() {
        this.groups = this.read(JSON.parse(this.saved));
    }

    @task *save() {
        this.config.triggers = this.serialize(this.groups);
        try {
            yield this.config.save();
            this.saved = JSON.stringify(this.config.triggers);
            this.groups = this.read(this.config.triggers);
            this.notifications.success(this.intl.t(`${E}.saved`));
            this.args.overlay?.onSaved?.(this.config);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ----- reading and writing

    read(triggers) {
        const groups = [];
        (triggers ?? []).forEach((trigger) => {
            const options = trigger.event === 'driver.approaching' ? { ...(trigger.options ?? {}) } : {};
            const key = JSON.stringify([trigger.event, options]);
            let group = groups.find((candidate) => candidate.key === key);
            if (!group) {
                group = { id: this.nextId(), key, event: trigger.event, options, triggers: [] };
                groups.push(group);
            }
            const item = { action: trigger.action };
            if (trigger.params) item.params = { ...trigger.params };
            if (trigger.when?.length) item.when = trigger.when;
            group.triggers.push(item);
        });
        return groups;
    }

    serialize(groups) {
        return groups.flatMap((group) =>
            (group.triggers ?? [])
                .filter((trigger) => trigger.action)
                .map((trigger) => {
                    const row = { event: group.event };
                    const options = Object.fromEntries(Object.entries(group.options ?? {}).filter(([, value]) => value !== undefined && value !== null && value !== ''));
                    if (group.event === 'driver.approaching' && Object.keys(options).length) row.options = options;
                    if (trigger.when?.length) row.when = trigger.when;
                    row.action = trigger.action;
                    if (trigger.params && Object.keys(trigger.params).length) row.params = trigger.params;
                    return row;
                })
        );
    }

    nextId() {
        this._id = (this._id ?? 0) + 1;
        return this._id;
    }
}
