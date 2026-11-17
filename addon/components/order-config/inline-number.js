import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';

/**
 * A number inside a sentence. Left empty it follows the default, which the placeholder shows.
 *
 * @argument {Number|String} value
 * @argument {String} defaultLabel the default, shown as the placeholder ("20 · default")
 * @argument {Function} onChange called with the number, or undefined when emptied
 * @argument {Boolean} invalid
 * @argument {Number} width in pixels
 */
export default class OrderConfigInlineNumberComponent extends Component {
    @service intl;

    get isEmpty() {
        return this.args.value === undefined || this.args.value === null || this.args.value === '';
    }

    get placeholder() {
        return this.args.defaultLabel ? this.intl.t('order-config-manager.common.default-suffix', { value: this.args.defaultLabel }) : '';
    }

    get style() {
        return htmlSafe(`width: ${this.args.width ?? 132}px`);
    }

    @action change(event) {
        const raw = String(event.target.value ?? '').replace(/[^0-9.-]/g, '');
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(raw === '' ? undefined : Number(raw), raw);
        }
    }
}
