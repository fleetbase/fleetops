import Component from '@glimmer/component';
import { htmlSafe } from '@ember/template';

/**
 * A small phone-sized preview of the customer tracking page, themed from the settings being edited.
 */
export default class TrackingPageSettingsPreviewComponent extends Component {
    get style() {
        return htmlSafe(this.args.themeStyle ?? '');
    }

    get monogram() {
        const words = (this.args.displayName ?? '').split(/\s+/);
        let monogram = '';
        for (const word of words) {
            if (word && monogram.length < 2) {
                monogram += word[0].toUpperCase();
            }
        }

        return monogram;
    }
}
