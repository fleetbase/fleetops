import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MaintenanceSchedulesIndexDetailsController extends Controller {
    @service maintenanceScheduleActions;
    @service hostRouter;
    @service intl;
    @service abilities;
    @service('universe/menu-service') menuService;
    @tracked overlay;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:schedule:details');
        return [{ route: 'maintenance.schedules.index.details.index', label: this.intl.t('common.overview') }, ...(isArray(registeredTabs) ? registeredTabs : [])];
    }

    get actionButtons() {
        return [
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update maintenance-schedule' },
            { icon: 'play', helpText: 'Trigger Work Order Now', fn: this.triggerNow, permission: 'fleet-ops update maintenance-schedule' },
            { icon: 'trash', fn: this.delete, permission: 'fleet-ops delete maintenance-schedule', type: 'danger' },
        ];
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index.edit', this.model);
    }

    @action triggerNow() {
        return this.maintenanceScheduleActions.triggerNow(this.model);
    }

    @action delete() {
        return this.maintenanceScheduleActions.delete(this.model, {
            onConfirm: () => {
                this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index');
            },
        });
    }
}
