import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AnalyticsReportsIndexEditController extends Controller {
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

    @task *save(report) {
        try {
            yield report.validate();

            try {
                const result = yield report.execute();
                report.fillResult(result);

                yield report.save();
                this.overlay?.close();

                yield this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index.details', report);
                this.notifications.success(
                    this.intl.t('common.resource-updated-success', {
                        resource: this.intl.t('resource.report'),
                        resourceName: report.title,
                    })
                );
            } catch (err) {
                this.notifications.serverError(err);
            }
        } catch (err) {
            if (err.message) {
                this.notifications.error(err?.validation_errors?.firstObject ?? err?.message ?? 'Error validating report configuration');
            } else {
                this.notifications.serverError(err);
            }
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(report, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.report') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                report.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index.details', report);
            },
            ...options,
        });
    }
}
