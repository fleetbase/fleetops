import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementDriverIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:driver:details');
        return [
            {
                route: 'management.drivers.index.details.index',
                label: this.intl.t('common.overview'),
            },
            {
                route: 'management.drivers.index.details.positions',
                label: 'Positions',
            },
            {
                route: 'management.drivers.index.details.schedule',
                label: this.intl.t('common.schedule'),
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.edit', this.model),
            },
        ];
    }
}
