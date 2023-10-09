import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class HomeRoute extends Route {
    /**
     * Inject the `loader` service.
     *
     * @var {Service}
     */
    @service loader;

    @action loading(transition) {
        const loader = this.loader.show(`Loading FleetOps...`);

        transition.finally(() => {
            this.loader.removeLoader(loader);
        });
    }
}
