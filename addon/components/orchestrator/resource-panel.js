import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Orchestrator::ResourcePanel
 *
 * Right panel of the Orchestrator Workbench. Shows available vehicles
 * and drivers in separate tabs with search and filter. Used pre-run to
 * select resources for targeted allocation runs.
 *
 * @arg vehicles              - Array of available vehicles
 * @arg drivers               - Array of available drivers
 * @arg selectedVehicleIds    - Set of selected vehicle public_ids
 * @arg selectedDriverIds     - Set of selected driver public_ids
 * @arg onToggleVehicle       - Action(vehicle)
 * @arg onToggleDriver        - Action(driver)
 * @arg onClearVehicles       - Action
 * @arg onClearDrivers        - Action
 */
export default class OrchestratorResourcePanelComponent extends Component {
    @tracked activeTab = 'vehicles';
    @tracked vehicleSearch = '';
    @tracked vehicleFilter = 'all';
    @tracked driverSearch = '';
    @tracked driverFilter = 'all';

    @action setTab(tab) {
        this.activeTab = tab;
    }

    @action onVehicleSearchInput(event) {
        this.vehicleSearch = event.target.value;
    }

    @action setVehicleFilter(filter) {
        this.vehicleFilter = filter;
    }

    @action onDriverSearchInput(event) {
        this.driverSearch = event.target.value;
    }

    @action setDriverFilter(filter) {
        this.driverFilter = filter;
    }

    get filteredVehicles() {
        let vehicles = this.args.vehicles ?? [];

        if (this.vehicleSearch) {
            const q = this.vehicleSearch.toLowerCase();
            vehicles = vehicles.filter(
                (v) =>
                    v.display_name?.toLowerCase().includes(q) ||
                    v.plate_number?.toLowerCase().includes(q) ||
                    v.call_sign?.toLowerCase().includes(q) ||
                    v.driver?.name?.toLowerCase().includes(q)
            );
        }

        if (this.vehicleFilter === 'active') {
            vehicles = vehicles.filter((v) => v.status === 'active');
        } else if (this.vehicleFilter === 'no-driver') {
            vehicles = vehicles.filter((v) => !v.driver?.id);
        } else if (this.vehicleFilter === 'available') {
            vehicles = vehicles.filter((v) => v.status === 'active' && !v.driver?.current_job);
        }

        return vehicles;
    }

    get filteredDrivers() {
        let drivers = this.args.drivers ?? [];

        if (this.driverSearch) {
            const q = this.driverSearch.toLowerCase();
            drivers = drivers.filter(
                (d) => d.name?.toLowerCase().includes(q) || d.phone?.toLowerCase().includes(q) || d.email?.toLowerCase().includes(q) || d.vehicle?.display_name?.toLowerCase().includes(q)
            );
        }

        if (this.driverFilter === 'online') {
            drivers = drivers.filter((d) => d.online);
        } else if (this.driverFilter === 'offline') {
            drivers = drivers.filter((d) => !d.online);
        } else if (this.driverFilter === 'on-shift') {
            drivers = drivers.filter((d) => d.current_job);
        }

        return drivers;
    }

    get selectedVehicleIdsArray() {
        return [...(this.args.selectedVehicleIds ?? new Set())];
    }

    get selectedDriverIdsArray() {
        return [...(this.args.selectedDriverIds ?? new Set())];
    }

    @action clearAllSelections() {
        this.args.onClearVehicles?.();
        this.args.onClearDrivers?.();
    }
}
