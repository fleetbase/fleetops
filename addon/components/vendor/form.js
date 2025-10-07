import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class VendorFormComponent extends Component {
    @service vendorActions;
    @tracked integration;

    @action createVendorIntegration(provider) {
        if (!provider) return;
        const integration = this.vendorActions.createVendorIntegration(provider);
        this.#setIntegration(integration);
    }

    @action handleTypeSelection({ value: type }) {
        this.args.resource.type = type;
        if (type !== 'integrated_vendor') {
            this.#setIntegration(null);
        }
    }

    @action selectPlace(place) {
        if (!place) return;
        this.args.resource.setProperties({
            place,
            place_uuid: place.id,
        });
    }

    @action removePlace() {
        this.args.resource.setProperties({
            place: null,
            place_uuid: null,
        });
    }

    @action editPlace() {
        if (this.args.resource.has_place) {
            this.vendorActions.editPlace(this.args.resource);
        } else {
            this.vendorActions.createPlace(this.args.resource);
        }
    }

    #setIntegration(integration) {
        this.integration = integration;
        if (typeof this.args.onIntegrationCreated === 'function') {
            this.args.onIntegrationCreated(integration);
        }
    }
}
