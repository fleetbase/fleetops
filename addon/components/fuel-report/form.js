import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class FuelReportFormComponent extends Component {
    @action onAutocomplete({ location }) {
        if (!location) return;
        this.args.resource.setProperties({ location });
    }

    @action setReporter(user) {
        this.args.resource.set('reporter', user);
        this.args.resource.set('reported_by_uuid', user.id);
    }
}
