import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementIndexController extends Controller {
    @service fetch;
    @service docsPanel;

    @tracked hub = null;

    constructor() {
        super(...arguments);
        this.loadHub.perform();
    }

    get kpis() {
        return (this.hub?.kpis ?? this.loadingKpis).map((kpi) => ({
            ...kpi,
            actionLabel: kpi.actionLabel ?? this.kpiActionLabels[kpi.key] ?? `Open ${kpi.label}`,
        }));
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
        'fleet-ops/resources/drivers/overview': 'Create driver profiles, app access, assignment context, and operating status.',
        'fleet-ops/resources/vehicles/overview': 'Track fleet assets, assignment readiness, and vehicle operating details.',
        'fleet-ops/resources/fleets/overview': 'Group drivers and vehicles by team, region, or service coverage.',
        'fleet-ops/resources/contacts/overview': 'Manage people and organizations used across orders and operational records.',
        'fleet-ops/resources/places/overview': 'Manage reusable pickup, dropoff, hub, and facility locations.',
        'fleet-ops/resources/issues/overview': 'Track incidents, service exceptions, and operational follow-up.',
    };

    kpiActionLabels = {
        drivers: 'Manage Drivers',
        vehicles: 'Track Vehicles',
        issues: 'Review Issues',
        fuel_records: 'Review Fuel Records',
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
            { key: 'drivers', label: 'Drivers', value: '...', caption: 'Loading resource readiness.', tone: 'blue', icon: 'id-card', route: 'management.drivers' },
            { key: 'vehicles', label: 'Vehicles', value: '...', caption: 'Loading fleet asset coverage.', tone: 'green', icon: 'truck', route: 'management.vehicles' },
            { key: 'issues', label: 'Open Issues', value: '...', caption: 'Loading exception health.', tone: 'rose', icon: 'triangle-exclamation', route: 'management.issues' },
            { key: 'fuel_records', label: 'Fuel Records', value: '...', caption: 'Loading fuel activity.', tone: 'amber', icon: 'gas-pump', route: 'management.fuel-reports' },
        ];
    }

    @action openDocs(link) {
        if (!link?.slug) {
            return;
        }

        return this.docsPanel.open(link.slug, {
            title: link.title ?? link.label,
            source: 'fleet-ops-resources-hub',
        });
    }

    @task *loadHub() {
        this.hub = yield this.fetch.get('fleet-ops/hubs/resources');
    }
}
