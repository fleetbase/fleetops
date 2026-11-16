import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { actionIcon, actionLabel, actionDescription } from '../../utils/order-config-action-meta';

const S = 'order-config-manager.step';
const NOTIFY = ['notify_customer', 'notify_driver'];
const SAMPLE = {
    'driver.first_name': 'Sam',
    'driver.name': 'Sam Lee',
    'customer.name': 'Alex Kim',
    'order.id': 'order_7f3k2',
    'order.tracking_number': 'FLB102938',
    'order.status': 'started',
};

/**
 * The things that happen at one point of an order, as cards: "Notify the customer 'Your driver
 * has arrived'". Edit opens the card in place: what happens, its settings (title and message
 * with placeholder chips and a preview for notifications), and "Only if" conditions. A removed
 * card can be undone until the list is saved.
 *
 * Triggers are `{ action, params, when }`; the list hands the whole new array to `onChange`.
 *
 * @argument {Array} triggers
 * @argument {Function} onChange
 * @argument {Array} actions the actions from `order-configs/capabilities`
 * @argument {Array<String>} placeholders the message placeholders from capabilities
 * @argument {String} stepStatus the step's status, used when a notification has no title
 * @argument {String} stepDetails the step's details, used when it has no message
 * @argument {Boolean} readonly
 */
export default class OrderConfigTriggerListComponent extends Component {
    @service intl;

    @tracked editing = null;
    @tracked draft = null;
    @tracked missingAction = false;
    @tracked removed = [];
    @tracked changedIndexes = [];
    messageElement;

    get triggers() {
        return this.args.triggers ?? [];
    }

    get actionOptions() {
        return (this.args.actions ?? []).map((described) => ({
            value: described.name,
            label: actionLabel(this.intl, described.name, this.args.actions),
            description: actionDescription(this.intl, described.name, this.args.actions),
        }));
    }

    get quickAdd() {
        const names = (this.args.actions ?? []).map((described) => described.name);
        return ['notify_customer', 'notify_driver', 'open_chat', 'close_chat', 'send_invoice']
            .filter((name) => names.includes(name))
            .map((name) => ({ name, label: this.intl.t(`${S}.quick.${name}`, { default: actionLabel(this.intl, name, this.args.actions) }) }));
    }

    get cards() {
        return this.triggers.map((trigger, index) => {
            const isNotify = NOTIFY.includes(trigger.action);
            const conditions = (trigger.when ?? []).reduce((count, block) => count + (block?.conditions?.length ?? 0), 0);
            return {
                index,
                trigger,
                icon: actionIcon(trigger.action),
                label: actionLabel(this.intl, trigger.action, this.args.actions),
                title: isNotify ? trigger.params?.title || this.args.stepStatus : null,
                message: isNotify ? trigger.params?.message || this.intl.t(`${S}.message-fallback`) : null,
                meta: [isNotify ? this.intl.t(`${S}.push-notification`) : null, conditions ? this.intl.t(`${S}.only-if-count`, { count: conditions }) : this.intl.t(`${S}.every-time`)]
                    .filter(Boolean)
                    .join(' · '),
                isEditing: this.editing === index,
                isNew: this.editing === index && this.draft?.isNew,
                isChanged: this.changedIndexes.includes(index),
            };
        });
    }

    get draftIsNotify() {
        return NOTIFY.includes(this.draft?.action);
    }

    /** The draft action's parameters other than a notification's title and message. */
    get draftParameters() {
        const described = (this.args.actions ?? []).find((item) => item.name === this.draft?.action);
        if (!described || this.draftIsNotify) {
            return [];
        }
        return Object.entries(described.parameters ?? {}).map(([name, description]) => ({ name, description, value: this.draft.params?.[name] ?? '' }));
    }

    get titlePlaceholder() {
        return this.intl.t(`${S}.title-placeholder`, { status: this.args.stepStatus || this.intl.t(`${S}.title-placeholder-event`) });
    }

    get placeholders() {
        return (this.args.placeholders ?? Object.keys(SAMPLE)).filter((token) => !token.startsWith('activity.') || this.args.stepStatus).map((token) => `{${token}}`);
    }

    fill(text) {
        return String(text ?? '').replace(/\{\s*([a-z_]+(?:\.[a-z_]+)*)\s*\}/gi, (match, token) => {
            const key = token.toLowerCase();
            if (key === 'activity.status') return this.args.stepStatus ?? '';
            if (key === 'activity.details') return this.args.stepDetails ?? '';
            return SAMPLE[key] ?? '';
        });
    }

    get previewTitle() {
        return this.fill(this.draft?.params?.title || this.args.stepStatus || '');
    }

    get previewMessage() {
        return this.fill(this.draft?.params?.message || this.args.stepDetails || '');
    }

    // ----- editing

    @action edit(index) {
        const trigger = this.triggers[index];
        this.editing = index;
        this.missingAction = false;
        this.draft = { action: trigger.action, params: { ...(trigger.params ?? {}) }, when: JSON.parse(JSON.stringify(trigger.when ?? [])), isNew: false };
    }

    @action add(name = null) {
        const list = [...this.triggers, { action: name, params: {}, when: [] }];
        this.args.onChange?.(list);
        this.editing = list.length - 1;
        this.missingAction = false;
        this.draft = { action: name, params: {}, when: [], isNew: true };
    }

    @action setDraftAction(name) {
        this.draft = { ...this.draft, action: name };
        this.missingAction = false;
    }

    @action setParam(name, event) {
        this.draft = { ...this.draft, params: { ...this.draft.params, [name]: event.target.value } };
    }

    @action setWhen(logic) {
        this.draft = { ...this.draft, when: logic ?? [] };
    }

    @action registerMessage(element) {
        this.messageElement = element;
    }

    @action insertToken(token) {
        const current = this.draft.params?.message ?? '';
        const element = this.messageElement;
        const start = element && typeof element.selectionStart === 'number' ? element.selectionStart : current.length;
        const end = element && typeof element.selectionEnd === 'number' ? element.selectionEnd : current.length;
        const needsSpace = start > 0 && !/\s$/.test(current.slice(0, start));
        const message = current.slice(0, start) + (needsSpace ? ' ' : '') + token + current.slice(end);
        this.draft = { ...this.draft, params: { ...this.draft.params, message } };
    }

    @action cancel() {
        if (this.draft?.isNew) {
            this.args.onChange?.(this.triggers.filter((_, index) => index !== this.editing));
        }
        this.editing = null;
        this.draft = null;
        this.missingAction = false;
    }

    @action done() {
        if (!this.draft?.action) {
            this.missingAction = true;
            return;
        }

        const params = Object.fromEntries(Object.entries(this.draft.params ?? {}).filter(([, value]) => value !== '' && value !== undefined && value !== null));
        const trigger = { action: this.draft.action };
        if (Object.keys(params).length) trigger.params = params;
        if ((this.draft.when ?? []).length) trigger.when = this.draft.when;

        const index = this.editing;
        this.args.onChange?.(this.triggers.map((existing, i) => (i === index ? { ...existing, ...trigger, params: trigger.params, when: trigger.when } : existing)));
        this.changedIndexes = [...new Set([...this.changedIndexes, index])];
        this.editing = null;
        this.draft = null;
    }

    @action remove(index) {
        const trigger = this.triggers[index];
        this.removed = [...this.removed, { trigger, index, label: actionLabel(this.intl, trigger.action, this.args.actions) }];
        this.args.onChange?.(this.triggers.filter((_, i) => i !== index));
    }

    @action undo(entry) {
        const list = [...this.triggers];
        list.splice(Math.min(entry.index, list.length), 0, entry.trigger);
        this.removed = this.removed.filter((item) => item !== entry);
        this.args.onChange?.(list);
    }
}
