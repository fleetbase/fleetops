import loadLeafletPlugins from '@fleetbase/ember-ui/utils/load-leaflet-plugins';
import ensureLeafletDrawEditNamespace from '../utils/leaflet-draw-namespace-guard';

export function initialize() {
    let waitForLeaflet = setInterval(() => {
        let leafletLoaded = window.L !== undefined;
        if (leafletLoaded) {
            ensureLeafletDrawEditNamespace();
            loadLeafletPlugins(
                {
                    scripts: ['leaflet.contextmenu.js', 'leaflet.draw-src.js'],
                    stylesheets: ['leaflet.contextmenu.css', 'leaflet.draw.css'],
                    globalIndicatorKey: 'fleetopsLeafletPluginsLoaded',
                },
                () => {
                    ensureLeafletDrawEditNamespace();
                }
            );
            clearInterval(waitForLeaflet);
        }
    }, 100);
}

export default {
    initialize,
};
