import Component from '@glimmer/component';
import { flattenFields } from '../utils/inspection-form-structure';
import { summarize } from '../utils/inspection-answers';

/**
 * An inspection form, being filled in.
 *
 * One component renders the sheet wherever it is answered — the console's
 * submission screen and the public link a driver opens on a phone — so the
 * two cannot drift apart. `@values` in, `@onChange` out; the screen that owns
 * the answers holds them, and nothing is written here during render.
 *
 * Groups are plain sections, always open. They were collapsible panels, which
 * hid the point of the exercise: an inspector has to read every line, and a
 * panel that can be shut invites them not to.
 */
export default class InspectionSheetComponent extends Component {
    get groups() {
        return Array.isArray(this.args.groups) ? this.args.groups : [];
    }

    get fields() {
        return flattenFields(this.groups);
    }

    get summary() {
        return summarize(this.fields, this.args.values ?? {});
    }
}
