import { modifier } from 'ember-modifier';

/**
 * The Radar keyboard layer: j/k move, x selects, e acknowledges, s snoozes,
 * a assigns, Enter opens the record, Escape closes what is open.
 *
 * Keys are ignored while the user is typing in a field or a modal or
 * overlay is open, so the list never steals a keystroke from a form.
 * `handlers` is a hash of key => function; a missing key is ignored.
 */
const KEYS = {
    j: 'next',
    k: 'previous',
    ArrowDown: 'next',
    ArrowUp: 'previous',
    x: 'select',
    e: 'acknowledge',
    s: 'snooze',
    a: 'assign',
    Enter: 'open',
    Escape: 'close',
    '/': 'search',
};

export function isTypingTarget(target) {
    if (!target || typeof target.closest !== 'function') {
        return false;
    }

    if (target.isContentEditable) {
        return true;
    }

    const tag = (target.tagName || '').toLowerCase();
    if (['input', 'textarea', 'select'].includes(tag)) {
        return true;
    }

    return Boolean(target.closest('.ember-basic-dropdown-content, .modal, .next-content-overlay-panel, [role="dialog"], .ember-power-select-dropdown'));
}

export default modifier(function radarKeyboard(element, [handlers = {}], { enabled = true } = {}) {
    const onKeydown = (event) => {
        if (!enabled || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        // A letter arrives as typed; the map is lowercase.
        const key = typeof event.key === 'string' && event.key.length === 1 ? event.key.toLowerCase() : event.key;
        const name = KEYS[key];
        if (!name) {
            return;
        }

        // "/" focuses the search even from a field; every other key only
        // fires when the user is not typing.
        if (name !== 'search' && isTypingTarget(event.target)) {
            return;
        }
        if (name === 'search' && isTypingTarget(event.target)) {
            return;
        }

        const handler = handlers[name];
        if (typeof handler !== 'function') {
            return;
        }

        event.preventDefault();
        handler(event);
    };

    document.addEventListener('keydown', onKeydown);

    return () => {
        document.removeEventListener('keydown', onKeydown);
    };
});
