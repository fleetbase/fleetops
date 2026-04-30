export function buildRouteTypeSummary({ hasIntermediateWaypoints = false, intermediateStopCount = 0, hasPickup = false, hasDropoff = false } = {}) {
    const count = Math.max(Number(intermediateStopCount) || 0, 0);
    const hasIntermediateStops = count > 0 || Boolean(hasIntermediateWaypoints);
    const hasEndpoints = Boolean(hasPickup || hasDropoff);
    const kind = hasIntermediateStops ? (hasEndpoints ? 'pickup_dropoff_stops' : 'multi_stop') : 'pickup_dropoff';

    return {
        kind,
        intermediateStopCount: count,
        icon: hasIntermediateStops ? 'route' : 'exchange-alt',
        badgeClass: hasIntermediateStops ? 'import-preview-badge import-preview-badge--blue' : 'import-preview-badge import-preview-badge--gray',
        translationKey:
            kind === 'pickup_dropoff_stops'
                ? 'orchestrator.col-preview-pickup-dropoff-stops'
                : kind === 'multi_stop'
                  ? 'orchestrator.col-preview-multi-stop'
                  : 'orchestrator.col-preview-pickup-dropoff',
        translationOptions: hasIntermediateStops ? { count } : {},
    };
}
