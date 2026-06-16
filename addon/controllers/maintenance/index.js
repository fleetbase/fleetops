import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceIndexController extends Controller {
    @service fetch;
    @service docsPanel;

    @tracked hub = null;

    constructor() {
        super(...arguments);
        this.loadHub.perform();
    }

    get kpis() {
        return this.hub?.kpis ?? this.loadingKpis;
    }

    get actions() {
        return (this.hub?.actions ?? []).map((action) => this.normalizeAction(action));
    }

    get sections() {
        return this.hub?.sections ?? [];
    }

    get docs() {
        return (this.hub?.docs ?? []).map((doc) => ({
            ...doc,
            description: doc.description ?? this.docDescriptions[doc.slug] ?? '',
        }));
    }

    docDescriptions = {
        'fleet-ops/maintenance/schedules/overview': 'Plan recurring service windows and convert due schedules into work orders.',
        'fleet-ops/maintenance/work-orders/overview': 'Coordinate assigned maintenance work, vendors, due dates, and completion.',
        'fleet-ops/maintenance/equipment/overview': 'Track serviceable equipment that participates in maintenance operations.',
        'fleet-ops/maintenance/parts/overview': 'Manage parts inventory and restocking signals used by maintenance teams.',
    };

    normalizeAction(action = {}) {
        const query = action.query && typeof action.query === 'object' && !Array.isArray(action.query) ? action.query : {};

        return {
            ...action,
            query,
        };
    }

    get loadingKpis() {
        return [
            {
                key: 'overdue_schedules',
                label: 'Overdue Schedules',
                value: '...',
                caption: 'Loading recurring service risk.',
                tone: 'rose',
                icon: 'calendar-xmark',
                route: 'maintenance.schedules',
            },
            {
                key: 'upcoming_schedules',
                label: 'Due This Week',
                value: '...',
                caption: 'Loading upcoming service windows.',
                tone: 'blue',
                icon: 'calendar-day',
                route: 'maintenance.schedules',
            },
            {
                key: 'open_work_orders',
                label: 'Open Work Orders',
                value: '...',
                caption: 'Loading active maintenance work.',
                tone: 'amber',
                icon: 'clipboard-list',
                route: 'maintenance.work-orders',
            },
            { key: 'low_stock_parts', label: 'Low Stock Parts', value: '...', caption: 'Loading parts inventory signals.', tone: 'amber', icon: 'cog', route: 'maintenance.parts' },
        ];
    }

    @action openDocs(link) {
        if (!link?.slug) {
            return;
        }

        return this.docsPanel.open(link.slug, {
            title: link.title ?? link.label,
            source: 'fleet-ops-maintenance-hub',
        });
    }

    @task *loadHub() {
        this.hub = yield this.fetch.get('fleet-ops/hubs/maintenance');
    }
}
