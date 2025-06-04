import Route from '@ember/routing/route';

export default class OperationsRoutesIndexNewRoute extends Route {
    queryParams = {
        selectedOrders: {
            refreshModel: false,
        },
    };
}
