import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class OrderDetailsActivityComponent extends Component {
    @service orderActions;
    @service appCache;
    @service intl;
    @tracked layout = this.appCache.get('fleetops:order:activity:layout', 'timeline');

    /* eslint-disable ember/no-side-effects */
    get actionButtons() {
        return [
            {
                items: [
                    {
                        text: this.intl.t('order.actions.update-activity'),
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
                        text: this.intl.t('order.actions.reload-activity'),
                        icon: 'refresh',
                        onClick: () => {
                            this.loadActivity.perform();
                        },
                    },
                    {
                        text: this.layout === 'timeline' ? this.intl.t('order.actions.view-activity-as-list') : this.intl.t('order.actions.view-activity-as-timeline'),
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
