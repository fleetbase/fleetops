import Component from '@glimmer/component';

export default class VehiclePillComponent extends Component {
    get resource() {
        return this.args.vehicle ?? this.args.resource;
    }
}
