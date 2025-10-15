import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AnalyticsReportsIndexNewController extends Controller {
    @service reportActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked validationErrors = [];
    @tracked report = this.reportActions.createNewInstance({ type: 'fleet-ops' });

    @task *save(report) {
        try {
            yield report.validate();

            try {
                const result = yield report.execute();
                report.fillResult(result);

                yield report.save();
                this.overlay?.close();

                yield this.hostRouter.refresh();
                yield this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index.details', report);
                this.notifications.success(
                    this.intl.t('common.resource-created-success-name', {
                        resource: this.intl.t('resource.report'),
                        resourceName: report.title,
                    })
                );
                this.resetForm();
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

    @action resetForm() {
        this.report = this.reportActions.createNewInstance({ type: 'fleet-ops' });
    }
}
