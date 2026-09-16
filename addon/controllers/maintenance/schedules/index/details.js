import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MaintenanceSchedulesIndexDetailsController extends Controller {
    @service maintenanceScheduleActions;
    @service hostRouter;
    @service intl;
    @service('universe/menu-service') menuService;
    @tracked overlay;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:schedule:details');
        return [
            { route: 'maintenance.schedules.index.details.index', label: this.intl.t('common.overview') },
            { route: 'maintenance.schedules.index.details.work-orders', label: this.intl.t('menu.work-orders') },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return this.maintenanceScheduleActions.panelActionButtons(this.model, {
            onEdit: this.edit,
            onDeleted: () => this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index'),
        });
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index.edit', this.model);
    }
}
