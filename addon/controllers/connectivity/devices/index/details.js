import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ConnectivityDevicesIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service deviceActions;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:device:details');
        return [
            {
                route: 'connectivity.devices.index.details.index',
                label: this.intl.t('common.overview'),
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.edit', this.model),
                permission: 'fleet-ops update device',
            },
            {
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: [
                    {
                        text: this.intl.t('device.actions.attach-to-vehicle'),
                        icon: 'link',
                        fn: () => this.deviceActions.attachToVehicle(this.model),
                        permission: 'fleet-ops update device',
                    },
                    ...(this.model.attachable_uuid || this.model.attached_to_name || this.model.attachable
                        ? [
                              {
                                  text: this.intl.t('device.actions.detach-from-vehicle'),
                                  icon: 'unlink',
                                  fn: () => this.deviceActions.detachFromVehicle(this.model),
                                  permission: 'fleet-ops update device',
                              },
                          ]
                        : []),
                ],
            },
        ];
    }
}
