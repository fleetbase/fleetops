import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Trailers currently coupled to a vehicle, with attach/detach controls.
 */
export default class VehicleDetailsTrailersComponent extends Component {
    @service store;
    @service notifications;
    @service trailerActions;
    @service vehicleActions;
    @tracked trailers = [];

    get vehicle() {
        return this.args.resource ?? this.args.vehicle;
    }

    constructor() {
        super(...arguments);
        this.loadTrailers.perform();
    }

    @task *loadTrailers() {
        try {
            const trailers = yield this.store.query('trailer', {
                vehicle: this.vehicle.id,
                attachment_state: 'attached',
                sort: '-created_at',
            });

            this.trailers = Array.from(trailers ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action attach() {
        return this.vehicleActions.attachTrailer(this.vehicle, { callback: () => this.loadTrailers.perform() });
    }

    @action detach(trailer) {
        return this.trailerActions.detachVehicle(trailer, { callback: () => this.loadTrailers.perform() });
    }

    @action view(trailer) {
        if (this.trailerActions.panel?.view) {
            return this.trailerActions.panel.view(trailer);
        }

        return this.trailerActions.transition.view(trailer);
    }
}
