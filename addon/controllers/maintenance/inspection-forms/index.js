import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceInspectionFormsIndexController extends Controller {
    @service inspectionFormActions;
    @service intl;

    @tracked queryParams = ['status', 'type', 'frequency', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;
    @tracked type;
    @tracked frequency;

    get actionButtons() {
        return [
            { icon: 'refresh', onClick: this.inspectionFormActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.inspectionFormActions.transition.create },
        ];
    }

    get bulkActions() {
        return [{ label: 'Delete selected...', class: 'text-red-500', fn: this.inspectionFormActions.bulkDelete }];
    }

    get columns() {
        return [
            {
                label: 'Name',
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.inspectionFormActions.transition.view,
                permission: 'fleet-ops view inspection-form',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: 'Type',
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/string',
            },
            {
                label: 'Status',
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/string',
            },
            {
                label: 'Frequency',
                valuePath: 'frequency',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'frequency',
                filterComponent: 'filter/string',
            },
            { label: 'Items', valuePath: 'item_count', resizable: true, sortable: false },
            { label: this.intl.t('column.created-at'), valuePath: 'createdAt', sortParam: 'created_at', resizable: true, sortable: true, filterable: true, filterComponent: 'filter/date' },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    { label: 'View form', fn: this.inspectionFormActions.transition.view, permission: 'fleet-ops view inspection-form' },
                    { label: 'Edit form', fn: this.inspectionFormActions.transition.edit, permission: 'fleet-ops update inspection-form' },
                    { separator: true },
                    { label: 'Publish', fn: this.inspectionFormActions.publish, permission: 'fleet-ops publish inspection-form' },
                    { label: 'Archive', fn: this.inspectionFormActions.archive, permission: 'fleet-ops archive inspection-form' },
                    { label: 'Generate inspection link', fn: this.inspectionFormActions.generateLink, permission: 'fleet-ops view inspection-form' },
                    { separator: true },
                    { label: 'Delete form', fn: this.inspectionFormActions.delete, class: 'text-red-500', permission: 'fleet-ops delete inspection-form' },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }
}
