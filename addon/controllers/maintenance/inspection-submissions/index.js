import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceInspectionSubmissionsIndexController extends Controller {
    @service inspectionSubmissionActions;
    @service intl;

    @tracked queryParams = ['status', 'result', 'type', 'page', 'limit', 'sort', 'query', 'public_id', 'vehicle', 'driver', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;
    @tracked result;
    @tracked type;
    @tracked vehicle;
    @tracked driver;

    get actionButtons() {
        return [
            { icon: 'refresh', onClick: this.inspectionSubmissionActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.inspectionSubmissionActions.transition.create },
        ];
    }

    get bulkActions() {
        return [{ label: 'Delete selected...', class: 'text-red-500', fn: this.inspectionSubmissionActions.bulkDelete }];
    }

    get columns() {
        return [
            {
                label: 'Inspection',
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                action: this.inspectionSubmissionActions.transition.view,
                permission: 'fleet-ops view inspection-submission',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'public_id',
                filterComponent: 'filter/string',
            },
            { label: 'Form', valuePath: 'form_name', resizable: true, sortable: false },
            { label: 'Vehicle', valuePath: 'vehicle_name', resizable: true, sortable: false },
            { label: 'Driver', valuePath: 'driver_name', resizable: true, sortable: false },
            {
                label: 'Result',
                valuePath: 'result',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'result',
                filterComponent: 'filter/string',
            },
            { label: 'Failed', valuePath: 'failed_items', resizable: true, sortable: true },
            { label: 'Submitted', valuePath: 'submittedAt', sortParam: 'submitted_at', resizable: true, sortable: true, filterable: true, filterComponent: 'filter/date' },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    { label: 'View inspection', fn: this.inspectionSubmissionActions.transition.view, permission: 'fleet-ops view inspection-submission' },
                    { label: 'Edit inspection', fn: this.inspectionSubmissionActions.transition.edit, permission: 'fleet-ops update inspection-submission' },
                    { separator: true },
                    { label: 'Submit', fn: this.inspectionSubmissionActions.submit, permission: 'fleet-ops submit inspection-submission' },
                    { label: 'Create issue', fn: this.inspectionSubmissionActions.createIssue, permission: 'fleet-ops create-issue inspection-submission' },
                    { label: 'Create work order', fn: this.inspectionSubmissionActions.createWorkOrder, permission: 'fleet-ops create-work-order inspection-submission' },
                    { label: 'Resolve', fn: this.inspectionSubmissionActions.resolve, permission: 'fleet-ops resolve inspection-submission' },
                    { separator: true },
                    { label: 'Delete inspection', fn: this.inspectionSubmissionActions.delete, class: 'text-red-500', permission: 'fleet-ops delete inspection-submission' },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }
}
