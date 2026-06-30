import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';

const FILTER_ALL = 'all';
const FILTER_DRIVERS = 'drivers';
const FILTER_VEHICLES = 'vehicles';
const FILTER_ONLINE = 'online';

export default class FleetHierarchyTreeComponent extends Component {
    @service store;

    @tracked expandedFleetIds = new Set();
    @tracked query = '';
    @tracked activeFilter = FILTER_ALL;
    @tracked loadedDriversByFleetKey = {};
    @tracked loadedVehiclesByFleetKey = {};
    loadingDriverFleetKeys = new Set();
    loadingVehicleFleetKeys = new Set();

    constructor() {
        super(...arguments);
        this.expandedFleetIds = new Set(this.initialExpandedFleetIds);
    }

    get filters() {
        return [
            { id: FILTER_ALL, label: 'All' },
            { id: FILTER_DRIVERS, label: 'Drivers' },
            { id: FILTER_VEHICLES, label: 'Vehicles' },
            { id: FILTER_ONLINE, label: 'Online' },
        ];
    }

    get rootFleet() {
        return this.args.fleet;
    }

    get fleets() {
        return this.rootFleet ? [this.rootFleet] : [];
    }

    get normalizedQuery() {
        return String(this.query ?? '')
            .trim()
            .toLowerCase();
    }

    get hasQuery() {
        return this.normalizedQuery.length > 0;
    }

    get hasActiveFilter() {
        return this.activeFilter !== FILTER_ALL;
    }

    get hasControlsApplied() {
        return this.hasQuery || this.hasActiveFilter;
    }

    get hasHierarchyResources() {
        return this.fleets.some((fleet) => this.fleetHasChildren(fleet));
    }

    get allFleetNodesExpanded() {
        const fleetKeys = this.collectFleetKeys(this.fleets);

        return fleetKeys.length > 0 && fleetKeys.every((key) => this.expandedFleetIds.has(key));
    }

    get expandCollapseIcon() {
        return this.allFleetNodesExpanded ? 'down-left-and-up-right-to-center' : 'up-right-and-down-left-from-center';
    }

    get expandCollapseHelpText() {
        return this.allFleetNodesExpanded ? 'Collapse all' : 'Expand all';
    }

    get visibleRows() {
        if (this.hasControlsApplied) {
            return this.buildFilteredFleetRows(this.fleets);
        }

        return this.buildFleetRows(this.fleets);
    }

    get emptyTitle() {
        if (this.hasControlsApplied) {
            return 'No matching resources';
        }

        return 'No fleet resources assigned';
    }

    get emptyDescription() {
        if (this.hasControlsApplied) {
            return 'Clear the current search or filter to show the full fleet hierarchy.';
        }

        return 'Assign drivers or vehicles to make this fleet operationally useful.';
    }

    get initialExpandedFleetIds() {
        return this.fleets.flatMap((fleet) => {
            const key = this.fleetKey(fleet);
            const childKeys = this.fleetSubfleets(fleet)
                .map((subfleet) => this.fleetKey(subfleet))
                .filter(Boolean);

            return [key, ...childKeys].filter(Boolean);
        });
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

    @action displayName(resource) {
        return resource?.displayName ?? resource?.display_name ?? resource?.name ?? resource?.public_id ?? 'Unnamed resource';
    }

    fleetKey(fleet) {
        return String(fleet?.id ?? fleet?.uuid ?? fleet?.public_id ?? fleet?.name ?? '');
    }

    fleetSubfleets(fleet) {
        return this.sortByName(this.resourceArray(fleet?.subfleets));
    }

    @action fleetSubfleetCount(fleet) {
        return this.fleetSubfleets(fleet).length;
    }

    fleetDrivers(fleet) {
        const embeddedDrivers = this.resourceArray(fleet?.drivers);

        if (embeddedDrivers.length) {
            return this.sortOnlineFirst(embeddedDrivers);
        }

        return this.sortOnlineFirst(this.loadedDriversByFleetKey[this.fleetKey(fleet)] ?? []);
    }

    fleetVehicles(fleet) {
        const embeddedVehicles = this.resourceArray(fleet?.vehicles);

        if (embeddedVehicles.length) {
            return this.sortOnlineFirst(embeddedVehicles);
        }

        return this.sortOnlineFirst(this.loadedVehiclesByFleetKey[this.fleetKey(fleet)] ?? []);
    }

    fleetDriverCount(fleet) {
        return Number(fleet?.drivers_count ?? fleet?.driver_count ?? this.fleetDrivers(fleet).length ?? 0);
    }

    fleetVehicleCount(fleet) {
        return Number(fleet?.vehicles_count ?? fleet?.vehicle_count ?? this.fleetVehicles(fleet).length ?? 0);
    }

    fleetOnlineDriverCount(fleet) {
        return Number(fleet?.drivers_online_count ?? this.fleetDrivers(fleet).filter((driver) => driver?.online).length ?? 0);
    }

    fleetOnlineVehicleCount(fleet) {
        return Number(fleet?.vehicles_online_count ?? this.fleetVehicles(fleet).filter((vehicle) => vehicle?.online).length ?? 0);
    }

    @action fleetSubtitle(fleet) {
        return `${this.fleetOnlineDriverCount(fleet)}/${this.fleetDriverCount(fleet)} drivers online - ${this.fleetOnlineVehicleCount(fleet)}/${this.fleetVehicleCount(fleet)} vehicles online`;
    }

    @action driverSubtitle(driver) {
        return [driver?.vehicle_name, driver?.status, driver?.public_id].filter(Boolean).join(' - ') || (driver?.online ? 'Online' : 'Offline');
    }

    @action vehicleSubtitle(vehicle) {
        return [vehicle?.driver_name, vehicle?.plate_number, vehicle?.vin, vehicle?.status, vehicle?.public_id].filter(Boolean).join(' - ') || (vehicle?.online ? 'Online' : 'Offline');
    }

    @action fleetHasChildren(fleet) {
        return this.fleetSubfleets(fleet).length > 0 || this.fleetDriverCount(fleet) > 0 || this.fleetVehicleCount(fleet) > 0;
    }

    @action isFleetExpanded(fleet) {
        return this.expandedFleetIds.has(this.fleetKey(fleet));
    }

    buildFleetRows(fleets = [], depth = 0) {
        return fleets.flatMap((fleet) => {
            const rows = [this.fleetRow(fleet, depth)];

            if (!this.isFleetExpanded(fleet)) {
                return rows;
            }

            rows.push(...this.childRows(fleet, depth + 1));

            return rows;
        });
    }

    buildFilteredFleetRows(fleets = [], depth = 0) {
        return fleets.flatMap((fleet) => {
            const fleetMatches = this.fleetMatches(fleet);
            const childDepth = depth + 1;
            const subfleetRows = this.buildFilteredFleetRows(this.fleetSubfleets(fleet), childDepth);
            const driverRows = this.fleetDrivers(fleet)
                .filter((driver) => this.driverVisible(driver))
                .map((driver) => this.driverRow(driver, childDepth));
            const vehicleRows = this.fleetVehicles(fleet)
                .filter((vehicle) => this.vehicleVisible(vehicle))
                .map((vehicle) => this.vehicleRow(vehicle, childDepth));

            if (fleetMatches) {
                return [this.fleetRow(fleet, depth), ...this.childRows(fleet, childDepth, true)];
            }

            if (!subfleetRows.length && !driverRows.length && !vehicleRows.length) {
                return [];
            }

            return [this.fleetRow(fleet, depth), ...subfleetRows, ...driverRows, ...vehicleRows];
        });
    }

    childRows(fleet, depth, forceExpanded = false) {
        const rows = [];

        this.fleetSubfleets(fleet).forEach((subfleet) => {
            if (forceExpanded) {
                rows.push(this.fleetRow(subfleet, depth), ...this.childRows(subfleet, depth + 1, true));
            } else {
                rows.push(...this.buildFleetRows([subfleet], depth));
            }
        });

        if (this.activeFilter !== FILTER_VEHICLES) {
            this.fleetDrivers(fleet)
                .filter((driver) => this.activeFilter !== FILTER_ONLINE || driver?.online)
                .forEach((driver) => rows.push(this.driverRow(driver, depth)));
        }

        if (this.activeFilter !== FILTER_DRIVERS) {
            this.fleetVehicles(fleet)
                .filter((vehicle) => this.activeFilter !== FILTER_ONLINE || vehicle?.online)
                .forEach((vehicle) => rows.push(this.vehicleRow(vehicle, depth)));
        }

        return rows;
    }

    fleetRow(fleet, depth) {
        return {
            type: 'fleet',
            key: `fleet:${this.fleetKey(fleet)}`,
            fleet,
            depth,
            depthClass: this.depthClass(depth),
        };
    }

    driverRow(driver, depth) {
        return {
            type: 'driver',
            key: `driver:${driver?.id ?? driver?.uuid ?? driver?.public_id ?? this.displayName(driver)}`,
            driver,
            depth,
            depthClass: this.depthClass(depth),
        };
    }

    vehicleRow(vehicle, depth) {
        return {
            type: 'vehicle',
            key: `vehicle:${vehicle?.id ?? vehicle?.uuid ?? vehicle?.public_id ?? this.displayName(vehicle)}`,
            vehicle,
            depth,
            depthClass: this.depthClass(depth),
        };
    }

    depthClass(depth) {
        return `fleet-hierarchy-tree-row-depth-${Math.min(Number(depth) || 0, 5)}`;
    }

    fleetMatches(fleet) {
        return this.resourceMatches(fleet, ['name', 'public_id', 'status', 'slug'], [this.fleetSubtitle(fleet)]);
    }

    driverMatches(driver) {
        return this.resourceMatches(driver, ['displayName', 'display_name', 'name', 'public_id', 'status', 'vehicle_name'], [this.driverSubtitle(driver)]);
    }

    vehicleMatches(vehicle) {
        return this.resourceMatches(vehicle, ['displayName', 'display_name', 'name', 'public_id', 'status', 'driver_name', 'plate_number', 'vin'], [this.vehicleSubtitle(vehicle)]);
    }

    driverVisible(driver) {
        if (this.activeFilter === FILTER_VEHICLES) {
            return false;
        }

        if (this.activeFilter === FILTER_ONLINE && !driver?.online) {
            return false;
        }

        return !this.hasQuery || this.driverMatches(driver);
    }

    vehicleVisible(vehicle) {
        if (this.activeFilter === FILTER_DRIVERS) {
            return false;
        }

        if (this.activeFilter === FILTER_ONLINE && !vehicle?.online) {
            return false;
        }

        return !this.hasQuery || this.vehicleMatches(vehicle);
    }

    resourceMatches(resource, fields = [], extraValues = []) {
        if (!this.hasQuery) {
            return true;
        }

        const values = [...fields.map((field) => resource?.[field]), ...extraValues];

        return values.filter(Boolean).some((value) => String(value).toLowerCase().includes(this.normalizedQuery));
    }

    sortOnlineFirst(resources = []) {
        return [...resources].sort((a, b) => {
            const onlineSort = Number(Boolean(b?.online)) - Number(Boolean(a?.online));

            if (onlineSort !== 0) {
                return onlineSort;
            }

            return String(this.displayName(a)).localeCompare(String(this.displayName(b)));
        });
    }

    sortByName(resources = []) {
        return [...resources].sort((a, b) => String(this.displayName(a)).localeCompare(String(this.displayName(b))));
    }

    @action toggleFleet(fleet) {
        const key = this.fleetKey(fleet);
        const expandedFleetIds = new Set(this.expandedFleetIds);
        const willExpand = !expandedFleetIds.has(key);

        if (!willExpand) {
            expandedFleetIds.delete(key);
        } else {
            expandedFleetIds.add(key);
        }

        this.expandedFleetIds = expandedFleetIds;

        if (willExpand) {
            this.loadFleetResources(fleet);
        }
    }

    @action expandAll() {
        this.expandedFleetIds = new Set(this.collectFleetKeys(this.fleets));
        this.loadVisibleFleetResources();
    }

    @action collapseAll() {
        this.expandedFleetIds = new Set(this.fleets.map((fleet) => this.fleetKey(fleet)).filter(Boolean));
    }

    @action toggleExpandCollapseAll() {
        if (this.allFleetNodesExpanded) {
            this.collapseAll();
        } else {
            this.expandAll();
        }
    }

    collectFleetKeys(fleets = []) {
        return fleets.flatMap((fleet) => [this.fleetKey(fleet), ...this.collectFleetKeys(this.fleetSubfleets(fleet))].filter(Boolean));
    }

    visibleFleets(fleets = this.fleets) {
        return fleets.flatMap((fleet) => {
            if (!this.isFleetExpanded(fleet)) {
                return [fleet];
            }

            return [fleet, ...this.visibleFleets(this.fleetSubfleets(fleet))];
        });
    }

    @action loadVisibleFleetResources() {
        this.visibleFleets().forEach((fleet) => this.loadFleetResources(fleet));
    }

    loadFleetResources(fleet) {
        if (!fleet) {
            return;
        }

        if (!this.fleetDrivers(fleet).length && this.fleetDriverCount(fleet) > 0) {
            this.loadFleetDrivers(fleet);
        }

        if (!this.fleetVehicles(fleet).length && this.fleetVehicleCount(fleet) > 0) {
            this.loadFleetVehicles(fleet);
        }
    }

    async loadFleetDrivers(fleet) {
        const key = this.fleetKey(fleet);

        if (!key || this.loadingDriverFleetKeys.has(key) || this.loadedDriversByFleetKey[key]) {
            return;
        }

        this.loadingDriverFleetKeys.add(key);

        try {
            const drivers = await this.store.query('driver', { fleet: fleet.id, limit: -1 });
            this.loadedDriversByFleetKey = {
                ...this.loadedDriversByFleetKey,
                [key]: this.resourceArray(drivers),
            };
        } catch (error) {
            debug(`Unable to load hierarchy drivers for fleet ${key}: ${error.message}`);
        } finally {
            this.loadingDriverFleetKeys.delete(key);
        }
    }

    async loadFleetVehicles(fleet) {
        const key = this.fleetKey(fleet);

        if (!key || this.loadingVehicleFleetKeys.has(key) || this.loadedVehiclesByFleetKey[key]) {
            return;
        }

        this.loadingVehicleFleetKeys.add(key);

        try {
            const vehicles = await this.store.query('vehicle', { fleet: fleet.id, limit: -1 });
            this.loadedVehiclesByFleetKey = {
                ...this.loadedVehiclesByFleetKey,
                [key]: this.resourceArray(vehicles),
            };
        } catch (error) {
            debug(`Unable to load hierarchy vehicles for fleet ${key}: ${error.message}`);
        } finally {
            this.loadingVehicleFleetKeys.delete(key);
        }
    }

    @action updateQuery(event) {
        this.query = event.target.value;
    }

    @action clearFilters() {
        this.query = '';
        this.activeFilter = FILTER_ALL;
    }

    @action setFilter(filter) {
        this.activeFilter = filter;
    }

    @action viewFleet(fleet) {
        if (typeof this.args.onViewFleet === 'function') {
            return this.args.onViewFleet(fleet);
        }
    }

    @action viewDriver(driver) {
        if (typeof this.args.onViewDriver === 'function') {
            return this.args.onViewDriver(driver);
        }
    }

    @action viewVehicle(vehicle) {
        if (typeof this.args.onViewVehicle === 'function') {
            return this.args.onViewVehicle(vehicle);
        }
    }

    @action assignDriver(fleet) {
        if (typeof this.args.onAssignDriver === 'function') {
            return this.args.onAssignDriver(fleet);
        }
    }

    @action assignVehicle(fleet) {
        if (typeof this.args.onAssignVehicle === 'function') {
            return this.args.onAssignVehicle(fleet);
        }
    }

    @action calculateDropdownLeftPosition(trigger, content) {
        const triggerRect = trigger.getBoundingClientRect();
        const contentRect = content?.getBoundingClientRect?.();
        const contentWidth = contentRect?.width || 224;

        return {
            style: {
                position: 'fixed',
                marginTop: '0px',
                left: triggerRect.left - contentWidth - 3,
                top: triggerRect.top,
            },
        };
    }
}
