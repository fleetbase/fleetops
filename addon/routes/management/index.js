import Route from '@ember/routing/route';

export default class ManagementIndexRoute extends Route {
    queryParams = {
        view: { refreshModel: false },
        status: { refreshModel: false },
        filters: { refreshModel: false },
        fleet: { refreshModel: false },
        q: { refreshModel: false },
        saved: { refreshModel: false },
        assigned: { refreshModel: false },
        window: { refreshModel: false },
    };

    setupController(controller, model, transition) {
        super.setupController(controller, model, transition);
        controller.reload.perform();
    }
}
