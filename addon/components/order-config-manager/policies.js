import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';
import humanize from '@fleetbase/ember-core/utils/humanize';

/**
 * Manages an order config's policies: the settings that decide how its orders behave, such as how
 * adhoc orders are offered, what customers may do and whether a completed order is priced again.
 * The policies and their settings come from the server (`order-configs/capabilities`), so a policy
 * an extension registers shows up here too. A setting left at its default isn't stored.
 *
 * @extends Component
 */
export default class OrderConfigManagerPoliciesComponent extends Component {
    @service fetch;
    @service notifications;
    @service abilities;
    @service intl;

    /** The config being managed. */
    @tracked config;

    /** The registered policies, each with its settings. */
    @tracked policies = [];

    /** Working copy of the config's stored policies. */
    @tracked values = {};

    /** Stored policies as last saved, serialized, to tell whether there is anything to save. */
    @tracked saved = '{}';

    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.changeConfig(config);
        this.loadPolicies.perform();

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.changeConfig(newConfig);
        });
    }

    get canEdit() {
        return this.abilities.can('fleet-ops update order-config');
    }

    get isDirty() {
        return JSON.stringify(this.values) !== this.saved;
    }

    /** The config's activities, for settings that name one. */
    get activityChoices() {
        return Object.values(this.config?.flow ?? {})
            .filter((activity) => activity && activity.code)
            .map((activity) => ({ value: activity.code, label: activity.status || activity.code }));
    }

    /** Policies with each setting's current value and choices, for the template. */
    get rows() {
        return this.policies.map((policy) => ({
            ...policy,
            settings: (policy.settings ?? []).map((setting) => ({
                ...setting,
                label: humanize(setting.name),
                value: this.valueOf(policy.key, setting),
                isSet: this.storedValue(policy.key, setting.name) !== undefined,
                options: this.optionsFor(setting),
            })),
        }));
    }

    storedValue(policyKey, name) {
        return this.values?.[policyKey]?.[name];
    }

    valueOf(policyKey, setting) {
        const stored = this.storedValue(policyKey, setting.name);
        return stored === undefined ? setting.default : stored;
    }

    optionsFor(setting) {
        if (setting.type !== 'choice') {
            return null;
        }

        const choices = setting.choices === 'activity' ? this.activityChoices : (setting.choices ?? []);
        return [{ value: null, label: this.intl.t('order-config-manager.policies.default') }, ...choices];
    }

    @action setValue(policyKey, name, value) {
        const policy = { ...(this.values[policyKey] ?? {}) };
        if (value === '' || value === undefined) {
            delete policy[name];
        } else {
            policy[name] = value;
        }

        this.values = { ...this.values, [policyKey]: policy };
    }

    @action setNumber(policyKey, name, event) {
        const raw = event?.target?.value ?? event;
        this.setValue(policyKey, name, raw === '' || raw === null ? undefined : Number(raw));
    }

    @action setText(policyKey, name, event) {
        this.setValue(policyKey, name, event?.target?.value ?? event);
    }

    @action addListItem(policyKey, setting, item) {
        const list = [...(this.valueOf(policyKey, setting) ?? [])];
        if (item && !list.includes(item)) {
            list.push(item);
        }
        this.setValue(policyKey, setting.name, list);
    }

    @action removeListItem(policyKey, setting, index) {
        const list = [...(this.valueOf(policyKey, setting) ?? [])];
        list.splice(index, 1);
        this.setValue(policyKey, setting.name, list);
    }

    @action reset(policyKey, name) {
        this.setValue(policyKey, name, undefined);
    }

    @task *loadPolicies() {
        try {
            const { policies } = yield this.fetch.get('order-configs/capabilities');
            this.policies = policies ?? [];
            this.changeConfig(this.config);
        } catch (error) {
            this.policies = [];
            this.notifications.serverError(error);
        }
    }

    @task *save() {
        this.config.policies = this.merge(this.config.policies, this.values);

        try {
            yield this.config.save();
            this.saved = JSON.stringify(this.values);
            this.notifications.success(this.intl.t('order-config-manager.saved-success-message', { orderConfigName: this.config.name }));
            if (typeof this.args.onConfigUpdated === 'function') {
                this.args.onConfigUpdated(this.config);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * The stored policies with the edited ones written in, nested by key as the server keeps them
     * (`dispatch.adhoc` under `dispatch`). Policies this console doesn't know are kept as they are.
     */
    merge(stored, values) {
        const nested = JSON.parse(JSON.stringify(stored ?? {}));
        for (const policy of this.policies) {
            const path = policy.key.split('.');
            let node = nested;
            path.slice(0, -1).forEach((segment) => {
                node[segment] = node[segment] && typeof node[segment] === 'object' ? node[segment] : {};
                node = node[segment];
            });

            const settings = values[policy.key] ?? {};
            if (Object.keys(settings).length) {
                node[path[path.length - 1]] = settings;
            } else {
                delete node[path[path.length - 1]];
            }
        }

        return nested;
    }

    /** The stored settings of each known policy, keyed by policy key, for editing. */
    flatten(stored) {
        const flat = {};
        for (const policy of this.policies) {
            const settings = get(stored ?? {}, policy.key);
            if (settings && typeof settings === 'object' && !Array.isArray(settings)) {
                flat[policy.key] = { ...settings };
            }
        }

        return flat;
    }

    changeConfig(config) {
        this.config = config;
        this.values = this.flatten(config?.policies ?? {});
        this.saved = JSON.stringify(this.values);
    }
}
