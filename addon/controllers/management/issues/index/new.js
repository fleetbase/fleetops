import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { format } from 'date-fns';

export default class ManagementIssuesIndexNewController extends Controller {
    @service issueActions;
    @service hostRouter;
    @service intl;
    @service currentUser;
    @service notifications;
    @tracked overlay;
    @tracked issue = this.issueActions.createNewInstance({ reporter: this.currentUser.user });

    @task *save(issue) {
        try {
            yield issue.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.details', issue);
            this.notifications.success(this.intl.t('fleet-ops.component.issue-form-panel.success-message', { issueName: issue.title }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.issue = this.issueActions.createNewInstance({ reporter: this.currentUser.user });
    }
}
