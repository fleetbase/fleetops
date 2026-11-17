import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * A choice inside a sentence: the chosen option's words, styled as following the default
 * (dashed, "· default") or set for this config (solid). Opens a list of the options.
 *
 * @argument {Array<{value, label, description?, isDefault?}>} options
 * @argument {*} value the chosen option's value
 * @argument {Boolean} isDefault the value is the default, so it reads as one
 * @argument {Function} onSelect called with the chosen option's value
 * @argument {Boolean} disabled render as read-only text
 * @argument {String} label words to show instead of the chosen option's label
 */
export default class OrderConfigInlineSelectComponent extends Component {
    @service intl;

    get displayLabel() {
        const label = this.args.label ?? this.args.options?.find((option) => option.value === this.args.value)?.label ?? '';
        return this.args.isDefault && !this.args.hideDefaultSuffix ? this.intl.t('order-config-manager.common.default-suffix', { value: label }) : label;
    }

    @action select(dropdown, option) {
        dropdown.actions.close();
        if (typeof this.args.onSelect === 'function') {
            this.args.onSelect(option.value, option);
        }
    }

    @action selectOnKey(dropdown, option, event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.select(dropdown, option);
        }
    }
}
