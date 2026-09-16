import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityFuelProvidersIndexDetailsSettingsRoute extends Route {
    @service hostRouter;

    redirect() {
        return this.hostRouter.replaceWith('console.fleet-ops.connectivity.fuel-providers.edit', this.modelFor('connectivity.fuel-providers.details'));
    }
}
