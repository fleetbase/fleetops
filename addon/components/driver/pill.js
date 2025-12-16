import Component from '@glimmer/component';

export default class DriverPillComponent extends Component {
    get resource() {
        return this.args.driver ?? this.args.resource;
    }
}
