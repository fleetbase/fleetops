import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ManagementDriverIndexDetailsController extends Controller {
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'management.drivers.index.details.index',
                label: 'Overview',
            },
            {
                route: 'management.drivers.index.details.positions',
                label: 'Positions',
            },
            // {
            //     route: 'management.drivers.index.details.schedule',
            //     label: 'Schedule',
            // },
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
