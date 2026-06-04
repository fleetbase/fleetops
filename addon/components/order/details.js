import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class OrderDetailsComponent extends Component {
    @tracked proofReloadToken = 0;

    get effectiveProofReloadToken() {
        return `${this.args.proofReloadToken ?? 0}:${this.proofReloadToken}`;
    }

    @action handleActivityChanged(optionsOrActivity) {
        if (typeof this.args.onActivityChanged === 'function') {
            this.args.onActivityChanged(optionsOrActivity?.activityCreated ?? optionsOrActivity);
        }

        if (optionsOrActivity?.proofCreated) {
            this.proofReloadToken++;
        }
    }
}
