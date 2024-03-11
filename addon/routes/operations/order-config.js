import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrderConfigRoute extends Route {
    @service loader;

    queryParams = {
        config: {
            refreshModel: true,
        },
        tab: {
            refreshModel: false,
        },
        context: {
            refreshModel: false,
        },
        contextModel: {
            refreshModel: false,
        },
    };

    @action loading(transition) {
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', {
            loadingMessage: this.intl.t('fleet-ops.operations.order-config.route-loading-message'),
        });
    }
}
