import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MaintenanceMaintenancesIndexDetailsController extends Controller {
    @service maintenanceActions;
    @service hostRouter;
    @service intl;
    @service abilities;
    @service('universe/menu-service') menuService;

    @tracked overlay;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:maintenance:details');
        return [
            {
                route: 'maintenance.maintenances.index.details.index',
                label: this.intl.t('common.overview'),
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'edit',
                fn: this.edit,
                permission: 'fleet-ops update maintenance',
            },
            {
                icon: 'trash',
                fn: this.delete,
                type: 'danger',
                permission: 'fleet-ops delete maintenance',
            },
        ];
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index.edit', this.model);
    }

    @action delete() {
        return this.maintenanceActions.delete(this.model, {
            onConfirm: () => {
                this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index');
            },
        });
    }
}
