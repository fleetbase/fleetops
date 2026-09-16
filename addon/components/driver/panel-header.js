import Component from '@glimmer/component';
import relationValue from '../../utils/relation-value';

export default class DriverPanelHeaderComponent extends Component {
    get vehicleName() {
        const vehicle = relationValue(this.args.resource, 'vehicle');

        return vehicle?.displayName || vehicle?.display_name || vehicle?.name || this.args.resource?.vehicle_name || vehicle?.plate_number;
    }
}
