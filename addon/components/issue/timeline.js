import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class IssueTimelineComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked events = [];

    constructor() {
        super(...arguments);
        this.loadTimeline.perform();
    }

    get issueId() {
        return this.args.resource?.id || this.args.resource?.uuid || this.args.resource?.public_id;
    }

    get hasEvents() {
        return this.events.length > 0;
    }

    @task *loadTimeline() {
        if (!this.issueId) {
            this.events = [];
            return;
        }

        try {
            const response = yield this.fetch.get(`issues/${this.issueId}/timeline`, {}, { namespace: 'int/v1' });
            this.events = response.events ?? response.timeline ?? [];
        } catch (err) {
            debug('Failed to load issue timeline: ' + err.message);
            this.notifications.serverError(err);
        }
    }

    @action reload() {
        this.loadTimeline.perform();
    }
}
