import Component from '@glimmer/component';

export default class MaintenanceFormComponent extends Component {
    /**
     * Maintenance type options
     */
    maintenanceTypeOptions = ['preventive', 'corrective', 'predictive', 'routine', 'emergency', 'inspection', 'repair', 'replacement', 'calibration'];

    /**
     * Status options for maintenance
     */
    statusOptions = ['scheduled', 'in_progress', 'completed', 'cancelled', 'on_hold'];

    /**
     * Priority options
     */
    priorityOptions = ['low', 'medium', 'high', 'critical'];
}
