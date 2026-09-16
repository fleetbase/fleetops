import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { getOwner } from '@ember/application';
import { task, timeout } from 'ember-concurrency';
import { format } from 'date-fns';
import { PANEL_DEFAULTS } from '../../utils/context-panel';
import { RADAR_PILLS, RADAR_DEFAULT_VIEWS, SNOOZE_PRESETS, snoozePayloadFor, patchPayload, recordPanelFor, recordOf } from '../../utils/radar';

const VIEWS_CACHE_KEY = 'fleetops:radar:views';
const VIEW_MODE_CACHE_KEY = 'fleetops:radar:view';
const BRIEFING_CACHE_KEY = 'fleetops:radar:briefing-collapsed';
const PAGE_SIZE = 50;

/** A record key Radar holds as a uuid rather than a public id. */
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Radar: the triage list that replaced the Resources Hub.
 *
 * The list, its pills, tabs and saved views live in query params so a view
 * is shareable. Items come from `fleet-ops/radar/items`; every action on an
 * item posts to the same prefix and patches the row in place, so the list
 * never reflows under the cursor.
 */
export default class ManagementIndexController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service appCache;
    @service hostRouter;
    @service store;
    @service modalsManager;
    @service currentUser;
    @service inspectionSubmissionActions;
    @service inspectionFormActions;
    @service issueActions;
    @service resourceContextPanel;

    queryParams = ['view', 'status', 'filters', 'category', 'fleet', 'q', 'saved', 'assigned', 'window'];

    @tracked view = 'list';
    @tracked status = 'open';
    @tracked filters = '';
    @tracked category = '';
    @tracked fleet = '';
    @tracked q = '';
    @tracked saved = '';
    @tracked assigned = '';
    @tracked window = '24h';
    @tracked page = 1;

    @tracked payload = null;
    @tracked summary = null;
    @tracked briefing = null;
    @tracked agenda = null;
    @tracked handover = null;
    @tracked strips = [];
    @tracked briefingCollapsed = false;
    @tracked busyDecisionKey = null;
    @tracked selection = [];
    @tracked selectionAnchor = null;
    @tracked focusedKey = null;
    @tracked drawerItem = null;
    @tracked savedViews = [];
    @tracked lastLoadedAt = null;

    snoozePresets = SNOOZE_PRESETS;

    constructor() {
        super(...arguments);
        this.view = this.appCache.get(VIEW_MODE_CACHE_KEY, 'list') === 'agenda' ? 'agenda' : 'list';
        this.briefingCollapsed = this.appCache.get(BRIEFING_CACHE_KEY, false) === true;
        this.savedViews = this.readSavedViews();
    }

    // ------------------------------------------------------------------
    // Derived state
    // ------------------------------------------------------------------

    get items() {
        return this.payload?.items ?? [];
    }

    get groups() {
        return this.payload?.groups ?? [];
    }

    get counts() {
        return this.payload?.counts ?? this.summary?.counts ?? {};
    }

    get stats() {
        return this.summary?.summary ?? this.payload?.summary ?? {};
    }

    get meta() {
        return this.payload?.meta ?? { total: 0, page: 1, pages: 1, limit: PAGE_SIZE };
    }

    get snoozeSchedule() {
        return this.payload?.snooze_schedule ?? [];
    }

    get sourceErrors() {
        return Object.keys(this.payload?.sources ?? {});
    }

    get sourceErrorsText() {
        return this.sourceErrors.join(', ');
    }

    get previousPage() {
        return Math.max(1, (this.meta.page ?? 1) - 1);
    }

    get nextPage() {
        return Math.min(this.meta.pages ?? 1, (this.meta.page ?? 1) + 1);
    }

    get activeFilters() {
        return this.filters ? this.filters.split(',').filter(Boolean) : [];
    }

    get pills() {
        return RADAR_PILLS.map((key) => ({
            key,
            label: this.intl.t(`radar.pills.${key}`),
            count: this.counts[key] ?? 0,
            active: this.activeFilters.includes(key),
            dot: key === 'overdue',
        }));
    }

    get isFiltered() {
        return this.activeFilters.length > 0 || Boolean(this.q) || Boolean(this.category) || this.assigned === 'me';
    }

    get isLoading() {
        return this.loadItems.isRunning && !this.payload;
    }

    get isEmpty() {
        return !this.loadItems.isRunning && Boolean(this.payload) && this.items.length === 0;
    }

    get allViews() {
        return [...RADAR_DEFAULT_VIEWS.map((view) => ({ ...view, label: this.intl.t(view.intl) })), ...this.savedViews];
    }

    get activeView() {
        return this.allViews.find((view) => view.id === this.saved) ?? null;
    }

    get hasSelection() {
        return this.selection.length > 0;
    }

    get selectedItems() {
        return this.items.filter((item) => this.selection.includes(item.key));
    }

    get isAllSelected() {
        return this.items.length > 0 && this.items.every((item) => this.selection.includes(item.key));
    }

    get focusedItem() {
        return this.items.find((item) => item.key === this.focusedKey) ?? null;
    }

    /**
     * With a selection, the action keys run over the selection; without one
     * they act on the focused row.
     */
    get keyboardHandlers() {
        return {
            next: this.focusNext,
            previous: this.focusPrevious,
            select: this.toggleFocusedSelection,
            acknowledge: () => this.actOnSelectionOrFocused('acknowledge'),
            snooze: () => this.actOnSelectionOrFocused('snooze', snoozePayloadFor('1h')),
            assign: () => this.actOnSelectionOrFocused('assign'),
            open: this.openFocused,
            close: this.closeOpen,
            search: this.focusSearch,
        };
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    @task *reload() {
        const loads = [this.loadSummary.perform(), this.loadItems.perform(), this.loadBriefing.perform()];
        if (this.view === 'agenda') {
            loads.push(this.loadAgenda.perform());
        }
        yield Promise.all(loads);
    }

    @task({ restartable: true }) *loadAgenda() {
        try {
            this.agenda = yield this.fetch.get('fleet-ops/radar/agenda', { window: this.window, fleet: this.fleet });
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task({ restartable: true }) *loadBriefing() {
        try {
            this.briefing = yield this.fetch.get('fleet-ops/radar/briefing');
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task({ restartable: true }) *loadItems(debounce = false) {
        if (debounce) {
            yield timeout(250);
        }

        const params = {
            status: this.status,
            filters: this.filters,
            category: this.category,
            fleet: this.fleet,
            query: this.q,
            assigned: this.assigned,
            page: this.page,
            limit: PAGE_SIZE,
        };

        try {
            this.payload = yield this.fetch.get('fleet-ops/radar/items', params);
            this.lastLoadedAt = new Date();
            this.pruneSelection();
            if (this.focusedKey && !this.items.some((item) => item.key === this.focusedKey)) {
                this.focusedKey = null;
            }
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task({ restartable: true }) *loadSummary() {
        try {
            this.summary = yield this.fetch.get('fleet-ops/radar/summary');
        } catch {
            // The header falls back to the list's own summary.
        }
    }

    // ------------------------------------------------------------------
    // Filters, tabs, views
    // ------------------------------------------------------------------

    @action togglePill(key) {
        const active = new Set(this.activeFilters);
        if (active.has(key)) {
            active.delete(key);
        } else {
            active.add(key);
        }
        this.filters = [...active].join(',');
        this.saved = '';
        this.page = 1;
        this.loadItems.perform();
    }

    @action clearFilters() {
        this.filters = '';
        this.category = '';
        this.q = '';
        this.assigned = '';
        this.saved = '';
        this.page = 1;
        this.loadItems.perform();
    }

    @action setStatus(status) {
        if (status === this.status) {
            return;
        }
        this.status = status;
        this.page = 1;
        this.selection = [];
        this.loadItems.perform();
    }

    @action search(query) {
        this.q = query ?? '';
        this.page = 1;
        this.loadItems.perform(true);
    }

    @action setView(view) {
        this.view = view === 'agenda' ? 'agenda' : 'list';
        this.appCache.set(VIEW_MODE_CACHE_KEY, this.view);
        if (this.view === 'agenda') {
            this.loadAgenda.perform();
        }
    }

    @action setWindow(window) {
        this.window = window === '7d' ? '7d' : '24h';
        this.loadAgenda.perform();
    }

    /** An agenda entry is an item in another coat: open the same drawer. */
    @action openAgendaEntry(entry) {
        if (!entry?.key) {
            return;
        }
        const item = this.items.find((row) => row.key === entry.key) ?? {
            key: entry.key,
            rule: entry.rule,
            category: entry.category,
            severity: entry.severity,
            title: entry.title,
            subject: entry.subject,
            meta_line: entry.meta_line,
            due_at: entry.at,
            due_bucket: 'none',
            due_label: entry.label,
            window: null,
            record: entry.record,
            actions: entry.actions ?? [],
            state: entry.state ?? { status: 'open' },
            details: null,
        };
        this.openDrawer(item);
    }

    @action showAnytimeInList() {
        this.setView('list');
        this.clearFilters();
    }

    /** Dropping a tray item on a lane gives it a time. */
    @task *planItem(key, plannedAt) {
        try {
            yield this.fetch.post(`fleet-ops/radar/items/${encodeURIComponent(key)}/plan`, { planned_at: plannedAt });
            this.notifications.success(this.intl.t('radar.toasts.planned'));
            yield Promise.all([this.loadAgenda.perform(), this.loadItems.perform()]);
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    // ------------------------------------------------------------------
    // Handover
    // ------------------------------------------------------------------

    @task({ restartable: true }) *openHandover(key) {
        this.handover = null;
        try {
            const response = yield this.fetch.get(`fleet-ops/radar/handovers/${encodeURIComponent(key)}`);
            this.handover = response?.handover ?? null;
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action closeHandover() {
        this.handover = null;
    }

    @task({ drop: true }) *reassignOrders(handover) {
        const ids = (handover?.orders ?? []).map((order) => order.uuid).filter(Boolean);
        const driver = handover?.suggested?.driver;
        if (!ids.length || !driver?.uuid) {
            return;
        }

        try {
            yield this.fetch.patch('orders/bulk-assign-driver', { ids, driver: driver.uuid });
            this.notifications.success(this.intl.t('radar.handover.reassigned', { name: driver.label }));
            this.handover = null;
            this.reload.perform();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task({ drop: true }) *extendShift(handover, minutes = 60) {
        const shiftId = handover?.shift?.uuid ?? handover?.shift?.public_id;
        if (!shiftId) {
            return;
        }

        try {
            yield this.fetch.post(`fleet-ops/radar/shifts/${shiftId}/extend`, { minutes });
            this.notifications.success(this.intl.t('radar.handover.extended'));
            this.handover = null;
            this.reload.perform();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task({ drop: true }) *snoozeHandover(handover) {
        if (!handover?.key) {
            return;
        }
        yield this.stateAction({ key: handover.key }, 'snooze', { minutes: 60 });
        this.handover = null;
        this.loadAgenda.perform();
    }

    @action setPage(page) {
        this.page = Math.max(1, page);
        this.loadItems.perform();
    }

    @action applyView(view) {
        this.saved = view.id;
        this.status = view.status ?? 'open';
        this.filters = view.filters ?? '';
        this.category = view.category ?? '';
        this.q = view.q ?? '';
        this.assigned = view.assigned ?? '';
        this.page = 1;
        this.loadItems.perform();
    }

    @action saveCurrentView() {
        return this.modalsManager.show('modals/radar-save-view', {
            title: this.intl.t('radar.saved-views.save-current'),
            acceptButtonText: this.intl.t('common.save'),
            acceptButtonIcon: 'save',
            name: '',
            confirm: (modal) => {
                const name = (modal.getOption('name') ?? '').trim();
                if (!name) {
                    return this.notifications.warning(this.intl.t('radar.saved-views.name-prompt'));
                }

                const view = {
                    id: `view-${Date.now()}`,
                    label: name,
                    status: this.status,
                    filters: this.filters,
                    category: this.category,
                    q: this.q,
                    assigned: this.assigned,
                };
                this.savedViews = [...this.savedViews, view];
                this.writeSavedViews();
                this.saved = view.id;
                this.notifications.success(this.intl.t('radar.saved-views.saved'));
                modal.done();
            },
        });
    }

    @action deleteView(view) {
        this.savedViews = this.savedViews.filter((saved) => saved.id !== view.id);
        this.writeSavedViews();
        if (this.saved === view.id) {
            this.saved = '';
        }
        this.notifications.info(this.intl.t('radar.saved-views.deleted'));
    }

    readSavedViews() {
        const views = this.appCache.get(VIEWS_CACHE_KEY, []);

        return Array.isArray(views) ? views.filter((view) => view && view.id && view.label) : [];
    }

    writeSavedViews() {
        this.appCache.set(VIEWS_CACHE_KEY, this.savedViews);
    }

    // ------------------------------------------------------------------
    // Selection and focus
    // ------------------------------------------------------------------

    /**
     * Toggle one row, or with Shift held set every row between the last row
     * toggled and this one to this row's new state, the way a mail inbox does.
     */
    @action toggleSelect(item, { range = false } = {}) {
        const key = item.key;
        const select = !this.selection.includes(key);
        const keys = this.items.map((row) => row.key);
        const from = keys.indexOf(this.selectionAnchor);
        const to = keys.indexOf(key);

        let affected = [key];
        if (range && from !== -1 && to !== -1) {
            affected = keys.slice(Math.min(from, to), Math.max(from, to) + 1);
        }

        const selection = new Set(this.selection);
        for (const affectedKey of affected) {
            if (select) {
                selection.add(affectedKey);
            } else {
                selection.delete(affectedKey);
            }
        }

        this.selection = keys.filter((rowKey) => selection.has(rowKey));
        this.selectionAnchor = key;
    }

    @action selectAll() {
        this.selection = this.isAllSelected ? [] : this.items.map((item) => item.key);
    }

    @action clearSelection() {
        this.selectionAnchor = null;
        this.selection = [];
    }

    pruneSelection() {
        const keys = new Set(this.items.map((item) => item.key));
        this.selection = this.selection.filter((key) => keys.has(key));
    }

    @action focusItem(item) {
        this.focusedKey = item?.key ?? null;
    }

    @action focusNext() {
        this.moveFocus(1);
    }

    @action focusPrevious() {
        this.moveFocus(-1);
    }

    moveFocus(step) {
        const items = this.items;
        if (!items.length) {
            return;
        }

        const index = items.findIndex((item) => item.key === this.focusedKey);
        const next = index === -1 ? (step > 0 ? 0 : items.length - 1) : Math.min(items.length - 1, Math.max(0, index + step));
        this.focusedKey = items[next].key;
        document.querySelector(`[data-radar-key="${CSS.escape(this.focusedKey)}"]`)?.scrollIntoView({ block: 'nearest' });
    }

    @action toggleFocusedSelection() {
        if (this.focusedItem) {
            this.toggleSelect(this.focusedItem);
        }
    }

    @action openFocused() {
        if (this.focusedItem) {
            this.openDrawer(this.focusedItem);
        }
    }

    actOnFocused(actionName, payload = {}) {
        if (this.focusedItem) {
            this.act.perform(this.focusedItem, actionName, payload);
        }
    }

    actOnSelectionOrFocused(actionName, payload = {}) {
        if (this.hasSelection) {
            return this.bulkAct.perform(actionName, payload);
        }

        return this.actOnFocused(actionName, payload);
    }

    @action closeOpen() {
        if (this.drawerItem) {
            return this.closeDrawer();
        }
        if (this.hasSelection) {
            return this.clearSelection();
        }
    }

    @action focusSearch() {
        document.querySelector('.fleet-ops-radar-search input')?.focus();
    }

    // ------------------------------------------------------------------
    // Drawer
    // ------------------------------------------------------------------

    @action openDrawer(item) {
        this.drawerItem = item;
        this.focusedKey = item?.key ?? this.focusedKey;
    }

    @action closeDrawer() {
        this.drawerItem = null;
    }

    // ------------------------------------------------------------------
    // Acting on items
    // ------------------------------------------------------------------

    /**
     * Every action a row, the drawer or a key can fire, by name. State
     * actions post to Radar; record actions use the record's own endpoint.
     */
    @task *act(item, actionName, payload = {}) {
        switch (actionName) {
            case 'acknowledge':
                return yield this.stateAction(item, 'acknowledge', {}, 'radar.toasts.acknowledged');
            case 'snooze':
                return yield this.snoozeItem(item, payload);
            case 'wake':
                return yield this.stateAction(item, 'wake', {}, 'radar.toasts.woken');
            case 'assign':
                return yield this.assignItem(item);
            case 'unassign':
                return yield this.stateAction(item, 'assign', {}, 'radar.toasts.unassigned');
            case 'plan':
                return yield this.stateAction(item, 'plan', { planned_at: payload.planned_at ?? null }, 'radar.toasts.planned');
            case 'unplan':
                return yield this.stateAction(item, 'plan', { planned_at: null }, 'radar.toasts.planned');
            case 'resolve':
                return yield this.stateAction(item, 'resolve', { resolution: payload.resolution ?? null }, 'radar.toasts.resolved');
            case 'open_record':
                return this.openRecord(item);
            case 'assign_vehicle':
                return yield this.assignVehicle(item);
            case 'assign_driver':
                return yield this.assignDriver(item);
            case 'create_work_order':
                return yield this.createWorkOrderFromSchedule(item);
            case 'create_work_order_from_inspection':
                return yield this.inspectionFollowUp(item, 'createWorkOrder');
            case 'create_issue_from_inspection':
                return yield this.inspectionFollowUp(item, 'createIssue');
            case 'resolve_inspection':
                return yield this.inspectionFollowUp(item, 'resolve');
            case 'resolve_issue':
                return yield this.resolveIssue(item);
            case 'match_vehicle':
                return yield this.matchVehicle(item);
            case 'ignore_transaction':
                return yield this.ignoreTransaction(item);
            case 'attach_device':
                return yield this.attachDevice(item);
            case 'send_pin':
                return yield this.sendPin(item);
            case 'revoke_link':
                return yield this.revokeLink(item);
            case 'call':
                return this.callDriver(item);
            case 'delete_notice':
                return yield this.deleteNotice(item);
            case 'cover_shift':
            case 'handover':
                return yield this.openHandover.perform(item.key);
            case 'extend_shift': {
                const shiftId = item.source?.uuid ?? item.source?.public_id;
                return shiftId ? yield this.extendShift.perform({ shift: { uuid: shiftId } }, 60) : null;
            }
            default:
                return null;
        }
    }

    /**
     * One state action over the selection: acknowledge, snooze, wake or
     * assign. Assign asks who first, then posts once for every key.
     */
    @task({ drop: true }) *bulkAct(actionName, payload = {}) {
        const keys = [...this.selection];
        if (!keys.length) {
            return;
        }

        if (actionName === 'assign') {
            return yield this.bulkAssign(keys);
        }

        if (actionName === 'revoke_link') {
            return yield this.bulkRevokeLinks(this.selectedItems.filter((item) => item.actions?.includes('revoke_link')));
        }

        const body = { keys, action: actionName };
        if (actionName === 'snooze') {
            Object.assign(body, payload.minutes || payload.until ? { minutes: payload.minutes, until: payload.until } : snoozePayloadFor(payload.preset ?? '1h'));
        }

        yield this.postBulk(body);
    }

    async bulkAssign(keys) {
        return this.modalsManager.show('modals/radar-select-resource', {
            title: this.intl.t('radar.prompts.assign-user-title'),
            helpText: this.intl.t('radar.prompts.assign-user-help'),
            inputLabel: this.intl.t('radar.prompts.select-user'),
            placeholder: this.intl.t('radar.prompts.select-user'),
            modelName: 'user',
            query: { is_not_customer: true },
            allowClear: true,
            selected: null,
            acceptButtonText: this.intl.t('radar.actions.assign'),
            acceptButtonIcon: 'user-plus',
            confirm: async (modal) => {
                const user = modal.getOption('selected');
                modal.startLoading();
                const ok = await this.postBulk({ keys, action: 'assign', user: user?.id ?? null });
                modal.stopLoading();
                if (ok) {
                    modal.done();
                }
            },
        });
    }

    /**
     * Revoke every selected inspection link at once. Each link is its own
     * delete, so one failure does not stop the rest; the toast counts both.
     */
    async bulkRevokeLinks(items) {
        if (!items.length) {
            return;
        }

        return this.modalsManager.confirm({
            title: this.intl.t('radar.prompts.bulk-revoke-link-title', { count: items.length }),
            body: this.intl.t('radar.prompts.bulk-revoke-link-body'),
            acceptButtonText: this.intl.t('radar.actions.revoke-links', { count: items.length }),
            acceptButtonIcon: 'link-slash',
            acceptButtonType: 'danger',
            confirm: async (modal) => {
                modal.startLoading();

                const results = await Promise.allSettled(
                    items.map((item) => {
                        const formId = item.source?.form_uuid ?? item.source?.form_public_id;
                        const linkId = item.source?.uuid ?? item.source?.public_id;

                        return this.fetch.delete(`inspection-forms/${formId}/links/${linkId}`);
                    })
                );
                const revoked = results.filter((result) => result.status === 'fulfilled').length;
                const failed = results.length - revoked;

                if (revoked) {
                    this.notifications.success(this.intl.t('radar.toasts.links-revoked', { count: revoked }));
                }
                if (failed) {
                    this.notifications.error(this.intl.t('radar.toasts.links-revoke-failed', { count: failed }));
                }

                this.selection = [];
                modal.done();
                this.reload.perform();
            },
        });
    }

    async postBulk(body) {
        try {
            const response = await this.fetch.post('fleet-ops/radar/items/bulk', body);
            const results = response?.results ?? [];
            for (const result of results) {
                if (result.ok && result.state) {
                    this.applyState({ key: result.key }, result.state);
                }
            }
            const updated = results.filter((result) => result.ok).length;
            this.selection = [];
            this.notifications.success(this.intl.t('radar.toasts.bulk-done', { count: updated }));
            this.loadSummary.perform();

            return true;
        } catch (err) {
            this.notifications.serverError(err);

            return false;
        }
    }

    async stateAction(item, endpoint, body = {}, toastKey = null, toastParams = {}) {
        try {
            const response = await this.fetch.post(`fleet-ops/radar/items/${encodeURIComponent(item.key)}/${endpoint}`, body);
            this.applyState(item, response?.state ?? response?.item?.state);
            if (toastKey) {
                this.notifications.success(this.intl.t(toastKey, toastParams));
            }
            this.loadSummary.perform();

            return response;
        } catch (err) {
            this.notifications.serverError(err);

            return null;
        }
    }

    /**
     * Put a fresh state on the row, or take the row off this tab when the
     * state moved it to another one.
     */
    applyState(item, state) {
        if (!state) {
            return;
        }

        const leavesTab = (this.status === 'open' && ['snoozed', 'resolved'].includes(state.status)) || (this.status === 'snoozed' && state.status !== 'snoozed');
        this.payload = patchPayload(this.payload, item.key, (row) => (leavesTab ? null : { ...row, state }));

        if (this.drawerItem?.key === item.key) {
            this.drawerItem = leavesTab ? null : { ...this.drawerItem, state };
        }
        if (leavesTab) {
            this.selection = this.selection.filter((key) => key !== item.key);
            if (this.focusedKey === item.key) {
                this.focusedKey = null;
            }
        }
    }

    async snoozeItem(item, payload = {}) {
        const body = payload.minutes || payload.until ? payload : snoozePayloadFor(payload.preset ?? '1h');
        const response = await this.stateAction(item, 'snooze', body);
        if (response?.state?.snoozed_until) {
            this.notifications.success(this.intl.t('radar.toasts.snoozed', { time: this.formatTime(response.state.snoozed_until) }));
        }

        return response;
    }

    async assignItem(item) {
        return this.modalsManager.show('modals/radar-select-resource', {
            title: this.intl.t('radar.prompts.assign-user-title'),
            helpText: this.intl.t('radar.prompts.assign-user-help'),
            inputLabel: this.intl.t('radar.prompts.select-user'),
            placeholder: this.intl.t('radar.prompts.select-user'),
            modelName: 'user',
            query: { is_not_customer: true },
            allowClear: true,
            selected: null,
            acceptButtonText: this.intl.t('radar.actions.assign'),
            acceptButtonIcon: 'user-plus',
            confirm: async (modal) => {
                const user = modal.getOption('selected');
                modal.startLoading();
                const response = await this.stateAction(item, 'assign', { user: user?.id ?? null }, user ? 'radar.toasts.assigned' : 'radar.toasts.unassigned', { name: user?.name });
                modal.stopLoading();
                if (response) {
                    modal.done();
                }
            },
        });
    }

    async assignVehicle(item) {
        const driver = await this.findRecord('driver', item.subject?.public_id);
        if (!driver) {
            return;
        }

        return this.modalsManager.show('modals/driver-assign-vehicle', {
            title: this.intl.t('radar.prompts.assign-vehicle-title', { name: driver.name }),
            acceptButtonText: this.intl.t('radar.actions.assign-vehicle'),
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            driver,
            confirm: async (modal) => {
                const vehicleId = driver.vehicle_uuid ?? driver.vehicle?.id;
                if (!vehicleId) {
                    return this.notifications.warning(this.intl.t('radar.prompts.no-selection'));
                }

                modal.startLoading();
                try {
                    await this.fetch.post(`drivers/${driver.id}/assign-vehicle`, { vehicle: vehicleId });
                    this.notifications.success(this.intl.t('radar.toasts.vehicle-assigned'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async assignDriver(item) {
        const vehicleId = item.subject?.uuid ?? item.subject?.public_id;

        return this.modalsManager.show('modals/radar-select-resource', {
            title: this.intl.t('radar.prompts.assign-driver-title', { name: item.subject?.label }),
            inputLabel: this.intl.t('radar.prompts.select-user'),
            placeholder: this.intl.t('radar.actions.assign-driver'),
            modelName: 'driver',
            selected: null,
            acceptButtonText: this.intl.t('radar.actions.assign-driver'),
            acceptButtonIcon: 'check',
            confirm: async (modal) => {
                const driver = modal.getOption('selected');
                if (!driver) {
                    return this.notifications.warning(this.intl.t('radar.prompts.no-selection'));
                }

                modal.startLoading();
                try {
                    await this.fetch.post(`vehicles/${vehicleId}/assign-driver`, { driver: driver.id });
                    this.notifications.success(this.intl.t('radar.toasts.driver-assigned'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async createWorkOrderFromSchedule(item) {
        const scheduleId = item.source?.uuid ?? item.source?.public_id;

        return this.modalsManager.confirm({
            title: this.intl.t('radar.prompts.create-work-order-title'),
            body: this.intl.t('radar.prompts.create-work-order-body'),
            icon: 'clipboard-list',
            iconClass: 'text-blue-500',
            acceptButtonText: this.intl.t('radar.actions.create-work-order'),
            acceptButtonScheme: 'primary',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`maintenance-schedules/${scheduleId}/trigger`);
                    this.notifications.success(this.intl.t('radar.toasts.work-order-created'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async inspectionFollowUp(item, kind) {
        const submission = await this.findRecord('inspection-submission', item.source?.public_id ?? item.source?.uuid);
        if (!submission) {
            return;
        }

        await this.inspectionSubmissionActions[kind](submission);
        this.reload.perform();
    }

    /**
     * Close an issue the way its details panel does: the close-issue modal,
     * which asks for the resolution note and records who closed it.
     */
    async resolveIssue(item) {
        const issue = await this.findRecord('issue', item.source?.public_id ?? item.source?.uuid, recordPanelFor('management.issues')?.include);
        if (!issue) {
            return;
        }

        return this.issueActions.openCloseIssueModal(issue, { onSaved: () => this.reload.perform() });
    }

    async matchVehicle(item) {
        const transactionId = item.source?.uuid ?? item.source?.public_id;
        const suggested = item.source?.suggested_vehicle;

        return this.modalsManager.show('modals/radar-select-resource', {
            title: this.intl.t('radar.prompts.match-vehicle-title'),
            body: suggested ? `${this.intl.t('radar.actions.match-vehicle')}: ${suggested.label}` : null,
            inputLabel: this.intl.t('radar.prompts.select-vehicle'),
            placeholder: this.intl.t('radar.prompts.select-vehicle'),
            modelName: 'vehicle',
            selected: null,
            selectedId: suggested?.uuid ?? null,
            acceptButtonText: this.intl.t('radar.actions.match-vehicle'),
            acceptButtonIcon: 'link',
            confirm: async (modal) => {
                const vehicle = modal.getOption('selected');
                const vehicleId = vehicle?.id ?? modal.getOption('selectedId');
                if (!vehicleId) {
                    return this.notifications.warning(this.intl.t('radar.prompts.no-selection'));
                }

                modal.startLoading();
                try {
                    await this.fetch.post(`fuel-provider-transactions/${transactionId}/match-vehicle`, { vehicle: vehicleId });
                    this.notifications.success(this.intl.t('radar.toasts.matched'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async ignoreTransaction(item) {
        const transactionId = item.source?.uuid ?? item.source?.public_id;

        return this.modalsManager.confirm({
            title: this.intl.t('radar.prompts.ignore-title'),
            body: this.intl.t('radar.prompts.ignore-body'),
            acceptButtonText: this.intl.t('radar.actions.ignore'),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`fuel-provider-transactions/${transactionId}/review`, { status: 'ignored' });
                    this.notifications.success(this.intl.t('radar.toasts.ignored'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async attachDevice(item) {
        const deviceId = item.source?.uuid ?? item.source?.public_id;

        return this.modalsManager.show('modals/radar-select-resource', {
            title: this.intl.t('radar.prompts.attach-device-title'),
            inputLabel: this.intl.t('radar.prompts.select-vehicle'),
            placeholder: this.intl.t('radar.prompts.select-vehicle'),
            modelName: 'vehicle',
            selected: null,
            acceptButtonText: this.intl.t('radar.actions.attach'),
            acceptButtonIcon: 'link',
            confirm: async (modal) => {
                const vehicle = modal.getOption('selected');
                if (!vehicle) {
                    return this.notifications.warning(this.intl.t('radar.prompts.no-selection'));
                }

                modal.startLoading();
                try {
                    await this.fetch.post(`devices/${deviceId}/attach`, { vehicle: vehicle.id });
                    this.notifications.success(this.intl.t('radar.toasts.device-attached'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Send an inspection link's PIN, asking how first: by email or by text,
     * offering only the channels the link's recipient can receive, the same
     * choice the inspection form's link list gives.
     */
    async sendPin(item) {
        const formId = item.source?.form_uuid ?? item.source?.form_public_id;
        const linkId = item.source?.uuid ?? item.source?.public_id;

        let link;
        try {
            const response = await this.fetch.get(`inspection-forms/${formId}/links`);
            link = (response?.links ?? []).find((candidate) => [candidate.id, candidate.uuid, candidate.public_id].includes(linkId));
        } catch (err) {
            return this.notifications.serverError(err);
        }

        if (!link?.has_pin) {
            return this.notifications.warning(this.intl.t('inspection.link.no-pin'));
        }

        const channels = ['email', 'sms'].filter((via) => link.can_send_pin?.[via]);
        if (!channels.length) {
            return this.notifications.warning(this.intl.t('radar.prompts.send-pin-unavailable'));
        }

        return this.modalsManager.show('modals/radar-send-pin', {
            title: this.intl.t('radar.prompts.send-pin-title'),
            recipient: link.recipient?.name ?? null,
            channels,
            via: channels[0],
            acceptButtonText: this.intl.t('radar.actions.send-pin'),
            acceptButtonIcon: 'paper-plane',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const response = await this.fetch.post(`inspection-forms/${formId}/links/${link.id ?? linkId}/send-pin`, { via: modal.getOption('via') });
                    this.inspectionFormActions.notifyPinDelivery(response?.pin_delivery);
                    modal.done();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async revokeLink(item) {
        const formId = item.source?.form_uuid ?? item.source?.form_public_id;
        const linkId = item.source?.uuid ?? item.source?.public_id;

        return this.modalsManager.confirm({
            title: this.intl.t('radar.prompts.revoke-link-title'),
            body: this.intl.t('radar.prompts.revoke-link-body'),
            acceptButtonText: this.intl.t('radar.actions.revoke-link'),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`inspection-forms/${formId}/links/${linkId}`);
                    this.notifications.success(this.intl.t('radar.toasts.link-revoked'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    callDriver(item) {
        const phone = item.subject?.phone;
        if (!phone) {
            return this.openRecord(item);
        }

        window.open(`tel:${phone}`, '_self');
    }

    /**
     * Open the record behind a row, a decision card, a handover or a brief
     * link in a context panel, so Radar stays on screen. Resources with an
     * action service use its `panel.view`; the rest open their details
     * component in a plain panel. Only a record Radar has no panel for falls
     * back to navigating to it.
     */
    @action openRecord(target) {
        const record = recordOf(target);
        if (!record?.route) {
            return;
        }

        return this.openRecordPanel.perform(record);
    }

    @task *openRecordPanel(record) {
        const panel = recordPanelFor(record.route);
        if (!panel) {
            return this.hostRouter.transitionTo(`console.fleet-ops.${record.route}`, record.model);
        }

        const resource = yield this.findRecord(panel.modelName, record.model, panel.include);
        if (!resource) {
            return;
        }

        const actions = panel.service ? getOwner(this).lookup(`service:${panel.service}`) : null;
        if (typeof actions?.panel?.view === 'function') {
            return yield actions.panel.view(resource);
        }

        return this.resourceContextPanel.open({
            resource,
            tabs: [{ key: 'overview', label: this.intl.t('common.overview'), component: panel.component }],
            ...PANEL_DEFAULTS,
        });
    }

    // ------------------------------------------------------------------
    // Morning brief
    // ------------------------------------------------------------------

    @action toggleBriefing() {
        this.briefingCollapsed = !this.briefingCollapsed;
        this.appCache.set(BRIEFING_CACHE_KEY, this.briefingCollapsed);
    }

    /** A category row's "Filter": show exactly the items behind that score. */
    @action filterCategory(category) {
        this.category = this.category === category ? '' : category;
        this.status = 'open';
        this.saved = '';
        this.page = 1;
        this.view = 'list';
        this.loadItems.perform();
    }

    /**
     * Confirm a decision card: make the call it describes, keep a strip with
     * its undo, and refresh the list the change affects.
     */
    @task({ drop: true }) *confirmDecision(decision, call) {
        if (!call?.endpoint) {
            return;
        }

        this.busyDecisionKey = decision.key;
        try {
            yield this.performCall(call);
            this.addStrip({ kind: 'confirmed', key: decision.key, title: decision.title, at: new Date().toISOString(), undo: call.undo ?? null, keys: decision.keys ?? [] });
            this.removeDecision(decision.key);
            this.notifications.success(call.label ?? this.intl.t('radar.briefing.confirm'));
            this.reload.perform();
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.busyDecisionKey = null;
        }
    }

    /** An alternative is either another call or a prompt (pick another vehicle). */
    @task({ drop: true }) *decisionAlternative(decision, alternative) {
        if (alternative?.endpoint) {
            return yield this.confirmDecision.perform(decision, alternative);
        }

        if (alternative?.action === 'pick_vehicle') {
            const item = { subject: decision.subject, key: decision.keys?.[0] };
            yield this.assignVehicle(item);
        }
    }

    /** "Not now": snooze the items behind the card for a day. */
    @task({ drop: true }) *dismissDecision(decision) {
        const keys = decision.keys ?? [];
        if (!keys.length) {
            return this.removeDecision(decision.key);
        }

        this.busyDecisionKey = decision.key;
        try {
            const response = yield this.fetch.post('fleet-ops/radar/items/bulk', { keys, action: 'snooze', minutes: 1440 });
            const until = (response?.results ?? []).find((result) => result.ok)?.state?.snoozed_until ?? null;
            for (const result of response?.results ?? []) {
                if (result.ok && result.state) {
                    this.applyState({ key: result.key }, result.state);
                }
            }
            this.addStrip({ kind: 'snoozed', key: decision.key, title: decision.title, until, keys });
            this.removeDecision(decision.key);
            this.loadSummary.perform();
        } catch (err) {
            this.notifications.serverError(err);
        } finally {
            this.busyDecisionKey = null;
        }
    }

    @task({ drop: true }) *undoStrip(strip) {
        if (!strip.undo?.endpoint) {
            return;
        }

        try {
            yield this.performCall(strip.undo);
            this.strips = this.strips.filter((entry) => entry !== strip);
            this.notifications.info(strip.undo.label ?? this.intl.t('radar.briefing.undo'));
            this.reload.perform();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task({ drop: true }) *wakeStrip(strip) {
        try {
            yield this.fetch.post('fleet-ops/radar/items/bulk', { keys: strip.keys ?? [], action: 'wake' });
            this.strips = this.strips.filter((entry) => entry !== strip);
            this.reload.perform();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    performCall(call) {
        const method = String(call.method ?? 'POST').toLowerCase();
        const body = call.body ?? {};

        switch (method) {
            case 'patch':
                return this.fetch.patch(call.endpoint, body);
            case 'put':
                return this.fetch.put(call.endpoint, body);
            case 'delete':
                return this.fetch.delete(call.endpoint, body);
            default:
                return this.fetch.post(call.endpoint, body);
        }
    }

    addStrip(strip) {
        this.strips = [strip, ...this.strips].slice(0, 8);
    }

    removeDecision(key) {
        if (!this.briefing) {
            return;
        }

        this.briefing = { ...this.briefing, decisions: (this.briefing.decisions ?? []).filter((decision) => decision.key !== key) };
    }

    // ------------------------------------------------------------------
    // Notices
    // ------------------------------------------------------------------

    @action newNotice() {
        return this.modalsManager.show('modals/radar-notice', {
            title: this.intl.t('radar.notice.title'),
            acceptButtonText: this.intl.t('radar.notice.create'),
            acceptButtonIcon: 'bullhorn',
            notice: { message: '', severity: 'info', due_at: '', scope: '' },
            confirm: async (modal) => {
                const notice = modal.getOption('notice');
                if (!notice.message?.trim()) {
                    return this.notifications.warning(this.intl.t('radar.notice.message'));
                }

                modal.startLoading();
                try {
                    await this.fetch.post('fleet-ops/radar/notices', {
                        message: notice.message,
                        severity: notice.severity,
                        due_at: notice.due_at || null,
                        scope: notice.scope || null,
                    });
                    this.notifications.success(this.intl.t('radar.notice.created'));
                    modal.done();
                    this.reload.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    async deleteNotice(item) {
        const id = item.source?.public_id ?? item.source?.uuid;

        return this.modalsManager.confirm({
            title: this.intl.t('radar.notice.delete'),
            acceptButtonText: this.intl.t('common.delete'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`fleet-ops/radar/notices/${id}`);
                    this.payload = patchPayload(this.payload, item.key, () => null);
                    if (this.drawerItem?.key === item.key) {
                        this.drawerItem = null;
                    }
                    this.notifications.success(this.intl.t('radar.notice.deleted'));
                    modal.done();
                    this.loadSummary.perform();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Load a record Radar points at by its public id (or uuid), the way the
     * details routes do. `store.findRecord` with a public id registers the
     * record under an identifier its payload then contradicts, which Ember
     * Data rejects with "You should not change the <type> of a
     * RecordIdentifier" once the record is already in the store.
     */
    async findRecord(modelName, id, include = []) {
        if (!id) {
            return null;
        }

        const field = UUID_PATTERN.test(id) ? 'uuid' : 'public_id';
        const query = { [field]: id, single: true };
        if (include?.length) {
            query.with = include;
        }

        try {
            return await this.store.queryRecord(modelName, query);
        } catch (err) {
            this.notifications.serverError(err);

            return null;
        }
    }

    formatTime(value) {
        const date = value ? new Date(value) : null;
        if (!date || Number.isNaN(date.getTime())) {
            return '';
        }

        return format(date, 'EEE d MMM HH:mm');
    }
}
