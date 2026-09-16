import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

/**
 * Resolves the Leaflet tile URL from Fleet-Ops map settings.
 *
 * Usage:
 *   <layers.tile @url={{(leaflet-tile-url)}} />
 *   <layers.tile @url={{leaflet-tile-url theme="dark"}} />
 *
 * With no arguments it must be invoked in parentheses. A bare
 * `@url={{leaflet-tile-url}}` passes the helper by name, which Glimmer refuses
 * with "A resolved helper cannot be passed as a named argument" and the whole
 * template fails to render.
 *
 * Recomputes automatically when map settings load or change.
 */
export default class LeafletTileUrlHelper extends Helper {
    @service mapSettings;

    compute(_params, { theme = 'light' } = {}) {
        return this.mapSettings.getLeafletTileUrl(theme);
    }
}
