import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class AnalyticsReportsIndexController extends Controller {
    @service reportActions;
    @service notifications;
    @service intl;
    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'name', 'created_at', ,];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked name;
    @tracked created_at;
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.reportActions.refresh,
            helpText: this.intl.t('common.refresh'),
        },
        {
            text: this.intl.t('common.new'),
            type: 'primary',
            icon: 'plus',
            onClick: this.reportActions.transition.create,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.reportActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: 'Title',
            valuePath: 'title',
            cellComponent: 'table/cell/anchor',
            action: this.reportActions.transition.view,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'ID',
            valuePath: 'public_id',
            width: '130px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: false,
            filterComponent: 'filter/string',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.Driver') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: 'View report...',
                    fn: this.reportActions.transition.view,
                },
                {
                    label: 'Edit report...',
                    fn: this.reportActions.transition.edit,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete report...',
                    fn: this.reportActions.delete,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];
}
