import humanize from '@fleetbase/ember-core/utils/humanize';

/** The icon each known action shows on the flow and in lists; extension actions get a bolt. */
export const ACTION_ICONS = {
    notify_customer: 'bell',
    notify_driver: 'bell',
    open_chat: 'comments',
    close_chat: 'comment-slash',
    send_invoice: 'file-invoice',
    set_meta: 'tag',
    cancel: 'ban',
};

/** What the legend calls each icon. */
export const ICON_GROUPS = [
    { icon: 'bell', key: 'legend-notification', actions: ['notify_customer', 'notify_driver'] },
    { icon: 'comments', key: 'legend-chat', actions: ['open_chat', 'close_chat'] },
    { icon: 'file-invoice', key: 'legend-invoice', actions: ['send_invoice'] },
];

export function actionIcon(name) {
    return ACTION_ICONS[name] ?? 'bolt';
}

/**
 * An action's label: the console's translation when there is one, else the label the server
 * describes (an extension's own), else the name made readable.
 */
export function actionLabel(intl, name, described = []) {
    for (const key of [`order-config-manager.actions.${name}`, `ledger.order-config-actions.${name}`]) {
        if (intl.exists(key)) {
            return intl.t(key);
        }
    }
    return described.find((action) => action.name === name)?.label ?? humanize(name ?? '');
}

export function actionDescription(intl, name, described = []) {
    for (const key of [`order-config-manager.actions.${name}-description`, `ledger.order-config-actions.${name}-description`]) {
        if (intl.exists(key)) {
            return intl.t(key);
        }
    }
    return described.find((action) => action.name === name)?.description ?? '';
}

/** A one-line summary of a trigger: its action and, for notifications, the title in quotes. */
export function triggerSummary(intl, trigger, described = []) {
    const label = actionLabel(intl, trigger?.action, described);
    const title = trigger?.params?.title;
    return title ? `${label} “${title}”` : label;
}
