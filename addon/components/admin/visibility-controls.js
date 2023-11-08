import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class AdminVisibilityControlsComponent extends Component {
    @service fetch;
    @tracked visibilitySettings = [
        { name: 'Dashboard', route: 'operations.orders', visible: true },
        { name: 'Service Rates', route: 'operations.service-rates', visible: true },
        { name: 'Scheduler', route: 'operations.scheduler', visible: true },
        { name: 'Drivers', route: 'management.drivers', visible: true },
        { name: 'Vehicles', route: 'management.vehicles', visible: true },
        { name: 'Fleets', route: 'management.fleets', visible: true },
        { name: 'Vendors', route: 'management.vendors', visible: true },
        { name: 'Contacts', route: 'management.contacts', visible: true },
        { name: 'Places', route: 'management.places', visible: true },
        { name: 'Fuel Reports', route: 'management.fuel-reports', visible: true },
        { name: 'Issues', route: 'management.issues', visible: true },
    ];
    @tracked isLoading = false;

    constructor() {
        super(...arguments);
        this.loadVisibilitySettings();
    }

    @action mutateVisibility(route, visible) {
        this.visibilitySettings = [...this.visibilitySettings].map((visibilityControl) => {
            if (visibilityControl.route === route) {
                return {
                    ...visibilityControl,
                    visible,
                };
            }

            return visibilityControl;
        });
    }

    @action loadVisibilitySettings() {
        this.isLoading = true;

        this.fetch
            .get('fleet-ops/settings/visibility')
            .then(({ visibilitySettings }) => {
                if (isArray(visibilitySettings)) {
                    for (let i = 0; i < visibilitySettings.length; i++) {
                        const visibilityControl = visibilitySettings.objectAt(i);
                        this.mutateVisibility(visibilityControl.route, visibilityControl.visible);
                    }
                }
            })
            .catch((error) => {
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }

    @action saveVisibilitySettings() {
        this.isLoading = true;

        this.fetch
            .post('fleet-ops/settings/visibility', { visibilitySettings: this.visibilitySettings })
            .then(({ visibilitySettings }) => {
                if (isArray(visibilitySettings)) {
                    for (let i = 0; i < visibilitySettings.length; i++) {
                        const visibilityControl = visibilitySettings.objectAt(i);
                        this.mutateVisibility(visibilityControl.route, visibilityControl.visible);
                    }
                }
            })
            .catch((error) => {
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
}
