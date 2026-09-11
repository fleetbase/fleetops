import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * Below this sheet width a floating panel would crowd the page and fight the
 * on-screen keyboard, so the same content slides up as a bottom sheet.
 */
const SHEET_BREAKPOINT = 520;

/**
 * The panel a failed check opens, anchored to its field.
 *
 * It renders outside the field so the layout never changes when a check
 * fails. Floating, it lives in the sheet's own flyout layer, so it scrolls
 * with its field and is never clipped by the sheet's rounded card. As a bottom
 * sheet on a phone it lives in the application's root wormhole instead,
 * because a fixed panel inside a container-query element would be pinned to
 * that element rather than to the screen.
 *
 * It is non-modal and traps nothing. It closes on Done, on its close button,
 * on Escape, and on a press anywhere outside it and its field — and closing is
 * always safe, because every answer inside it is saved as it is typed.
 */
export default class InspectionFlyoutComponent extends Component {
    /** Decided once, when it opens: a panel should not change shape under the user. */
    presentation = this.measurePresentation();

    get anchor() {
        return document.getElementById(`inspection-field-${this.args.fieldId}`);
    }

    get isSheet() {
        return this.presentation === 'sheet';
    }

    get mount() {
        if (this.isSheet) {
            return document.getElementById('application-root-wormhole') ?? document.body;
        }

        return this.anchor?.closest('.inspection-sheet')?.querySelector(':scope > .inspection-sheet__flyouts') ?? null;
    }

    /**
     * Where focus lands on opening. Floating, it is the comment — usually the
     * first thing a failure still owes. On a phone it is the panel itself:
     * focusing the comment would throw the keyboard up over the sheet before
     * the inspector has read it.
     */
    get focusTarget() {
        return this.isSheet ? true : '[data-flyout-focus]';
    }

    measurePresentation() {
        const anchor = document.getElementById(`inspection-field-${this.args.fieldId}`);
        const width = anchor?.closest('.inspection-sheet')?.clientWidth ?? window.innerWidth;

        return width < SHEET_BREAKPOINT ? 'sheet' : 'floating';
    }

    @action dismiss(reason) {
        if (typeof this.args.onClose === 'function') {
            this.args.onClose(reason);
        }
    }
}
