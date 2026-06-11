import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementDriverIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service driverActions;
    @service issueActions;
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
                label: this.intl.t('common.positions'),
            },
            {
                route: 'management.drivers.index.details.schedule',
                label: this.intl.t('common.schedule'),
            },
            {
                route: 'management.drivers.index.details.activity',
                label: this.intl.t('common.activity'),
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
            {
                icon: 'clipboard-list',
                fn: () => this.driverActions.assignOrder(this.model),
                helpText: this.intl.t('driver.actions.assign-order'),
            },
            {
                icon: 'car',
                fn: () => this.driverActions.assignVehicle(this.model),
                helpText: this.intl.t('driver.actions.assign-vehicle'),
            },
            {
                icon: 'location-dot',
                fn: () => this.driverActions.locate(this.model),
                helpText: this.intl.t('driver.actions.locate-driver'),
            },
            {
                icon: 'triangle-exclamation',
                fn: () => this.createIssue(this.model),
                helpText: 'Create issue',
            },
        ];
    }

    @action createIssue(driver) {
        this.issueActions.modal.create({
            driver,
            driver_uuid: driver.id,
            title: `Issue reported for ${driver.name}`,
        });
    }
}
