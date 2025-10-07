import Component from '@glimmer/component';

export default class DeviceFormComponent extends Component {
    /**
     * Device type options
     */
    deviceTypeOptions = ['dashcam', 'obd', 'blackbox', 'tablet', 'tracker', 'sensor'];

    /**
     * Status options for devices
     */
    statusOptions = ['active', 'inactive', 'maintenance', 'retired'];
}
