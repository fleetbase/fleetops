import Component from '@glimmer/component';

/**
 * One option in a ModelSelect or PowerSelect: a photo, a name, and a line of
 * detail beneath it — the select's counterpart to a card.
 *
 * `@details` is a list; empty entries are dropped, so a record missing its
 * email or phone reads cleanly rather than with a stray separator. `@compact`
 * puts everything on one line at a smaller photo, for a select's closed
 * trigger, where two stacked lines do not fit.
 *
 * The record-specific options (`SelectOption::User`, `::Driver`, `::Vehicle`)
 * are built on this. It lives in FleetOps for now and is meant to move to
 * ember-ui as a shared primitive.
 */
export default class SelectOptionComponent extends Component {
    get details() {
        return (this.args.details ?? []).filter((detail) => detail !== null && detail !== undefined && String(detail).trim() !== '').join(' · ');
    }

    get photo() {
        return this.args.photo || this.args.fallbackPhoto || null;
    }
}
