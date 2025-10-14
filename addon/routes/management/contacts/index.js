import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ManagementContactsIndexRoute extends Route {
    @service store;
    @service loader;
    @service intl;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        name: { refreshModel: true },
        title: { refreshModel: true },
        phone: { refreshModel: true },
        email: { refreshModel: true },
        type: { refreshModel: true },
        internal_id: { refreshModel: true },
        createdAt: { refreshModel: true },
        updatedAt: { refreshModel: true },
    };

    @action loading(transition) {
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', {
            loadingMessage: this.intl.t('common.loading-resource', { resource: 'Contacts' }),
        });
    }

    model(params) {
        return this.store.query('contact', { ...params, type: 'contact' });
    }
}
