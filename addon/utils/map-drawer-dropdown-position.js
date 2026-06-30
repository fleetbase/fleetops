export default function calculateMapDrawerDropdownPosition(trigger, content) {
    const drawerPanel = trigger?.closest?.('.next-drawer-panel');

    if (!trigger) {
        return { style: {} };
    }

    const triggerRect = trigger.getBoundingClientRect();
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
    const minLeft = boundaryRect.left + gap;
    const maxLeft = boundaryRect.right - contentWidth - gap;
    const minTop = boundaryRect.top + gap;
    const maxTop = boundaryRect.bottom - contentHeight - gap;

    const preferredLeft = triggerRect.left - contentWidth - gap;
    const preferredTop = triggerRect.top;
    const left = clamp(preferredLeft, minLeft, Math.max(minLeft, maxLeft));
    const top = clamp(preferredTop, minTop, Math.max(minTop, maxTop));

    return {
        style: {
            position: 'fixed',
            left,
            top,
            marginTop: '0px',
            zIndex: 10000,
        },
    };
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}
