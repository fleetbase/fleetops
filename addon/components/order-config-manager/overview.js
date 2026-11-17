import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';
import orderConfigSteps from '../../utils/order-config-steps';
import { actionIcon, triggerSummary } from '../../utils/order-config-action-meta';

const O = 'order-config-manager.overview';
const EVENT_KEYS = {
    'order.created': 'created',
    'order.completed': 'completed',
    'order.canceled': 'canceled',
    'dispatch.offer_exhausted': 'exhausted',
    'driver.approaching': 'approaching',
};

/**
 * What happens to an order of this config, read-only: before it's placed, at each step, and at
 * any time. Each part links to the tab where it's changed.
 *
 * @extends Component
 */
export default class OrderConfigManagerOverviewComponent extends Component {
    @service fetch;
    @service intl;

    @tracked config;
    @tracked actions = [];

    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.config = config;
        this.loadCapabilities.perform();

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.config = newConfig;
        });
    }

    @task *loadCapabilities() {
        try {
            const { actions } = yield this.fetch.get('order-configs/capabilities');
            this.actions = actions ?? [];
        } catch {
            this.actions = [];
        }
    }

    @action go(tab) {
        this.args.onSwitchTab?.(tab);
    }

    t(key, values) {
        return this.intl.t(`${O}.${key}`, values);
    }

    policy(key, name) {
        return get(this.config?.policies ?? {}, `${key}.${name}`);
    }

    get lifecycle() {
        return this.config?.meta?.lifecycle ?? null;
    }

    get endCodes() {
        const lifecycle = this.lifecycle ?? {};
        return new Set([lifecycle.completed, lifecycle.canceled, ...(lifecycle.terminal ?? [])].filter(Boolean));
    }

    get before() {
        const items = [];
        const methods = this.policy('customer.ordering', 'payment_methods') ?? [];
        if (methods.length) {
            items.push(this.t('rule-payment', { methods: methods.map((m) => this.methodLabel(m)).join(', ') }));
        }
        if (this.policy('customer.ordering', 'require_quote')) {
            items.push(this.t('rule-quote'));
        }
        const settle = this.policy('customer.ordering', 'require_settlement') ?? [];
        if (settle.length) {
            items.push(this.t('rule-settlement', { methods: settle.map((m) => this.methodLabel(m)).join(', ') }));
        }
        const interactions = ['chat', 'rating', 'share_tracking'].filter((name) => this.policy('customer.interactions', name));
        if (interactions.length) {
            items.push(this.t('rule-interactions', { list: interactions.map((name) => this.t(`interaction-${name}`)).join(', ') }));
        }
        return items;
    }

    methodLabel(method) {
        const key = `order-config-manager.policies.ordering.method.${method}`;
        return this.intl.exists(key) ? this.intl.t(key) : method;
    }

    get steps() {
        const steps = orderConfigSteps(this.config);
        const label = (code) => steps.find((step) => step.code === code)?.label ?? code;
        const adhoc = this.policy('dispatch.adhoc', 'customer_orders');
        const cancelUntil = this.policy('customer.cancellation', 'allowed_until');
        const cancelOff = this.policy('customer.cancellation', 'enabled') === false;
        const reprice = this.policy('pricing', 'reprice_on_completion');
        const meterFrom = this.policy('pricing', 'metering_starts_at');
        const rating = this.policy('customer.interactions', 'rating');
        const cancelIndex = cancelUntil ? steps.findIndex((step) => step.code === cancelUntil) : -1;

        return steps.map((step, index) => {
            const isComplete = step.activity?.complete || this.lifecycle?.completed === step.code;
            const ends = isComplete || this.endCodes.has(step.code);
            const rules = [];
            if (index === 0 && adhoc) {
                rules.push(this.t('rule-offered'));
            }
            if (index === 0 && this.policy('dispatch.matching', 'vehicle_type')) {
                rules.push(this.t('rule-vehicle'));
            }
            if (!cancelOff && cancelIndex >= 0 && !ends) {
                rules.push(index < cancelIndex ? this.t('rule-can-cancel') : this.t('rule-cannot-cancel'));
            }
            if (reprice && meterFrom === step.code) {
                rules.push(this.t('rule-metering'));
            }
            if (isComplete && reprice) {
                rules.push(this.t('rule-repriced'));
            }
            if (isComplete && rating) {
                rules.push(this.t('rule-rating'));
            }
            const happens = (step.activity?.actions ?? []).map((trigger) => ({ icon: actionIcon(trigger.action), text: triggerSummary(this.intl, trigger, this.actions) }));

            return {
                ...step,
                number: index + 1,
                happens,
                rules,
                tag: isComplete ? this.t('tag-completes') : ends ? (step.branchFrom ? this.t('branch-from', { step: label(step.branchFrom) }) : this.t('tag-ends')) : null,
                ends,
                role: isComplete ? 'complete' : ends ? 'end' : index === 0 ? 'start' : 'step',
                isLast: index === steps.length - 1,
            };
        });
    }

    get anyTime() {
        return (this.config?.triggers ?? []).map((trigger) => {
            const event = EVENT_KEYS[trigger.event];
            const options = trigger.options ?? {};
            const when = event ? this.intl.t(`order-config-manager.events.${event}-menu`, {}) : trigger.event;
            const detail =
                trigger.event === 'driver.approaching'
                    ? this.intl.t('order-config-manager.events.approaching-short', {
                          metres: options.distance ?? 500,
                          target: this.intl.t(`order-config-manager.events.target-${options.target === 'dropoff' ? 'dropoff' : 'pickup'}`),
                      })
                    : when;
            return { when: detail, then: triggerSummary(this.intl, trigger, this.actions), hasCondition: (trigger.when ?? []).length > 0 };
        });
    }

    get glance() {
        const steps = this.steps;
        const automatic = steps.reduce((sum, step) => sum + step.happens.length, 0) + this.anyTime.length;
        let changed = 0;
        const walk = (node) => {
            Object.values(node ?? {}).forEach((value) => {
                if (value && typeof value === 'object' && !Array.isArray(value)) {
                    walk(value);
                } else {
                    changed += 1;
                }
            });
        };
        walk(this.config?.policies);
        return [
            this.t('glance-steps', { count: steps.length, ends: steps.filter((step) => step.ends).length }),
            this.t('glance-automatic', { count: automatic }),
            this.t('glance-policies', { count: changed }),
        ];
    }
}
