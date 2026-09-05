import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

/**
 * Resolves the Leaflet tile URL from Fleet-Ops map settings.
 *
 * Usage:
 *   <layers.tile @url={{(leaflet-tile-url)}} />
 *   <layers.tile @url={{leaflet-tile-url theme="dark"}} />
 *
 * Recomputes automatically when map settings load or change.
 */
export default class LeafletTileUrlHelper extends Helper {
    @service mapSettings;

    compute(_params, { theme = 'light' }) {
        return this.mapSettings.getLeafletTileUrl(theme);
    }
}
