import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * The builder's field editor, in a modal.
 *
 * The field is a plain object in the builder's draft; `options.state` is the
 * handle both sides hold, so the builder's `confirm` callback reads back
 * whatever the editor last produced without either side mutating the draft
 * until the author accepts.
 */
export default class ModalsInspectionFieldComponent extends Component {
    @tracked field = this.args.options.state.field;

    @action onChange(field) {
        this.field = field;
        this.args.options.state.field = field;
    }
}
