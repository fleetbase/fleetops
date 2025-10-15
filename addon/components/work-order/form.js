import Component from '@glimmer/component';

export default class WorkOrderFormComponent extends Component {
    /**
     * Status options for work orders
     */
    statusOptions = ['open', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    /**
     * Priority options
     */
    priorityOptions = ['low', 'medium', 'high', 'critical'];
}
