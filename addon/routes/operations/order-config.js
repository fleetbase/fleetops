import Route from '@ember/routing/route';

export default class OperationsOrderConfigRoute extends Route {
    queryParams = {
        tab: {
            refreshModel: false,
        },
    };
}
