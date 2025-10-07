import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OrderDetailsActivityComponent extends Component {
    @service orderActions;
    @service appCache;
    @tracked layout = this.appCache.get('fleetops:order:activity:layout', 'timeline');

    get actionButtons() {
        return [
            {
                items: [
                    {
                        text: 'Update activity',
                        icon: 'signal',
                        disabled: this.args.resource.status === 'canceled',
                        onClick: () => {
                            this.orderActions.updateActivity(this.args.resource, {
                                onFinish: ({ activityCreated }) => {
                                    if (typeof this.args.onChange === 'function') {
                                        this.args.onChange(activityCreated);
                                    }
                                },
                            });
                        },
                    },
                    {
                        text: 'Reload activity',
                        icon: 'refresh',
                        onClick: () => {
                            this.loadActivity.perform();
                        },
                    },
                    {
                        text: this.layout === 'timeline' ? 'View as list' : 'View as timeline',
                        icon: this.layout === 'timeline' ? 'list' : 'timeline',
                        onClick: () => {
                            this.layout = this.layout === 'timeline' ? 'list' : 'timeline';
                            this.appCache.set('fleetops:order:activity:layout', this.layout);
                        },
                    },
                ],
            },
        ];
    }

    @task *loadActivity() {
        try {
            yield this.args.resource.loadTrackingActivity();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
