import { modifier } from 'ember-modifier';

/** No value has been seen for this element yet — distinct from `undefined`. */
const NEVER = Symbol('never');
const seen = new WeakMap();

/**
 * Run something when a value changes, but not when it first appears.
 *
 * `{{did-update}}` does this and is deprecated for it. The distinction it does
 * not make, and this does, is between the first render and a later change: a
 * component that already loads its own data on construction must not load it
 * again the moment it is inserted.
 *
 *     <div {{when-changed @reloadOn this.reload}}>
 */
export default modifier(function whenChanged(element, [value, callback]) {
    const previous = seen.has(element) ? seen.get(element) : NEVER;

    seen.set(element, value);

    if (previous !== NEVER && previous !== value && typeof callback === 'function') {
        callback(value);
    }
});
