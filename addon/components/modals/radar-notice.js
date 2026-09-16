import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class ModalsRadarNoticeComponent extends Component {
    @action setSeverity(event) {
        this.args.options.notice.severity = event.target.value;
    }
}
