export default function calculateMapDrawerDropdownPosition(trigger, content) {
    const dropdownRoot = trigger?.closest?.('.ember-basic-dropdown');
    const drawerPanel = trigger?.closest?.('.next-drawer-panel');

    if (!trigger || !dropdownRoot) {
        return { style: {} };
    }

    const triggerRect = trigger.getBoundingClientRect();
    const rootRect = dropdownRoot.getBoundingClientRect();
    const drawerRect = drawerPanel?.getBoundingClientRect?.();
    const contentRect = content?.getBoundingClientRect?.();
    const contentWidth = contentRect?.width || 224;
    const contentHeight = contentRect?.height || 240;
    const gap = 6;

    const viewportRect = {
        top: 0,
        right: window.innerWidth,
        bottom: window.innerHeight,
        left: 0,
    };
    const boundaryRect = drawerRect ?? viewportRect;
    const minLeft = boundaryRect.left - rootRect.left + gap;
    const maxLeft = boundaryRect.right - rootRect.left - contentWidth - gap;
    const minTop = boundaryRect.top - rootRect.top + gap;
    const maxTop = boundaryRect.bottom - rootRect.top - contentHeight - gap;

    const preferredLeft = triggerRect.left - rootRect.left - contentWidth - gap;
    const preferredTop = triggerRect.top - rootRect.top;
    const left = clamp(preferredLeft, minLeft, Math.max(minLeft, maxLeft));
    const top = 0; //clamp(preferredTop, minTop, Math.max(minTop, maxTop));

    return {
        style: {
            position: 'absolute',
            left: `${left}px`,
            top: `${top}px`,
            marginTop: '0px',
            zIndex: '10000',
        },
    };
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}
