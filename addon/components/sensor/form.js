import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class SensorFormComponent extends Component {
    @action selectTelematic(telematic) {
        this.args.resource.setProperties({
            telematic,
            telematic_uuid: telematic.id,
            provider: telematic.provider,
        });
    }
}
