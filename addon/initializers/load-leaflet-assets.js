import loadLeafletPlugins from '@fleetbase/ember-ui/utils/load-leaflet-plugins';

export function initialize() {
    loadLeafletPlugins({
        scripts: ['leaflet.contextmenu.js', 'leaflet.draw-src.js'],
        stylesheets: ['leaflet.contextmenu.css', 'leaflet.draw.css'],
        globalIndicatorKey: 'fleetopsLeafletPluginsLoaded',
    });
}

export default {
    initialize,
};
