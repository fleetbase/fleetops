import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class OperationsSchedulerController extends Controller {
    @service intl;

    get tabs() {
        return [
            {
                route: 'operations.scheduler.index',
                label: this.intl.t('scheduler.orders-tab'),
                icon: 'box',
            },
            {
                route: 'operations.scheduler.fleet-schedule',
                label: this.intl.t('scheduler.fleet-schedule-tab'),
                icon: 'calendar-week',
            },
        ];
    }
}
