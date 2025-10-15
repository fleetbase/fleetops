import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementIssuesIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked overlay;
    @tracked actionButtons = [
        {
            icon: 'eye',
            fn: this.view,
        },
    ];

    @task *save(issue) {
        try {
            yield issue.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.details', issue);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.issue'),
                    resourceName: issue.title ?? issue.public_id,
                })
            );
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.issues.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(issue, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.issue') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                issue.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.details', issue);
            },
            ...options,
        });
    }
}
