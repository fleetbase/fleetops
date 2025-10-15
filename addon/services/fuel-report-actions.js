import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FuelReportActionsService extends ResourceActionService {
    @service driverActions;
    @service vehicleActions;

    constructor() {
        super(...arguments);
        this.initialize('fuel-report', {
            defaultAttributes: {},
        });
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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.fuel-report')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                fuelReport,
            });
        },
        edit: (fuelReport) => {
            return this.resourceContextPanel.open({
                content: 'fuel-report/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: fuelReport.name }),
                useDefaultSaveTask: true,
                fuelReport,
            });
        },
        view: (fuelReport) => {
            return this.resourceContextPanel.open({
                fuelReport,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'fuel-report/details',
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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.fuel-report')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.fuel-report') }),
                component: 'fuel-report/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fuelReport, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (fuelReport, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fuelReport,
                title: this.intl.t('common.edit-resource-name', { resourceName: fuelReport.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
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
