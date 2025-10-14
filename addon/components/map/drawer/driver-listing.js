import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MapDrawerDriverListingComponent extends Component {
    @service driverActions;
    @service leafletMapManager;
    @service hostRouter;
    @service intl;
    @tracked query = '';

    get filteredDrivers() {
        const drivers = this.leafletMapManager._livemap?.drivers ?? [];
        const query = this.query?.toLowerCase();
        if (!query) {
            return drivers;
        }

        return drivers.filter((driver) => {
            const driverName = driver.name?.toLowerCase();
            if (driverName) {
                return driverName.includes(query.toLowerCase());
            }
            return true;
        });
    }

    /** columns */
    get columns() {
        return [
            {
                label: this.intl.t('column.driver'),
                valuePath: 'name',
                photoPath: 'photo_url',
                width: '100px',
                cellComponent: 'cell/driver-name',
                onClick: this.view,
            },
            {
                label: this.intl.t('column.location'),
                valuePath: 'location',
                width: '80px',
                cellComponent: 'table/cell/point',
                onClick: this.locate,
            },
            {
                label: this.intl.t('column.current-job'),
                valuePath: 'current_job_id',
                width: '80px',
                cellComponent: 'table/cell/anchor',
                onClick: this.job,
            },
            {
                label: this.intl.t('common.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                width: '60px',
            },
            {
                label: this.intl.t('column.last-seen'),
                valuePath: 'updatedAgo',
                width: '60px',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.driver') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '90px',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.driver') }),
                        fn: this.view,
                        permission: 'fleet-ops view driver',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.driver') }),
                        fn: this.edit,
                        permission: 'fleet-ops update driver',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('driver.actions.assign-order'),
                        fn: this.driverActions.assignOrder,
                        permission: 'fleet-ops assign-order-for driver',
                    },
                    {
                        label: this.intl.t('driver.actions.assign-vehicle'),
                        fn: this.driverActions.assignVehicle,
                        permission: 'fleet-ops assign-vehicle-for driver',
                    },
                    {
                        label: this.intl.t('driver.actions.locate-driver'),
                        fn: this.locate,
                        permission: 'fleet-ops view driver',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.driver') }),
                        fn: this.driverActions.delete,
                        permission: 'fleet-ops delete driver',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action view(driver) {
        this.leafletMapManager.flyToRecordLayer(driver, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.driverActions.panel.view(driver);
            },
        });
    }

    @action edit(driver) {
        this.leafletMapManager.flyToRecordLayer(driver, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.driverActions.panel.edit(driver);
            },
        });
    }

    @action locate(driver) {
        this.leafletMapManager.flyToRecordLayer(driver, 18, {
            paddingBottomRight: [300, 200],
        });
    }

    @action job(driver) {
        if (!driver.current_job_id) return;
        this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', driver.current_job_id);
    }
}
