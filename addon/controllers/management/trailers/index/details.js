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

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.edit', this.model),
                permission: 'fleet-ops update trailer',
            },
            {
                // Actions dropdown — shared with the trailer context panel
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: this.trailerActions.detailsMenuItems(this.model, {
                    onDeleted: () => this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index'),
                }),
            },
        ];
    }
}
