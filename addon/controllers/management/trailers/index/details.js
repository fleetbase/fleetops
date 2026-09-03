import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
export default class ManagementTrailersIndexDetailsController extends Controller {
    @service trailerActions;
    @service hostRouter;
    @service intl;
    get tabs() {
        return [
            { route: 'management.trailers.index.details.index', label: this.intl.t('common.overview') },
            { route: 'management.trailers.index.details.positions', label: this.intl.t('common.positions') },
            { route: 'management.trailers.index.details.devices', label: this.intl.t('resource.devices') },
            { route: 'management.trailers.index.details.equipment', label: this.intl.t('resource.equipment') },
            { route: 'management.trailers.index.details.connections', label: this.intl.t('trailer.tabs.connections') },
            { route: 'management.trailers.index.details.schedules', label: this.intl.t('trailer.tabs.schedules') },
            { route: 'management.trailers.index.details.work-orders', label: this.intl.t('trailer.tabs.work-orders') },
            { route: 'management.trailers.index.details.maintenance-history', label: this.intl.t('trailer.tabs.maintenance') },
        ];
    }
    get actionButtons() {
        return [
            { icon: 'pencil', fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.edit', this.model), permission: 'fleet-ops update trailer' },
            {
                icon: 'ellipsis-h',
                renderInPlace: true,
                items: [
                    {
                        text: this.intl.t('trailer.actions.attach-vehicle'),
                        icon: 'link',
                        fn: () => this.trailerActions.attachVehicle(this.model),
                        permission: 'fleet-ops attach-vehicle-for trailer',
                        isVisible: this.model.attachment_state !== 'attached',
                    },
                    {
                        text: this.intl.t('trailer.actions.detach-vehicle'),
                        icon: 'unlink',
                        fn: () => this.trailerActions.detachVehicle(this.model),
                        permission: 'fleet-ops detach-vehicle-for trailer',
                        isVisible: this.model.attachment_state === 'attached',
                    },
                    {
                        text: this.intl.t('trailer.actions.attach-device'),
                        icon: 'microchip',
                        fn: () => this.trailerActions.attachDevice(this.model),
                        permission: 'fleet-ops attach-device-for trailer',
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
                    { separator: true },
                    {
                        text: this.intl.t('common.delete'),
                        icon: 'trash',
                        fn: () => this.trailerActions.delete(this.model, { onConfirm: () => this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index') }),
                        permission: 'fleet-ops delete trailer',
                        class: 'text-red-500',
                    },
                ],
            },
        ];
    }
}
