import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MaintenancePartsIndexDetailsController extends Controller {
    @service partActions;
    @service hostRouter;
    @service intl;
    @service('universe/menu-service') menuService;

    @tracked overlay;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:part:details');
        return [{ route: 'maintenance.parts.index.details.index', label: this.intl.t('common.overview') }, ...(isArray(registeredTabs) ? registeredTabs : [])];
    }

    get actionButtons() {
        return [
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update part' },
            { icon: 'trash', fn: this.delete, type: 'danger', permission: 'fleet-ops delete part' },
        ];
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.parts.index.edit', this.model);
    }

    @action delete() {
        return this.partActions.delete(this.model, {
            onConfirm: () => {
                this.hostRouter.transitionTo('console.fleet-ops.maintenance.parts.index');
            },
        });
    }
}
