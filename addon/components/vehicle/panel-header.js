import Component from '@glimmer/component';
import relationValue from '../../utils/relation-value';

export default class VehiclePanelHeaderComponent extends Component {
    get driverName() {
        const driver = relationValue(this.args.resource, 'driver');

        return driver?.name || this.args.resource?.driver_name;
    }
}
