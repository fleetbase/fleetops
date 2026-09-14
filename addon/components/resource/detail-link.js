import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * A linked resource, rendered as its identity rather than its name alone.
 *
 * Details panels name the records they relate to — a vehicle, a driver, the
 * form an inspection was filled in from — and until now most of them were dead
 * text. This renders the photo, the name and one identifying line beside it,
 * and opens the record when clicked.
 *
 * It takes what to show rather than working it out: each resource decides what
 * its own identity is, and the wrappers (`Vehicle::DetailLink`,
 * `Driver::DetailLink`) supply it. A record that is not loaded renders as the
 * fallback text, unlinked, because a link to nothing is worse than a name.
 */
export default class ResourceDetailLinkComponent extends Component {
    @action open(event) {
        event?.preventDefault?.();

        if (typeof this.args.onClick === 'function') {
            return this.args.onClick(this.args.record, event);
        }
    }
}
