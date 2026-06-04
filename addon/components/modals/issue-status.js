import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ModalsIssueStatusComponent extends Component {
    @service notifications;

    @tracked options = {};
    @tracked issue;
    @tracked selectedStatus;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.issue = options.issue;
        this.selectedStatus = options.statusOptions?.find((status) => status.value === this.issue?.status);
        this.setupOptions();
    }

    setupOptions() {
        this.options.title = 'Change Issue Status';
        this.options.acceptButtonText = 'Update Status';
        this.options.acceptButtonIcon = 'save';
        this.options.confirm = async (modal) => {
            modal.startLoading();

            try {
                this.issue.status = this.selectedStatus?.value ?? this.issue.status;
                await this.issue.save();
                this.notifications.success('Issue status updated.');

                if (typeof this.options.onSaved === 'function') {
                    await this.options.onSaved(this.issue);
                }

                modal.done();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
    }
}
