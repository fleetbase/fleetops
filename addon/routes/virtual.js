import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VirtualRoute extends Route {
    @service universe;
    @service('universe/menu-service') menuService;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = {
        view: {
            refreshModel: true,
        },
    };

    model({ section = null, slug }, transition) {
        const view = this.universe.getViewFromTransition(transition);
        return this.menuService.lookupMenuItem('engine:fleet-ops', slug, view, section);
    }

    afterModel(menuItem) {
        if (menuItem?.permission && this.abilities.cannot(menuItem.permission)) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }
}
