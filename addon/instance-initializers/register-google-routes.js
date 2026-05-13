export function initialize(owner) {
    const routeOptimization = owner.lookup('service:route-optimization');
    const routeEngine = owner.lookup('service:route-engine');
    const googleRoutes = owner.lookup('service:google-routes');

    if (routeOptimization && googleRoutes) {
        routeOptimization.register('google', googleRoutes);
    }

    if (routeEngine && googleRoutes) {
        routeEngine.register('google', googleRoutes, { display: true });
    }
}

export default {
    initialize,
};
