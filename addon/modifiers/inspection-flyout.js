import { modifier } from 'ember-modifier';

/** Room left between the flyout and its field, and between it and the sheet's edge. */
const GAP = 8;
const EDGE = 8;

/** The nearest ancestor that actually scrolls, or null for the window. */
function scrollParentOf(element) {
    let node = element?.parentElement;

    while (node && node !== document.body) {
        const { overflowY } = getComputedStyle(node);

        if (/(auto|scroll|overlay)/.test(overflowY) && node.scrollHeight > node.clientHeight) {
            return node;
        }

        node = node.parentElement;
    }

    return null;
}

/**
 * Place a floating flyout against its field.
 *
 * Both live inside the same sheet, so they scroll together and nothing has to
 * follow the scroll. The flyout goes below the field unless only the visible
 * space above it can hold it, is kept inside the sheet horizontally, and
 * points its caret at the field's Fail button.
 */
export function placeFlyout(element, anchor, container) {
    const containerRect = container.getBoundingClientRect();
    const anchorRect = anchor.getBoundingClientRect();
    const width = element.offsetWidth;
    const height = element.offsetHeight;

    const scroller = scrollParentOf(anchor);
    const viewTop = scroller ? scroller.getBoundingClientRect().top : 0;
    const viewBottom = scroller ? scroller.getBoundingClientRect().bottom : window.innerHeight;

    const fitsBelow = viewBottom - anchorRect.bottom >= height + GAP;
    const fitsAbove = anchorRect.top - viewTop >= height + GAP;

    // Below, unless only the space above can hold it. Whatever hangs below a
    // field can always be scrolled to — an absolutely placed panel extends
    // the scroll area — but a panel pushed above the start of the sheet
    // cannot be reached at all, so "more room above" is not reason enough.
    const below = fitsBelow || !fitsAbove;

    const top = below ? anchorRect.bottom - containerRect.top + GAP : anchorRect.top - containerRect.top - height - GAP;

    const maxLeft = Math.max(EDGE, container.clientWidth - width - EDGE);
    const left = Math.max(EDGE, Math.min(anchorRect.left - containerRect.left, maxLeft));

    element.style.top = `${Math.round(top)}px`;
    element.style.left = `${Math.round(left)}px`;
    element.dataset.placement = below ? 'bottom' : 'top';

    const pointAt = anchor.querySelector('[data-answer="fail"]') ?? anchor;
    const pointRect = pointAt.getBoundingClientRect();
    const caret = pointRect.left + pointRect.width / 2 - containerRect.left - left;

    element.style.setProperty('--flyout-caret-x', `${Math.round(Math.max(16, Math.min(caret, width - 16)))}px`);

    // Where it now sits, worked out from layout rather than read off its
    // rendered box: the open animation is still translating it, and a reveal
    // measured from the moving box stops exactly that many pixels short.
    const flyoutTop = containerRect.top + top;

    return { placement: below ? 'bottom' : 'top', flyoutTop, flyoutBottom: flyoutTop + height, viewTop, viewBottom, scroller };
}

/**
 * Scroll just enough to bring a newly opened flyout fully into view, keeping
 * a small margin from the edge. If it is taller than the view, its top — the
 * title, and the first thing to answer — is what stays in view. Returns how
 * far it scrolled.
 */
export function revealFlyout({ flyoutTop, flyoutBottom, viewTop, viewBottom, scroller }) {
    let delta = 0;

    if (flyoutBottom + EDGE > viewBottom) {
        delta = flyoutBottom + EDGE - viewBottom;
    }

    if (flyoutTop - delta - EDGE < viewTop) {
        delta = flyoutTop - EDGE - viewTop;
    }

    if (Math.abs(delta) < 1) {
        return 0;
    }

    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    (scroller ?? window).scrollBy({ top: delta, behavior: reduce ? 'auto' : 'smooth' });

    return delta;
}

/**
 * Run something once the flyout's opening animation has finished.
 *
 * The animation translates the panel, and a translated box changes the
 * scrollable area: a reveal worked out mid-animation is clamped against a
 * scroll area that is briefly too short, and lands short by exactly the
 * animated offset. Without an animation — reduced motion — it runs at once.
 * Returns a timer to clear on teardown.
 */
function afterOpening(element, callback) {
    const style = getComputedStyle(element);
    const seconds = parseFloat(style.animationDuration) || 0;

    if (style.animationName === 'none' || seconds === 0) {
        callback();
        return null;
    }

    let done = false;
    const finish = () => {
        if (done) {
            return;
        }

        done = true;
        element.removeEventListener('animationend', finish);
        callback();
    };

    element.addEventListener('animationend', finish);

    // In case the event never comes: a hidden tab, an interrupted animation.
    return setTimeout(finish, seconds * 1000 + 50);
}

/**
 * Keep a defect flyout attached to its field, and close it the natural way.
 *
 *     <div {{inspection-flyout this.anchor presentation="floating" onDismiss=this.dismiss}}>
 *
 * It closes on a press outside itself and outside its own field, and on
 * Escape. It deliberately does not close on blur or on scroll: focus leaves
 * the page for the native photo picker, and scrolling moves the flyout with
 * its field anyway. Nothing is lost by closing, because every answer is saved
 * as it is typed.
 *
 * A bottom sheet is placed by its stylesheet, so it only takes the
 * dismissal half of this.
 */
export default modifier(function inspectionFlyout(element, [anchor], { presentation = 'floating', onDismiss, focus } = {}) {
    if (!(anchor instanceof Element)) {
        return;
    }

    const container = anchor.closest('.inspection-sheet') ?? document.body;
    const floating = presentation === 'floating';
    let frame = null;
    let revealed = false;
    let revealTimer = null;

    const place = () => {
        if (!floating || !element.isConnected || !anchor.isConnected) {
            return;
        }

        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            placeFlyout(element, anchor, container);

            // A panel that opens half off the page is not natural. Bring it
            // into view once, by the least scroll that will do it, as soon as
            // it has finished opening; after that the inspector is in charge
            // of the scrolling.
            if (!revealed) {
                revealed = true;
                revealTimer = afterOpening(element, () => {
                    if (element.isConnected && anchor.isConnected) {
                        revealFlyout(placeFlyout(element, anchor, container));
                    }
                });
            }
        });
    };

    const dismiss = (reason) => {
        if (typeof onDismiss === 'function') {
            onDismiss(reason);
        }
    };

    // A press anywhere but the flyout or its own field closes it. Pointerdown,
    // not click, so pressing another field's Fail closes this one first.
    const onPointerDown = (event) => {
        const target = event.target;

        if (element.contains(target) || anchor.contains(target)) {
            return;
        }

        dismiss('outside');
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            dismiss('escape');
        }
    };

    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('keydown', onKeyDown, true);
    window.addEventListener('resize', place);

    // Re-place when anything around it changes height: a field above it
    // gaining a line, or the flyout itself growing as a photo is added.
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(place) : null;
    observer?.observe(container);
    observer?.observe(element);

    place();

    if (focus) {
        requestAnimationFrame(() => {
            const target = focus === true ? element : element.querySelector(focus);
            target?.focus({ preventScroll: true });
        });
    }

    return () => {
        cancelAnimationFrame(frame);
        clearTimeout(revealTimer);
        document.removeEventListener('pointerdown', onPointerDown, true);
        document.removeEventListener('keydown', onKeyDown, true);
        window.removeEventListener('resize', place);
        observer?.disconnect();
    };
});
