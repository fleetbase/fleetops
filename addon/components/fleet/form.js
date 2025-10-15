import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { underscore } from '@ember/string';

export default class FleetFormComponent extends Component {
    @tracked statusOptions = ['active', 'disabled', 'decommissioned'];

    get writePermission() {
        return this.args.resource.isNew ? 'fleet-ops create fleet' : 'fleet-ops update fleet';
    }

    @action updateRelationship(relation, value) {
        this.args.resource.set(relation, value);

        if (!value) {
            this.args.resource.set(underscore(relation) + '_uuid', null);
        }
    }
}
