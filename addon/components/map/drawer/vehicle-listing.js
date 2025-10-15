import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MapDrawerVehicleListingComponent extends Component {
    @service vehicleActions;
    @service leafletMapManager;
    @service hostRouter;
    @service intl;
    @tracked query = '';

    get filteredVehicles() {
        const vehicles = this.leafletMapManager._livemap?.vehicles ?? [];
        const query = this.query?.toLowerCase();
        if (!query) {
            return vehicles;
        }

        return vehicles.filter((vehicle) => {
            const vehicleName = vehicle.searchString?.toLowerCase();
            if (vehicleName) {
                return vehicleName.includes(query.toLowerCase());
            }
            return true;
        });
    }

    /** columns */
    get columns() {
        return [
            {
                label: this.intl.t('column.vehicle'),
                valuePath: 'display_name',
                photoPath: 'photo_url',
                width: '100px',
                cellComponent: 'table/cell/vehicle-name',
                onClick: this.view,
                showOnlineIndicator: true,
            },
            {
                label: this.intl.t('column.location'),
                valuePath: 'location',
                width: '80px',
                cellComponent: 'table/cell/point',
                onClick: this.locate,
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.vehicle') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '90px',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.vehicle') }),
                        fn: this.view,
                        permission: 'fleet-ops view vehicle',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.vehicle') }),
                        fn: this.edit,
                        permission: 'fleet-ops update vehicle',
                    },
                    {
                        label: this.intl.t('vehicle.actions.locate-vehicle'),
                        fn: this.locate,
                        permission: 'fleet-ops view vehicle',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.vehicle') }),
                        fn: this.vehicleActions.delete,
                        permission: 'fleet-ops delete vehicle',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action view(vehicle) {
        this.leafletMapManager.flyToRecordLayer(vehicle, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.vehicleActions.panel.view(vehicle);
            },
        });
    }

    @action edit(vehicle) {
        this.leafletMapManager.flyToRecordLayer(vehicle, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.vehicleActions.panel.edit(vehicle);
            },
        });
    }

    @action locate(vehicle) {
        this.leafletMapManager.flyToRecordLayer(vehicle, 18, {
            paddingBottomRight: [300, 200],
        });
    }
}
