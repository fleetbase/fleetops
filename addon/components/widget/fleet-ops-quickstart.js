import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';

export default class WidgetFleetOpsQuickstartComponent extends Component {
    @service fetch;
    @service hostRouter;
    @tracked tasks = {};
    @tracked isLoading = true;
    @tracked isHidden = false;

    @computed('args.title') get title() {
        return this.args.title || 'Quickstart tasks for Fleet Ops';
    }

    @action async getTasks() {
        this.tasks = await this.fetchTasks();
    }

    @action startTask(action) {
        switch (action) {
            case 'add_drivers':
                break;
            case 'add_fleet':
                break;
            case 'add_order_config':
                break;
            case 'add_service_area':
                break;
            case 'add_users':
                break;
            case 'add_vehicles':
                break;
            case 'create_first_order':
                break;
        }
    }

    fetchTasks() {
        this.isLoading = true;

        return new Promise((resolve) => {
            this.fetch.get('actions/get-fleet-ops-quickstart-actions').then((tasks) => {
                this.isLoading = false;
                resolve(tasks);
            });
        });
    }

    shouldHideWidget(tasks) {
        const shouldHide = Object.values(tasks).every((task) => task.should === false);

        if (shouldHide) {
            this.isHidden = true;
        }
    }
}
