import Component from '@glimmer/component';

export default class SensorFormComponent extends Component {
    /**
     * Sensor type options
     */
    sensorTypeOptions = [
        'temperature',
        'door_status',
        'fuel_level',
        'tire_pressure',
        'humidity',
        'speed',
        'acceleration',
        'gyroscope',
        'gps',
        'battery',
        'voltage',
        'current',
        'pressure',
        'weight',
        'proximity',
    ];

    /**
     * Status options for sensors
     */
    statusOptions = ['active', 'inactive', 'calibrating', 'maintenance', 'error'];
}
