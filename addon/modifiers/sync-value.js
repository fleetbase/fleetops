import { modifier } from 'ember-modifier';

/**
 * Keep an input's value in step with a bound one, without taking the caret.
 *
 * Binding `value={{@value}}` on an input whose every keystroke re-renders the
 * component sends the caret to the end mid-word, which is what made the form
 * builder's group inputs unusable. Leaving the value unbound instead means an
 * input never shows a value that arrives after it was rendered — the stored
 * answers an inspection loads a moment after the sheet appears.
 *
 * So write the value in, and only while the field is not being typed in.
 *
 *     <input {{sync-value @value}} {{on "input" this.setText}} />
 */
export default modifier(function syncValue(element, [value]) {
    if (element.ownerDocument?.activeElement === element) {
        return;
    }

    const next = value === null || value === undefined ? '' : String(value);

    if (element.value !== next) {
        element.value = next;
    }
});
