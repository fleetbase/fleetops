import Service, { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class ResourceMetadataService extends Service {
    @service modalsManager;
    @service notifications;
    @service intl;

    @action view(resource, options = {}) {
        this.modalsManager.show('modals/view-metadata', {
            title: `${this.intl.t('resource.' + getModelName(resource))} ${this.intl.t('common.metadata')}`,
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            metadata: resource.meta,
            ...options,
        });
    }

    @action edit(resource, options = {}) {
        this.modalsManager.show('modals/edit-metadata', {
            title: `${this.intl.t('common.edit')} ${this.intl.t('resource.' + getModelName(resource))} ${this.intl.t('common.metadata')}`,
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            actionsWrapperClass: 'px-3',
            metadata: resource.meta,
            onChange: (meta) => {
                resource.set('meta', meta);
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await resource.save();
                    this.notifications.success(this.intl.t('common.field-saved', { field: this.intl.t('common.metadata') }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }
}
