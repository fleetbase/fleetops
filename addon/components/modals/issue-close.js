import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ModalsIssueCloseComponent extends Component {
    @service currentUser;
    @service notifications;

    @tracked options = {};
    @tracked issue;
    @tracked note;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.issue = options.issue;
        this.setupOptions();
    }

    get orderLabel() {
        return this.issue?.order?.tracking || this.issue?.order?.public_id;
    }

    get vehicleLabel() {
        return this.issue?.vehicle?.displayName || this.issue?.vehicle_name;
    }

    setupOptions() {
        this.options.title = `Close ${this.issue?.public_id || 'Issue'}`;
        this.options.acceptButtonText = 'Close Issue';
        this.options.acceptButtonIcon = 'circle-check';
        this.options.acceptButtonScheme = 'success';
        this.options.confirm = async (modal) => {
            if (!this.note) {
                this.notifications.warning('Add a close note before closing the issue.');
                return;
            }

            modal.startLoading();

            try {
                const closedAt = new Date().toISOString();
                const user = this.currentUser.user;
                const meta = this.issue.meta ?? {};

                this.issue.status = 'closed';
                this.issue.resolved_at = new Date();
                this.issue.meta = {
                    ...meta,
                    resolution: {
                        ...(meta.resolution ?? {}),
                        note: this.note,
                        closed_at: closedAt,
                        closed_by_uuid: user?.id,
                        closed_by_name: user?.name,
                    },
                };

                await this.issue.save();
                this.notifications.success('Issue closed.');

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
