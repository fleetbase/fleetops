import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * A list of values inside a sentence, such as payment methods: removable chips and an Add menu.
 *
 * @argument {Array<{value, label}>} items the chosen values
 * @argument {Array<{value, label}>} options every value that can be chosen
 * @argument {String} emptyLabel shown when nothing is chosen
 * @argument {Function} onChange called with the new array of values
 */
export default class OrderConfigInlineListComponent extends Component {
    get addable() {
        const chosen = (this.args.items ?? []).map((item) => item.value);
        return (this.args.options ?? []).filter((option) => !chosen.includes(option.value));
    }

    get canAdd() {
        return !this.args.disabled && this.addable.length > 0;
    }

    get values() {
        return (this.args.items ?? []).map((item) => item.value);
    }

    @action add(value) {
        this.args.onChange?.([...this.values, value]);
    }

    @action remove(item) {
        this.args.onChange?.(this.values.filter((value) => value !== item.value));
    }
}
