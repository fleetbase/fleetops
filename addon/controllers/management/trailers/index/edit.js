import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
export default class ManagementTrailersIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service events;
    @tracked overlay;
    get actionButtons() {
        return [{ icon: 'eye', fn: this.view }];
    }
    @task *save(trailer) {
        try {
            yield trailer.save();
            this.events.trackResourceUpdated(trailer);
            this.overlay?.close();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', trailer);
            this.notifications.success(this.intl.t('trailer.messages.updated'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
    @action cancel() {
        if (!this.model.hasDirtyAttributes) return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
        return this.confirmUnsaved();
    }
    @action view() {
        if (!this.model.hasDirtyAttributes) return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', this.model);
        return this.confirmUnsaved();
    }
    confirmUnsaved() {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.trailer') }),
            confirm: async () => {
                this.model.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', this.model);
            },
        });
    }
}
