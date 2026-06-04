import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class GettingStartedService extends Service {
    @service fetch;

    @tracked data = null;
    @tracked error = null;

    get isCompleted() {
        return this.data?.is_completed === true;
    }

    get steps() {
        return this.data?.steps ?? [];
    }

    get recommendations() {
        return this.data?.recommendations ?? [];
    }

    get progress() {
        return this.data?.progress ?? { completed: 0, total: 0, percent: 0 };
    }

    get nextStepKey() {
        return this.data?.next_step;
    }

    get nextStep() {
        return this.steps.find((step) => step.key === this.nextStepKey) ?? this.steps.find((step) => !step.completed);
    }

    @task({ drop: true }) *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/getting-started/status');
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Failed to load getting started status';
        }
    }
}
