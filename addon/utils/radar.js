/**
 * Shared vocabulary for the Radar page: which pills exist, how a rule maps
 * to a badge colour, which actions are record-level, and the snooze presets.
 */

export const RADAR_PILLS = ['overdue', 'due_week', 'unassigned', 'issues', 'inspections', 'shifts', 'expiring', 'low_stock', 'fuel', 'notices'];

export const RADAR_STATUSES = ['open', 'snoozed', 'resolved'];

export const RADAR_GROUPS = ['overdue', 'today', 'week', 'later', 'none'];

/** Saved views every user starts with. */
export const RADAR_DEFAULT_VIEWS = [
    { id: 'my-assignments', intl: 'radar.saved-views.defaults.my-assignments', status: 'open', filters: '', assigned: 'me', q: '', isDefault: true },
    { id: 'shift-changes', intl: 'radar.saved-views.defaults.shift-changes', status: 'open', filters: 'shifts', assigned: '', q: '', isDefault: true },
    { id: 'due-this-week', intl: 'radar.saved-views.defaults.due-this-week', status: 'open', filters: 'due_week', assigned: '', q: '', isDefault: true },
    { id: 'unmatched-fuel', intl: 'radar.saved-views.defaults.unmatched-fuel', status: 'open', filters: 'fuel', assigned: '', q: '', isDefault: true },
];

/** Badge status per category; issues go by severity instead. */
const CHIP_BY_CATEGORY = {
    maintenance: 'warning',
    inspections: 'violet',
    staffing: 'info',
    compliance: 'cyan',
    fuel: 'sky',
    parts: 'slate',
    connectivity: 'slate',
    issues: 'warning',
    notices: 'gray',
};

const CHIP_BY_RULE = {
    work_order_overdue: 'orange',
    work_order_blocked: 'orange',
    shift_late_start: 'indigo',
    shift_no_vehicle: 'indigo',
    shift_handover: 'indigo',
};

export function chipStatusFor(item) {
    if (!item) {
        return 'gray';
    }

    if (item.rule === 'issue_open') {
        return item.severity === 'critical' ? 'error' : 'warning';
    }

    return CHIP_BY_RULE[item.rule] ?? CHIP_BY_CATEGORY[item.category] ?? 'gray';
}

/**
 * Actions that change the record itself, in the order the rail prefers
 * them, each with its label key and icon. Everything else is state.
 */
export const RECORD_ACTIONS = {
    create_work_order: { label: 'radar.actions.create-work-order', icon: 'clipboard-list' },
    create_work_order_from_inspection: { label: 'radar.actions.create-work-order', icon: 'clipboard-list' },
    create_issue_from_inspection: { label: 'radar.actions.create-issue', icon: 'triangle-exclamation' },
    resolve_inspection: { label: 'radar.actions.mark-resolved', icon: 'check' },
    resolve_issue: { label: 'radar.actions.mark-resolved', icon: 'check' },
    assign_vehicle: { label: 'radar.actions.assign-vehicle', icon: 'truck' },
    assign_driver: { label: 'radar.actions.assign-driver', icon: 'id-card' },
    match_vehicle: { label: 'radar.actions.match-vehicle', icon: 'link' },
    ignore_transaction: { label: 'radar.actions.ignore', icon: 'eye-slash' },
    attach_device: { label: 'radar.actions.attach', icon: 'satellite-dish' },
    send_pin: { label: 'radar.actions.send-pin', icon: 'paper-plane' },
    revoke_link: { label: 'radar.actions.revoke-link', icon: 'ban' },
    call: { label: 'radar.actions.call', icon: 'phone' },
    cover_shift: { label: 'radar.actions.cover-shift', icon: 'people-arrows' },
    handover: { label: 'radar.actions.reassign', icon: 'right-left' },
    extend_shift: { label: 'radar.actions.extend-shift', icon: 'clock' },
    resolve: { label: 'radar.actions.resolve', icon: 'check' },
};

export const STATE_ACTIONS = ['acknowledge', 'snooze', 'wake', 'assign', 'unassign', 'plan', 'unplan', 'open_record'];

/** The first record-level action an item offers, with its label and icon. */
export function primaryActionFor(item) {
    const key = (item?.actions ?? []).find((action) => RECORD_ACTIONS[action]);
    if (!key) {
        return null;
    }

    return { key, ...RECORD_ACTIONS[key] };
}

/** Every record-level action after the primary one. */
export function secondaryActionsFor(item) {
    const primary = primaryActionFor(item);

    return (item?.actions ?? []).filter((action) => RECORD_ACTIONS[action] && action !== primary?.key).map((key) => ({ key, ...RECORD_ACTIONS[key] }));
}

export const SNOOZE_PRESETS = ['1h', '4h', 'tomorrow', 'next-week'];

/**
 * The snooze payload for a preset: minutes for the short ones, a
 * morning-of date for the longer ones.
 */
export function snoozePayloadFor(preset, now = new Date()) {
    switch (preset) {
        case '1h':
            return { minutes: 60 };
        case '4h':
            return { minutes: 240 };
        case 'tomorrow': {
            const until = new Date(now);
            until.setDate(until.getDate() + 1);
            until.setHours(8, 0, 0, 0);
            return { until: until.toISOString() };
        }
        case 'next-week': {
            const until = new Date(now);
            until.setDate(until.getDate() + 7);
            until.setHours(8, 0, 0, 0);
            return { until: until.toISOString() };
        }
        default:
            return { minutes: 60 };
    }
}

export function initialsOf(name) {
    const parts = String(name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (!parts.length) {
        return '';
    }

    const first = parts[0].charAt(0);
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';

    return (first + last).toUpperCase();
}

/** Replace one item (by key) inside a Radar items payload, or drop it when the updater returns null. */
export function patchPayload(payload, key, updater) {
    if (!payload) {
        return payload;
    }

    const apply = (item) => (item.key === key ? updater(item) : item);
    const items = (payload.items ?? []).map(apply).filter(Boolean);
    const groups = (payload.groups ?? [])
        .map((group) => {
            const members = (group.items ?? []).map(apply).filter(Boolean);
            return { ...group, items: members, count: members.length };
        })
        .filter((group) => group.items.length > 0);

    return { ...payload, items, groups };
}

/**
 * How to show a record Radar links to without leaving the page: the Ember
 * Data model to load, the action service whose `panel.view` renders it, or
 * the details component to open in a plain context panel when the resource
 * has no panel of its own. Keyed by the record route's prefix; the longest
 * matching prefix wins, so `management.vehicles.index.details.devices`
 * resolves to the vehicle panel.
 */
export const RECORD_PANELS = {
    'management.drivers': { modelName: 'driver', service: 'driver-actions' },
    'management.vehicles': { modelName: 'vehicle', service: 'vehicle-actions' },
    'management.trailers': { modelName: 'trailer', service: 'trailer-actions' },
    'management.issues': { modelName: 'issue', service: 'issue-actions' },
    'management.fuel-transactions': { modelName: 'fuel-provider-transaction', component: 'fuel-provider-transaction/summary' },
    'maintenance.schedules': { modelName: 'maintenance-schedule', service: 'maintenance-schedule-actions' },
    'maintenance.work-orders': { modelName: 'work-order', service: 'work-order-actions' },
    'maintenance.parts': { modelName: 'part', service: 'part-actions' },
    'maintenance.inspection-submissions': { modelName: 'inspection-submission', component: 'inspection-submission/details' },
    'maintenance.inspection-forms': { modelName: 'inspection-form', component: 'inspection-form/details' },
    'connectivity.devices': { modelName: 'device', service: 'device-actions' },
};

/** The panel definition for a record route, or null when Radar has none for it. */
export function recordPanelFor(route) {
    if (!route) {
        return null;
    }

    const prefix = Object.keys(RECORD_PANELS)
        .filter((key) => route === key || route.startsWith(`${key}.`))
        .sort((a, b) => b.length - a.length)[0];

    return prefix ? RECORD_PANELS[prefix] : null;
}

/**
 * The `{ route, model }` a Radar link points at, whichever shape it arrived
 * in: an item (`item.record`), a decision or handover record, or a brief
 * sentence segment carrying `route` and `model` itself.
 */
export function recordOf(target) {
    if (!target) {
        return null;
    }
    if (target.record?.route) {
        return target.record;
    }
    if (target.route && target.model) {
        return { route: target.route, model: target.model };
    }

    return null;
}
