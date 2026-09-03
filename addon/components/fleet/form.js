import Component from '@glimmer/component';
import { action } from '@ember/object';
import { underscore } from '@ember/string';

export default class FleetFormComponent extends Component {
    @action updateRelationship(relation, value) {
        this.args.resource.set(relation, value);

        if (!value) {
            this.args.resource.set(underscore(relation) + '_uuid', null);
        }
    }
}
