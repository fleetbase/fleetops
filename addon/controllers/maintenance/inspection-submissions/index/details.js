import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class MaintenanceInspectionSubmissionsIndexDetailsController extends Controller {
    @service inspectionSubmissionActions;
    @service hostRouter;
    @service intl;
    @tracked overlay;

    get tabs() {
        return [
            { route: 'maintenance.inspection-submissions.index.details.index', label: this.intl.t('inspection.record.overview') },
            { route: 'maintenance.inspection-submissions.index.details.photos', label: this.intl.t('inspection.record.photos') },
            { route: 'maintenance.inspection-submissions.index.details.audit', label: this.intl.t('inspection.record.audit') },
        ];
    }

    /**
     * Editing is the one action worth a button of its own; the rest sit behind
     * the ellipsis, which is what the vehicle panel does. Five buttons
     * overflowed the overlay header and pushed the title out of sight.
     */
    get actionButtons() {
        return [
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update inspection-submission' },
            {
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: this.followUpItems,
            },
        ];
    }

    /**
     * A submission raises one issue and one work order, and the server enforces
     * that — `createIssueFromFailures()` hands back what already exists. The
     * console kept offering both anyway, so the same click reported success
     * over and over while creating nothing. What is already raised is linked
     * from the Follow Up panel instead of offered again here.
     */
    get followUpItems() {
        const record = this.model;
        const items = [];

        if (record?.has_failures && !record?.issue_uuid) {
            items.push({
                text: this.intl.t('inspection.record.create-issue'),
                icon: 'triangle-exclamation',
                fn: this.createIssue,
                permission: 'fleet-ops create-issue inspection-submission',
            });
        }

        if (record?.has_failures && !record?.work_order_uuid) {
            items.push({
                text: this.intl.t('inspection.record.create-work-order'),
                icon: 'clipboard-list',
                fn: this.createWorkOrder,
                permission: 'fleet-ops create-work-order inspection-submission',
            });
        }

        if (record?.status !== 'resolved') {
            items.push({ text: this.intl.t('inspection.record.resolve'), icon: 'check', fn: this.resolve, permission: 'fleet-ops resolve inspection-submission' });
        }

        if (items.length) {
            items.push({ separator: true });
        }

        items.push({ text: this.intl.t('common.delete'), icon: 'trash', class: 'text-red-500', fn: this.delete, permission: 'fleet-ops delete inspection-submission' });

        return items;
    }

    @action createIssue() {
        return this.inspectionSubmissionActions.createIssue(this.model);
    }

    @action createWorkOrder() {
        return this.inspectionSubmissionActions.createWorkOrder(this.model);
    }

    @action resolve() {
        return this.inspectionSubmissionActions.resolve(this.model);
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.edit', this.model);
    }

    @action delete() {
        return this.inspectionSubmissionActions.delete(this.model, {
            onConfirm: () => this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index'),
        });
    }
}
