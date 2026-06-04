import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const ACCENT_CLASS = {
    blue: 'feature-accent-blue',
    green: 'feature-accent-green',
    amber: 'feature-accent-amber',
    purple: 'feature-accent-purple',
};

export default class HomeGettingStartedGuidanceComponent extends Component {
    @service gettingStarted;
    @service router;
    @service docsPanel;

    constructor() {
        super(...arguments);
        this.gettingStarted.load.perform();
    }

    get steps() {
        return this.gettingStarted.steps;
    }

    get recommendations() {
        return this.gettingStarted.recommendations.slice(0, 4);
    }

    get progress() {
        return this.gettingStarted.progress;
    }

    get nextStep() {
        return this.gettingStarted.nextStep;
    }

    get isComplete() {
        return this.gettingStarted.isCompleted;
    }

    @action
    isActiveStep(step) {
        return this.nextStep?.key === step.key && !step.completed;
    }

    @action
    statusText(step) {
        if (step.completed) {
            return 'Done';
        }

        return this.isActiveStep(step) ? 'Next' : 'Not started';
    }

    @action
    statusClass(step) {
        if (step.completed) {
            return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
        }

        if (this.isActiveStep(step)) {
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
        }

        return 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300';
    }

    @action
    accentClass(feature) {
        return ACCENT_CLASS[feature.accent] ?? ACCENT_CLASS.blue;
    }

    @action
    startStep(step) {
        if (step?.route) {
            return this.router.transitionTo(step.route);
        }

        if (step?.docs_url) {
            return this.openDocs(step.docs_url, step.title, 'fleet-ops-getting-started-guidance');
        }
    }

    @action
    openFeature(feature) {
        return this.openDocs(feature.docs_url, feature.title, 'fleet-ops-recommended-features-guidance');
    }

    openDocs(url, title, source) {
        if (this.docsPanel?.open) {
            return this.docsPanel.open(url, { title, source });
        }

        return window.open(url, '_docs');
    }

    @action
    refresh() {
        this.gettingStarted.load.perform();
    }
}
