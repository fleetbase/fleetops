import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsOrdersIndexSeriesDetailsController extends Controller {
    @service recurringOrderScheduleActions;

    @tracked overlay;
    @tracked activeTab = 'upcoming';

    get actionButtons() {
        const isPaused = this.model?.status === 'paused';

        return [
            {
                text: isPaused ? 'Resume' : 'Pause',
                icon: isPaused ? 'play' : 'pause',
                onClick: () => (isPaused ? this.recurringOrderScheduleActions.resume(this.model) : this.recurringOrderScheduleActions.pause(this.model)),
            },
            {
                text: 'Edit template',
                icon: 'pencil',
                onClick: () => this.recurringOrderScheduleActions.transition.editTemplate(this.model),
            },
        ];
    }

    @action selectTab(tab) {
        this.activeTab = tab;
    }
}
