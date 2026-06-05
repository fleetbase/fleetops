import { debug } from '@ember/debug';
import ensureLeafletPluginsReady from '../utils/leaflet-plugin-loader';

export function initialize() {
    let waitForLeaflet = setInterval(() => {
        let leafletLoaded = window.L !== undefined;
        if (leafletLoaded) {
            clearInterval(waitForLeaflet);
            ensureLeafletPluginsReady().catch((error) => {
                debug(error.message);
            });
        }
    }, 100);
}

export default {
    initialize,
};
