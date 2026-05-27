import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ModalsStopActivityComponent extends Component {
    @service store;
    @service notifications;
    @tracked activity = [];

    constructor() {
        super(...arguments);
        this.loadActivity.perform();
    }

    get stop() {
        return this.args.options?.stop;
    }

    get place() {
        return this.stop?.place ?? this.stop;
    }

    get order() {
        return this.args.options?.order;
    }

    get stopName() {
        return this.place?.name ?? this.place?.address ?? this.place?.id;
    }

    get trackingNumberUuid() {
        return this.stop?.trackingNumberUuid ?? this.stop?.tracking_number_uuid ?? this.stop?.tracking_number?.uuid ?? this.place?.tracking_number_uuid ?? this.place?.tracking_number?.uuid;
    }

    @task *loadActivity() {
        if (!this.trackingNumberUuid) {
            this.activity = [];
            return [];
        }

        try {
            const activity = yield this.store.query('tracking-status', {
                tracking_number_uuid: this.trackingNumberUuid,
            });
            this.activity = activity.toArray();
            return activity;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
