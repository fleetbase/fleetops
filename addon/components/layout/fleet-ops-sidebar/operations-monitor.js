import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { registerDestructor } from '@ember/destroyable';
import { task } from 'ember-concurrency';

export default class LayoutFleetOpsSidebarOperationsMonitorComponent extends Component {
    @service store;
    @service universe;
    @service hostRouter;
    @service mapManager;
    @service driverActions;
    @service vehicleActions;
    @service fleetActions;
    @service notifications;

    @tracked activeTab = 'fleets';
    @tracked query = '';
    @tracked fallbackDrivers = [];
    @tracked fallbackVehicles = [];
    @tracked fallbackFleets = [];
    @tracked expandedFleetIds = new Set();

    monitorElement;
    listElement;
    resizeObserver;
    resizeFrame;

    constructor() {
        super(...arguments);
        registerDestructor(this, () => this.teardownLayoutObservers());
        this.loadFallbackResources.perform();
        this.listenForChanges();
    }

    get driverSource() {
        return this.liveDrivers.length ? this.liveDrivers : this.fallbackDrivers;
    }

    get vehicleSource() {
        return this.liveVehicles.length ? this.liveVehicles : this.fallbackVehicles;
    }

    get drivers() {
        return this.sortOnlineFirst(this.filterResources(this.driverSource, ['name', 'public_id', 'status']));
    }

    get vehicles() {
        return this.sortOnlineFirst(this.filterResources(this.vehicleSource, ['display_name', 'name', 'public_id', 'status']));
    }

    get fleets() {
        return this.fallbackFleets;
    }

    get fleetRows() {
        if (this.hasQuery) {
            return this.buildFilteredFleetRows(this.fleets);
        }

        return this.buildFleetRows(this.fleets);
    }

    get hasQuery() {
        return this.query.trim().length > 0;
    }

    get normalizedQuery() {
        return this.query.trim().toLowerCase();
    }

    get liveDrivers() {
        return this.mapManager.livemap?.drivers ?? [];
    }

    get liveVehicles() {
        return this.mapManager.livemap?.vehicles ?? [];
    }

    get onlineDriverCount() {
        return this.driverSource.filter((driver) => driver.online).length;
    }

    get onlineVehicleCount() {
        return this.vehicleSource.filter((vehicle) => vehicle.online).length;
    }

    get driverOnlineSummary() {
        return `${this.onlineDriverCount} drivers online`;
    }

    get vehicleOnlineSummary() {
        return `${this.onlineVehicleCount} vehicles online`;
    }

    get activeResources() {
        return this[this.activeTab] ?? [];
    }

    get emptyMessage() {
        if (this.query) {
            return 'No resources match this search.';
        }

        return `No ${this.activeTab} available.`;
    }

    get emptyState() {
        if (this.hasQuery) {
            return {
                icon: 'magnifying-glass',
                title: 'No resources match this search',
                description: 'Try another driver, vehicle, fleet, public ID, status, or assignment.',
                action: 'Clear filter',
            };
        }

        return {
            drivers: {
                icon: 'id-card',
                title: 'No drivers yet',
                description: 'Create drivers so dispatchers can assign orders and track active work on the live map.',
                action: 'Create driver',
            },
            vehicles: {
                icon: 'truck',
                title: 'No vehicles yet',
                description: 'Add vehicles to power live map visibility, driver assignments, and fleet tracking.',
                action: 'Create vehicle',
            },
            fleets: {
                icon: 'user-group',
                title: 'No fleets yet',
                description: 'Create fleets to organize drivers and vehicles into operational groups.',
                action: 'Create fleet',
            },
        }[this.activeTab];
    }

    get tabs() {
        return [
            { id: 'fleets', label: 'Fleets' },
            { id: 'drivers', label: 'Drivers' },
            { id: 'vehicles', label: 'Vehicles' },
        ];
    }

    filterResources(resources = [], fields = []) {
        const query = this.normalizedQuery;

        if (!query) {
            return resources;
        }

        return resources.filter((resource) => {
            return fields.some((field) =>
                String(resource[field] ?? '')
                    .toLowerCase()
                    .includes(query)
            );
        });
    }

    resourceMatches(resource, fields = [], extraValues = []) {
        const query = this.normalizedQuery;

        if (!query) {
            return true;
        }

        const fieldValues = fields.map((field) => resource?.[field]);

        return [...fieldValues, ...extraValues].some((value) =>
            String(value ?? '')
                .toLowerCase()
                .includes(query)
        );
    }

    displayName(resource) {
        return resource.displayName ?? resource.display_name ?? resource.name ?? resource.public_id;
    }

    subtitle(resource) {
        return resource.vehicle_name ?? resource.driver_name ?? resource.public_id ?? resource.status;
    }

    fleetKey(fleet) {
        return fleet?.id ?? fleet?.uuid ?? fleet?.public_id ?? fleet?.name;
    }

    resourceArray(resources) {
        if (!resources) {
            return [];
        }

        if (typeof resources.toArray === 'function') {
            return resources.toArray();
        }

        return Array.isArray(resources) ? resources : [];
    }

    fleetDrivers(fleet) {
        return this.resourceArray(fleet?.drivers);
    }

    fleetVehicles(fleet) {
        return this.resourceArray(fleet?.vehicles);
    }

    fleetSubfleets(fleet) {
        return this.resourceArray(fleet?.subfleets);
    }

    fleetDriverCount(fleet) {
        return Number(fleet?.drivers_count ?? fleet?.driver_count ?? this.fleetDrivers(fleet).length ?? 0);
    }

    fleetVehicleCount(fleet) {
        return Number(fleet?.vehicles_count ?? fleet?.vehicle_count ?? this.fleetVehicles(fleet).length ?? 0);
    }

    @action fleetSubtitle(fleet) {
        return `${this.fleetDriverCount(fleet)} drivers - ${this.fleetVehicleCount(fleet)} vehicles`;
    }

    @action isFleetExpanded(fleet) {
        return this.expandedFleetIds.has(this.fleetKey(fleet));
    }

    @action fleetHasChildren(fleet) {
        return this.fleetSubfleets(fleet).length > 0 || this.fleetDrivers(fleet).length > 0 || this.fleetVehicles(fleet).length > 0;
    }

    buildFleetRows(fleets = [], depth = 0) {
        return fleets.flatMap((fleet) => {
            const rows = [{ type: 'fleet', fleet, depth }];

            if (!this.isFleetExpanded(fleet)) {
                return rows;
            }

            const childDepth = depth + 1;

            this.fleetSubfleets(fleet).forEach((subfleet) => {
                rows.push(...this.buildFleetRows([subfleet], childDepth));
            });

            this.fleetDrivers(fleet).forEach((driver) => {
                rows.push({ type: 'driver', driver, depth: childDepth });
            });

            this.fleetVehicles(fleet).forEach((vehicle) => {
                rows.push({ type: 'vehicle', vehicle, depth: childDepth });
            });

            return rows;
        });
    }

    buildFilteredFleetRows(fleets = [], depth = 0) {
        return fleets.flatMap((fleet) => {
            const fleetMatches = this.fleetMatches(fleet);

            if (fleetMatches) {
                return this.buildExpandedFleetRows(fleet, depth);
            }

            const childDepth = depth + 1;
            const matchingSubfleetRows = this.buildFilteredFleetRows(this.fleetSubfleets(fleet), childDepth);
            const matchingDriverRows = this.fleetDrivers(fleet)
                .filter((driver) => this.driverMatches(driver))
                .map((driver) => ({ type: 'driver', driver, depth: childDepth }));
            const matchingVehicleRows = this.fleetVehicles(fleet)
                .filter((vehicle) => this.vehicleMatches(vehicle))
                .map((vehicle) => ({ type: 'vehicle', vehicle, depth: childDepth }));

            if (!matchingSubfleetRows.length && !matchingDriverRows.length && !matchingVehicleRows.length) {
                return [];
            }

            return [{ type: 'fleet', fleet, depth }, ...matchingSubfleetRows, ...matchingDriverRows, ...matchingVehicleRows];
        });
    }

    buildExpandedFleetRows(fleet, depth = 0) {
        const rows = [{ type: 'fleet', fleet, depth }];
        const childDepth = depth + 1;

        this.fleetSubfleets(fleet).forEach((subfleet) => {
            rows.push(...this.buildExpandedFleetRows(subfleet, childDepth));
        });

        this.fleetDrivers(fleet).forEach((driver) => {
            rows.push({ type: 'driver', driver, depth: childDepth });
        });

        this.fleetVehicles(fleet).forEach((vehicle) => {
            rows.push({ type: 'vehicle', vehicle, depth: childDepth });
        });

        return rows;
    }

    fleetMatches(fleet) {
        return this.resourceMatches(
            fleet,
            ['name', 'public_id', 'status', 'slug'],
            [this.fleetSubtitle(fleet), this.fleetDriverCount(fleet), this.fleetVehicleCount(fleet), fleet?.drivers_online_count, fleet?.vehicles_online_count]
        );
    }

    driverMatches(driver) {
        return this.resourceMatches(driver, ['displayName', 'display_name', 'name', 'public_id', 'status', 'vehicle_name']);
    }

    vehicleMatches(vehicle) {
        return this.resourceMatches(vehicle, ['displayName', 'display_name', 'name', 'public_id', 'status', 'driver_name', 'plate_number', 'vin']);
    }

    collectFleetKeys(fleets = []) {
        return fleets.flatMap((fleet) => {
            const key = this.fleetKey(fleet);
            return [key, ...this.collectFleetKeys(this.fleetSubfleets(fleet))].filter(Boolean);
        });
    }

    sortOnlineFirst(resources = []) {
        return [...resources].sort((a, b) => {
            const onlineSort = Number(Boolean(b.online)) - Number(Boolean(a.online));

            if (onlineSort !== 0) {
                return onlineSort;
            }

            return String(this.displayName(a) ?? '').localeCompare(String(this.displayName(b) ?? ''));
        });
    }

    listenForChanges() {
        this.universe.on('fleet-ops.driver.saved', () => this.loadFallbackResources.perform());
        this.universe.on('fleet-ops.vehicle.saved', () => this.loadFallbackResources.perform());
        this.universe.on('fleet-ops.fleet.vehicle_assigned', () => this.loadFallbackResources.perform());
        this.universe.on('fleet-ops.fleet.vehicle_unassigned', () => this.loadFallbackResources.perform());
        this.universe.on('fleet-ops.fleet.driver_assigned', () => this.loadFallbackResources.perform());
        this.universe.on('fleet-ops.fleet.driver_unassigned', () => this.loadFallbackResources.perform());
    }

    @action setActiveTab(tab) {
        this.activeTab = tab;
        this.scheduleListHeightUpdate();
    }

    @action updateQuery(event) {
        this.query = event.target.value;
        this.scheduleListHeightUpdate();
    }

    @action clearFilter() {
        this.query = '';
        this.scheduleListHeightUpdate();
    }

    @action performEmptyStateAction() {
        if (this.hasQuery) {
            this.clearFilter();
            return;
        }

        if (this.activeTab === 'drivers') {
            this.driverActions.panel.create();
            return;
        }

        if (this.activeTab === 'vehicles') {
            this.vehicleActions.panel.create();
            return;
        }

        if (this.activeTab === 'fleets') {
            this.fleetActions.panel.create();
        }
    }

    @action registerMonitor(element) {
        this.monitorElement = element;
        this.setupLayoutObservers();
        this.scheduleListHeightUpdate();
    }

    @action registerList(element) {
        this.listElement = element;
        this.scheduleListHeightUpdate();
    }

    setupLayoutObservers() {
        this.teardownLayoutObservers();

        if (typeof window !== 'undefined') {
            window.addEventListener('resize', this.scheduleListHeightUpdate);
        }

        if (typeof ResizeObserver === 'undefined' || !this.monitorElement) {
            return;
        }

        this.resizeObserver = new ResizeObserver(() => this.scheduleListHeightUpdate());
        this.resizeObserver.observe(this.monitorElement);

        const sidebarContentInner = this.monitorElement.closest('.next-sidebar-content-inner');
        const navigator = this.monitorElement.closest('.next-sidebar-navigator');

        if (sidebarContentInner) {
            this.resizeObserver.observe(sidebarContentInner);
        }

        if (navigator) {
            this.resizeObserver.observe(navigator);
        }
    }

    teardownLayoutObservers() {
        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', this.scheduleListHeightUpdate);
        }

        if (this.resizeFrame) {
            cancelAnimationFrame(this.resizeFrame);
            this.resizeFrame = undefined;
        }

        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = undefined;
        }
    }

    @action scheduleListHeightUpdate() {
        if (typeof requestAnimationFrame === 'undefined') {
            this.updateListHeight();
            return;
        }

        if (this.resizeFrame) {
            cancelAnimationFrame(this.resizeFrame);
        }

        this.resizeFrame = requestAnimationFrame(() => {
            this.resizeFrame = undefined;
            this.updateListHeight();
        });
    }

    updateListHeight() {
        if (!this.listElement || !this.monitorElement) {
            return;
        }

        const boundary = this.listElement.closest('.next-sidebar-content-inner') ?? this.listElement.closest('.next-sidebar-content') ?? this.monitorElement.parentElement;

        if (!boundary) {
            return;
        }

        const listRect = this.listElement.getBoundingClientRect();
        const boundaryRect = boundary.getBoundingClientRect();
        const availableHeight = Math.floor(boundaryRect.bottom - listRect.top - 10);
        const height = Math.max(128, availableHeight);

        this.listElement.style.setProperty('--fleet-ops-operations-monitor-list-height', `${height}px`);
    }

    @action toggleFleet(fleet) {
        const key = this.fleetKey(fleet);
        const expandedFleetIds = new Set(this.expandedFleetIds);

        if (expandedFleetIds.has(key)) {
            expandedFleetIds.delete(key);
        } else {
            expandedFleetIds.add(key);
        }

        this.expandedFleetIds = expandedFleetIds;
    }

    @action calculateDropdownPosition(trigger) {
        const { top, right } = trigger.getBoundingClientRect();

        return {
            style: {
                left: right + window.scrollX,
                top: top + window.scrollY - 4,
            },
        };
    }

    @action viewDriver(driver) {
        this.driverActions.panel.view(driver);
    }

    @action editDriver(driver) {
        this.driverActions.panel.edit(driver, { useDefaultSaveTask: true });
    }

    @action assignOrderToDriver(driver) {
        this.driverActions.assignOrder(driver);
    }

    @action assignVehicleToDriver(driver) {
        this.driverActions.assignVehicle(driver);
    }

    @action deleteDriver(driver) {
        this.driverActions.delete(driver);
    }

    @action viewVehicle(vehicle) {
        this.vehicleActions.panel.view(vehicle);
    }

    @action editVehicle(vehicle) {
        this.vehicleActions.panel.edit(vehicle, { useDefaultSaveTask: true });
    }

    @action deleteVehicle(vehicle) {
        this.vehicleActions.delete(vehicle);
    }

    @action viewFleet(fleet) {
        this.fleetActions.panel.view(fleet);
    }

    @action assignDriverToFleet(fleet) {
        this.fleetActions.assignDriver(fleet);
    }

    @action assignVehicleToFleet(fleet) {
        this.fleetActions.assignVehicle(fleet);
    }

    @action async locateDriver(driver) {
        await this.transitionToLiveMap();
        await this.focusResource(driver, () => this.driverActions.panel.view(driver, { closeOnTransition: true }));
    }

    @action async locateVehicle(vehicle) {
        await this.transitionToLiveMap();
        await this.focusResource(vehicle, () => this.vehicleActions.panel.view(vehicle, { closeOnTransition: true }));
    }

    async transitionToLiveMap() {
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch (_) {
            // Keep locate actions usable even if the current transition is already in-flight.
        }
    }

    async focusResource(resource, moveend) {
        if (!resource) {
            return;
        }

        await this.mapManager.waitForMap({ timeoutMs: 8000 });
        this.mapManager.focusResource(resource, 16, {
            paddingBottomRight: [300, 200],
            moveend,
        });
    }

    @task *loadFallbackResources() {
        try {
            const [drivers, vehicles, fleets] = yield Promise.all([
                this.store.query('driver', { limit: 20, without: ['vendor'] }),
                this.store.query('vehicle', { limit: 20 }),
                this.store.query('fleet', { limit: 20, with: ['vehicles', 'drivers', 'subfleets'], parents_only: true }),
            ]);

            this.fallbackDrivers = drivers.toArray?.() ?? drivers;
            this.fallbackVehicles = vehicles.toArray?.() ?? vehicles;
            this.fallbackFleets = fleets.toArray?.() ?? fleets;
            this.expandedFleetIds = new Set(this.collectFleetKeys(this.fallbackFleets));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
