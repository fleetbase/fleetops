import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VirtualRoute extends Route {
    @service universe;

    model({ slug, view }) {
        return this.universe.lookupMenuItemFromRegistry('engine:fleet-ops', slug, view);
    }
}
