import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MapDrawerPlaceListingComponent extends Component {
    @service placeActions;
    @service leafletMapManager;
    @service hostRouter;
    @service intl;
    @tracked query = '';

    get filteredPlaces() {
        const places = this.leafletMapManager._livemap?.places ?? [];
        const query = this.query?.toLowerCase();
        if (!query) {
            return places;
        }

        return places.filter((place) => {
            const placeName = place.address?.toLowerCase();
            if (placeName) {
                return placeName.includes(query.toLowerCase());
            }
            return true;
        });
    }

    /** columns */
    get columns() {
        return [
            {
                label: this.intl.t('column.address'),
                valuePath: 'address',
                width: '200px',
                cellComponent: 'table/cell/anchor',
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
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.place') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '90px',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.place') }),
                        fn: this.view,
                        permission: 'fleet-ops view place',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.place') }),
                        fn: this.edit,
                        permission: 'fleet-ops update place',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('place.actions.locate-place', { resource: this.intl.t('resource.place') }),
                        fn: this.locate,
                        permission: 'fleet-ops view place',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.place') }),
                        fn: this.placeActions.delete,
                        permission: 'fleet-ops delete place',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action view(place) {
        this.leafletMapManager.flyToRecordLayer(place, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.placeActions.panel.view(place);
            },
        });
    }

    @action edit(place) {
        this.leafletMapManager.flyToRecordLayer(place, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.placeActions.panel.edit(place);
            },
        });
    }

    @action locate(place) {
        this.leafletMapManager.flyToRecordLayer(place, 18, {
            paddingBottomRight: [300, 200],
        });
    }
}
