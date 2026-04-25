import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MaintenanceWorkOrdersIndexDetailsController extends Controller {
    @service workOrderActions;
    @service hostRouter;
    @service intl;
    @service abilities;
    @service('universe/menu-service') menuService;
    @tracked overlay;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:work-order:details');
        return [{ route: 'maintenance.work-orders.index.details.index', label: this.intl.t('common.overview') }, ...(isArray(registeredTabs) ? registeredTabs : [])];
    }

    get actionButtons() {
        return [
            { icon: 'paper-plane', fn: this.sendEmail, text: 'Send to Vendor', permission: 'fleet-ops update work-order' },
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update work-order' },
            { icon: 'trash', fn: this.delete, type: 'danger', permission: 'fleet-ops delete work-order' },
        ];
    }

    @action sendEmail() {
        return this.workOrderActions.sendEmail(this.model);
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index.edit', this.model);
    }

    @action delete() {
        return this.workOrderActions.delete(this.model, {
            onConfirm: () => {
                this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index');
            },
        });
    }
}
