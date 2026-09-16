import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';

/**
 * Options every record context panel shares with its details route: the
 * details route's 600px width, a header that sits flush on the tab list (the
 * tab list draws the only rule), and tabs lined up with the header padding.
 */
export const PANEL_DEFAULTS = Object.freeze({
    size: 'md',
    headerClass: 'no-bottom-border',
    tablistClass: 'pl-2',
});

/**
 * The tabs other extensions registered for a details view that can render
 * inside a context panel. Route-only tabs have nothing to render there, so
 * they are left out, and every tab gets the key the panel switches tabs by.
 */
export function registeredPanelTabs(menuService, registry) {
    const tabs = menuService?.getMenuItems?.(registry);

    return (isArray(tabs) ? tabs : []).filter((tab) => tab && (tab.component || tab.render)).map((tab) => ({ ...tab, key: tab.key ?? tab.id ?? dasherize(tab.label ?? tab.title ?? 'tab') }));
}

/**
 * Close every open context panel, then run the next step — how a panel's
 * edit button swaps the details panel for the form panel.
 */
export async function closePanelsThen(resourceContextPanel, next) {
    await resourceContextPanel?.closeAll?.();

    return next();
}
