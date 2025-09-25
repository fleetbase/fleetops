import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FuelReportActionsService extends ResourceActionService {
    @service driverActions;
    @service vehicleActions;

    constructor() {
        super(...arguments);
        this.initialize('fuel-report');
    }

    transition = {
        view: (fuelReport) => this.transitionTo('management.fuel-reports.index.details', fuelReport),
        edit: (fuelReport) => this.transitionTo('management.fuel-reports.index.edit', fuelReport),
        create: () => this.transitionTo('management.fuel-reports.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const fuelReport = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'fuel-report/form',
                title: 'Create a new fuel-report',
                panelContentClass: 'px-4',
                saveOptions: {
                    callback: this.refresh,
                },
                fuelReport,
            });
        },
        edit: (fuelReport) => {
            return this.resourceContextPanel.open({
                content: 'fuel-report/form',
                title: `Edit: ${fuelReport.name}`,
                panelContentClass: 'px-4',
                fuelReport,
            });
        },
        view: (fuelReport) => {
            return this.resourceContextPanel.open({
                fuelReport,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'fuel-report/details',
                        contentClass: 'p-4',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const fuelReport = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: fuelReport,
                title: 'Create a new fuel-report',
                acceptButtonText: 'Create Fuel Report',
                component: 'fuel-report/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fuelReport, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (fuelReport, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fuelReport,
                title: `Edit: ${fuelReport.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'fuel-report/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fuelReport, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (fuelReport, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fuelReport,
                title: fuelReport.name,
                component: 'fuel-report/details',
                ...options,
            });
        },
    };

    @action async viewDriver(fuelReport) {
        const driver = await fuelReport.loadDriver();
        if (driver) {
            this.driverActions.panel.view(driver);
        }
    }

    @action async viewVehicle(fuelReport) {
        const vehicle = await fuelReport.loadVehicle();
        if (vehicle) {
            this.vehicleActions.panel.view(vehicle);
        }
    }
}
