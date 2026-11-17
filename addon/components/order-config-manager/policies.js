import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';
import humanize from '@fleetbase/ember-core/utils/humanize';
import sentenceParts from '../../utils/sentence-parts';

const P = 'order-config-manager.policies';
const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'wallet'];

/**
 * An order config's policies, written as plain sentences with the settings inline. Each built-in
 * policy has its own sentences; a policy an extension registers reads "Label: [control]" for each
 * of its settings. A setting left at its default isn't stored, and reads as the default.
 *
 * Policies and their settings come from `order-configs/capabilities`; the company's dispatch
 * defaults (offer method, time, radius) come from Settings → Dispatch, so placeholders show what
 * an empty setting falls back to.
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

    /** Working copy of the config's stored policies, keyed by policy key. */
    @tracked values = {};

    /** The stored policies as last saved, keyed by policy key. */
    @tracked savedValues = {};

    /** The company's adhoc dispatch defaults. */
    @tracked companyDefaults = { adhoc_strategy: 'sequential', adhoc_offer_timeout: 20, adhoc_distance: 6000 };

    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.changeConfig(config);
        this.loadPolicies.perform();
        this.loadCompanyDefaults.perform();

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.changeConfig(newConfig);
        });
    }

    get canEdit() {
        return this.abilities.can('fleet-ops update order-config');
    }

    get readonly() {
        return !this.canEdit;
    }

    get isSystemConfig() {
        return Boolean(this.config?.core_service);
    }

    get allDefaults() {
        return Object.values(this.values).every((settings) => Object.keys(settings ?? {}).length === 0);
    }

    get unsavedCount() {
        return this.lines.filter((line) => line.unsaved).length;
    }

    get errorCount() {
        return this.lines.filter((line) => line.error).length;
    }

    get errorHint() {
        return this.errorCount ? this.intl.t(`${P}.save-hint-errors`, { count: this.errorCount }) : null;
    }

    get lines() {
        return this.groups.flatMap((group) => group.lines);
    }

    /** The config's steps, for settings that name one. */
    get steps() {
        return Object.values(this.config?.flow ?? {})
            .filter((activity) => activity && activity.code)
            .map((activity) => ({ value: activity.code, label: activity.status || activity.code }));
    }

    // ----- reading and writing values

    stored(policyKey, name) {
        return this.values?.[policyKey]?.[name];
    }

    definition(policyKey) {
        return this.policies.find((policy) => policy.key === policyKey);
    }

    setting(policyKey, name) {
        return (this.definition(policyKey)?.settings ?? []).find((setting) => setting.name === name);
    }

    valueOf(policyKey, name) {
        const stored = this.stored(policyKey, name);
        return stored === undefined ? this.setting(policyKey, name)?.default : stored;
    }

    isDefault(policyKey, name) {
        return this.stored(policyKey, name) === undefined;
    }

    isUnsaved(policyKey, name) {
        return JSON.stringify(this.values?.[policyKey]?.[name]) !== JSON.stringify(this.savedValues?.[policyKey]?.[name]);
    }

    @action setValue(policyKey, name, value) {
        const policy = { ...(this.values[policyKey] ?? {}) };
        const setting = this.setting(policyKey, name);
        const isEmptyList = Array.isArray(value) && value.length === 0;
        // Choosing the default, or emptying a setting, stores nothing so it keeps following the default.
        if (value === '' || value === undefined || value === null || isEmptyList || JSON.stringify(value) === JSON.stringify(setting?.default)) {
            delete policy[name];
        } else {
            policy[name] = value;
        }

        this.values = { ...this.values, [policyKey]: policy };
    }

    @action reset(policyKey, names) {
        const policy = { ...(this.values[policyKey] ?? {}) };
        names.forEach((name) => delete policy[name]);
        this.values = { ...this.values, [policyKey]: policy };
    }

    @action discard() {
        this.values = JSON.parse(JSON.stringify(this.savedValues));
    }

    // ----- building sentences

    t(key, values) {
        return this.intl.t(`${P}.${key}`, values);
    }

    /** A choice part for a setting, from `[value, label]` pairs. */
    choice(policyKey, name, pairs, ariaKey) {
        const setting = this.setting(policyKey, name);
        return {
            kind: 'choice',
            value: this.valueOf(policyKey, name),
            isDefault: this.isDefault(policyKey, name),
            ariaLabel: this.t(ariaKey),
            options: pairs.map(([value, label]) => ({ value, label, isDefault: value === (setting?.default ?? null) })),
            onChange: (value) => this.setValue(policyKey, name, value),
        };
    }

    /** An on/off setting as a two-way choice. */
    toggle(policyKey, name, offLabel, onLabel, ariaKey) {
        return this.choice(
            policyKey,
            name,
            [
                [false, offLabel],
                [true, onLabel],
            ],
            ariaKey
        );
    }

    /** A number part with the range from the setting's rules. Returns `[part, error]`. */
    number(policyKey, name, defaultLabel, ariaKey, width) {
        const setting = this.setting(policyKey, name) ?? {};
        const value = this.stored(policyKey, name);
        const n = Number(value);
        const invalid = value !== undefined && (Number.isNaN(n) || (setting.min !== undefined && n < setting.min) || (setting.max !== undefined && n > setting.max));
        const part = {
            kind: 'number',
            value: value ?? '',
            defaultLabel,
            invalid,
            width,
            ariaLabel: this.t(ariaKey),
            onChange: (number) => this.setValue(policyKey, name, number),
        };

        return [part, invalid ? this.intl.t('order-config-manager.common.number-range-error', { min: setting.min, max: setting.max }) : null];
    }

    methodLabel(method) {
        const key = `${P}.ordering.method.${method}`;
        return this.intl.exists(key) ? this.intl.t(key) : humanize(method);
    }

    list(policyKey, name, choices, emptyKey, ariaKey) {
        const values = this.valueOf(policyKey, name) ?? [];
        return {
            kind: 'list',
            items: values.map((value) => ({ value, label: this.methodLabel(value) })),
            options: choices.map((value) => ({ value, label: this.methodLabel(value) })),
            emptyLabel: this.intl.t('order-config-manager.common.default-suffix', { value: this.t(emptyKey) }),
            ariaLabel: this.t(ariaKey),
            onChange: (list) => this.setValue(policyKey, name, list),
        };
    }

    /** A sentence line. `settings` are the `[policyKey, name]` pairs it shows. */
    line(policyKey, names, key, controls, { help, error, dim, values } = {}) {
        return {
            policyKey,
            names,
            parts: sentenceParts(this.intl, `${P}.${key}`, controls, values),
            help,
            error,
            dim,
            unsaved: names.some((name) => this.isUnsaved(policyKey, name)),
            isSet: names.some((name) => !this.isDefault(policyKey, name)),
            canReset: names.some((name) => !this.isDefault(policyKey, name)),
            onReset: () => this.reset(policyKey, names),
        };
    }

    strategyLabel(value) {
        const key = `${P}.offering.strategy.${value}`;
        if (this.intl.exists(key)) {
            return this.intl.t(key);
        }
        return (this.setting('dispatch.adhoc', 'strategy')?.choices ?? []).find((choice) => choice.value === value)?.label ?? humanize(value ?? '');
    }

    stepLabel(code) {
        return this.steps.find((step) => step.value === code)?.label ?? code;
    }

    // ----- groups

    get groups() {
        if (!this.policies.length) {
            return [];
        }

        const known = ['dispatch.adhoc', 'dispatch.matching', 'customer.ordering', 'customer.cancellation', 'customer.interactions', 'pricing'];
        const groups = [this.offeringGroup(), this.driversGroup(), this.orderingGroup(), this.cancellingGroup(), this.interactionsGroup(), this.priceGroup()].filter(Boolean);
        const extensions = this.policies.filter((policy) => !known.includes(policy.key)).map((policy) => this.extensionGroup(policy));

        return [...groups, ...extensions].map((group) => {
            const set = group.lines.filter((line) => line.isSet).length;
            const unsaved = group.lines.filter((line) => line.unsaved).length;
            return {
                ...group,
                anchor: `oc-policy-${group.id.replace(/[^a-z0-9]+/gi, '-')}`,
                navCount: [set ? this.t('nav-set', { count: set }) : this.t('nav-defaults'), unsaved ? this.t('nav-unsaved', { count: unsaved }) : null].filter(Boolean).join(' · '),
                hasUnsaved: unsaved > 0,
            };
        });
    }

    offeringGroup() {
        const K = 'dispatch.adhoc';
        if (!this.definition(K)) return null;
        const company = this.companyDefaults;
        const offering = this.valueOf(K, 'customer_orders') === true;
        const strategy = this.valueOf(K, 'strategy') ?? company.adhoc_strategy;
        const dimHelp = offering ? null : this.t('applies-when-offered');
        const strategyChoices = (this.setting(K, 'strategy')?.choices ?? []).map((choice) => [choice.value, this.strategyLabel(choice.value)]);
        const strategyBase = this.choice(K, 'strategy', strategyChoices, 'offering.strategy-aria');
        // An empty strategy follows the company's, so that is the option marked as the default.
        const strategyPart = { ...strategyBase, value: strategy, options: strategyBase.options.map((option) => ({ ...option, isDefault: option.value === company.adhoc_strategy })) };
        const [seconds, secondsError] = this.number(K, 'offer_timeout', String(company.adhoc_offer_timeout ?? 20), 'offering.seconds-aria', 132);
        const [metres, metresError] = this.number(K, 'radius', Number(company.adhoc_distance ?? 6000).toLocaleString(), 'offering.radius-aria', 150);
        const companyHelp = this.t('offering.strategy-help', { strategy: this.strategyLabel(company.adhoc_strategy), seconds: company.adhoc_offer_timeout ?? 20 });

        return {
            id: K,
            nav: this.t('offering.nav'),
            title: this.t('offering.title'),
            description: this.t('offering.description'),
            lines: [
                this.line(
                    K,
                    ['customer_orders'],
                    'offering.customer-orders.sentence',
                    { choice: this.toggle(K, 'customer_orders', this.t('offering.customer-orders.wait'), this.t('offering.customer-orders.offer'), 'offering.customer-orders-aria') },
                    { help: this.t('offering.customer-orders-help') }
                ),
                strategy === 'sequential'
                    ? this.line(
                          K,
                          ['strategy', 'offer_timeout'],
                          'offering.strategy.sentence',
                          { strategy: strategyPart, seconds },
                          { help: dimHelp ?? companyHelp, error: secondsError, dim: !offering }
                      )
                    : this.line(K, ['strategy', 'offer_timeout'], 'offering.strategy-only', { strategy: strategyPart }, { help: dimHelp ?? companyHelp, dim: !offering }),
                this.line(
                    K,
                    ['radius'],
                    'offering.radius',
                    { metres },
                    { help: dimHelp ?? this.t('offering.radius-help', { metres: Number(company.adhoc_distance ?? 6000).toLocaleString() }), error: metresError, dim: !offering }
                ),
                this.line(
                    K,
                    ['on_exhausted'],
                    'offering.exhausted.sentence',
                    {
                        choice: this.choice(
                            K,
                            'on_exhausted',
                            [
                                ['keep_open', this.t('offering.exhausted.keep-open')],
                                ['cancel', this.t('offering.exhausted.cancel')],
                            ],
                            'offering.exhausted-aria'
                        ),
                    },
                    { help: dimHelp ?? this.t('offering.exhausted-help'), dim: !offering }
                ),
            ],
        };
    }

    driversGroup() {
        const K = 'dispatch.matching';
        if (!this.definition(K)) return null;
        return {
            id: K,
            nav: this.t('drivers.nav'),
            title: this.t('drivers.title'),
            description: this.t('drivers.description'),
            lines: [
                this.line(
                    K,
                    ['vehicle_type'],
                    'drivers.vehicle.sentence',
                    { choice: this.toggle(K, 'vehicle_type', this.t('drivers.vehicle.any'), this.t('drivers.vehicle.match'), 'drivers.vehicle-aria') },
                    { help: this.t('drivers.vehicle-help') }
                ),
                this.line(
                    K,
                    ['exclude_busy_drivers'],
                    'drivers.busy.sentence',
                    { choice: this.toggle(K, 'exclude_busy_drivers', this.t('drivers.busy.offered'), this.t('drivers.busy.skipped'), 'drivers.busy-aria') },
                    { help: this.t('drivers.busy-help') }
                ),
            ],
        };
    }

    orderingGroup() {
        const K = 'customer.ordering';
        if (!this.definition(K)) return null;
        const chosen = this.valueOf(K, 'payment_methods') ?? [];
        const allMethods = [...new Set([...PAYMENT_METHODS, ...chosen, ...(this.valueOf(K, 'require_settlement') ?? [])])];
        return {
            id: K,
            nav: this.t('ordering.nav'),
            title: this.t('ordering.title'),
            description: this.t('ordering.description'),
            lines: [
                this.line(
                    K,
                    ['payment_methods'],
                    'ordering.payment',
                    { methods: this.list(K, 'payment_methods', allMethods, 'ordering.payment-empty', 'ordering.payment-aria') },
                    { help: this.t('ordering.payment-help') }
                ),
                this.line(
                    K,
                    ['require_quote'],
                    'ordering.quote.sentence',
                    { choice: this.toggle(K, 'require_quote', this.t('ordering.quote.optional'), this.t('ordering.quote.required'), 'ordering.quote-aria') },
                    { help: this.t('ordering.quote-help') }
                ),
                this.line(
                    K,
                    ['require_settlement'],
                    'ordering.settlement',
                    { methods: this.list(K, 'require_settlement', chosen.length ? chosen : allMethods, 'ordering.settlement-empty', 'ordering.settlement-aria') },
                    { help: this.t('ordering.settlement-help') }
                ),
            ],
        };
    }

    cancellingGroup() {
        const K = 'customer.cancellation';
        if (!this.definition(K)) return null;
        const enabled = this.valueOf(K, 'enabled') !== false;
        const can = this.choice(
            K,
            'enabled',
            [
                [true, this.t('cancelling.can')],
                [false, this.t('cancelling.cannot')],
            ],
            'cancelling.enabled-aria'
        );
        const until = this.choice(
            K,
            'allowed_until',
            [[null, this.t('cancelling.finishes')], ...this.steps.map((step) => [step.value, this.t('cancelling.reaches', { step: step.label })])],
            'cancelling.until-aria'
        );
        return {
            id: K,
            nav: this.t('cancelling.nav'),
            title: this.t('cancelling.title'),
            description: this.t('cancelling.description'),
            lines: [
                enabled
                    ? this.line(K, ['enabled', 'allowed_until'], 'cancelling.until', { choice: can, step: until }, { help: this.t('cancelling-help') })
                    : this.line(K, ['enabled', 'allowed_until'], 'cancelling.off', { choice: can }),
            ],
        };
    }

    interactionsGroup() {
        const K = 'customer.interactions';
        if (!this.definition(K)) return null;
        return {
            id: K,
            nav: this.t('interactions.nav'),
            title: this.t('interactions.title'),
            description: this.t('interactions.description'),
            lines: [
                this.line(
                    K,
                    ['chat', 'rating', 'share_tracking'],
                    'interactions.sentence',
                    {
                        message: this.toggle(K, 'chat', this.t('interactions.message.off'), this.t('interactions.message.on'), 'interactions.message-aria'),
                        rate: this.toggle(K, 'rating', this.t('interactions.rate.off'), this.t('interactions.rate.on'), 'interactions.rate-aria'),
                        share: this.toggle(K, 'share_tracking', this.t('interactions.share.off'), this.t('interactions.share.on'), 'interactions.share-aria'),
                    },
                    { help: this.t('interactions-help') }
                ),
            ],
        };
    }

    priceGroup() {
        const K = 'pricing';
        if (!this.definition(K)) return null;
        const reprice = this.valueOf(K, 'reprice_on_completion') === true;
        const choice = this.toggle(K, 'reprice_on_completion', this.t('price.stands'), this.t('price.recalculate'), 'price.reprice-aria');
        const from = this.choice(K, 'metering_starts_at', [[null, this.t('price.from-start')], ...this.steps.map((step) => [step.value, step.label])], 'price.from-aria');
        return {
            id: K,
            nav: this.t('price.nav'),
            title: this.t('price.title'),
            description: this.t('price.description'),
            lines: [
                reprice
                    ? this.line(K, ['reprice_on_completion', 'metering_starts_at'], 'price.reprice', { choice, step: from }, { help: this.t('price-help') })
                    : this.line(K, ['reprice_on_completion', 'metering_starts_at'], 'price.no-reprice', { choice }, { help: this.t('price-help') }),
            ],
        };
    }

    /** A policy an extension registered: "Label: [control]" for each setting. */
    extensionGroup(policy) {
        const K = policy.key;
        const lines = (policy.settings ?? []).map((setting) => {
            const label = humanize(setting.name);
            let control;
            let error = null;
            if (setting.type === 'boolean') {
                control = this.toggle(K, setting.name, this.t('extension-off'), this.t('extension-on'), 'extension-aria');
            } else if (setting.type === 'choice' && Array.isArray(setting.choices)) {
                control = this.choice(K, setting.name, [[null, this.t('extension-none')], ...setting.choices.map((choice) => [choice.value, choice.label])], 'extension-aria');
            } else if (setting.type === 'choice' && setting.choices === 'activity') {
                control = this.choice(K, setting.name, [[null, this.t('extension-none')], ...this.steps.map((step) => [step.value, step.label])], 'extension-aria');
            } else if (setting.type === 'list') {
                const values = this.valueOf(K, setting.name) ?? [];
                control = {
                    kind: 'list',
                    items: values.map((value) => ({ value, label: String(value) })),
                    options: [],
                    emptyLabel: this.t('extension-none'),
                    ariaLabel: label,
                    disabled: true,
                };
            } else {
                [control, error] = this.number(K, setting.name, setting.default === null || setting.default === undefined ? '' : String(setting.default), 'extension-aria', 150);
            }
            control.ariaLabel = label;

            return this.line(K, [setting.name], 'extension-fallback', { control }, { values: { label }, error });
        });

        return { id: K, nav: policy.label, title: policy.label, description: policy.description, isExtension: true, lines };
    }

    // ----- loading and saving

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

    @task *loadCompanyDefaults() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/adhoc-dispatch-settings');
            this.companyDefaults = { ...this.companyDefaults, ...settings };
        } catch {
            // The built-in defaults stand in.
        }
    }

    @task *save() {
        if (this.errorCount) {
            return;
        }

        this.config.policies = this.merge(this.config.policies, this.values);

        try {
            yield this.config.save();
            this.savedValues = JSON.parse(JSON.stringify(this.values));
            this.notifications.success(this.t('saved'));
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
        this.savedValues = JSON.parse(JSON.stringify(this.values));
    }
}
