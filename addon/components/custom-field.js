import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { equal } from '@ember/object/computed';

export default class CustomFieldComponent extends Component {
    @tracked selectedPort;
    @tracked selectedVessel;

    @equal('args.metaField.type', 'text') isTextInput;
    @equal('args.metaField.type', 'select') isSelectInput;
    @equal('args.metaField.type', 'vessel') isVesselInput;
    @equal('args.metaField.type', 'port') isPortInput;
    @equal('args.metaField.type', 'datetime') isDateTimeInput;
    @equal('args.metaField.type', 'boolean') isBooleanInput;

    @action onToggle() {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(...arguments);
        }
    }

    @action setDateValue(dateInstance) {
        const dateTime = dateInstance.toDate();

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(dateTime);
        }
    }

    @action selectPort(port) {
        this.selectedPort = port;

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(port.portcode);
        }
    }

    @action selectVessel(vessel) {
        this.selectedVessel = vessel;

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(vessel.name);
        }
    }
}
