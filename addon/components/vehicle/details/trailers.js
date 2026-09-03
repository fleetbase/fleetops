import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
export default class VehicleDetailsTrailersComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;
    @service trailerActions;
    @tracked trailers = [];
    constructor() {
        super(...arguments);
        this.load.perform();
    }
    @task *load() {
        try {
            this.trailers = yield this.store.query('trailer', { vehicle: this.args.resource.public_id ?? this.args.resource.id, sort: '-created_at' });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
    @action attach() {
        this.modalsManager.show('modals/select-trailer', {
            title: this.intl.t('vehicle.actions.attach-trailer'),
            selectedTrailer: null,
            confirm: async (modal) => {
                const trailer = modal.getOption('selectedTrailer');
                if (!trailer) return this.notifications.warning(this.intl.t('trailer.messages.select-trailer'));
                modal.startLoading();
                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach`, { vehicle: this.args.resource.id });
                    modal.done();
                    this.notifications.success(this.intl.t('trailer.messages.attached'));
                    this.load.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
    @action detach(trailer) {
        this.trailerActions.detachVehicle(trailer);
    }
}
