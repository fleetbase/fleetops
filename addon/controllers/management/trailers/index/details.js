import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementTrailersIndexDetailsController extends Controller {
    @service trailerActions;
    @service('universe/menu-service') menuService;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:trailer:details');

        return [
            { id: 'index', route: 'management.trailers.index.details.index', label: this.intl.t('trailer.tabs.overview') },
            { id: 'positions', route: 'management.trailers.index.details.positions', label: this.intl.t('trailer.tabs.positions') },
            { id: 'devices', route: 'management.trailers.index.details.devices', label: this.intl.t('trailer.tabs.devices') },
            { id: 'equipment', route: 'management.trailers.index.details.equipment', label: this.intl.t('trailer.tabs.equipment') },
            { id: 'connections', route: 'management.trailers.index.details.connections', label: this.intl.t('trailer.tabs.connections') },
            { id: 'schedules', route: 'management.trailers.index.details.schedules', label: this.intl.t('trailer.tabs.schedules') },
            { id: 'work-orders', route: 'management.trailers.index.details.work-orders', label: this.intl.t('trailer.tabs.work-orders') },
            { id: 'maintenance-history', route: 'management.trailers.index.details.maintenance-history', label: this.intl.t('trailer.tabs.maintenance') },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get isAttached() {
        return this.model?.isAttached ?? this.model?.attachment_state === 'attached';
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.edit', this.model),
                permission: 'fleet-ops update trailer',
            },
            {
                // Actions dropdown — mirrors the row-level actions from the trailers index table
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: [
                    {
                        text: this.intl.t('trailer.actions.locate'),
                        icon: 'location-dot',
                        fn: () => this.trailerActions.locate(this.model),
                        permission: 'fleet-ops view trailer',
                    },
                    ...(this.isAttached
                        ? [
                              {
                                  text: this.intl.t('trailer.actions.detach-vehicle'),
                                  icon: 'unlink',
                                  fn: () => this.trailerActions.detachVehicle(this.model),
                                  permission: 'fleet-ops detach-vehicle-for trailer',
                              },
                          ]
                        : [
                              {
                                  text: this.intl.t('trailer.actions.attach-vehicle'),
                                  icon: 'link',
                                  fn: () => this.trailerActions.attachVehicle(this.model),
                                  permission: 'fleet-ops attach-vehicle-for trailer',
                              },
                          ]),
                    {
                        text: this.intl.t('trailer.actions.attach-device'),
                        icon: 'microchip',
                        fn: () => this.trailerActions.attachDevice(this.model),
                        permission: 'fleet-ops attach-device-for trailer',
                    },
                    {
                        text: this.intl.t('trailer.actions.attach-equipment'),
                        icon: 'toolbox',
                        fn: () => this.trailerActions.attachEquipment(this.model),
                        permission: 'fleet-ops attach-equipment-for trailer',
                    },
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('trailer.actions.schedule-maintenance'),
                        icon: 'calendar-check',
                        fn: () => this.trailerActions.scheduleMaintenance(this.model),
                        permission: 'fleet-ops create maintenance-schedule',
                    },
                    {
                        text: this.intl.t('trailer.actions.create-work-order'),
                        icon: 'clipboard-list',
                        fn: () => this.trailerActions.createWorkOrder(this.model),
                        permission: 'fleet-ops create work-order',
                    },
                    {
                        text: this.intl.t('trailer.actions.log-maintenance'),
                        icon: 'wrench',
                        fn: () => this.trailerActions.logMaintenance(this.model),
                        permission: 'fleet-ops create maintenance',
                    },
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.trailer') }),
                        icon: 'trash',
                        fn: () =>
                            this.trailerActions.delete(this.model, {
                                onConfirm: () => {
                                    this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
                                },
                            }),
                        permission: 'fleet-ops delete trailer',
                        class: 'text-red-500 hover:text-red-600',
                    },
                ],
            },
        ];
    }
}
